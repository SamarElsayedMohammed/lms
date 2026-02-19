---
name: systematic-debugging
description: Systematic approach to debugging issues
---

# Systematic Debugging Skill

## When to Use
Use this skill when encountering bugs, errors, or unexpected behavior.

## Debugging Process

### Step 1: Understand the Problem
- [ ] Read the error message carefully
- [ ] Identify the error type (syntax, runtime, logic)
- [ ] Note the file and line number

### Step 2: Reproduce the Issue
```bash
# For API errors, use curl to reproduce
curl -X METHOD 'http://127.0.0.1:8000/api/endpoint' \
  --header 'Content-Type: application/json' \
  --data '{"key": "value"}'
```

### Step 3: Check Logs
```bash
# Laravel logs
tail -50 storage/logs/laravel.log

# Clear logs to get fresh output
> storage/logs/laravel.log
```

### Step 4: Isolate the Cause
- Add debugging statements
- Check variable values
- Trace the execution flow

### Step 5: Fix and Verify
- Make the smallest possible fix
- Test the fix thoroughly
- Check for side effects

### Step 6: Document
- Note the root cause
- Update `.agent/memory-bank/projectContext.md` if it's a common issue

## Common Laravel Issues

| Issue | Check |
|-------|-------|
| 500 Error | `storage/logs/laravel.log` |
| 404 Error | `php artisan route:list` |
| Auth Error | Token validity, middleware |
| Database Error | Migration status, connection |
| Cache Issues | `php artisan config:clear` |
