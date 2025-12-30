# WebSocket Architecture - Real-Time Features

**Status:** Queued for next development stage
**Priority:** High (real-time monitoring + collaborative editing)
**Estimated Effort:** 20-25 hours for core system
**Dependencies:** Redis, Node.js (or PHP Workerman)

## Overview

WebSocket integration provides real-time bidirectional communication for:
1. **Cron monitoring** - Live job execution updates, progress tracking
2. **Collaborative editing** - Multi-user simultaneous editing with Monaco
3. **System notifications** - Instant alerts, DLQ warnings, system events

## Architecture Options

### Option A: Sticky Sessions (Simplest)
**Load Balancer → Kyte Servers (with built-in WebSocket)**

**Pros:**
- No additional infrastructure
- Simple deployment
- WebSocket in same PHP process

**Cons:**
- Doesn't scale horizontally well
- Connection lost if server restarts
- Uneven load distribution

**Use when:** Single server or <1000 concurrent users

---

### Option B: Separate WebSocket Server + Redis (Recommended)
**Load Balancer → Kyte Servers → Redis Pub/Sub → WebSocket Server → Clients**

```
Architecture:
┌─────────────────────────────────────────┐
│      Load Balancer (HTTP/HTTPS)         │
│       kyte.example.com                  │
└─────────────────────────────────────────┘
            ├──────┬──────┬──────┐
            ▼      ▼      ▼      ▼
        Kyte1  Kyte2  Kyte3  Kyte4
        (PHP)  (PHP)  (PHP)  (PHP)
            │      │      │      │
            └──────┴──────┴──────┘
                    │
                    ▼
            ┌──────────────┐
            │ Redis Pub/Sub│ ← Message broker
            └──────────────┘
                    │
            ┌───────▼────────┐
            │ WebSocket LB   │
            │ ws.kyte.example.com
            └───────┬────────┘
                    │
            ┌───────▼────────┐
            │ WS Server 1    │
            │ WS Server 2    │ ← Node.js/Go/PHP Workerman
            │ WS Server 3    │
            └───────┬────────┘
                    │
            ┌───────▼────────┐
            │   Clients      │
            │ (Browser WS)   │
            └────────────────┘
```

**Pros:**
- Scales horizontally (add more WS servers)
- Survives individual server restarts
- 10,000+ concurrent connections per server
- Clean separation of concerns

**Cons:**
- Redis dependency (but lightweight)
- Additional service to maintain
- More complex deployment

**Use when:** >1000 users or multi-server setup

---

### Option C: Managed Service (Easiest)
**Kyte Servers → Pusher/Ably → Clients**

**Pros:**
- Zero infrastructure management
- Global edge network
- Automatic scaling
- Free tier available

**Cons:**
- Monthly cost at scale ($49-499/mo)
- External dependency
- Data through 3rd party

**Use when:** Want simplicity, low maintenance, or global distribution

---

## Recommended: Option B (Separate Server + Redis)

### Technology Stack

**Message Broker:**
- **Redis** (in-memory pub/sub)
- Alternative: RabbitMQ, NATS (more complex)

**WebSocket Server:**
- **Node.js + Socket.io** (recommended - best ecosystem)
- Alternative: Go + Gorilla WebSocket (higher performance)
- Alternative: PHP Workerman (keep everything PHP)

**Why Node.js:**
- Built for async I/O (perfect for WebSockets)
- 10,000+ concurrent connections on single core
- Massive ecosystem (Socket.io, ws, uWebSockets)
- Can run on $5/mo VPS

### Redis Channel Structure

```
Channel Naming Convention:

Cron System:
├─ cron.execution.{job_id}      → Specific job updates
├─ cron.execution.global         → All execution events
├─ cron.worker.{worker_id}       → Worker heartbeat/status
└─ cron.system.alerts            → System-wide alerts (DLQ, failures)

Collaborative Editing:
├─ editor.function.{function_id} → Function code editing
├─ editor.controller.{ctrl_id}   → Controller code editing
├─ editor.model.{model_id}       → Model editing
└─ editor.page.{page_id}         → Page editing

User Notifications:
└─ user.{user_id}                → Personal notifications

Examples:
- cron.execution.5               → Job #5 updates
- editor.function.123            → Function #123 being edited
- user.42                        → Notifications for user #42
```

