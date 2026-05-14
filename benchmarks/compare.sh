#!/usr/bin/env bash
# Runs the same k6 workload against Swoole/Octane and FPM/nginx, saves results side-by-side.
# Prerequisites: k6 installed on the host (https://k6.io/docs/get-started/installation/)
#
# Usage: bash benchmarks/compare.sh

set -e

SCRIPT="benchmarks/load_test.js"
RESULTS_DIR="benchmarks/results"
TS=$(date +%Y%m%d_%H%M%S)

mkdir -p "$RESULTS_DIR"

echo "============================================"
echo "  Benchmark: Swoole/Octane on :8000"
echo "============================================"
k6 run -e BASE_URL=http://localhost:8000 \
       --out json="${RESULTS_DIR}/swoole_${TS}.json" \
       "$SCRIPT"

echo ""
echo "============================================"
echo "  Benchmark: PHP-FPM/nginx on :8080"
echo "============================================"
k6 run -e BASE_URL=http://localhost:8080 \
       --out json="${RESULTS_DIR}/fpm_${TS}.json" \
       "$SCRIPT"

echo ""
echo "Results saved to ${RESULTS_DIR}/"
echo "Compare: swoole_${TS}.json vs fpm_${TS}.json"