#!/bin/sh
# Docker HEALTHCHECK only. Coolify HTTP probes belong on /api/health/live.
# Scan /proc because this image has no procps utilities.
kill -0 1 || exit 1

has_comm() {
  needle=$1
  for piddir in /proc/[0-9]*; do
    comm=$(cat "$piddir/comm" 2>/dev/null) || continue
    case "$comm" in
      "$needle"*) return 0 ;;
    esac
  done
  return 1
}

has_comm php-fpm || exit 1
has_comm nginx || exit 1
exit 0