### Message Format (JSON)

```json
// Cron execution update
{
  "channel": "cron.execution.5",
  "event": "execution-update",
  "data": {
    "job_id": 5,
    "execution_id": 123,
    "status": "running",
    "progress": 45,
    "duration_ms": 12500,
    "memory_mb": 14.2,
    "timestamp": 1735516800
  }
}

// Collaborative editing - cursor move
{
  "channel": "editor.function.123",
  "event": "cursor-move",
  "data": {
    "user_id": 42,
    "user_name": "John Doe",
    "position": {"line": 10, "column": 5},
    "timestamp": 1735516801
  }
}

// Collaborative editing - text change
{
  "channel": "editor.function.123",
  "event": "text-change",
  "data": {
    "user_id": 42,
    "user_name": "John Doe",
    "changes": [{
      "range": {"startLine": 10, "startCol": 5, "endLine": 10, "endCol": 10},
      "text": "Hello",
      "rangeLength": 5
    }],
    "timestamp": 1735516802
  }
}

// System alert
{
  "channel": "cron.system.alerts",
  "event": "dlq-alert",
  "data": {
    "job_id": 8,
    "job_name": "Email Sender",
    "reason": "SMTP server unavailable",
    "consecutive_failures": 5,
    "timestamp": 1735516803
  }
}
```

---

## Implementation

### 1. PHP Publisher (Kyte Servers)

```php
<?php
namespace Kyte\Core;

use Predis\Client;

/**
 * WebSocket Publisher
 *
 * Publishes events to Redis for WebSocket server to broadcast
 */
class WebSocketPublisher
{
    private static $redis = null;
    private static $enabled = true;

    public static function init($host = '127.0.0.1', $port = 6379) {
        if (!class_exists('\\Predis\\Client')) {
            self::$enabled = false;
            return;
        }

        try {
            self::$redis = new Client([
                'scheme' => 'tcp',
                'host'   => $host,
                'port'   => $port,
                'timeout' => 1.0
            ]);
            self::$redis->ping(); // Test connection
        } catch (\Exception $e) {
            error_log("WebSocket publisher disabled: " . $e->getMessage());
            self::$enabled = false;
        }
    }

    public static function publish($channel, $event, $data) {
        if (!self::$enabled || !self::$redis) {
            return false;
        }

        $message = json_encode([
            'channel' => $channel,
            'event' => $event,
            'data' => $data,
            'timestamp' => time()
        ]);

        try {
            self::$redis->publish($channel, $message);
            return true;
        } catch (\Exception $e) {
            error_log("WebSocket publish failed: " . $e->getMessage());
            return false;
        }
    }

    // Helper methods for common events

    public static function cronExecutionUpdate($jobId, $executionId, $status, $progress = null) {
        $data = [
            'job_id' => $jobId,
            'execution_id' => $executionId,
            'status' => $status,
            'progress' => $progress,
            'timestamp' => time()
        ];

        // Publish to specific job channel
        self::publish("cron.execution.{$jobId}", 'execution-update', $data);

        // Also publish to global channel
        self::publish("cron.execution.global", 'execution-update', $data);
    }

    public static function editorCursorMove($editorType, $fileId, $userId, $userName, $position) {
        $data = [
            'user_id' => $userId,
            'user_name' => $userName,
            'position' => $position,
            'timestamp' => time()
        ];

        self::publish("editor.{$editorType}.{$fileId}", 'cursor-move', $data);
    }

    public static function editorTextChange($editorType, $fileId, $userId, $userName, $changes) {
        $data = [
            'user_id' => $userId,
            'user_name' => $userName,
            'changes' => $changes,
            'timestamp' => time()
        ];

        self::publish("editor.{$editorType}.{$fileId}", 'text-change', $data);
    }

    public static function systemAlert($type, $data) {
        self::publish('cron.system.alerts', $type, $data);
    }
}

// Initialize on bootstrap
WebSocketPublisher::init(REDIS_HOST ?? '127.0.0.1', REDIS_PORT ?? 6379);
```

