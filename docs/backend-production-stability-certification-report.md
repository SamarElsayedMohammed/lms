# SKILLSO BACKEND PRODUCTION STABILITY CERTIFICATION REPORT

Report time: 2026-08-23 16:40 Africa/Cairo / 13:40 UTC  
Certification target: Skillso production backend  
Evidence policy: observations are reported only for the environment and duration actually measured  
Final status: **FAIL — PRODUCTION CLOSURE BLOCKED**

## 1. Environment

Production is the Coolify/Traefik deployment reached through `api.skillso.net` and the VPS sslip host. Authenticated VPS, Docker, Coolify application, Redis, and database access were unavailable. The available execution host was Windows with PHP 8.4, curl, SQLite, FFmpeg/ffprobe, four logical CPUs, approximately 14.7 GB RAM, and only approximately 0.8–1.1 GB available RAM during discovery/rehearsal. Docker and Podman were unavailable; WSL had no installed Linux distribution.

The local exercise therefore used Laravel's development server and an isolated SQLite file. It was a harness rehearsal, not a production-equivalent stress or soak test. The production incident evidence is recorded in [live-production-incident-root-cause-report.md](live-production-incident-root-cause-report.md).

## 2. Runtime topology

The repository declares nginx, PHP-FPM, one default worker, one ingestion worker, one video worker, a scheduler, Redis, and database dependencies. Supervisor is configured to manage the PHP-FPM/nginx processes and workers. The live container/process/network state could not be inspected, so declared topology is not accepted as deployed topology.

Production probes at 13:40 UTC returned HTTP 503 from both the canonical hostname and sslip route. The exact canonical response body was `no available server`. This proves the public route had no eligible backend at the time of certification.

## 3. Resource limits

Declared limits include PHP-FPM `memory_limit=128M`, `request_terminate_timeout=60s`, and `pm.max_requests=200`; default queue worker 128 MB and 3,600-second maximum lifetime; ingestion worker 192 MB, one process, 7,200-second timeout/lifetime, and one job per lifecycle; video worker 160 MB, one process, 7,200-second lifetime, and one job per lifecycle; scheduler 128 MB. These are configuration facts only. No live cgroup limits, actual RSS headroom, process counts, or enforced recycle boundaries were measured.

No safe operating concurrency can be certified from the available evidence. The configured one-process ingestion and video limits are conservative intent, not measured capacity.

## 4. Baseline measurements

No valid production or production-like baseline was captured because container access and a matching runtime were unavailable. Missing baseline fields are host RAM/swap/load/disk, container memory and limits, PHP-FPM process count/RSS, queue/scheduler RSS, Redis memory/latency, restart counts, health state, file descriptors, and process/thread counts.

The local host had four logical CPUs, approximately 14.7 GB total RAM, and approximately 0.8 GB available RAM at discovery. During the low-rate rehearsal, sampled available RAM ranged from 1,110,491,136 to 1,177,907,200 bytes. These host values are not representative of the VPS.

## 5. API load results

Two bounded localhost phases were run against `/api/health/live`, `/api/health/ready`, and `/api/health`:

| Phase | Duration | Concurrency/rate | Requests | Result | Latency |
| --- | ---: | --- | ---: | --- | --- |
| Initial rehearsal | 30 s | 2 / 1 RPS | 26 | 24 HTTP 200, 2 transport timeouts | p95 10,000.98 ms; max 10,004.83 ms |
| Warm low-rate rehearsal | 30 s | 1 / 0.25 RPS | 8 | 8 HTTP 200, 0 transport errors | p50 516.06 ms; p95/max 636.15 ms |

The first phase failed the harness criteria. The second phase only demonstrates that the scripts and endpoints work at very low load after warm-up. Authentication, dashboard, course/player, progress, notification, subscription/payment, certificate, and report workflows were not exercised because there was no isolated representative dataset or test identity. Low/moderate/burst/sustained production-like traffic was not run.

## 6. Queue results

No queue stress was run. Redis and the production worker topology were absent, and using the synchronous local queue would not test worker memory, retries, failed jobs, queue depth, or recovery. Job loss and retry correctness across recycling remain unverified.

## 7. Worker memory trend

No production PHP-FPM or queue worker trend exists. The local PHP development-server process had six samples over approximately 31 seconds during the passing low-rate phase: first RSS 47,943,680 bytes, last RSS 47,968,256 bytes, minimum 47,943,680 bytes, maximum 47,968,256 bytes, delta +24,576 bytes. All sampled liveness and readiness probes were HTTP 200.

This interval is far too short to establish a plateau or exclude monotonic growth. Worker PID changes at `--memory`, `--max-jobs`, and `--max-time` boundaries were not observed.

## 8. FFmpeg results

A local, bounded smoke test generated a five-second 320×240 synthetic MP4 and converted it to HLS with a single FFmpeg thread. Both commands exited 0 in 701 ms, produced a playlist and media files totaling 255,838 bytes, and left zero FFmpeg processes. This proves only local executable/process cleanup for a tiny synthetic input.

Representative video size, sequential jobs, supported concurrency, API latency during encoding, queue containment, retry behavior, temp cleanup under failure, and VPS CPU/RAM pressure were not tested. FFmpeg production safety is not certified.

## 9. Scheduler results

The scheduler configuration declares one `schedule:work` process with automatic restart. No live process enumeration, scheduled-command timeline, overlap-lock validation, duplicate scheduler check, memory trend, or schedule-driven load observation was possible.

## 10. Redis results

No Redis instance was accessible in the test environment. Connection errors, used/peak memory, eviction policy, latency, queue depth, locks, session/cache load, temporary outage behavior, and recovery are all unverified.

