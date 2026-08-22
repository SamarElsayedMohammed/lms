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

usage() {
    cat <<'EOF'
Usage: sudo ./scripts/capture-runtime-health.sh [options]

Options:
  --match <text>       Case-insensitive text in the backend container name.
  --interval <seconds> Snapshot interval (default: 10).
  --duration <seconds> Total capture duration (default: 7200).
  --output <directory> Evidence directory (default: timestamped directory).
  --log-tail <lines>   Docker log lines captured on a state transition (default: 250).
EOF
}

while (($# > 0)); do
    case "$1" in
        --match) container_match="$2"; shift 2 ;;
        --interval) interval_seconds="$2"; shift 2 ;;
        --duration) duration_seconds="$2"; shift 2 ;;
        --output) output_dir="$2"; shift 2 ;;
        --log-tail) log_tail="$2"; shift 2 ;;
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

find_container() {
    # `docker ps -a` retains a recently exited container, which lets the next
    # snapshot capture its exit code and OOMKilled state instead of losing it.
    docker ps -aq --filter "name=$container_match" | head -n 1
}

inspect_summary() {
    docker inspect --format 'status={{.State.Status}} running={{.State.Running}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} error={{.State.Error}} started={{.State.StartedAt}} finished={{.State.FinishedAt}} restart_count={{.RestartCount}} health={{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}} memory={{.HostConfig.Memory}} memory_swap={{.HostConfig.MemorySwap}} pids_limit={{.HostConfig.PidsLimit}} restart_policy={{.HostConfig.RestartPolicy.Name}}' "$1"
}

capture_container_logs() {
    local container_id="$1"
    local stamp="$2"
    docker logs --timestamps --tail "$log_tail" "$container_id" > "$output_dir/container-${container_id}-${stamp}.log" 2>&1 || true
}

last_state=''
capture_snapshot() {
    local stamp container_id state
    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    container_id="$(find_container || true)"

    {
        printf 'timestamp=%s\n' "$stamp"
        uptime || true
        free -h || true
        swapon --show || true
        df -h || true
        df -i || true
        printf '\n[docker ps]\n'
        docker ps -a --no-trunc || true
        printf '\n[docker stats]\n'
        docker stats --no-stream || true
        printf '\n[host memory]\n'
        ps aux --sort=-%mem | head -n 30 || true
        printf '\n[host cpu]\n'
        ps aux --sort=-%cpu | head -n 30 || true

        if [[ -n "$container_id" ]]; then
            printf '\n[container=%s]\n' "$container_id"
            state="$(inspect_summary "$container_id" 2>&1 || true)"
            printf '%s\n' "$state"
            printf '\n[docker top]\n'
            docker top "$container_id" -eo pid,ppid,rss,vsz,%mem,%cpu,stat,etime,cmd || true
            printf '\n[supervisor status]\n'
            docker exec "$container_id" supervisorctl status || true

            if [[ "$state" != "$last_state" ]]; then
                capture_container_logs "$container_id" "$stamp"
                last_state="$state"
            fi
        else
            printf '\nNo running container matched name: %s\n' "$container_match"
        fi
    } > "$output_dir/snapshot-${stamp}.txt" 2>&1
}

docker events --filter type=container --format '{{json .}}' > "$output_dir/docker-events.ndjson" 2>&1 &
events_pid=$!

cleanup() {
    kill "$events_pid" 2>/dev/null || true
    wait "$events_pid" 2>/dev/null || true
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