**Integration in CronWorker:**

```php
// In CronWorker::executeJob()
private function executeJob($execution, $job) {
    // Notify execution started
    WebSocketPublisher::cronExecutionUpdate(
        $job['id'],
        $execution['id'],
        'running',
        0
    );

    try {
        // Execute job...

        // During execution, publish progress if job calls heartbeat with progress
        // (Modified heartbeat method to accept progress parameter)

        // Notify completion
        WebSocketPublisher::cronExecutionUpdate(
            $job['id'],
            $execution['id'],
            'completed',
            100
        );
    } catch (\Exception $e) {
        // Notify failure
        WebSocketPublisher::cronExecutionUpdate(
            $job['id'],
            $execution['id'],
            'failed',
            null
        );
    }
}
```

### 2. Node.js WebSocket Server

**File: `websocket-server/package.json`**

```json
{
  "name": "kyte-websocket-server",
  "version": "1.0.0",
  "description": "WebSocket server for Kyte real-time features",
  "main": "server.js",
  "scripts": {
    "start": "node server.js",
    "dev": "nodemon server.js"
  },
  "dependencies": {
    "socket.io": "^4.6.0",
    "ioredis": "^5.3.0",
    "dotenv": "^16.0.3"
  },
  "devDependencies": {
    "nodemon": "^3.0.1"
  }
}
```

**File: `websocket-server/.env`**

```env
PORT=3000
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CORS_ORIGIN=https://kyte.example.com
```

**File: `websocket-server/server.js`**

```javascript
const io = require('socket.io')(process.env.PORT || 3000, {
    cors: {
        origin: process.env.CORS_ORIGIN || "http://localhost",
        methods: ["GET", "POST"],
        credentials: true
    }
});

const Redis = require('ioredis');
const redis = new Redis({
    host: process.env.REDIS_HOST || '127.0.0.1',
    port: process.env.REDIS_PORT || 6379,
    retryStrategy: (times) => {
        const delay = Math.min(times * 50, 2000);
        return delay;
    }
});

// Subscribe to all Kyte channels
redis.psubscribe('cron.*', 'editor.*', 'system.*', 'user.*', (err, count) => {
    if (err) {
        console.error('Redis subscribe error:', err);
        return;
    }
    console.log(`Subscribed to ${count} channel patterns`);
});

// Handle messages from Redis
redis.on('pmessage', (pattern, channel, message) => {
    try {
        const data = JSON.parse(message);
        routeMessage(channel, data);
    } catch (err) {
        console.error('Message parse error:', err);
    }
});

function routeMessage(channel, data) {
    const parts = channel.split('.');

    if (channel.startsWith('cron.execution.')) {
        // Route to job-specific room or global cron room
        if (parts[2] === 'global') {
            io.to('cron-all').emit('execution-update', data.data);
        } else {
            const jobId = parts[2];
            io.to(`cron-job-${jobId}`).emit('execution-update', data.data);
            io.to('cron-all').emit('execution-update', data.data); // Also to global
        }
    }
    else if (channel.startsWith('cron.system.alerts')) {
        io.to('cron-all').emit('system-alert', data.data);
    }
    else if (channel.startsWith('editor.')) {
        // Route to editor session room
        const [_, editorType, fileId] = parts;
        io.to(`editor-${editorType}-${fileId}`).emit(data.event, data.data);
    }
    else if (channel.startsWith('user.')) {
        // Route to user-specific room
        const userId = parts[1];
        io.to(`user-${userId}`).emit('notification', data.data);
    }
}

// Client connection handling
io.on('connection', (socket) => {
    console.log('Client connected:', socket.id);

    // Authenticate (verify session token)
    socket.on('authenticate', (token) => {
        // TODO: Validate token against Kyte API
        // For now, accept all connections
        socket.authenticated = true;
        socket.userId = extractUserIdFromToken(token);
        socket.emit('authenticated', { success: true });
    });

    // Subscribe to all cron updates
    socket.on('subscribe-cron-all', () => {
        socket.join('cron-all');
        console.log(`${socket.id} subscribed to all cron updates`);
    });

    // Subscribe to specific job updates
    socket.on('subscribe-job', (jobId) => {
        socket.join(`cron-job-${jobId}`);
        console.log(`${socket.id} subscribed to job ${jobId}`);
    });

    // Subscribe to editor session
    socket.on('subscribe-editor', ({ type, fileId }) => {
        const room = `editor-${type}-${fileId}`;
        socket.join(room);
        socket.currentEditor = { type, fileId };

        // Notify other users in room
        socket.to(room).emit('user-joined', {
            userId: socket.userId,
            socketId: socket.id
        });

        console.log(`${socket.id} joined editor ${room}`);
    });

    // Collaborative editing: cursor position
    socket.on('cursor-move', (position) => {
        if (!socket.currentEditor) return;

        const { type, fileId } = socket.currentEditor;
        socket.to(`editor-${type}-${fileId}`).emit('remote-cursor', {
            userId: socket.userId,
            socketId: socket.id,
            position: position
        });
    });

    // Collaborative editing: text changes
    socket.on('text-change', (changes) => {
        if (!socket.currentEditor) return;

        const { type, fileId } = socket.currentEditor;

        // Broadcast to other users in editor
        socket.to(`editor-${type}-${fileId}`).emit('remote-change', {
            userId: socket.userId,
            socketId: socket.id,
            changes: changes
        });

        // Also persist to Redis for replay (optional)
        // Could store recent changes for users joining later
    });

    // Disconnect handling
    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);

        // Notify editor room if user was editing
        if (socket.currentEditor) {
            const { type, fileId } = socket.currentEditor;
            socket.to(`editor-${type}-${fileId}`).emit('user-left', {
                userId: socket.userId,
                socketId: socket.id
            });
        }
    });
});

function extractUserIdFromToken(token) {
    // TODO: Validate token and extract user ID
    // For now, return dummy ID
    return 'user-' + Math.floor(Math.random() * 1000);
}

// Health check endpoint
const http = require('http').createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'healthy',
            connections: io.engine.clientsCount,
            uptime: process.uptime()
        }));
    }
});

http.listen(process.env.PORT || 3000);

console.log(`WebSocket server running on port ${process.env.PORT || 3000}`);
```

