# Job Templates - Future Feature Design

**Status:** Tabled for separate project
**Priority:** Medium (after core system is stable)
**Estimated Effort:** 15-20 hours for basic system
**Marketplace Potential:** High (SaaS revenue opportunity)

## Overview

Job Templates allow users to create cron jobs from pre-built, configurable templates. This accelerates job creation, promotes best practices, and creates foundation for future template marketplace (SaaS revenue model).

## Database Schema

```sql
CREATE TABLE CronJobTemplate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100), -- 'backup', 'api', 'cleanup', 'reporting', 'maintenance'
    description TEXT,
    code LONGBLOB, -- bzip2 compressed template code with {{PLACEHOLDERS}}

    -- Template metadata
    author VARCHAR(255),
    version VARCHAR(20),
    icon VARCHAR(50), -- FontAwesome icon class (e.g., 'fa-database', 'fa-cloud-upload')

    -- Configuration schema (JSON)
    config_schema JSON,
    /* Example config_schema:
    {
        "fields": [
            {"name": "database", "type": "string", "label": "Database Name", "required": true},
            {"name": "backup_path", "type": "string", "label": "Backup Path", "default": "/var/backups"},
            {"name": "retention_days", "type": "int", "label": "Retention (Days)", "default": 7}
        ]
    }
    */

    -- Marketplace fields (for future)
    is_public TINYINT DEFAULT 0,
    marketplace_id VARCHAR(100), -- UUID for marketplace listing
    price_cents INT DEFAULT 0, -- 0 = free, e.g., 499 = $4.99
    downloads INT DEFAULT 0,
    rating DECIMAL(3,2),

    -- Standard Kyte audit
    kyte_account INT, -- NULL = built-in template
    created_by INT,
    date_created INT,
    modified_by INT,
    date_modified INT,
    deleted TINYINT DEFAULT 0,

    INDEX idx_category (category),
    INDEX idx_public (is_public),
    INDEX idx_account (kyte_account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Template Code Structure

Templates use `{{PLACEHOLDER}}` syntax for user-configurable values:

```php
/**
 * Database Backup Template
 *
 * Performs MySQL database backup with automatic cleanup of old backups.
 * Supports custom retention policies and error notifications.
 */
class {{CLASS_NAME}} extends \Kyte\Core\CronJobBase {

    // User-configurable constants
    private const DATABASE = '{{DATABASE_NAME}}';
    private const BACKUP_PATH = '{{BACKUP_PATH}}';
    private const RETENTION_DAYS = {{RETENTION_DAYS}};
    private const COMPRESS = {{COMPRESS_BACKUP}}; // true/false

    public function execute() {
        $this->log("Starting backup for database: " . self::DATABASE);

        // Create backup directory if needed
        if (!is_dir(self::BACKUP_PATH)) {
            mkdir(self::BACKUP_PATH, 0755, true);
        }

        // Generate filename
        $timestamp = date('Y-m-d_His');
        $filename = self::BACKUP_PATH . '/' . self::DATABASE . '_' . $timestamp . '.sql';

        // Perform backup
        $cmd = sprintf(
            'mysqldump --single-transaction --quick %s > %s',
            escapeshellarg(self::DATABASE),
            escapeshellarg($filename)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \Exception("Backup failed with exit code {$exitCode}: " . implode("\n", $output));
        }

        // Compress if requested
        if (self::COMPRESS) {
            $this->log("Compressing backup...");
            exec("gzip -9 " . escapeshellarg($filename));
            $filename .= '.gz';
        }

        $size = filesize($filename);
        $this->log("Backup completed: {$filename} (" . $this->formatBytes($size) . ")");

        // Cleanup old backups
        $this->cleanupOldBackups();

        $this->log("Backup process complete");
    }

