#!/usr/bin/env bash
# Capture bounded, host-side Docker evidence while investigating a Skillso crash.
# Run this on the VPS host (not inside the application container).
# It intentionally records only selected docker inspect fields, never container
# environment variables, because those may include production secrets.

set -u
umask 077

interval_seconds="${INTERVAL_SECONDS:-10}"
duration_seconds="${DURATION_SECONDS:-7200}"
container_match="${CONTAINER_MATCH:-skillso}"
log_tail="${LOG_TAIL:-250}"
output_dir="${OUTPUT_DIR:-./skillso-crash-forensics-$(date -u +%Y%m%dT%H%M%SZ)}"
probe_url="${PROBE_URL:-}"

usage() {
    cat <<'EOF'
Usage: sudo ./scripts/capture-runtime-health.sh [options]

Options:
  --match <text>       Case-insensitive text in the backend container name.
  --interval <seconds> Snapshot interval (default: 10).
  --duration <seconds> Total capture duration (default: 7200).
  --output <directory> Evidence directory (default: timestamped directory).
  --log-tail <lines>   Docker log lines captured on a state transition (default: 250).
  --probe-url <url>    Optional public liveness URL; records status/timing only.
EOF
}

while (($# > 0)); do
    case "$1" in
        --match) container_match="$2"; shift 2 ;;
        --interval) interval_seconds="$2"; shift 2 ;;
        --duration) duration_seconds="$2"; shift 2 ;;
        --output) output_dir="$2"; shift 2 ;;
        --log-tail) log_tail="$2"; shift 2 ;;
        --probe-url) probe_url="$2"; shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
done

case "$interval_seconds:$duration_seconds:$log_tail" in
    *[!0-9:]*|:*|*::*) printf 'interval, duration, and log-tail must be positive integers.\n' >&2; exit 2 ;;
esac

if ((interval_seconds < 5 || duration_seconds < interval_seconds || log_tail < 1)); then
    printf 'Use an interval of at least 5 seconds and a duration >= interval.\n' >&2
    exit 2
fi

if [[ -n "$probe_url" ]]; then
    case "$probe_url" in
        http://*|https://*) ;;
        *) printf 'probe-url must use http:// or https://.\n' >&2; exit 2 ;;
    esac
    case "$probe_url" in
        *'?'*|*'@'*) printf 'probe-url must not contain credentials or a query string.\n' >&2; exit 2 ;;
    esac
fi

mkdir -p "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
case "$output_dir" in
    /|/var|/var/log) printf 'Refusing an unsafe output directory: %s\n' "$output_dir" >&2; exit 2 ;;
esac

if ! command -v docker >/dev/null 2>&1; then
    printf 'docker is required; run this on the VPS host with Docker access.\n' >&2
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    printf 'Cannot access Docker. Run as root or a user in the docker group.\n' >&2
    exit 1
fi

redact_stream() {
    sed -E \
        -e 's/(Bearer[[:space:]]+)[A-Za-z0-9._~+\/=:-]+/\1<redacted>/Ig' \
        -e 's/((authorization|password|passwd|token|secret|api[_-]?key|cookie)[[:space:]"'"'"']*[:=][[:space:]"'"'"']*)[^,[:space:]"'"'"']+/\1<redacted>/Ig'
}

find_containers() {
    # Preserve every matching replacement/exited container. Selecting only the
    # first match can silently inspect an old deployment instead of the failure.
    docker ps -aq --no-trunc --filter "name=$container_match"
}

inspect_summary() {
    docker inspect --format 'name={{.Name}} status={{.State.Status}} running={{.State.Running}} dead={{.State.Dead}} pid={{.State.Pid}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} error={{.State.Error}} started={{.State.StartedAt}} finished={{.State.FinishedAt}} restart_count={{.RestartCount}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} memory={{.HostConfig.Memory}} memory_swap={{.HostConfig.MemorySwap}} nano_cpus={{.HostConfig.NanoCpus}} pids_limit={{.HostConfig.PidsLimit}} restart_policy={{.HostConfig.RestartPolicy.Name}}' "$1"
}

capture_container_logs() {
    local container_id="$1"
    local stamp="$2"
    docker logs --timestamps --tail "$log_tail" "$container_id" 2>&1 \
        | redact_stream > "$output_dir/container-${container_id}-${stamp}.log" || true
}

