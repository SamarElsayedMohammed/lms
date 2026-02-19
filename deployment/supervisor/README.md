# Supervisor Configuration for eLMS

This directory contains supervisor configuration files for running Laravel queue workers in production.

## Queue Architecture

The system uses two separate queues for optimal resource management:

| Queue            | Purpose                             | Workers | Timeout |
|------------------|-------------------------------------|---------|---------|
| `default`        | Notifications, emails, general jobs | 2       | 1 hour  |
| `video-encoding` | HLS video encoding (CPU intensive)  | 1       | 2 hours |

This separation ensures:
- Video encoding doesn't block other jobs
- Resource-intensive encoding runs with limited concurrency
- Server stays responsive during heavy encoding

### How Queues and Workers Work

Jobs are added to a **queue** (a list of pending tasks). **Workers** are processes that pick up jobs from the queue and execute them.

**With 1 video worker (default):**
```
Queue (video-encoding)              Workers
┌─────────────────────────┐
│ Video 1 (waiting)       │         ┌──────────┐
│ Video 2 (waiting)       │ ──────► │ Worker 1 │ (encoding 1 video at a time)
│ Video 3 (waiting)       │         └──────────┘
│ Video 4 (waiting)       │
└─────────────────────────┘         numprocs=1

Videos encode ONE BY ONE. Others wait in queue.
```

**With 3 video workers (high traffic server):**
```
Queue (video-encoding)              Workers
┌─────────────────────────┐         ┌──────────┐
│ Video 4 (waiting)       │ ──────► │ Worker 1 │ (encoding Video 1)
│ Video 5 (waiting)       │ ──────► │ Worker 2 │ (encoding Video 2)
│ Video 6 (waiting)       │ ──────► │ Worker 3 │ (encoding Video 3)
└─────────────────────────┘         └──────────┘
                                    numprocs=3

3 videos encode SIMULTANEOUSLY. Faster but uses more CPU/RAM.
```

**Trade-off:**
- More workers = faster processing, higher resource usage
- Fewer workers = slower processing, server stays responsive

## Queue Driver Recommendation

The supervisor config uses Laravel's configured queue driver from `.env` (`QUEUE_CONNECTION`).

| Driver     | Pros                                    | Cons                    | Best For                        |
|------------|-----------------------------------------|-------------------------|---------------------------------|
| `database` | No extra services, simple setup         | Slower under heavy load | Most users, small-medium sites  |
| `redis`    | Fast, efficient, supports rate limiting | Requires Redis server   | High traffic sites              |
| `sqs`      | Managed, auto-scaling                   | AWS dependency, cost    | Large scale, AWS infrastructure |

**Recommendation:** Use `database` for most deployments. It's simple, requires no additional services, and
handles typical LMS workloads well. Switch to `redis` only if you're experiencing queue bottlenecks with high traffic.

```bash
# .env
QUEUE_CONNECTION=database  # Recommended for most users
```

## Prerequisites

- Ubuntu/Debian VPS with PHP 8.3+ installed
- Laravel application deployed to `/var/www/html` (adjust paths if different)
- FFmpeg installed for video encoding (optional but recommended)

## Finding Supervisor Configuration Paths

> [!WARNING]
> **Do not assume `/etc/supervisor/conf.d/` is used.**
> Hosting panels (aaPanel, cPanel, Plesk) often override this path and may only accept specific file extensions.

Before installing the worker configuration, find where supervisor actually looks for config files on your server.

### Step 1: Find the Active Supervisor Configuration

```bash
# Get the supervisor process ID
sudo supervisorctl pid

# Check which config file supervisor is using
ps -fp $(sudo supervisorctl pid)
```

**Example output:**

```
/usr/bin/python3 /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
```

The file after `-c` is the main supervisor configuration file.

### Step 2: Find the Include Directory

```bash
# View the [include] section
sudo grep -A5 "\[include\]" /etc/supervisor/supervisord.conf
```

**Example output (standard installation):**

```ini
[include]
files = /etc/supervisor/conf.d/*.conf
```

**Example output (aaPanel / BT Panel):**

```ini
[include]
files = /www/server/panel/plugin/supervisor/profile/*.ini
```

**Example output (Plesk):**

```ini
[include]
files = /etc/supervisord/conf.d/*.conf
```

This `files =` directive tells you:

1. **Where** to place your worker config file
2. **What extension** it must have (`.conf`, `.ini`, etc.)

