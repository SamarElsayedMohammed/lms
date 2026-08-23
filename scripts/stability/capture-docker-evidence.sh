#!/usr/bin/env bash
set -euo pipefail

# Read-only sampler for a staging host. Container names/IDs are explicit inputs.
if [[ $# -lt 2 ]]; then
  echo "usage: $0 <evidence-dir> <container> [container ...]" >&2
  exit 64
fi

evidence_dir=$1
shift
containers=("$@")
mkdir -p "$evidence_dir"
run_id=$(date -u +%Y%m%dT%H%M%SZ)
snapshot="$evidence_dir/${run_id}-baseline.txt"
samples="$evidence_dir/${run_id}-docker-samples.csv"

{
  date -u --iso-8601=seconds
  uname -a
  uptime
  free -h
  df -h
  df -i
  docker system df
  docker ps -a --no-trunc
  for container in "${containers[@]}"; do
    docker inspect "$container" --format 'Name={{.Name}} Status={{.State.Status}} Running={{.State.Running}} Restarting={{.State.Restarting}} ExitCode={{.State.ExitCode}} OOMKilled={{.State.OOMKilled}} StartedAt={{.State.StartedAt}} FinishedAt={{.State.FinishedAt}} RestartCount={{.RestartCount}} Health={{json .State.Health}} Memory={{.HostConfig.Memory}} MemorySwap={{.HostConfig.MemorySwap}} NanoCPUs={{.HostConfig.NanoCpus}} PidsLimit={{.HostConfig.PidsLimit}} RestartPolicy={{json .HostConfig.RestartPolicy}}'
  done
} > "$snapshot"

echo 'timestamp_utc,container,cpu_percent,memory_usage,memory_percent,net_io,block_io,pids,restart_count,health' > "$samples"
for container in "${containers[@]}"; do
  docker stats --no-stream --format '{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.MemPerc}},{{.NetIO}},{{.BlockIO}},{{.PIDs}}' "$container" |
    while IFS= read -r stats; do
      restart_count=$(docker inspect "$container" --format '{{.RestartCount}}')
      health=$(docker inspect "$container" --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')
      printf '%s,%s,%s,%s\n' "$(date -u --iso-8601=seconds)" "$stats" "$restart_count" "$health" >> "$samples"
    done
done

printf '%s\n%s\n' "$snapshot" "$samples"
