# Skillso production incident runbook

## API is down

1. **Do not redeploy, restart, or delete the container.** The failed container is evidence.
2. Confirm the public symptom:

   ```bash
   curl -i --max-time 10 https://api.skillso.net/api/health/live
   curl -i --max-time 10 https://api.skillso.net/api/health/ready
   ```

3. Start the owner-only evidence capture on the VPS:

   ```bash
   cd /path/to/backend-skillso
   chmod 700 scripts/capture-runtime-health.sh
   sudo ./scripts/capture-runtime-health.sh \
     --match skillso \
     --interval 10 \
     --duration 7200 \
     --probe-url https://api.skillso.net/api/health/live \
     --output /var/log/skillso-crash-forensics
   ```

4. In a second shell, identify every current and exited application container. Do not inspect only the newest replacement:

   ```bash
   docker ps -a --no-trunc --filter name=skillso
   docker inspect <container-id> --format 'status={{.State.Status}} running={{.State.Running}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} error={{.State.Error}} started={{.State.StartedAt}} finished={{.State.FinishedAt}} restart_count={{.RestartCount}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} memory={{.HostConfig.Memory}} memory_swap={{.HostConfig.MemorySwap}} pids_limit={{.HostConfig.PidsLimit}} restart_policy={{.HostConfig.RestartPolicy.Name}}'
   docker inspect <container-id> --format '{{json .State.Health}}'
   docker logs --timestamps --tail 2000 <container-id>
   ```

5. Capture the host cause before recovery:

   ```bash
   uptime
   free -h
   df -h
   df -i
   docker stats --no-stream
   docker system df
   journalctl -k --since '6 hours ago' | grep -Ei 'oom|out of memory|killed process|memory cgroup|segfault|I/O error|hung task'
   journalctl -u docker --since '6 hours ago'
   last reboot
   ```

6. If the container is running, classify its child processes and dependencies:

   ```bash
   docker top <container-id> -eo pid,ppid,rss,vsz,%mem,%cpu,stat,etime,cmd
   docker exec <container-id> supervisorctl status
   docker exec -u www-data <container-id> php /var/www/html/artisan skillso:runtime-snapshot --no-interaction
   docker exec <container-id> curl -i --max-time 5 http://127.0.0.1:${PORT:-80}/api/health/live
   ```

7. Preserve the complete timestamped evidence directory before any recovery action.

## Classification and bounded recovery

| Evidence | Classification | Recovery after evidence is saved |
| --- | --- | --- |
| `State.Status=exited`, `OOMKilled=true`, matching kernel OOM event | Container/cgroup OOM | Stop the triggering workload; recover the API; calculate limits from captured RSS before tuning. |
| Exit 137 but no OOM evidence | External SIGKILL, cause open | Inspect Docker/Coolify/host event initiator; do not label it OOM. |
| Container running, health `unhealthy`, local liveness 200 | Health/proxy mismatch | Correct the Coolify path/port/network based on inspected configuration. |
| Container running, local liveness fails, Supervisor child stopped/fatal | Nginx/Supervisor child failure | Capture child logs/status, then restart only the failed process if safe. |
| API healthy locally, public response `no available server` | Traefik/Coolify routing | Inspect proxy network, labels, health state, and Coolify events; do not restart Laravel first. |
| Disk or inode 100% | Host/storage exhaustion | Preserve logs, identify the exact growth source, archive/delete only confirmed disposable data, then recover. |
| Redis unreachable | Dependency failure | Restore Redis/network; API liveness should remain available; verify queue/cache/session behavior. |
| Database unreachable | Dependency failure | Restore DB/network and inspect connection saturation; readiness may fail while liveness remains available. |
| Worker failed while API is healthy | Background-worker failure | Keep API serving; capture failed job and worker logs, then restart only that worker. |

## Evidence handling

The capture directory is mode `0700` via `umask 077`. It excludes container environment variables and redacts common credential patterns, but production logs can still contain personal or sensitive data. Keep the directory private, do not commit it, and review it before sharing.