capture_initial_host_context() {
    local stamp
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    {
        printf 'timestamp=%s\n' "$stamp"
        hostname || true
        uname -a || true
        uptime || true
        last reboot -n 5 2>/dev/null || true
        printf '\n[docker info]\n'
        docker info --format 'server_version={{.ServerVersion}} containers={{.Containers}} running={{.ContainersRunning}} paused={{.ContainersPaused}} stopped={{.ContainersStopped}} driver={{.Driver}}' || true
        printf '\n[docker disk usage]\n'
        docker system df || true
        printf '\n[kernel incidents, previous 6 hours]\n'
        journalctl -k --since '6 hours ago' --no-pager 2>/dev/null \
            | grep -Ei 'out of memory|oom|killed process|memory cgroup|segfault|I/O error|hung task|docker|containerd' || true
        printf '\n[dmesg incidents]\n'
        dmesg -T 2>/dev/null \
            | grep -Ei 'out of memory|oom|killed process|memory cgroup|segfault|I/O error|hung task' || true
    } 2>&1 | redact_stream > "$output_dir/host-initial-${stamp}.txt"
}

capture_snapshot() {
    local stamp container_id state state_file running
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"

    {
        printf 'timestamp=%s\n' "$stamp"
        hostname || true
        uptime || true
        free -h || true
        swapon --show || true
        df -h || true
        df -i || true
        printf '\n[docker ps]\n'
        docker ps -a --no-trunc || true
        printf '\n[docker stats]\n'
        docker stats --no-stream || true
        printf '\n[docker disk usage]\n'
        docker system df || true
        printf '\n[host memory]\n'
        ps aux --sort=-%mem | head -n 30 || true
        printf '\n[host cpu]\n'
        ps aux --sort=-%cpu | head -n 30 || true

        if [[ -n "$probe_url" ]]; then
            printf '\n[public liveness probe]\n'
            curl --silent --show-error --output /dev/null --max-time 10 \
                --write-out 'http_code=%{http_code} remote_ip=%{remote_ip} connect_seconds=%{time_connect} total_seconds=%{time_total}\n' \
                "$probe_url" || true
        fi

        mapfile -t container_ids < <(find_containers || true)
        if ((${#container_ids[@]} == 0)); then
            printf '\nNo current or exited container matched name: %s\n' "$container_match"
        fi

        for container_id in "${container_ids[@]}"; do
            printf '\n[container=%s]\n' "$container_id"
            state="$(inspect_summary "$container_id" 2>&1 || true)"
            printf '%s\n' "$state"
            printf '\n[health detail]\n'
            docker inspect --format '{{json .State.Health}}' "$container_id" || true
            printf '\n[docker top]\n'
            docker top "$container_id" -eo pid,ppid,rss,vsz,%mem,%cpu,stat,etime,cmd || true

            running="$(docker inspect --format '{{.State.Running}}' "$container_id" 2>/dev/null || true)"
            if [[ "$running" == 'true' ]]; then
                printf '\n[container process tree]\n'
                docker exec "$container_id" /bin/sh -c 'ps -ef 2>/dev/null || { for p in /proc/[0-9]*; do printf "%s " "${p##*/}"; tr "\000" " " < "$p/cmdline" 2>/dev/null; printf "\n"; done; }' || true
                printf '\n[supervisor status]\n'
                docker exec "$container_id" supervisorctl status || true
                printf '\n[read-only application dependency snapshot]\n'
                timeout 15s docker exec -u www-data "$container_id" php /var/www/html/artisan skillso:runtime-snapshot --no-interaction 2>&1 \
                    | redact_stream || true
            fi

            state_file="$output_dir/.state-${container_id}"
            if [[ ! -f "$state_file" ]] || [[ "$state" != "$(<"$state_file")" ]]; then
                capture_container_logs "$container_id" "$stamp"
                printf '%s' "$state" > "$state_file"
            fi
        done
    } 2>&1 | redact_stream > "$output_dir/snapshot-${stamp}.txt"
}

capture_initial_host_context

docker events --filter type=container --format '{{json .}}' > "$output_dir/docker-events.ndjson" 2>&1 &
events_pid=$!

kernel_pid=''
if command -v journalctl >/dev/null 2>&1; then
    journalctl -k -f --since now -o short-iso --no-pager > "$output_dir/kernel-events.log" 2>&1 &
    kernel_pid=$!
fi

cleanup() {
    kill "$events_pid" 2>/dev/null || true
    wait "$events_pid" 2>/dev/null || true
    if [[ -n "$kernel_pid" ]]; then
        kill "$kernel_pid" 2>/dev/null || true
        wait "$kernel_pid" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

printf 'Writing bounded evidence to %s\n' "$output_dir"
printf 'Container name match: %s; interval: %ss; duration: %ss\n' "$container_match" "$interval_seconds" "$duration_seconds"

deadline=$(( $(date +%s) + duration_seconds ))
while (( $(date +%s) < deadline )); do
    capture_snapshot
    sleep "$interval_seconds"
done

capture_snapshot
printf 'Capture complete: %s\n' "$output_dir"
