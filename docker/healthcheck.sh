#!/bin/sh
# PID 1 must be alive, and php-fpm plus nginx must still be present.
# HTTP probes belong to Coolify on /api/health/live, not this script.
kill -0 1 || exit 1
pgrep php-fpm >/dev/null 2>&1 || exit 1
pgrep nginx >/dev/null 2>&1 || exit 1
exit 0
