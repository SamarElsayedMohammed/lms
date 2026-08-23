# Skillso backend production stability closure report

Report date: 2026-08-23

## 1. Executive summary

**BLOCKED — ROOT CAUSE NOT YET PROVEN.** A real production outage was observed. The reverse proxy returned `503 no available server` for every tested API endpoint while the Coolify control plane remained reachable. Authenticated Coolify/VPS access was unavailable, so the required container exit, health, OOM, Docker-event, and kernel evidence could not be collected. No speculative runtime tuning or redeploy was performed.

## 2. Exact original failure

- **PROVEN:** At `2026-08-23T12:18:33Z`–at least `12:25:19Z`, `api.skillso.net` and the Coolify-generated hostname returned `503 no available server`.
- **PROVEN:** `/api/health/live`, which production Nginx serves without PHP-FPM, was also unavailable through the proxy.
- **VERIFIED:** Coolify's web UI endpoint returned its normal login redirect at the same time.
- **UNVERIFIED:** Application container status, exit code, signal, health state, restart count, and network attachment.

## 3. Root cause

**BLOCKED.** The immediate public failure is loss of every routable backend in Traefik/Coolify. The reason the backend left routing is not yet known. Required discriminating evidence: Docker inspect state/health, Docker events, application container logs, Supervisor status, kernel OOM log, host RAM/disk, and actual Coolify health/resource/network configuration.

## 4. Rejected hypotheses

| Suspect | Evidence for | Evidence against | Status |
| --- | --- | --- | --- |
| PHP-FPM alone | Shared multi-process container | Nginx-only liveness was also absent from routing | PARTIALLY REJECTED |
| Database alone | Readiness depends on DB | Nginx-only liveness does not | PARTIALLY REJECTED |
| Redis alone | Workers/cache can depend on Redis | Nginx-only liveness does not | PARTIALLY REJECTED |
| Entire host down | API was unavailable | Coolify UI remained reachable | PARTIALLY REJECTED |
| Host/cgroup OOM | 2 GB host was previously reported; web and heavy workers share a container | No `OOMKilled` or kernel event | OPEN |
| Container exit/restart | Matches the reported recurring behavior | No inspect/event state | OPEN |
| Health/proxy/network removal | Proxy explicitly reported no available server | Actual Coolify and Docker health/network state unavailable | OPEN |
| Disk/inode exhaustion | Persistent/debug/media logs are plausible growth sources | No incident disk snapshot | OPEN |

## 5. Runtime architecture before

**VERIFIED in current repository image, UNVERIFIED in running production:** one container starts Supervisor as PID 1. Supervisor owns Nginx, PHP-FPM, one default worker, one ingestion worker, one video worker, and one scheduler. The image includes FFmpeg. Database and Redis are external dependencies. Local `docker-compose.yml` is a development topology and is not evidence of Coolify's deployed topology.

## 6. Runtime architecture after

**UNCHANGED / BLOCKED.** No evidence currently justifies a production topology migration during an active, unclassified incident. The recommended future blast-radius design remains separate API, default worker, ingestion/media worker, and scheduler services using the same image, after current failure evidence identifies the triggering process and production resource envelope.

## 7. Files changed

- `scripts/capture-runtime-health.sh`: captures all matching replacement/exited containers, host/kernel/Docker evidence, process and Supervisor state, optional public probe timing, and redacted transition logs.
- `app/Console/Commands/SkillsoRuntimeSnapshotCommand.php`: read-only DB/cache/Redis dependency checks without exception-message disclosure.
- `tests/Feature/RuntimeForensicCaptureScriptTest.php`: forensic capture contract.
- `backend-crash-forensics.md`: observed incident and updated capture command.
- `docs/production-incident-runbook.md`: evidence-first response and bounded recovery paths.
- This report.

## 8. Docker / Coolify changes

**Docker runtime unchanged. Coolify unchanged and unverified.** The diagnostic script no longer chooses an arbitrary first matching container. It preserves all matching current/exited deployments and exact state transitions.

