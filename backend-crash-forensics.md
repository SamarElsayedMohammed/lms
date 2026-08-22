# Skillso backend crash forensics

## Incident status

**Verdict: ROOT CAUSE NOT YET FOUND.**

The production container was reported as `Exited` after being healthy for minutes or less than an hour. No Docker exit state, Docker event, kernel log, or VPS resource snapshot for a failing instance has been collected yet. Therefore no application-level change is a proven root-cause fix.

## Preserved worktree state (2026-08-21)

Pre-existing user changes were present in:

- `app/Console/Commands/Subscriptions/ActivateQueuedSubscriptionsCommand.php`
- `app/Services/SubscriptionService.php`
- `tests/Unit/Forensics/OutboundHttpTimeoutContractTest.php`

Unpublished hardening changes are also present in the deployment files, FFmpeg job, and its stability test. They were made before this forensic protocol was supplied. They must be treated as **unproven hardening**, not deployed as a root-cause fix until evidence is captured.

## Evidence collected so far

| Observation | Evidence | Interpretation |
| --- | --- | --- |
| Coolify host answered HTTP | `http://187.77.77.216:8000/` returned a Coolify login redirect | The VPS and Coolify UI were alive during the check. This does not prove the backend container was alive at the incident time. |
| Backend health endpoints answered | `https://api.skillso.net/api/health/live`, `/ready`, `/health`, and `/version` returned 200 | A point-in-time health check only. |
| Controlled read-only load survived | 800 total requests to `/api/health/ready`, max concurrency 25, all 200; final p95 251.2 ms | This rules out a simple immediate failure on that endpoint. It does not test jobs, scheduler, FFmpeg, mutations, or a long soak. |
| Production configuration risk | `APP_DEBUG=true`, `LOG_LEVEL=debug`, and `REDIS_HOST=127.0.0.1` were present in supplied production environment | Candidates only. No causal chain to PID 1/container exit has been captured. |
| Heavy background processes exist | Supervisor starts default, ingestion, video, and scheduler processes in one backend container | Candidate for resource pressure; unproven without runtime RSS/exit evidence. |
| API inventory exists | `php artisan route:list --json` reported 684 routes, including 654 under `api/`; 326 of all routes use a mutation method | Production testing must be split into read-only production checks and isolated staging/test-data mutation checks. |

## Cause matrix

| Candidate | Evidence for | Evidence against | Status |
| --- | --- | --- | --- |
| Host/cgroup OOM | Multi-process container on a reported 2 GB host | No `OOMKilled`, kernel, or memory sample from a crash | UNTESTED |
| Coolify/Docker replacement | UI reported an exited application | No Docker event or exit code | UNTESTED |
| Healthcheck action | Docker healthcheck and Coolify probes exist | Liveness endpoint is intentionally independent of DB/PHP-FPM | PARTIAL |
| Supervisor/PID 1 exit | PID 1 is Supervisor | No Supervisor state or container exit evidence | UNTESTED |
| Queue/FFmpeg memory spike | Dedicated ingestion and video queues exist | No job-level RSS or active job correlation | UNTESTED |
| Redis errors | Host is set to `127.0.0.1`; analytics uses Redis | No failure/retry/log-growth sequence captured | UNTESTED |
| Disk or inode exhaustion | Debug-level logs can grow; media jobs write files | No host disk/inode data from incident | UNTESTED |
| Application fatal/segfault | Fatal shutdown logging exists | No production fatal/segfault record tied to the exit | UNTESTED |

## Required VPS capture

Copy `scripts/capture-runtime-health.sh` to the VPS host or run it from the checked-out backend repository. Start it before a redeploy and leave it running for at least two hours:

```bash
chmod 700 scripts/capture-runtime-health.sh
sudo INTERVAL_SECONDS=10 DURATION_SECONDS=7200 \
  ./scripts/capture-runtime-health.sh --match skillso --output /var/log/skillso-crash-forensics
```

Use the exact container name fragment if `skillso` does not match. The output directory is owner-only and records bounded snapshots plus Docker events. It intentionally excludes container environment variables.

Immediately after an exit, preserve the output directory and run:

```bash
docker ps -a --no-trunc
docker inspect <container-id> --format 'status={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} error={{.State.Error}} started={{.State.StartedAt}} finished={{.State.FinishedAt}} restart_count={{.RestartCount}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} memory={{.HostConfig.Memory}} memory_swap={{.HostConfig.MemorySwap}} pids_limit={{.HostConfig.PidsLimit}} restart_policy={{.HostConfig.RestartPolicy.Name}}'
journalctl -k --since '2 hours ago' | grep -Ei 'out of memory|oom|killed process|memory cgroup|segfault|I/O error|hung task'
```

## Next validation sequence

1. Capture 10 cold/warm restarts with the script running.
2. Run an idle soak for at least two hours with workers and scheduler enabled.
3. Build the 654-route API matrix: public GET endpoints may be checked in controlled production traffic; authenticated reads and every mutation must use staging, test users, and isolated test data.
4. Run a mixed workload only while the host-side capture is running, including safe API reads and representative background jobs in staging.
5. Correlate any exit with Docker events, `State.ExitCode`, `State.OOMKilled`, kernel logs, Supervisor state, and resource snapshots.
6. Only then select a targeted root-cause fix and repeat the triggering scenario.