### Step 3: Verify Configuration is Loaded

After copying your config file to the correct location:

```bash
# Check if supervisor can see your workers
sudo supervisorctl avail
```

If `elms-default-worker` and `elms-video-worker` don't appear, your config file is not in the right location or doesn't
have the right extension.

### Common Configuration Paths by System

| System           | Include Path                                          | Extension |
|------------------|-------------------------------------------------------|-----------|
| Ubuntu/Debian    | `/etc/supervisor/conf.d/`                             | `.conf`   |
| CentOS/RHEL      | `/etc/supervisord.d/`                                 | `.ini`    |
| aaPanel/BT Panel | `/www/server/panel/plugin/supervisor/profile/`        | `.ini`    |
| cPanel           | `/etc/supervisord.d/` or `/usr/local/etc/supervisor/` | `.conf`   |
| Plesk            | `/etc/supervisord/conf.d/`                            | `.conf`   |

> [!IMPORTANT]
> Always use **Step 1-3** above to verify the actual path on your server. Don't rely on this table alone.

## Installation

### 1. Install Supervisor

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install epel-release
sudo yum install supervisor
```

### 2. Copy Configuration File

```bash
# Copy the worker config to supervisor's config directory
sudo cp /var/www/html/deployment/supervisor/elms-worker.conf /etc/supervisor/conf.d/

# If your application is in a different directory, update the paths in the config file
sudo nano /etc/supervisor/conf.d/elms-worker.conf
```

### 3. Update Configuration (If Needed)

Edit `/etc/supervisor/conf.d/elms-worker.conf` and adjust:

- `command` - Update the path `/var/www/html/artisan` to your actual application path
- `user` - Change from `www-data` to your web server user (e.g., `nginx`, `apache`)
- `numprocs` - Number of worker processes per queue
- `stdout_logfile` - Update log file paths if needed

### 4. Start Supervisor

```bash
# Reload supervisor configuration
sudo supervisorctl reread
sudo supervisorctl update

# Start all workers
sudo supervisorctl start all

# Or start specific worker groups
sudo supervisorctl start elms-default-worker:*
sudo supervisorctl start elms-video-worker:*

# Check status
sudo supervisorctl status
```

### 5. Enable Supervisor on Boot

```bash
# Enable supervisor to start on system boot
sudo systemctl enable supervisor
```

## Management Commands

```bash
# View all process status
sudo supervisorctl status

# Start all workers
sudo supervisorctl start all

# Stop all workers
sudo supervisorctl stop all

# Restart all workers (after code deployment)
sudo supervisorctl restart all

# Manage specific worker groups
sudo supervisorctl start elms-default-worker:*
sudo supervisorctl stop elms-video-worker:*
sudo supervisorctl restart elms-default-worker:*

# Reload configuration (after changing .conf file)
sudo supervisorctl reread
sudo supervisorctl update
```

## After Deployment

After each code deployment, restart the workers to pick up new code:

```bash
sudo supervisorctl restart all
```

Or use Laravel's graceful restart (finishes current job before restarting):

```bash
php /var/www/html/artisan queue:restart
```

## Troubleshooting

### View Worker Logs

```bash
# View supervisor main log
sudo tail -f /var/log/supervisor/supervisord.log

# View default queue worker logs
sudo tail -f /var/www/html/storage/logs/worker-default.log

# View video encoding worker logs
sudo tail -f /var/www/html/storage/logs/worker-video.log

# View Laravel application logs
sudo tail -f /var/www/html/storage/logs/laravel.log
```

### Check Queue Status

```bash
# View pending jobs in database
php /var/www/html/artisan queue:monitor default,video-encoding

# View failed jobs
php /var/www/html/artisan queue:failed

# Retry failed jobs
php /var/www/html/artisan queue:retry all
```

### Common Issues

#### 1. Workers Don't Appear in `supervisorctl status`

**Symptom:** After copying the config file, `sudo supervisorctl status` doesn't show your workers.

**Diagnosis:**

```bash
# Check if supervisor can find the config
sudo supervisorctl avail

