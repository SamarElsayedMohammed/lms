# SKILLSO BACKEND CODE-LEVEL STABILITY AUDIT REPORT

Audit date: 2026-08-23 (Africa/Cairo)  
Scope: `backend-skillso` Laravel application, runtime configuration, dependency lock, and tests  
Method: static source inventory, configuration tracing, targeted regression execution, full-suite execution, and Composer advisory audit

## 1. Executive Summary

The audit found and repaired concrete worker-memory, scheduler-memory, disk-growth, transaction-lifetime, report-memory, and dependency risks. The highest-confidence application defects were FFmpeg's multi-hour in-memory output buffering, ingestion retaining every generated vector in PHP arrays, an unbounded subscription-expiry hydration, two unbounded legacy report summaries, non-rotating Kashier logs, and validation returns that left database transactions open. The locked dependency graph also contained two critical and multiple high-severity advisories; it now reports zero advisories.

No static evidence proves that any one of these paths caused the observed production-wide `503 no available server` incident. Public application endpoints were unavailable while the Coolify control plane remained reachable, but authenticated container, kernel, OOM, process, and deployment telemetry was not available. Release safety therefore remains conditional on runtime verification and resolution of the pre-existing full-suite failures.

## 2. Files inspected

The audit searched all application PHP and runtime source, including 166 controllers, 71 services, 12 jobs, 4 listeners, 4 events, 30 commands, 25 notifications, and 1 mailable. It also inspected `routes/console.php`, queue/cache/session/logging/database/service configuration, Docker and Nixpacks images, both Supervisor configurations, entrypoint and healthcheck scripts, Composer manifests, migrations relevant to locks/queues, and the complete test inventory.

High-risk files received line-level review: `EncodeVideoToHLS`, `ProcessKnowledgeIngestionJob`, every queued job/listener/notification, `SubscriptionService`, `ReportsController`, `OrderApiController`, payment/HTTP services, `FFmpegService`, document parsing/chunking/embedding services, runtime shell scripts, and process-manager definitions.

## 3. Runtime architecture discovered

Supervisor is PID 1 and independently manages nginx, PHP-FPM, a scheduler, a default queue worker, an ingestion worker, and a video-encoding worker. Default jobs recycle after 3,600 seconds with a 128 MB worker limit. Ingestion is isolated to one 192 MB worker with `--max-jobs=1`, `--timeout=7200`; video encoding is isolated to one 160 MB worker with a 7,200-second timeout. Database and Redis queue `retry_after` is 7,300 seconds, 100 seconds beyond the longest job timeout.

The architecture limits a single queue failure to one worker in normal operation, but the actual deployed process counts, host/container memory limits, queue driver, cache driver, and rolling-deploy overlap could not be authenticated from runtime.

## 4. Queue inventory

Jobs: `AnalyzeLectureDurationJob`, `DispatchNotificationCampaignJob`, `EncodeVideoToHLS`, `FetchBunnyVideoDurationJob`, `FlushFeatureSectionAnalyticsJob`, `ProcessKnowledgeIngestionJob`, `RecalculateCourseDurationJob`, `SendFcmNotificationJob`, `SendNotificationCampaignChunkJob`, `SendOrderNotifications`, `SendTrackingEventJob`, and `UpdateExchangeRatesJob`.

All 12 jobs implement `ShouldQueue` and define explicit tries, timeout, and backoff controls. Campaign users are chunked by 100, FCM tokens by 50, and notification chunks contain at most 100 user IDs. Four queued listeners and all 25 notifications have bounded queue execution metadata. The longest jobs are isolated to single-purpose workers and fit inside `retry_after`.

## 5. Scheduler inventory

`artisan schedule:list` resolved eight entries: affiliate commission release daily; subscription expiry notification daily; expired-subscription handling every five minutes; queued-subscription activation every five minutes; currency updates every four hours; webinar reminders every 15 minutes; Telescope pruning every six hours; feature-section analytics flushing every five minutes.

Every business schedule uses `withoutOverlapping`; currency update also uses `runInBackground`. The subscription expiry scan is now lazy. Affiliate, expired-subscription, and activation flows already use bounded chunks. Scheduler singleton status is not runtime-proven, and cache-backed overlap locks may be container-local when the deployed cache driver is `file`.

## 6. External-process inventory

Application subprocess creation is confined to Symfony Process invocations for FFmpeg/FFprobe availability and encoding. No application use of `shell_exec`, `system`, raw `exec`, `passthru`, `popen`, or manual `proc_open` was found. Raw `curl_exec` call sites are outbound HTTP clients and have connect/request timeouts.

Process commands use argument arrays, not shell-concatenated execution. FFmpeg is restricted to one thread and one queue worker.