    private function cleanupOldBackups() {
        $cutoffTime = time() - (self::RETENTION_DAYS * 86400);
        $deleted = 0;

        $files = glob(self::BACKUP_PATH . '/' . self::DATABASE . '_*.sql*');

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->log("Deleted {$deleted} old backup(s)");
        }
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
```

## User Flow

### Creating Job from Template

1. User clicks **"New Cron Job"** button
2. Sees two tabs: **"Blank Job"** | **"From Template"**
3. Clicks **"From Template"** tab
4. Template gallery appears with categories:

```
┌─────────────────────────────────────────────────┐
│ 📁 Categories: All | Backup | API | Cleanup | Reports │
└─────────────────────────────────────────────────┘

┌──────────────┬──────────────┬──────────────┐
│ 💾 Database  │ ☁️  S3 Upload │ 📧 Email     │
│ Backup       │               │ Reports      │
│              │               │              │
│ Automated    │ Upload files  │ Send daily   │
│ MySQL dumps  │ to S3 with    │ summary via  │
│ with         │ retention     │ SMTP         │
│ rotation     │               │              │
│              │               │              │
│ ⭐⭐⭐⭐⭐    │ ⭐⭐⭐⭐      │ ⭐⭐⭐⭐⭐   │
│ 1.2k uses    │ 856 uses      │ 2.1k uses    │
└──────────────┴──────────────┴──────────────┘
```

5. User selects **"Database Backup"** template
6. Configuration modal appears:

```
┌────────────────────────────────────────────┐
│ Create Job from Template: Database Backup  │
├────────────────────────────────────────────┤
│                                            │
│ Job Name: *                                │
│ [Daily Production DB Backup            ]   │
│                                            │
│ Database Name: *                           │
│ [my_production_db                      ]   │
│                                            │
│ Backup Path: *                             │
│ [/var/backups/mysql                    ]   │
│                                            │
│ Retention Days: *                          │
│ [7                                     ]   │
│                                            │
│ Compress Backup:                           │
│ [✓] Yes (recommended)                      │
│                                            │
│ Schedule:                                  │
│ ○ Cron Expression                          │
│ ● Daily at: [02:00:00] Timezone: [UTC]    │
│ ○ Interval                                 │
│                                            │
│ [Preview Code]  [Cancel]  [Create Job]     │
└────────────────────────────────────────────┘
```

7. User clicks **"Preview Code"** to see generated PHP (optional)
8. User clicks **"Create Job"**
9. Backend:
   - Validates all required fields
   - Replaces `{{PLACEHOLDERS}}` with user values
   - Creates `CronJob` record
   - Creates Version 1
   - User redirected to job details

10. **User can still edit the generated code** - template is just a starting point!

## Built-in Templates (v1)

### 1. Database Backup
- **Category:** Backup
- **Icon:** fa-database
- **Fields:** database, backup_path, retention_days, compress
- **Use Case:** MySQL/PostgreSQL automated backups

### 2. S3 File Upload
- **Category:** Backup
- **Icon:** fa-cloud-upload-alt
- **Fields:** local_path, s3_bucket, s3_prefix, aws_region, delete_local
- **Use Case:** Upload files to S3, optionally delete local copies

### 3. API Data Sync
- **Category:** API
- **Icon:** fa-sync
- **Fields:** api_url, api_key, sync_interval, data_table
- **Use Case:** Pull data from external API, store in database

### 4. Log Cleanup
- **Category:** Cleanup
- **Icon:** fa-broom
- **Fields:** log_path, retention_days, file_pattern
- **Use Case:** Delete old log files matching pattern

### 5. Daily Email Report
- **Category:** Reporting
- **Icon:** fa-envelope
- **Fields:** recipient_email, report_query, subject_template
- **Use Case:** Run SQL query, email results

### 6. Sitemap Generator
- **Category:** Maintenance
- **Icon:** fa-sitemap
- **Fields:** base_url, output_path, exclude_patterns
- **Use Case:** Generate XML sitemap for SEO

### 7. Cache Warming
- **Category:** Performance
- **Icon:** fa-fire
- **Fields:** urls_file, concurrent_requests, user_agent
- **Use Case:** Pre-cache important pages

### 8. Image Optimization
- **Category:** Maintenance
- **Icon:** fa-images
- **Fields:** image_path, quality, max_width, formats
- **Use Case:** Compress and resize images

### 9. Health Check Ping
- **Category:** Monitoring
- **Icon:** fa-heartbeat
- **Fields:** check_url, expected_status, alert_webhook
- **Use Case:** Monitor endpoint availability

### 10. Data Export
- **Category:** Reporting
- **Icon:** fa-file-export
- **Fields:** export_query, format, destination_path
- **Use Case:** Export data as CSV/JSON/XML

## Backend Implementation

### Controller: CronJobTemplateController

```php
<?php
namespace Kyte\Mvc\Controller;

class CronJobTemplateController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Templates are read-only for most users
        $this->allowableActions = ['get'];
    }

    /**
     * Custom action: Get all templates for current account
     *
     * GET /api/CronJobTemplate/available
     */
    public function available() {
        $accountId = Api::getInstance()->account->id;

        // Get built-in templates (kyte_account IS NULL) + user's templates
        $sql = "
            SELECT id, name, category, description, icon,
                   config_schema, author, version, downloads, rating
            FROM CronJobTemplate
            WHERE (kyte_account IS NULL OR kyte_account = ?)
              AND deleted = 0
            ORDER BY category, downloads DESC
        ";

        $templates = DBI::prepared_query($sql, 'i', [$accountId]);

        // Parse config_schema JSON
        foreach ($templates as &$template) {
            $template['config_schema'] = json_decode($template['config_schema'], true);
        }

        return $this->respond([
            'templates' => $templates,
            'categories' => $this->getCategories()
        ]);
    }

    /**
     * Custom action: Instantiate template into new job
     *
     * POST /api/CronJobTemplate/instantiate
     * Body: {template_id: 5, config: {...}, schedule: {...}}
     */
    public function instantiate() {
        $input = json_decode(file_get_contents('php://input'), true);
        $templateId = $input['template_id'];
        $config = $input['config'];
        $schedule = $input['schedule'];

        // Get template
        $template = Model::one('CronJobTemplate', $templateId);
        if (!$template) {
            return $this->respond(['error' => 'Template not found'], 404);
        }

        // Decompress code
        $code = bzdecompress($template->code);

        // Replace placeholders
        foreach ($config as $key => $value) {
            $placeholder = '{{' . strtoupper($key) . '}}';

            // Handle boolean values
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            // Handle strings (add quotes)
            elseif (is_string($value)) {
                $value = "'" . addslashes($value) . "'";
            }

            $code = str_replace($placeholder, $value, $code);
        }

        // Create job using CronJobManager
        $manager = new \Kyte\Cron\CronJobManager();

        $jobData = array_merge($schedule, [
            'name' => $config['job_name'] ?? $template->name,
            'code' => $code,
            'description' => "Created from template: {$template->name}",
            'application' => Api::getInstance()->app->id,
            'created_by' => Api::getInstance()->account->user->id
        ]);

        try {
            $job = $manager->createJob($jobData);

            // Increment template download count
            $sql = "UPDATE CronJobTemplate SET downloads = downloads + 1 WHERE id = ?";
            DBI::prepared_query($sql, 'i', [$templateId]);

            return $this->respond([
                'success' => true,
                'job_id' => $job->id,
                'message' => 'Job created from template successfully'
            ]);

        } catch (\Exception $e) {
            return $this->respond(['error' => $e->getMessage()], 400);
        }
    }

    private function getCategories() {
        return ['Backup', 'API', 'Cleanup', 'Reporting', 'Maintenance', 'Performance', 'Monitoring'];
    }
}
```

## Frontend Implementation

### Template Gallery Modal

Add to `cron-jobs.html`:

```html
<!-- Template Gallery Modal -->
<div class="modal fade" id="templateGalleryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create from Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Category filters -->
                <div class="template-categories mb-3">
                    <button class="btn btn-sm btn-outline-primary active" data-category="all">All</button>
                    <button class="btn btn-sm btn-outline-primary" data-category="backup">Backup</button>
                    <button class="btn btn-sm btn-outline-primary" data-category="api">API</button>
                    <!-- ... more categories -->
                </div>

                <!-- Template grid -->
                <div id="template-grid" class="row g-3">
                    <!-- Templates loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Template Config Modal -->