# If nothing appears, check the include path
sudo grep -A5 "\[include\]" /etc/supervisor/supervisord.conf
```

**Possible causes:**

- **Wrong directory**: Config file is not in the directory specified in `[include]`
- **Wrong extension**: File has `.conf` extension but supervisor only looks for `.ini` (or vice versa)
- **Syntax errors**: Config file has syntax issues

**Solution:**

1. Verify the include path (see "Finding Supervisor Configuration Paths" above)
2. Copy config to the correct directory with the correct extension
3. Run `sudo supervisorctl reread` and `sudo supervisorctl update`

#### 2. Hosting Panel (aaPanel/cPanel) Override

**Symptom:** You placed the file in `/etc/supervisor/conf.d/` but supervisor doesn't see it.

**Cause:** Hosting panels often manage supervisor and override the default paths.

**Solution for aaPanel/BT Panel:**

```bash
# 1. Check the panel's supervisor config
sudo cat /etc/supervisor/supervisord.conf | grep -A5 "\[include\]"

# If it shows: files = /www/server/panel/plugin/supervisor/profile/*.ini

# 2. Copy with .ini extension
sudo cp /var/www/html/deployment/supervisor/elms-worker.conf \
        /www/server/panel/plugin/supervisor/profile/elms-worker.ini

# 3. OR modify the supervisor config to also accept .conf files
sudo nano /etc/supervisor/supervisord.conf

# Change:
# files = /www/server/panel/plugin/supervisor/profile/*.ini
# To:
# files = /www/server/panel/plugin/supervisor/profile/*.ini /www/server/panel/plugin/supervisor/profile/*.conf

# 4. Reload supervisor
sudo supervisorctl reread
sudo supervisorctl update
```

**Solution for cPanel/Plesk:**
Check the panel's documentation or use the diagnostic steps above to find the correct path.

#### 3. File Extension Mismatch

**Symptom:** Config file is in the right directory but not loaded.

**Diagnosis:**

```bash
# Check what extensions are allowed
sudo grep "files =" /etc/supervisor/supervisord.conf
```

**Common scenarios:**

| What You See           | What It Means                 | What To Do           |
|------------------------|-------------------------------|----------------------|
| `files = *.conf`       | Only `.conf` files are loaded | Rename to `.conf`    |
| `files = *.ini`        | Only `.ini` files are loaded  | Rename to `.ini`     |
| `files = *.conf *.ini` | Both extensions work          | Use either extension |

**Solution:**

```bash
# If you need .ini but have .conf
sudo mv /etc/supervisor/conf.d/elms-worker.conf \
        /etc/supervisor/conf.d/elms-worker.ini

# Reload
sudo supervisorctl reread
sudo supervisorctl update
```

#### 4. Permission Denied Errors

**Symptom:** Worker starts but immediately fails with permission errors.

**Diagnosis:**

```bash
# Check supervisor logs
sudo tail -50 /var/log/supervisor/supervisord.log

# Check worker logs
sudo supervisorctl tail elms-default-worker:elms-default-worker_00
```

**Solution:**

```bash
# 1. Find your web server user
ls -la /var/www/html

# Common users: www-data, nginx, apache, ftpuser

# 2. Update the config
sudo nano /etc/supervisor/conf.d/elms-worker.conf

# Change user=www-data to your actual user

# 3. Reload
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart all
```

#### 5. Artisan Path Not Found

**Symptom:** Error: `can't find 'php' or 'artisan'`

**Solution:**

```bash
# Use absolute paths in the config
sudo nano /etc/supervisor/conf.d/elms-worker.conf

# Change command lines to use absolute paths:
# Before:
# command=php artisan queue:work ...

# After (find your PHP path first with 'which php'):
command=/usr/bin/php /var/www/html/artisan queue:work ...
```

#### 6. Video Encoding Jobs Failing

**Diagnosis:**

```bash
# Check FFmpeg installation
ffmpeg -version

# Check video worker logs
sudo tail -f /var/www/html/storage/logs/worker-video.log

# Check failed jobs
php /var/www/html/artisan queue:failed
```

**Solutions:**

- **FFmpeg not installed**: `sudo apt-get install ffmpeg`
- **Memory limit**: Increase `--memory=512` to `--memory=1024` in config
- **Timeout**: Increase `--max-time=7200` for very large videos
- **Permission issues**: Ensure storage directories are writable

#### 7. Workers Crash on High Load

**Symptoms:** Workers show `FATAL` status or restart frequently.

**Solution:**

```bash
# Check available memory
free -h

# Reduce numprocs or add memory limit
sudo nano /etc/supervisor/conf.d/elms-worker.conf

# For low-resource servers:
numprocs=1  # Instead of 2
```