## 7. FFmpeg/media analysis

Before repair, Symfony Process buffered FFmpeg's continuous stderr for up to two hours and the job logged the buffered output. That could breach the 160 MB worker limit. Failed or shorter re-encodes could also retain stale or partial HLS segments.

The job now calls `disableOutput()`, reports only the exit code, removes stale output before encoding, and deletes partial output on any exception. It retains a 7,200-second timeout, one try, single worker, single FFmpeg thread, source-file validation, free-space preflight, manifest validation, and explicit status transitions.

## 8. Memory risks

Fixed P1 risks:

- Knowledge ingestion no longer retains every 1,536-dimension vector and row in PHP arrays. Rows are JSONL-spooled to an auto-deleted temporary file and inserted in batches of 25 inside the final atomic transaction.
- Subscription expiry notification uses `lazyById(100)` instead of hydrating every matching subscription and relation.
- Enrollment aggregates run in SQL and details remain paginated; revenue totals/payment groups run in SQL and category processing uses `lazyById(200)`.
- FFmpeg output is not buffered.

Residual lower-confidence risks include whole-document parsing/chunk construction for a validated 10 MB upload, a bounded 25 MB parser ceiling, a webinar reminder `get()` whose expected cardinality is low but not enforced, and static request/worker caches. Worker time-based recycling bounds the lifetime of those caches.

## 9. DB risks

Large scheduled subscription mutations use `chunkById`; queue writes and report exports have explicit batch or row limits. Report-wide counts and sums are calculated by the database rather than from a current page. The audit found no schema-wide `Model::all()` on a high-cardinality transactional table; remaining `all()` calls are configuration/reference tables.

Two buy-now/cart validation branches returned after acquiring a user row lock without rolling back. They now explicitly roll back before returning. Payment gateway initiation and queued tracking occur after commit. Remaining full-suite failures include existing test-isolation/unique-setting issues and should be resolved before unconditional release certification.

## 10. Redis/cache/session risks

Redis has bounded connect/read timeouts. Certificate, Kashier, and PDF locks have TTLs and bounded blocking windows; no `Cache::forever` usage was found. Session defaults to database and cache defaults to file in repository configuration, although deployed values are unknown.

Feature analytics uses Redis `SCAN`, not `KEYS`, but accumulates matching keys before flushing; this is a P2 cardinality risk if feature-section key count becomes unexpectedly large. Multi-replica scheduler overlap safety requires a shared lock-capable cache and `onOneServer()` or an external singleton guarantee.

## 11. External HTTP risks

All discovered Laravel HTTP, Guzzle, and cURL call sites have bounded connect and request timeouts. Representative bounds are tracking 1/3 seconds, geolocation 2/3 or 3/8 seconds, and provider APIs 3/10 seconds. SSL peer verification is not disabled. Embedding HTTP happens before the database replacement transaction; tracking and payment initiation happen after commit.

Several provider failures still log response bodies. These are P2 log-volume/data-minimization risks and should be converted to size-limited structured summaries, especially for chatbot, Zoom, Bunny, tracking, rates, and payment providers.

## 12. PHP-FPM findings

PHP-FPM is supervised independently from nginx and queues; the healthcheck verifies that it exists. Request memory and upload/post ceilings are configured, and request code does not disable memory limits. No evidence was found that a normal FPM request can intentionally terminate PID 1.

Actual `pm` mode, child counts, `pm.max_requests`, per-container memory, slowlog, and production `memory_limit` were not available. Those values are required to calculate worst-case resident memory and confirm recycling.

## 13. Supervisor findings

Queue concurrency is deliberately low and heavy queues are isolated. Workers have explicit memory, timeout, job-count, and/or max-time recycling controls. Programs auto-restart independently, reducing blast radius.

The video worker `stopwaitsecs` equals the 7,200-second job timeout rather than exceeding it; this is a P2 graceful-shutdown margin risk. Production process state, restart counters, exit codes, and Supervisor logs remain required evidence.

## 14. Docker/entrypoint findings

Supervisor remains PID 1 and owns child lifecycle. Entrypoint/startup scripts use bounded preparation steps and do not launch duplicate worker families in the reviewed definitions. Application health does not depend solely on PID 1 existing.

Docker was unavailable locally, so image build, boot, signal propagation, cgroup limits, filesystem ownership, and Coolify deployment-command behavior were not executed in this audit.

## 15. Healthcheck findings

The container healthcheck verifies PID 1 plus nginx and PHP-FPM using `/proc`, without depending on curl, DNS, database availability, or an externally routed port. The runtime snapshot command and capture script collect process, cgroup, disk, queue, scheduler, failed-job, and Laravel diagnostics with bounded calls and redaction.

