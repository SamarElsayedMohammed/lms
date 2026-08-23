# SKILLSO LIVE PRODUCTION INCIDENT ROOT-CAUSE REPORT

Report updated: 2026-08-23 16:24 Africa/Cairo / 13:24 UTC  
Incident status: active at last public probe  
Closure status: blocked pending authenticated VPS/Coolify evidence

Conclusion labels in this report mean:

- **PROVEN**: directly demonstrated by captured evidence.
- **SUPPORTED**: consistent with multiple observations but not uniquely established.
- **REJECTED**: contradicted by captured evidence.
- **UNKNOWN**: required evidence was inaccessible.

## 1. Incident timestamp

- **PROVEN** — The service was returning `503 no available server` from at least 2026-08-23 12:18:33 UTC through 13:24:23 UTC. The latest probes in this investigation were at 13:23:44–13:24:23 UTC.
- **UNKNOWN** — Exact first failure time, last healthy probe, and triggering event require Docker, proxy, Coolify, and application timelines.

## 2. User-visible symptom

- **PROVEN** — `https://api.skillso.net/api/health/live`, `/api/health`, and `/api/version` all returned HTTP 503 with the exact body `no available server`.
- **PROVEN** — `https://toogowgo4ckks8cok84o4c4w.187.77.77.216.sslip.io/api/health/live` returned the identical HTTP 503 and body.
- **SUPPORTED** — The failure is upstream of the Laravel route and internal nginx-only liveness handler because unrelated API paths and both host rules fail identically at the proxy response layer.

## 3. Container state

- **UNKNOWN** — No authenticated Docker/Coolify access was available. `docker ps -a --no-trunc` and container inspection could not be executed.
- Required capture: API, workers, scheduler, Redis, database, and proxy container IDs, names, state, uptime, and restart count.

## 4. Exit code

- **UNKNOWN** — API container `.State.ExitCode`, `.State.Error`, `StartedAt`, and `FinishedAt` are unavailable.

## 5. OOMKilled

- **UNKNOWN** — Neither `.State.OOMKilled` nor kernel OOM evidence is available.
- OOM is not claimed. It remains one hypothesis among container exit, unhealthy removal, routing/network failure, PID 1 exit, and host resource failure.

## 6. Health status

- **PROVEN** — External API liveness cannot reach a backend and is answered by the proxy with 503.
- **PROVEN** — The Coolify control-plane health endpoint at `http://187.77.77.216:8000/api/health` returned HTTP 200 with `OK` at 13:24 UTC.
- **UNKNOWN** — API container health status, failing streak, healthcheck output, and last healthy/unhealthy transitions.

## 7. Docker events

- **UNKNOWN** — `docker events` for the incident interval was inaccessible.
- Required events: `oom`, `die`, `kill`, `stop`, `restart`, `destroy`, `start`, health transitions, and network connect/disconnect events from at least 11:45–13:30 UTC.

## 8. Host state

- **PROVEN** — The VPS responded on TCP 22, 80, 443, and 8000 at 13:24 UTC.
- **PROVEN** — DNS for `api.skillso.net` resolved directly to `187.77.77.216`.
- **PROVEN** — Coolify's nginx/login route was operational: `/` returned HTTP 302 to `/login`, and `/api/health` returned 200.
- **REJECTED** — A continuing total host outage, total network outage, or total Coolify-control-plane outage at probe time.
- **UNKNOWN** — Host reboot, Docker daemon restart, historical host OOM, RAM/swap/load, disk/inodes, Docker storage, and incident-time state.

## 9. Proxy evidence

- **PROVEN** — Both the canonical hostname and direct sslip host returned Traefik-style `503 no available server`, with identical 20-byte plain-text responses.
- **SUPPORTED** — The proxy had no eligible/routable backend for both Skillso host rules while Coolify remained reachable on port 8000.
- **UNKNOWN** — Why the backend was removed: container stopped, unhealthy, wrong port, missing network, missing dynamic configuration, deployment removal, or proxy failure. Traefik logs and dynamic service state are required.

## 10. Application logs

- **UNKNOWN** — Container stdout/stderr, Laravel daily logs, nginx logs, PHP-FPM logs, Supervisor logs, queue logs, scheduler logs, FFmpeg activity, Redis errors, and database errors around the incident were inaccessible.
- Searches must include memory exhaustion, fatal errors, SIGTERM, connection failures, file descriptor exhaustion, disk-full conditions, timeouts, and failed jobs.