#### 8. Still Stuck? Diagnostic Checklist

Run these commands and check the output:

```bash
# 1. Is supervisor running?
sudo supervisorctl pid

# 2. What config is supervisor using?
ps -fp $(sudo supervisorctl pid)

# 3. Where does it look for configs?
sudo grep -A5 "\[include\]" <config-file-from-step-2>

# 4. Is your config in that directory?
ls -la <directory-from-step-3>

# 5. Can supervisor see your workers?
sudo supervisorctl avail

# 6. What's the exact error?
sudo supervisorctl tail elms-default-worker:elms-default-worker_00 stdout
sudo tail -50 /var/log/supervisor/supervisord.log

# 7. Are there permission issues?
sudo -u www-data php /var/www/html/artisan queue:work --once
```

If none of this works, share:

- Output from Step 2 (supervisor config path)
- Output from Step 3 (include directory)
- Output from Step 4 (directory listing)
- Output from Step 6 (error messages)

## Configuration Options Explained

### Default Worker

| Option            | Value      | Description                    |
|-------------------|------------|--------------------------------|
| `numprocs`        | 2          | Number of worker processes     |
| `--queue=default` | default    | Only process default queue     |
| `--sleep=3`       | 3 seconds  | Wait between checking for jobs |
| `--tries=3`       | 3 attempts | Retry failed jobs 3 times      |
| `--max-time=3600` | 1 hour     | Restart worker after 1 hour    |

### Video Encoding Worker

| Option                   | Value          | Description                                 |
|--------------------------|----------------|---------------------------------------------|
| `numprocs`               | 1              | Single worker to limit resource usage       |
| `--queue=video-encoding` | video-encoding | Only process encoding jobs                  |
| `--sleep=10`             | 10 seconds     | Longer sleep (encoding jobs are infrequent) |
| `--tries=1`              | 1 attempt      | No retries (encoding is expensive)          |
| `--max-time=7200`        | 2 hours        | Allow long-running encoding jobs            |
| `--memory=512`           | 512 MB         | Stop if memory exceeds limit                |

## Scaling Workers

You can adjust the number of workers based on your server resources. This is done by changing the `numprocs` value in the config file.

### Step 1: Edit Configuration

```bash
sudo nano /etc/supervisor/conf.d/elms-worker.conf
```

### Step 2: Change numprocs

**High Traffic Servers (8+ cores, 8GB+ RAM):**
```ini
[program:elms-default-worker]
numprocs=4

[program:elms-video-worker]
numprocs=3
```

**Low Resource Servers (2 cores, 2GB RAM):**
```ini
[program:elms-default-worker]
numprocs=1

[program:elms-video-worker]
numprocs=1
```

### Step 3: Apply Changes

After editing the config file, you must reload supervisor to apply changes:

```bash
# Read the updated configuration
sudo supervisorctl reread

# Apply the changes (starts/stops workers as needed)
sudo supervisorctl update

# Verify the new worker count
sudo supervisorctl status
```

**What each command does:**
- `reread` - Tells supervisor to re-read config files (detects changes)
- `update` - Applies the changes (adds/removes worker processes)
- `status` - Shows all running workers and their state

**Example output after scaling to 3 video workers:**
```
elms-default-worker:elms-default-worker_00   RUNNING   pid 1234, uptime 0:05:00
elms-default-worker:elms-default-worker_01   RUNNING   pid 1235, uptime 0:05:00
elms-video-worker:elms-video-worker_00       RUNNING   pid 1236, uptime 0:05:00
elms-video-worker:elms-video-worker_01       RUNNING   pid 1237, uptime 0:05:00
elms-video-worker:elms-video-worker_02       RUNNING   pid 1238, uptime 0:05:00
```

## Docker (Local Development)

For local development with Docker, the supervisor config is at `docker/supervisor/supervisord.conf`.

### Scaling Workers in Docker

**Step 1: Edit the config file:**
```bash
nano docker/supervisor/supervisord.conf
```

**Step 2: Change numprocs value as needed**

**Step 3: Restart the queue container:**
```bash
# Restart to apply changes
docker compose restart queue

# Or rebuild if you made other changes
docker compose up -d --build queue
```

**Step 4: Verify workers are running:**
```bash
# Check supervisor status inside container
docker compose exec queue supervisorctl status
```