### 3. Frontend Integration

**Connect to WebSocket:**

```javascript
// In kyte-shipyard.js (global initialization)
document.addEventListener('KyteInitialized', function(e) {
    let _ks = e.detail._ks;

    // Initialize WebSocket connection
    if (typeof io !== 'undefined') {
        window.kyteSocket = io('wss://ws.kyte.example.com', {
            auth: {
                token: _ks.getSessionToken()
            },
            reconnection: true,
            reconnectionAttempts: 5,
            reconnectionDelay: 1000
        });

        kyteSocket.on('connect', () => {
            console.log('WebSocket connected');

            // Authenticate
            kyteSocket.emit('authenticate', _ks.getSessionToken());
        });

        kyteSocket.on('authenticated', (data) => {
            console.log('WebSocket authenticated');
            window.kyteSocketReady = true;

            // Trigger custom event for pages to subscribe
            document.dispatchEvent(new CustomEvent('KyteSocketReady', {
                detail: { socket: kyteSocket }
            }));
        });

        kyteSocket.on('disconnect', () => {
            console.log('WebSocket disconnected');
            window.kyteSocketReady = false;
        });

        kyteSocket.on('connect_error', (error) => {
            console.error('WebSocket connection error:', error);
        });
    }
});
```

**Cron Page Real-Time Updates:**