## 11. Root cause

- **PROVEN root class** — None.
- **SUPPORTED boundary** — `TRAEFIK HAD NO ROUTABLE SKILLSO BACKEND` while the VPS and Coolify control plane were reachable.
- **UNKNOWN primary class** — Must remain `UNKNOWN` until authenticated container, health, event, kernel, network, and proxy evidence is captured.

Current hypothesis status:

| Hypothesis | Classification | Evidence |
| --- | --- | --- |
| Total current host failure | REJECTED | SSH/HTTP/HTTPS/Coolify ports respond; Coolify health is 200 |
| DNS failure | REJECTED | Canonical API resolves to the responding VPS |
| Traefik has no eligible backend | PROVEN | Identical `no available server` responses on both host rules |
| Container OOM | UNKNOWN | No OOMKilled/kernel evidence |
| Host OOM/reboot | UNKNOWN | No journal/uptime/reboot evidence |
| API/PID 1 exit | UNKNOWN | No container state/exit code |
| Docker healthcheck failure | UNKNOWN | No health history/output |
| Docker network disconnect | UNKNOWN | No network inspection/events |
| Internal port mismatch | UNKNOWN | No listen/inspect/Traefik upstream comparison |
| Application deadlock | UNKNOWN | No process/internal-probe evidence |

## 12. Contributing factors

- **SUPPORTED** — Incident observability is insufficient: public probes collapse multiple failure classes into the same proxy 503.
- **SUPPORTED** — Manual recovery before Docker event/log capture would destroy or obscure the decisive timeline; no recovery action was taken by this investigation.
- **UNKNOWN** — Restart policy, healthcheck thresholds, resource limits, multi-process state, Redis/DB reachability, and network membership.

## 13. Exact fix

- **UNKNOWN / NOT AUTHORIZED YET** — No infrastructure fix is justified before root classification.
- **PROVEN** — No restart, redeploy, container recreation, prune, reboot, or configuration mutation was performed.
- The next action is forensic capture, not recovery. After classification, apply only the minimum fix supported by evidence.

## 14. Verification

Public verification completed:

```text
13:23:44 UTC  api.skillso.net /api/health/live  -> 503 no available server
13:23:44 UTC  api.skillso.net /api/health       -> 503 no available server
13:23:45 UTC  api.skillso.net /api/version      -> 503 no available server
13:23:45 UTC  direct sslip /api/health/live     -> 503 no available server
13:23:46 UTC  Coolify /                         -> 302 /login
13:24 UTC     Coolify /api/health               -> 200 OK
13:24 UTC     VPS TCP 22/80/443/8000            -> reachable
```

Authenticated verification was blocked:

- Local SSH directory contains only known-host files; no identity or SSH agent is present.
- Non-interactive SSH to `root@187.77.77.216` and `ubuntu@187.77.77.216` reached the server but was denied (`publickey,password`).
- No Coolify API token environment variable is configured.
- No connected in-app or external browser session is available.

## 15. Remaining risks

- Evidence may be overwritten by log rotation, container recreation, or redeployment while access is pending.
- Docker events are transient; capture them immediately once access is available.
- The current outage remains active and production closure remains blocked.
- Static code repairs do not establish or repair this runtime root cause.

## Required next capture

Provide either an authenticated Coolify browser session, a scoped Coolify API token, or SSH access to the VPS. Then run the read-only capture sequence before any recovery:

1. `docker ps -a --no-trunc` and full state/health/restart/resource/restart-policy inspection.
2. Docker events for 11:45–13:30 UTC and relevant container logs with timestamps.
3. Kernel OOM journal, `dmesg`, uptime/reboot history, memory, disk, inode, Docker-storage, and stats snapshots.
4. Supervisor/process/listener/internal-liveness checks inside a running API container.
5. Docker network membership and Traefik service/log evidence.
6. Laravel/nginx/PHP-FPM/worker/scheduler logs and Redis/DB reachability without exposing credentials.

```text
ROOT CLASS: UNKNOWN
PRODUCTION ROOT CAUSE: NOT YET PROVEN
PRODUCTION CLOSURE: BLOCKED
```
