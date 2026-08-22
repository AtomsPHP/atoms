#!/usr/bin/env bash
# The kill/wait/boot/poll/run choreography every Worker posture shares.
# Inputs arrive as environment variables from action.yml; see it for the
# contract. One posture = one invocation of this script.
set -euo pipefail

log_name="wrangler-${POSTURE_NAME}.log"

# --- Tear down the previous posture's worker -------------------------------
#
# wrangler.pid is the `npm run` wrapper where one exists; killing it alone
# orphans the wrangler/workerd children, which keep the port and would make
# this step test the previous posture's server. Take down the whole tree,
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
  echo "::error::a previous worker is still answering on port ${WORKER_PORT}; refusing to run the ${POSTURE_NAME} checks against it"
  exit 1
fi

rm -f test/.dev-secret.json

# --- Boot this posture's worker --------------------------------------------

var_args=()
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  name="${pair%%=*}"
  value="${pair#*=}"
  # A literal `-` omits the variable entirely, so a posture can leave a var
  # unset rather than pass it empty (an empty ATOMS_SHARED_SECRET is a
  # different configuration from an absent one).
  [[ "$value" == "-" ]] && continue
  # Substitute $RUN_SECRET and $RUN_SECRET_NEXT. Word-boundary matters: the
  # rotated secret must not match the prefix of $RUN_SECRET_NEXT.
  value="${value//\$RUN_SECRET_NEXT/${RUN_SECRET_NEXT:-}}"
  value="${value//\$RUN_SECRET/${RUN_SECRET:-}}"
  var_args+=("--var" "${name}:${value}")
done < <(printf '%s\n' "$POSTURE_VARS")

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
    echo "${POSTURE_NAME} worker answered /healthz after ~$((i * 2))s"
    up=1
    break
  fi
  if ! kill -0 "$(cat wrangler.pid)" 2>/dev/null; then
    echo "::error::wrangler dev (${POSTURE_NAME}) exited before serving /healthz"
    cat "$log_name"
    exit 1
  fi
  sleep 2
done
if [[ "$up" != "1" ]]; then
  echo "::error::the ${POSTURE_NAME} worker never served /healthz within 120s"
  cat "$log_name"
  exit 1
fi

# --- Run the requested slice of the suite ----------------------------------

# POSTURE_STEP_ENV pairs are exported into this script's own environment
# first: the suite derives the posture's bearer from ATOMS_SHARED_SECRET /
# ATOMS_SHARED_SECRET_PREVIOUS when bearer auth is required, so those must be
# real exported env for npm test, not arguments to env.
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  export "${pair%%=*}=${pair#*=}"
done < <(printf '%s\n' "$POSTURE_STEP_ENV")

extra_env_args=()
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  extra_env_args+=("${pair%%=*}=${pair#*=}")
done < <(printf '%s\n' "$POSTURE_EXTRA_ENV")

# The suite itself may need the posture's secrets as step env (the
# bearer-required run derives the bearer from them). POSTURE_STEP_ENV pairs
# are exported here, into this invocation only.
env \
  ATOMS_BASE_URL="http://127.0.0.1:${WORKER_PORT}" \
  ATOMS_ONLY="$POSTURE_CHECKS" \
  ${extra_env_args[@]+"${extra_env_args[@]}"} \
  npm test