```javascript
// In kyte-shipyard-cron-jobs.js
document.addEventListener('KyteSocketReady', function(e) {
    const socket = e.detail.socket;

    // Subscribe to all cron updates for real-time table refresh
    socket.emit('subscribe-cron-all');

    socket.on('execution-update', (data) => {
        console.log('Job update:', data);

        // Update specific table row in real-time
        const row = tblCronJobs.table.row(`[data-job-id="${data.job_id}"]`);

        if (row.length) {
            // Update status badge
            const statusCell = row.node().querySelector('.status-badge');
            if (statusCell) {
                updateStatusBadge(statusCell, data.status);
            }

            // Show progress bar if running
            if (data.status === 'running' && data.progress) {
                showProgressBar(row.node(), data.progress);
            }

            // Reload full row data when complete
            if (data.status === 'completed' || data.status === 'failed') {
                row.invalidate().draw(false); // Reload row from server
                hideProgressBar(row.node());
            }
        }
    });

    socket.on('system-alert', (data) => {
        // Show toast notification
        showToast('System Alert', data.message, 'warning');

        // If DLQ alert, highlight job in table
        if (data.type === 'dlq-alert') {
            highlightDLQJob(data.job_id);
        }
    });
});

function showProgressBar(rowNode, progress) {
    const existing = rowNode.querySelector('.job-progress');
    if (existing) {
        existing.querySelector('.progress-bar').style.width = progress + '%';
    } else {
        const progressHtml = `
            <div class="job-progress">
                <div class="progress" style="height: 4px;">
                    <div class="progress-bar" role="progressbar"
                         style="width: ${progress}%" aria-valuenow="${progress}"
                         aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        `;
        $(rowNode).find('td:first').append(progressHtml);
    }
}
```

**Collaborative Editing (Monaco):**

```javascript
// In function/controller editor pages
document.addEventListener('KyteSocketReady', function(e) {
    const socket = e.detail.socket;
    const editorType = 'function'; // or 'controller', 'model', etc.
    const fileId = getCurrentFileId();

    // Subscribe to editor session
    socket.emit('subscribe-editor', { type: editorType, fileId: fileId });

    // Track remote cursors
    const remoteCursors = new Map();

    // Send cursor position
    editor.onDidChangeCursorPosition((e) => {
        socket.emit('cursor-move', {
            line: e.position.lineNumber,
            column: e.position.column
        });
    });

    // Send text changes
    editor.onDidChangeModelContent((e) => {
        socket.emit('text-change', e.changes);
    });

    // Receive remote cursor positions
    socket.on('remote-cursor', (data) => {
        updateRemoteCursor(data.userId, data.socketId, data.position);
    });

    // Receive remote text changes
    socket.on('remote-change', (data) => {
        // Apply changes from other user
        editor.executeEdits('remote-user', data.changes.map(change => ({
            range: new monaco.Range(
                change.range.startLineNumber,
                change.range.startColumn,
                change.range.endLineNumber,
                change.range.endColumn
            ),
            text: change.text
        })));
    });

    // User joined editor
    socket.on('user-joined', (data) => {
        showNotification(`${data.userId} joined the editor`);
    });

    // User left editor
    socket.on('user-left', (data) => {
        removeRemoteCursor(data.socketId);
        showNotification(`${data.userId} left the editor`);
    });
});

function updateRemoteCursor(userId, socketId, position) {
    // Create or update cursor widget in Monaco editor
    let cursor = remoteCursors.get(socketId);

    if (!cursor) {
        cursor = {
            id: socketId,
            decoration: editor.deltaDecorations([], [{
                range: new monaco.Range(position.line, position.column, position.line, position.column),
                options: {
                    className: 'remote-cursor',
                    glyphMarginClassName: 'remote-cursor-glyph',
                    stickiness: monaco.editor.TrackedRangeStickiness.NeverGrowsWhenTypingAtEdges
                }
            }])
        };
        remoteCursors.set(socketId, cursor);
    } else {
        cursor.decoration = editor.deltaDecorations(cursor.decoration, [{
            range: new monaco.Range(position.line, position.column, position.line, position.column),
            options: {
                className: 'remote-cursor',
                glyphMarginClassName: 'remote-cursor-glyph'
            }
        }]);
    }
}

function removeRemoteCursor(socketId) {
    const cursor = remoteCursors.get(socketId);
    if (cursor) {
        editor.deltaDecorations(cursor.decoration, []);
        remoteCursors.delete(socketId);
    }
}
```

**CSS for Remote Cursors:**

```css
.remote-cursor {
    border-left: 2px solid #4299e1;
    background: rgba(66, 153, 225, 0.1);
}

.remote-cursor-glyph {
    background: #4299e1;
    width: 3px !important;
}
```

---

## Deployment

### Development (Single Server)

