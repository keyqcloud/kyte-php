# Supervisor Configuration

This directory contains the Supervisor configuration file for running the Kyte Cron Worker as a supervised process.

## Installation

1. **Install Supervisor** (if not already installed):
   ```bash
   sudo apt-get install supervisor  # Ubuntu/Debian
   # or
   sudo yum install supervisor      # CentOS/RHEL
   ```

2. **Copy the configuration file:**
   ```bash
   sudo cp vendor/keyqcloud/kyte-php/supervisor/kyte-cron-worker.conf /etc/supervisor/conf.d/
   ```

3. **Edit the configuration** (adjust paths and user):
   ```bash
   sudo nano /etc/supervisor/conf.d/kyte-cron-worker.conf
   ```

   Update these values:
   - `command=...` - Update the full path to cron-worker.php
   - `directory=/var/www/html` - Change to your project root
   - `user=www-data` - Change to your web server user

4. **Reload Supervisor configuration:**
   ```bash
   sudo supervisorctl reread
   sudo supervisorctl update
   ```

5. **Start the worker:**
   ```bash
   sudo supervisorctl start kyte-cron-worker
   ```

## Managing the Worker

**Check status:**
```bash
sudo supervisorctl status kyte-cron-worker
```

**View logs:**
```bash
sudo tail -f /var/log/supervisor/kyte-cron-worker.log
```

**Stop the worker:**
```bash
sudo supervisorctl stop kyte-cron-worker
```

**Restart the worker:**
```bash
sudo supervisorctl restart kyte-cron-worker
```

**Stop all workers:**
```bash
sudo supervisorctl stop all
```

## Running Multiple Workers

To run multiple worker processes (for high-load scenarios), edit the config:

```ini
numprocs=3
process_name=%(program_name)s_%(process_num)02d
```

Then reload:
```bash
sudo supervisorctl reread
sudo supervisorctl update
```

## Troubleshooting

**Worker won't start:**
- Check PHP path: `which php`
- Verify file permissions
- Check logs: `/var/log/supervisor/kyte-cron-worker.log`

**Supervisor not running:**
```bash
sudo systemctl start supervisor
sudo systemctl enable supervisor
```

**Clear stopped processes:**
```bash
sudo supervisorctl clear kyte-cron-worker
```

## Advantages of Supervisor

- Automatic restart on failure
- Easy log management
- Can manage multiple worker processes
- Web interface available (supervisorctl)
- Works across different Linux distributions