Public health/version probes returned the same upstream 503 during the incident, so they could not distinguish application boot failure from proxy/upstream loss. Container-local health history is still needed.

## 16. Disk/temp/log risks

Fixed: Kashier diagnostics no longer append forever to `storage/logs/kashier.log` or `/tmp/kashier.log`; they use the rotating daily channel. Full slider/course/instructor dumps and raw helpdesk request debugging were removed. HLS partial directories are cleaned, and ingestion's `tmpfile()` is closed in `finally` for automatic deletion.

Daily logs retain 14 days by repository default. Production `LOG_LEVEL`, volume size, log-driver rotation, Telescope table size, `/tmp` capacity, and orphaned files must be observed. Provider response-body logging remains P2.

## 17. Proven defects

1. P1: multi-hour FFmpeg stderr buffering could exhaust the video worker.
2. P1: ingestion retained all PHP float vectors until one final transaction.
3. P1: daily subscription expiry scan hydrated an unbounded relation graph.
4. P1: enrollment/revenue summaries hydrated full result sets.
5. P1: Kashier wrote to unrotated direct log files.
6. P1: locked dependencies had two critical and multiple high advisories, including code-injection and parser/DoS classes.
7. P2: locked-order validation returned without explicit rollback.
8. P2: high-volume admin debug payload logging amplified disk usage.

These are proven code defects or release risks. None is proven to be the production outage trigger.

## 18. Fixed defects

Implemented fixes: disabled FFmpeg output buffering and added deterministic HLS cleanup; disk-spooled embedding rows with batched atomic inserts; lazy subscription scans; SQL report aggregates and bounded report iteration; rotating Kashier logs; removal of payload debug logs; explicit transaction rollback; upgraded the dependency graph; and updated stale stability tests for queued tracking and Laravel 12 HTTP request inspection.

Dependency results: Laravel `12.47.0 -> 12.67.0`, Google API client `2.15.0 -> 2.19.4`, Guzzle `7.10.0 -> 7.15.3`, PhpSpreadsheet `5.5.0 -> 5.9.0`, FPDI `2.6.4 -> 2.6.8`, plus patched compatible transitive packages. `composer audit --locked` now reports no advisories.

## 19. Open risks

- Production root cause is unproven without OOM/kernel/container/deployment evidence.
- Full-suite baseline is not green; pre-update execution had 38 failures and 5 incomplete tests. Several failures are directly caused by pre-existing user edits changing completion thresholds from 100 to 11; others cover localization expectations, cart response shape, device limits, chatbot 409 conflicts, mail sender fixtures, and test database isolation.
- Multi-replica scheduler singleton and shared overlap-lock behavior are unverified.
- Provider response bodies can amplify logs.
- Webinar reminder selection and Redis analytics key collection are not hard-capped.
- Docker image/build and Linux optimized Composer autoload were not locally executable; normal autoload and application tests were executable on Windows.

## 20. Tests executed

- Focused post-fix/post-dependency suite: 58 passed, 585 assertions, zero failures.
- Full pre-dependency suite: 601 passed, 38 failed, 5 incomplete, 2,818 assertions. Failures were recorded rather than hidden.
- Full post-dependency suite: 601 passed, 38 failed, 5 incomplete, 2,818 assertions—the same count as the pre-update baseline, with no dependency-update regression detected.
- `artisan schedule:list`: eight schedules resolved successfully.
- Laravel Pint: touched PHP files pass formatting.
- `git diff --check`: no whitespace errors.
- Composer validation: valid with the pre-existing unbounded Telescope constraint warning.
- Composer advisory audit: zero advisories after update.
- Shell capture script syntax and focused runtime-forensic tests passed in the preceding incident-forensics pass.

## 21. Remaining runtime evidence required

Capture the incident window's: kernel `dmesg`/OOM-killer records; Coolify deployment and health-event history; `docker inspect` state/OOM/exit/health fields; cgroup memory current/peak/events/limit; container restart count and exit code; Supervisor status/restart logs; nginx/PHP-FPM error and slow logs; Laravel stderr around the first failure; queue backlog/failed-job/rate; scheduler singleton/process count; disk/inode usage; database connection/lock metrics; Redis memory/eviction/latency; and the exact deployed image digest, environment driver selections, and resource limits.

Only temporal correlation between the first failing signal and this evidence can prove the outage cause. Deploy the instrumentation, execute bounded FFmpeg/ingestion/report/scheduler load tests in staging, then observe at least one production scheduler/queue cycle under cgroup monitoring.

```text
CODE-LEVEL CRASH RISK:
CONDITIONAL

PRODUCTION ROOT CAUSE:
NOT YET PROVEN
```