<div class="modal fade" id="templateConfigModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateConfigTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="template-config-form">
                    <!-- Dynamic fields based on template config_schema -->
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btn-preview-template-code">Preview Code</button>
                <button type="button" class="btn btn-primary" id="btn-create-from-template">Create Job</button>
            </div>
        </div>
    </div>
</div>
```

### JavaScript (add to kyte-shipyard-cron-jobs.js):

```javascript
// Load template gallery
$(document).on('click', '#btn-new-from-template', function() {
    $.get('/api/CronJobTemplate/available', function(response) {
        renderTemplateGallery(response.templates);
        $('#templateGalleryModal').modal('show');
    });
});

function renderTemplateGallery(templates) {
    let html = '';
    templates.forEach(t => {
        html += `
            <div class="col-md-4">
                <div class="template-card" data-template-id="${t.id}">
                    <div class="template-icon"><i class="fas ${t.icon}"></i></div>
                    <h6>${t.name}</h6>
                    <p class="small">${t.description}</p>
                    <div class="template-meta">
                        <span class="badge bg-secondary">${t.category}</span>
                        <span class="template-stats">
                            <i class="fas fa-download"></i> ${t.downloads}
                            <i class="fas fa-star"></i> ${t.rating || 'N/A'}
                        </span>
                    </div>
                </div>
            </div>
        `;
    });
    $('#template-grid').html(html);
}

