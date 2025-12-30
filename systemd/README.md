# Systemd Service Configuration

This directory contains the systemd service file for running the Kyte Cron Worker as a system daemon.

## Installation

1. **Copy the service file:**
   ```bash
   sudo cp vendor/keyqcloud/kyte-php/systemd/kyte-cron-worker.service /etc/systemd/system/
   ```

2. **Edit the service file** (adjust paths and user):
   ```bash
   sudo nano /etc/systemd/system/kyte-cron-worker.service
   ```

   Update these values:
   - `User=www-data` - Change to your web server user
   - `Group=www-data` - Change to your web server group
   - `WorkingDirectory=/var/www/html` - Change to your project root
   - `ExecStart=...` - Update the full path to cron-worker.php

3. **Reload systemd:**
   ```bash
   sudo systemctl daemon-reload
   ```

4. **Start the service:**
   ```bash
   sudo systemctl start kyte-cron-worker
   ```

5. **Enable auto-start on boot:**
   ```bash
   sudo systemctl enable kyte-cron-worker
   ```

## Managing the Service

**Check status:**
```bash
sudo systemctl status kyte-cron-worker
```

**View logs:**
```bash
sudo journalctl -u kyte-cron-worker -f
```

**Stop the service:**
```bash
sudo systemctl stop kyte-cron-worker
```

**Restart the service:**
```bash
sudo systemctl restart kyte-cron-worker
```

## Troubleshooting

**Service fails to start:**
- Check file permissions: `ls -la /path/to/cron-worker.php`
- Verify PHP path: `which php`
- Check logs: `sudo journalctl -u kyte-cron-worker -n 50`

**Database connection errors:**
- Ensure MySQL service is running: `sudo systemctl status mysql`
- Verify database credentials in your config file
- Check that the service starts after MySQL: `After=mysql.service` in the service file

**Resource issues:**
- Adjust `MemoryLimit=512M` if jobs need more memory
- Adjust `CPUQuota=50%` for CPU limits

## Security Notes

The service file includes these security features:
- `PrivateTmp=true` - Isolates /tmp directory
- `NoNewPrivileges=true` - Prevents privilege escalation
- Resource limits prevent runaway processes

Adjust these settings based on your security requirements.
