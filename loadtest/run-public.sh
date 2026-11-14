#!/usr/bin/env bash
# TC-PERF-01 (versi lokal): beban baca katalog publik dengan ApacheBench.
# Pakai instance API terpisah dengan rate limit dinaikkan, mis.:
#   PUBLIC_RATE_LIMIT=1000000 PHP_CLI_SERVER_WORKERS=8 php backend/artisan serve --port=8010 --no-reload
# (--no-reload wajib agar PHP_CLI_SERVER_WORKERS berlaku.)
# Lalu: loadtest/run-public.sh http://127.0.0.1:8010 2000 20
set -euo pipefail
BASE=${1:-http://127.0.0.1:8010}
N=${2:-2000}
C=${3:-20}
TENANT=${TENANT_SLUG:-ppiu-a-jambi}
PKG=${PACKAGE_SLUG:-umrah-reguler-12-hari}
for path in \
  "/api/v1/public/packages" \
  "/api/v1/public/packages?sort=price&month=2027-02" \
  "/api/v1/public/packages/$TENANT/$PKG" \
  "/api/v1/public/tenants" \
  "/api/v1/public/departures" \
  "/api/v1/public/facets"; do
  out=$(ab -q -n "$N" -c "$C" -H 'Accept: application/json' "$BASE$path" 2>&1)
  failed=$(echo "$out" | awk '/Failed requests/ {print $3}')
  non2xx=$(echo "$out" | awk '/Non-2xx responses/ {print $3}')
  rps=$(echo "$out" | awk '/Requests per second/ {print $4}')
  p50=$(echo "$out" | awk '$1=="50%" {print $2}')
  p95=$(echo "$out" | awk '$1=="95%" {print $2}')
  p99=$(echo "$out" | awk '$1=="99%" {print $2}')
  printf '%-58s rps=%-8s p50=%4sms p95=%4sms p99=%4sms gagal=%s non2xx=%s\n' "$path" "$rps" "$p50" "$p95" "$p99" "${failed:-0}" "${non2xx:-0}"
done