## 9. PHP-FPM changes

**NONE.** Repository configuration is dynamic mode, two children, 200 requests per recycle, 128 MB worker limit, and 60-second termination timeout. These values are hardening assumptions, not empirically verified capacity settings; production RSS percentiles are still required.

## 10. Queue / worker changes

**NONE.** Current repository commands bound concurrency to one process per default, ingestion, and video queue. The ingestion worker recycles after one job. Production process/RSS evidence is unverified.

## 11. FFmpeg / media changes

**NONE.** Current code assigns HLS work to `video-encoding`, runs one video worker, and passes one-thread limits to FFmpeg/x264. Actual incident correlation and FFmpeg peak RSS/CPU remain unverified.

## 12. Scheduler changes

**NONE.** Repository schedules six application commands, Telescope pruning, and one analytics job. Scheduled entries use `withoutOverlapping`; the exchange-rate command also runs in background. Production must prove there is exactly one scheduler process and correlate its timestamps with failure.

## 13. Redis changes

**DIAGNOSTICS ONLY.** Runtime snapshot now issues a read-only Redis `PING` and reports only status/exception class. Actual production hostname/topology remains unverified.

## 14. Healthcheck changes

**NONE.** Docker health checks PID 1, Nginx, and PHP-FPM via `/proc`; Coolify should probe Nginx's exact `/api/health/live` endpoint. The actual configured Coolify path, port, interval, retries, and start period remain unverified.

## 15. Resource limits

**UNVERIFIED.** Repository worker/PHP limits exist, but Docker/Coolify memory, swap, CPU, and PID limits were not accessible. No values were changed.

## 16. Restart / recovery behavior

**UNVERIFIED.** The local development compose uses `unless-stopped`; this does not prove production policy. Supervisor child `autorestart` is configured. The runbook forbids redeploy before evidence capture.

## 17. Observability added

**MITIGATED:** timestamped owner-only incident directories, every matching container state/health/limits, Docker events, kernel stream and prior kernel incidents, host reboot/memory/CPU/disk/inodes, Docker disk usage, process tree, Supervisor status, dependency snapshot, redacted transition logs, and optional external liveness timing.

## 18. Tests executed

- **VERIFIED:** Git Bash `bash -n scripts/capture-runtime-health.sh` passed.
- **VERIFIED:** PHP syntax checks passed for the runtime command and forensic test.
- **VERIFIED:** 20 focused PHPUnit tests passed with 94 assertions: forensic capture, Coolify boot order, container liveness, and health-probe behavior.
- **VERIFIED:** `git diff --check` passed.
- **BLOCKED:** Docker image build and live host execution were unavailable on this Windows workstation. The capture script's Linux/Docker integrations still require VPS or staging execution.
- **NOTED:** Test bootstrap emitted existing Google API client PHP deprecation warnings; tests still passed.

## 19. Heavy workload verification

**BLOCKED.** No production mutation or heavy FFmpeg workload was run during an unclassified outage. Use staging/test data with host capture running.

## 20. Soak test results

**BLOCKED.** No 48–72 hour production-like soak has completed after a proven fix.

## 21. Remaining risks

- The current production failure cause is open.
- Actual Coolify configuration and container state are unavailable.
- API and heavy background processes likely share one container, so blast radius remains coupled.
- Production resource headroom, PHP-FPM RSS, FFmpeg peak usage, disk growth, Redis topology, and scheduler multiplicity are unmeasured.
- A persistent log volume is declared in the image but must be confirmed in Coolify.

## 22. Final certification

**BLOCKED — NOT CERTIFIED FOR PRODUCTION STABILITY CLOSURE.** The public outage is proven; its causal mechanism is not. Closure requires preserving this incident's host/container evidence (if still available), applying the minimum cause-specific fix, controlled failure tests, representative heavy workloads, memory-growth measurement, and a 48–72 hour soak with zero unexplained exits.
