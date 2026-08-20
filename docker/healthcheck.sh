#!/bin/sh
# Liveness only. Try Coolify PORT first, then 80 — nginx may still be on 80.
try_live() {
    curl -fsS --max-time 2 "http://127.0.0.1:${1}/api/health/live"
}

port="${PORT:-80}"
try_live "$port" && exit 0
if [ "$port" != "80" ]; then
    try_live 80 && exit 0
fi
exit 1
