#!/usr/bin/env bash
set -euo pipefail
root=$GITHUB_WORKSPACE/bench
mkdir -p "$root/report" "$RUNNER_TEMP/browser"
cd "$GITHUB_WORKSPACE/demo"
cp .env.example .env
composer install --prefer-dist --no-interaction --no-progress
test -x vendor/bin/atoms
php artisan key:generate --force
npm ci --no-audit --no-fund
npm run build
cd "$root"
npm install --prefix "$root" --no-save --no-audit --no-fund --ignore-scripts playwright@1.65.1
curl --fail --location --retry 3 --silent --show-error 'https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/linux64/chrome-linux64.zip' --output "$RUNNER_TEMP/browser.zip"
unzip -q "$RUNNER_TEMP/browser.zip" -d "$RUNNER_TEMP/browser"
sudo mkdir -p /home/dabernathy/.local/bin
sudo ln -s "$RUNNER_TEMP/browser/chrome-linux64/chrome" /home/dabernathy/.local/bin/chromium
/home/dabernathy/.local/bin/chromium --version | tee "$root/report/browser.txt"
php -r 'echo PHP_VERSION, "\n";' > "$root/report/php.txt"
node --version > "$root/report/node.txt"
node -p "require('playwright/package.json').version" > "$root/report/playwright.txt"
git -C "$GITHUB_WORKSPACE/demo" rev-parse HEAD > "$root/report/demo.txt"
curl --fail --silent --max-time 3 --noproxy '*' -H 'Metadata: true' 'http://169.254.169.254/metadata/instance/compute/location?api-version=2021-02-01&format=text' > "$root/report/runner-region.txt" || true
printf '%s\n' "$RUNNER_OS / $RUNNER_ARCH / $ImageOS / $ImageVersion" > "$root/report/runner.txt"
curl --fail --location --retry 3 --silent --show-error 'https://github.com/cloudflare/cloudflared/releases/download/2026.11.2/cloudflared-linux-amd64' --output "$RUNNER_TEMP/cloudflared"
printf '%s  %s\n' '56f13335143ec0e22772963290683a84a04114a35ec64cbc794a4d39ff9bb8cf' "$RUNNER_TEMP/cloudflared" | sha256sum --check
chmod +x "$RUNNER_TEMP/cloudflared"
python3 serve.py callback > "$RUNNER_TEMP/callback-ingress.log" 2>&1 &
python3 serve.py debug > "$RUNNER_TEMP/benchmark-proxy.log" 2>&1 &
"$RUNNER_TEMP/cloudflared" tunnel --no-autoupdate --protocol http2 --url http://127.0.0.1:18001 > "$RUNNER_TEMP/cloudflared.log" 2>&1 &
for i in {1..60}; do
  if grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' "$RUNNER_TEMP/cloudflared.log" | head -1 > "$root/callback-url.txt" && test -s "$root/callback-url.txt"; then break; fi
  sleep 1
done
test -s "$root/callback-url.txt"