// Select template
$(document).on('click', '.template-card', function() {
    let templateId = $(this).data('template-id');
    loadTemplateConfig(templateId);
});

function loadTemplateConfig(templateId) {
    $.get('/api/CronJobTemplate?id=' + templateId, function(template) {
        // Build dynamic form from config_schema
        let formHtml = buildTemplateForm(template.config_schema.fields);
        $('#template-config-form').html(formHtml);
        $('#templateConfigTitle').text('Configure: ' + template.name);

        $('#templateGalleryModal').modal('hide');
        $('#templateConfigModal').modal('show');

        // Store template ID for submission
        $('#templateConfigModal').data('template-id', templateId);
    });
}

// Create job from template
$(document).on('click', '#btn-create-from-template', function() {
    let templateId = $('#templateConfigModal').data('template-id');
    let config = getFormData('#template-config-form');

    $.ajax({
        url: '/api/CronJobTemplate/instantiate',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            template_id: templateId,
            config: config,
            schedule: getScheduleConfig()
        }),
        success: function(response) {
            alert('Job created successfully!');
            $('#templateConfigModal').modal('hide');
            tblCronJobs.table.ajax.reload();
        },
        error: function(xhr) {
            alert('Error: ' + (xhr.responseJSON?.error || 'Failed to create job'));
        }
    });
});
```

## Marketplace Evolution (Future)

### Current: Built-in Templates
- 10 curated templates included
- Free, maintained by Kyte team
- Stored in database

### Near-term: Private Templates (6-12 months)
- Users can save their own jobs as templates
- Share within team/organization
- Template versioning

### Long-term: Public Marketplace (12-24 months)
- Public template submission
- Review/approval process
- Free + paid templates
- Revenue sharing (70/30 split)
- Rating/review system
- Search & discovery
- Categories & tags
- Featured templates
- Author profiles
- Download statistics

### Monetization Strategy

**For Template Authors:**
- Free templates: Build reputation
- Freemium: Basic free, premium features paid
- Paid templates: $5-50 one-time or subscription
- 70% revenue share

**For Kyte Platform:**
- 30% commission on all sales
- Premium author tier ($19/mo - lower commission, featured placement)
- Enterprise template packs
- Custom template development service

**Projected Revenue (at scale):**
- 10,000 Kyte installations
- 20% use marketplace (2,000)
- Average $10/month in template purchases
- Gross: $20,000/month
- Kyte share (30%): $6,000/month
- Annual: $72,000

## Implementation Priority

**When to implement:**
1. After core cron system is complete
2. After 100+ production Kyte deployments
3. After user feedback on most-wanted templates
4. When you're ready to invest in marketplace platform

**Estimated total effort:**
- Basic templates (v1): 15 hours
- Template marketplace: 80-120 hours
- Payment integration: 20 hours
- Review/moderation system: 30 hours

## Notes

- Templates are a **differentiator** - not many cron systems have this
- Marketplace creates **network effect** - more users = more templates = more value
- **Start simple** - just 5-10 built-in templates initially
- **Validate demand** before building full marketplace
- Consider **template licensing** (MIT, proprietary, etc.)
- Plan for **template updates** - how to notify users of new versions?

---

**Next Steps When Ready:**
1. Design 5-10 initial templates based on common use cases
2. Implement template instantiation API
3. Build template gallery UI
4. Beta test with select users
5. Gather feedback on most-wanted templates
6. Plan marketplace features based on demand
