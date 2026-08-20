#!/bin/sh
# Liveness only. Coolify sets PORT at runtime — do not bake port 80 at image build.
# Probe nginx's static /api/health/live so a full PHP-FPM pool cannot restart the container.
port="${PORT:-80}"
exec curl -fsS "http://127.0.0.1:${port}/api/health/live"
