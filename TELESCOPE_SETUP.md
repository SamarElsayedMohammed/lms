# Laravel Telescope Setup Guide

## Step 1: Install on Server (SSH to VPS)

```bash
cd /app
composer require laravel/telescope
php artisan telescope:install
php artisan migrate
```

## Step 2: Add Environment Variables

Edit `.env` file and add:

```env
TELESCOPE_ENABLED=true
TELESCOPE_PATH=telescope
TELESCOPE_DRIVER=database
TELESCOPE_ALLOWED_EMAILS=admin@yourdomain.com
TELESCOPE_ALLOWED_IDS=1
```

## Step 3: Access Telescope

Open in browser:
```
https://your-domain.com/telescope
```

## What Telescope Monitors

| Tab | Purpose |
|-----|---------|
| **Requests** | All HTTP requests (API calls, page loads) |
| **Exceptions** | Errors and stack traces |
| **Logs** | All application logs |
| **Queries** | SQL queries with execution time |
| **Mail** | Sent emails |
| **Notifications** | Push/FCM notifications |
| **Jobs** | Queue jobs and failures |
| **Cache** | Cache hits/misses |
| **Events** | Laravel events fired |
| **Redis** | Redis operations |

## Common Commands

```bash
# View recent entries
php artisan telescope:prune --hours=24

# Clear all data
php artisan telescope:clear

# Pause recording
php artisan telescope:pause

# Resume recording
php artisan telescope:unpause
```

## Troubleshooting

**403 Forbidden:**
- Check `TELESCOPE_ALLOWED_EMAILS` or `TELESCOPE_ALLOWED_IDS` in .env
- Or modify `app/Providers/TelescopeServiceProvider.php` gate()

**404 Not Found:**
- Clear route cache: `php artisan route:clear`
- Clear config cache: `php artisan config:clear`

**Database growing too large:**
- Pruning is already configured: `Schedule::command('telescope:prune --hours=48')->daily()`
- Or manually run: `php artisan telescope:prune --hours=24`
