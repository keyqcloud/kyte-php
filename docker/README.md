# Docker / Docker Compose Configuration

This directory contains example Docker configuration for running the Kyte Cron Worker in a containerized environment.

## Docker Compose Setup

The provided `docker-compose.yml` includes a dedicated `cron-worker` service that runs alongside your web application.

### Quick Start

1. **Copy the docker-compose.yml** to your project root (or merge with existing):
   ```bash
   cp vendor/keyqcloud/kyte-php/docker/docker-compose.yml .
   ```

2. **Update environment variables** in the compose file:
   - Database credentials
   - Application-specific environment variables
   - AWS credentials (if needed)

3. **Start all services:**
   ```bash
   docker-compose up -d
   ```

4. **Check worker status:**
   ```bash
   docker-compose logs -f cron-worker
   ```

## Configuration Details

The cron-worker service:
- Shares the same image as your web app
- Mounts the same volumes for code access
- Connects to the same database
- Runs independently with auto-restart
- Has resource limits (512MB RAM, 0.5 CPU)

## Managing the Worker

**View logs:**
```bash
docker-compose logs -f cron-worker
```

**Restart worker:**
```bash
docker-compose restart cron-worker
```

**Stop worker:**
```bash
docker-compose stop cron-worker
```

**Rebuild and restart:**
```bash
docker-compose up -d --build cron-worker
```

## Scaling Workers

To run multiple worker instances:

```bash
docker-compose up -d --scale cron-worker=3
```

**Note:** With lease-based locking, multiple workers safely run on the same jobs without conflicts.

## Production Considerations

### Resource Limits

Adjust CPU and memory based on your job requirements:

```yaml
deploy:
  resources:
    limits:
      cpus: '1.0'
      memory: 1G
    reservations:
      cpus: '0.25'
      memory: 256M
```

### Health Checks

Add health check to ensure worker is responsive:

```yaml
healthcheck:
  test: ["CMD", "php", "-r", "echo 'OK';"]
  interval: 30s
  timeout: 10s
  retries: 3
  start_period: 40s
```

### Logging

For production, use a logging driver:

```yaml
logging:
  driver: "json-file"
  options:
    max-size: "10m"
    max-file: "3"
```

Or send to external logging service:

```yaml
logging:
  driver: "syslog"
  options:
    syslog-address: "tcp://logserver:514"
    tag: "kyte-cron-worker"
```

## Kubernetes Alternative

For Kubernetes deployments, create a Deployment:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: kyte-cron-worker
spec:
  replicas: 2
  selector:
    matchLabels:
      app: kyte-cron-worker
  template:
    metadata:
      labels:
        app: kyte-cron-worker
    spec:
      containers:
      - name: worker
        image: your-app-image:latest
        command: ["php", "/var/www/html/vendor/keyqcloud/kyte-php/bin/cron-worker.php"]
        resources:
          limits:
            memory: "512Mi"
            cpu: "500m"
        env:
        - name: DB_HOST
          value: mysql-service
        - name: DB_DATABASE
          valueFrom:
            secretKeyRef:
              name: db-secret
              key: database
```

## Troubleshooting

**Worker exits immediately:**
- Check logs: `docker-compose logs cron-worker`
- Verify database connection
- Ensure cron-worker.php exists in the container

**Database connection failed:**
- Ensure MySQL service is up: `docker-compose ps mysql`
- Check network connectivity: `docker-compose exec cron-worker ping mysql`
- Verify credentials match

**Code changes not reflected:**
- Restart worker: `docker-compose restart cron-worker`
- Or rebuild: `docker-compose up -d --build`

**Check if worker is running:**
```bash
docker-compose ps cron-worker
```