## 11. Database results

The local isolated SQLite migration did not complete: migration `2025_11_14_102042_add_meta_keywords_to_courses_table` failed with `duplicate column name: meta_keywords`. Health/readiness could still connect to the partially migrated isolated database and returned 200, but that is not schema certification.

Production connections, pool saturation, slow queries, deadlocks, timeouts, and long transactions were inaccessible and untested.

## 12. Healthcheck results

During the passing local low-rate phase, all six sampler liveness probes and all six readiness probes returned 200. The load generator also received eight 200 responses. In the initial 1 RPS phase, two requests exceeded the ten-second client timeout.

At 13:40 UTC, external production liveness returned HTTP 503 in approximately 0.30 seconds on the canonical route and approximately 0.32 seconds on the sslip route. The canonical body was `no available server`. Therefore the healthcheck and routing gate fails regardless of localhost behavior.

## 13. Failure injection results

Worker termination, media-worker failure, temporary Redis outage, readiness dependency loss, scheduler/container restart, and service-container restart were not injected. Those actions require an isolated production-like Docker environment and representative queued work. Performing them against the unresolved production outage would not have been safe.

## 14. Recovery results

No controlled failure/recovery cycle was observed. API blast-radius isolation, Supervisor restart timing, queue recovery, Redis reconnection, readiness recovery, retry safety, and recovery without redeployment remain unverified. The live API remained unavailable at the last probe, so no recovery claim is possible.

## 15. Disk/temp/log growth

No sustained production measurement exists for Laravel, Docker, nginx, Supervisor, scheduler, or FFmpeg logs. The local media smoke test produced three media files totaling 255,838 bytes plus its JSON summary and left no FFmpeg child process. Temporary/media cleanup under repetition or failure, Docker log rotation, inode use, descriptor growth, process growth, and long-duration disk growth were not measured.

## 16. Soak duration

Required production-like soak: 48–72 hours with recurring representative API and job traffic.  
Completed qualifying soak: **0 hours**.

The two 30-second local phases are explicitly excluded from soak duration.

## 17. Soak memory trend

No soak memory series exists for the host, API container, PHP-FPM, workers, FFmpeg, scheduler, or Redis. The short local +24,576-byte PHP RSS delta cannot establish steady state, leak absence, or post-load return toward baseline.

## 18. Container restarts

Live container IDs, states, start/finish times, exit codes, health histories, and restart counts were unavailable. No expected recycle or injected restart was verified. The repository now includes a read-only staging sampler that records Docker inspect/stats evidence when explicit container IDs are supplied; it was not runnable on this host.

## 19. OOM evidence

No `.State.OOMKilled`, Docker event stream, kernel log, cgroup memory event, or host OOM timeline was accessible. OOM is neither proven nor excluded. Because the public API is unavailable and its cause is unresolved, absence of OOM evidence cannot be treated as evidence of absence.

## 20. 5xx/503 evidence

- Production canonical liveness at 2026-08-23 13:40 UTC: HTTP 503, body `no available server`.
- Production sslip liveness at the same gate: HTTP 503.
- Earlier incident probes show the same response continuously from at least 12:18:33 UTC through 13:24:23 UTC.
- Local initial rehearsal: two status-0 transport timeouts; no HTTP 5xx among completed responses.
- Local low-rate rehearsal: eight HTTP 200 responses; no 5xx.

The unexplained production 503 is an immediate certification failure under the required rules.

## 21. Remaining risks

Blocking risks are the unknown live root cause; no routable production backend; unknown exit/OOM/restart history; unverified cgroup limits and PHP-FPM capacity; untested queue recycling and loss behavior; untested realistic FFmpeg pressure; unobserved scheduler; untested Redis/database saturation and recovery; no controlled failure injection; no log/temp/descriptor growth series; and no 48–72-hour soak.

Minimum rerun prerequisites are an isolated staging deployment built from the production image, matching resource limits and worker topology, Redis/database with representative non-production data and users, proxy/health routing, authenticated container/host telemetry, and permission to inject bounded failures. Practical starting alerts should include any 503, any unexpected restart/OOM, readiness failure beyond the expected dependency window, sustained container memory above 80% of its limit, host available memory below 15%, disk above 80%, unexpected queue-depth growth, and any nonzero failed-job trend; thresholds must be recalibrated from the eventual measured baseline.

Evidence tooling added for that rerun:

- `scripts/stability/stability-load.php`: rate-limited HTTP workload with production-target guard, CSV requests, JSON latency/status summary, and failure on transport error/503/5xx.
- `scripts/stability/sample-process.ps1`: bounded Windows process/RAM and liveness/readiness sampler.
- `scripts/stability/capture-docker-evidence.sh`: read-only explicit-container Docker baseline/inspect/stats capture.
- `tests/Feature/StabilityCertificationHarnessTest.php`: safety and read-only contract checks.

## 22. Final certification

**FAIL — PRODUCTION CLOSURE BLOCKED**

Closure gate:

- Code-level crash audit: evidence exists from the prior audit, but runtime certification remains separate.
- Runtime root cause resolved or conclusively classified: **no**.
- API/heavy-worker isolation proven: **no**.
- OOM, PHP-FPM capacity, queues, FFmpeg, scheduler, Redis, healthcheck, and recovery tested production-like: **no**.
- No unexplained 503/container exit/manual recovery: **no; an unexplained production 503 is active**.
- 48–72-hour soak passed: **no**.

The backend cannot be considered closed until the live incident is resolved with evidence and the entire production-like matrix, controlled failure phases, and qualifying soak are rerun successfully.