```bash
# Install Redis
sudo apt install redis-server

# Start Redis
sudo systemctl start redis
sudo systemctl enable redis

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Deploy WebSocket server
cd /var/www/kyte-websocket
npm install
npm install -g pm2
pm2 start server.js --name kyte-websocket
pm2 startup
pm2 save

# Configure Nginx
sudo nano /etc/nginx/sites-available/kyte-websocket
```

**Nginx config:**

```nginx
server {
    listen 443 ssl;
    server_name ws.kyte.example.com;

    ssl_certificate /etc/ssl/certs/kyte.crt;
    ssl_certificate_key /etc/ssl/private/kyte.key;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_cache_bypass $http_upgrade;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
```

### Production (Multi-Server)

**Separate WebSocket VPS:**
- $5-10/mo VPS (1GB RAM, 1 CPU)
- Digital Ocean, Linode, Vultr, etc.
- Handles 10,000+ concurrent connections

**Docker Compose:**

```yaml
version: '3.8'

services:
  redis:
    image: redis:7-alpine
    restart: always
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data

  websocket:
    build: ./websocket-server
    restart: always
    ports:
      - "3000:3000"
    environment:
      - NODE_ENV=production
      - PORT=3000
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - CORS_ORIGIN=https://kyte.example.com
    depends_on:
      - redis
    deploy:
      replicas: 3 # Run 3 instances
      resources:
        limits:
          memory: 512M

  nginx:
    image: nginx:alpine
    restart: always
    ports:
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf
      - ./ssl:/etc/ssl/certs
    depends_on:
      - websocket

volumes:
  redis-data:
```

---

## Performance & Scaling

### Single Server Capacity
- **1 Node.js process:** 10,000-15,000 concurrent connections
- **1GB RAM:** Sufficient for 10,000 connections
- **1 CPU core:** Handles load with Socket.io

### Horizontal Scaling
- Add more WebSocket servers behind load balancer
- Redis Pub/Sub distributes messages to all servers
- No shared state between WS servers
- Load balancer: Nginx or HAProxy

### Monitoring
```javascript
// Health metrics endpoint
app.get('/metrics', (req, res) => {
    res.json({
        connections: io.engine.clientsCount,
        uptime: process.uptime(),
        memory: process.memoryUsage(),
        rooms: io.sockets.adapter.rooms.size
    });
});
```

---

## Security

### Authentication
- Validate session token on connect
- Verify token with Kyte API
- Store userId with socket connection
- Disconnect invalid sessions

### Authorization
- Check permissions before room joins
- Verify user can access job/file
- Prevent cross-account data leaks

### Rate Limiting
- Limit messages per second per client
- Throttle cursor/change events
- Auto-disconnect abusive clients

---

## Cost Analysis

### Infrastructure Costs (Monthly)

**Option A (Sticky Sessions):**
- No additional cost
- Use existing Kyte servers

**Option B (Separate Server):**
- Redis: $0 (included with servers) or $15 (managed)
- WebSocket VPS: $5-10
- **Total: $5-25/month**

**Option C (Managed Service):**
- Pusher: $49-499/month
- Ably: $29-399/month

**Recommended:** Option B ($5-25/mo) for best value

---

## Next Steps When Ready

1. **Basic WebSocket** (8 hours)
   - Set up Redis
   - Deploy Node.js WebSocket server
   - Integrate with CronWorker for execution updates
   - Frontend connection and real-time table updates

2. **Live Dashboard** (6 hours)
   - Real-time running jobs display
   - Live progress bars
   - System health metrics
   - Alert notifications

3. **Collaborative Editing** (12 hours)
   - Cursor position tracking
   - Text change synchronization
   - User presence indicators
   - Conflict resolution

**Total: ~26 hours for complete WebSocket implementation**

---

**Benefits:**
- Real-time visibility into job executions
- Collaborative editing reduces conflicts
- Better user experience (no page refreshes)
- Foundation for future real-time features
- Differentiator vs other cron systems

**Risks:**
- Additional infrastructure complexity
- Redis dependency
- WebSocket server maintenance
- Connection scaling considerations

**Mitigation:**
- Start simple (Option A) and migrate to Option B later
- Docker containers for easy deployment
- Health checks and monitoring
- Fallback to polling if WebSocket fails
