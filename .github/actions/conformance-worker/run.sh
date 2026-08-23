#!/usr/bin/env bash
# The stop/start/wait/test sequence shared by every conformance suite run in
# this job. The environment variables come from action.yml; see it for what
# each one means. One suite run = one invocation of this script.
set -euo pipefail

log_name="wrangler-${RUN_NAME}.log"

# --- Stop the previous run's Worker ---------------------------------------------------------------------
#
# wrangler.pid is the `npm run` wrapper where one exists; killing it alone
# orphans the wrangler/workerd children, which keep the port and would make
# this run test the previous run's server. Take down the whole tree,
# then require the port to actually go quiet before booting — a still-
# answering /healthz here is the old server, not a head start.

if [[ -f wrangler.pid ]]; then
  kill "$(cat wrangler.pid)" 2>/dev/null || true
fi
pkill -f 'wrangler-dist/cli.js dev' 2>/dev/null || true
pkill -f 'workerd serve' 2>/dev/null || true

freed=0
for _ in $(seq 1 15); do
  if ! curl -fsS "http://127.0.0.1:${WORKER_PORT}/healthz" > /dev/null 2>&1; then
    freed=1
    break
  fi
  sleep 1
done
if [[ "$freed" != "1" ]]; then
  echo "::error::a previous worker is still answering on port ${WORKER_PORT}; refusing to start the ${RUN_NAME} run against it"
  exit 1
fi

rm -f test/.dev-secret.json

# --- Start this run's Worker --------------------------------------------

var_args=()
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  name="${pair%%=*}"
  value="${pair#*=}"
  # A literal `-` omits the variable entirely, so a run can leave a var
  # unset rather than pass it empty (an empty ATOMS_SHARED_SECRET is a
  # different configuration from an absent one).
  [[ "$value" == "-" ]] && continue
  var_args+=("--var" "${name}:${value}")
done < <(printf '%s\n' "$RUN_VARS")

npx wrangler dev --port "$WORKER_PORT" --ip 127.0.0.1 "${var_args[@]}" > "$log_name" 2>&1 &
echo $! > wrangler.pid

# --- Poll /healthz, with liveness ------------------------------------------
#
# Startup is dominated by workerd compiling an 18MB wasm module, which varies
# with runner load: poll rather than sleep. /healthz answers without touching
# a Durable Object, so a 200 means the worker module actually loaded. The
# liveness check inside the loop is what turns a startup crash into a real
# error instead of a timeout.

up=0
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:${WORKER_PORT}/healthz" > /dev/null 2>&1; then
    echo "Worker (${RUN_NAME} configuration) answered /healthz after ~$((i * 2))s"
    up=1
    break
  fi
  if ! kill -0 "$(cat wrangler.pid)" 2>/dev/null; then
    echo "::error::wrangler dev (${RUN_NAME}) exited before serving /healthz"
    cat "$log_name"
    exit 1
  fi
  sleep 2
done
if [[ "$up" != "1" ]]; then
  echo "::error::the ${RUN_NAME} Worker never served /healthz within 120s"
  cat "$log_name"
  exit 1
fi

# --- Run the requested slice of the suite ----------------------------------

suite_env_args=()
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  suite_env_args+=("${pair%%=*}=${pair#*=}")
done < <(printf '%s\n' "$RUN_SUITE_ENV")

env \
  ATOMS_BASE_URL="http://127.0.0.1:${WORKER_PORT}" \
  ATOMS_ONLY="$RUN_CHECKS" \
  ${suite_env_args[@]+"${suite_env_args[@]}"} \
  npm test
