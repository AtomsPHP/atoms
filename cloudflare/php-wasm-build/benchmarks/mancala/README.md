# Local PHP interpreter comparison

This run compares stack #228 at `321a2906` with the release packages from `c537da3ac75dd55c31eb911910ae7c37835111b4`. Both ran the Mancala application at `baadd7dc3ef0c6f7e32321cc5d00b5627bb63cee` on the same Intel(R) N150 host. The browser used the default PHP 8.3.32 selection. [#228 control](https://github.com/AtomsPHP/atoms/commit/321a2906fd7d250edd20fea26a1d14fd7ea77d74) · [Mancala source](https://github.com/AtomsPHP/laravel-mancala-demo/commit/baadd7dc3ef0c6f7e32321cc5d00b5627bb63cee)

On this host, the median PHP 8.3 browser return after eviction went from 924.0 to 924.5 ms (+0.1%). The p95 went from 937.0 to 938.0 ms. The three candidate interpreter tarballs totaled 554,987,176 bytes, an increase of 15,505,345 bytes (+2.9%).

PHP booted faster and used less peak Wasm memory in the direct-request measurements on all three minors. PHP 8.4 had the largest increase in median cold HTTP latency, from 353.4 to 406.8 ms (+15.1%).

## PHP 8.3 browser and invocation timings

| 20 samples per runtime (ms) | #228 median | Candidate median | Change | #228 p95 | Candidate p95 | Change |
|---|---:|---:|---:|---:|---:|---:|
| Return after verified eviction | 924.0 | 924.5 | +0.1% | 937.0 | 938.0 | +0.1% |
| Immediate return to a newly joined room | 232.5 | 233.5 | +0.4% | 257.0 | 249.0 | -3.1% |
| Warm HTTP snapshot immediately after the evicted return | 13.0 | 13.4 | +2.6% | 44.0 | 16.8 | -61.9% |
| PHP boot through the first parked turn in the evicted return | 265.5 | 251.5 | -5.3% | 297.0 | 273.0 | -8.1% |
| Create click through both players ready | 2,228.5 | 1,897.0 | -14.9% | 3,252.0 | 2,485.0 | -23.6% |

The same 20 rooms and player sessions crossed runtimes. The median paired difference in evicted browser latency was +2.5 ms. The control-first half measured -0.5 ms and the candidate-first half measured +3.0 ms. Each measured return increased the Durable Object construction count. The harness waited 30 seconds after reading all construction counts, then visited the rooms sequentially. Every room was idle for at least 30 seconds. Each fresh sample used a new game on the measured runtime. Immediate returns kept the second player connected and retained the same construction count.

## Direct requests to the same rooms

For each minor and runtime, the harness warmed all 20 rooms, idled them for 30 seconds, then timed an authenticated HTTP `snapshot` and an immediate second `snapshot`. The Worker’s `php_boot_ms` spans activation through the first parked turn. The memory column is the largest observed Wasm linear memory after either turn. Browser and HTTP measurements use the client clock.

| PHP, #228 → candidate | PHP boot median (ms) | PHP boot p95 | Cold HTTP median (ms) | Cold HTTP p95 | Warm HTTP median (ms) | Warm HTTP p95 | Peak memory (MiB) |
|---|---:|---:|---:|---:|---:|---:|---:|
| 8.3 | 271.5 → 248.5 (-8.5%) | 306.0 → 268.0 (-12.4%) | 347.0 → 354.8 (+2.3%) | 384.6 → 373.7 (-2.8%) | 12.1 → 12.2 (+1.1%) | 19.5 → 15.1 (-22.8%) | 64 → 43.125 |
| 8.4 | 273.5 → 236.0 (-13.7%) | 311.0 → 250.0 (-19.6%) | 353.4 → 406.8 (+15.1%) | 390.9 → 425.5 (+8.9%) | 12.6 → 14.0 (+11.0%) | 18.9 → 44.5 (+135.2%) | 64 → 45.625 |
| 8.5 | 284.0 → 255.0 (-10.2%) | 322.0 → 294.0 (-8.7%) | 370.4 → 373.6 (+0.9%) | 402.2 → 416.7 (+3.6%) | 12.6 → 13.4 (+6.5%) | 13.7 → 20.4 (+48.6%) | 64 → 49.25 |

## Package, installation and deployment sizes

| PHP, #228 → candidate | Wasm (B) | JavaScript (B) | npm packed (B) | npm unpacked (B) | Wrangler total (KiB) | Wrangler gzip (KiB) |
|---|---:|---:|---:|---:|---:|---:|
| 8.3 | 19,255,054 → 28,184,480 | 206,822 → 234,013 | 177,046,397 → 182,123,938 | 189,218,105 → 201,509,544 | 27,739.15 → 36,146.13 | 8,672.77 → 10,315.79 |
| 8.4 | 20,937,750 → 30,457,958 | 209,034 → 235,056 | 180,574,046 → 185,769,711 | 193,797,594 → 206,676,363 | 29,385.13 → 38,367.58 | 9,306.06 → 11,066.27 |
| 8.5 | 22,017,641 → 31,741,719 | 209,363 → 234,426 | 181,861,388 → 187,093,527 | 195,962,920 → 209,044,380 | 30,440.05 → 39,620.52 | 9,509.04 → 11,308.16 |

| Local bytes | #228 | Candidate | Difference | Change |
|---|---:|---:|---:|---:|
| Download of all three tarballs | 539,481,831 | 554,987,176 | +15,505,345 | +2.9% |
| All three npm packages unpacked | 578,978,619 | 617,230,287 | +38,251,668 | +6.6% |
| Installed Worker node_modules | 1,014,059,888 | 1,050,088,006 | +36,028,118 | +3.6% |
| Staged interpreter pairs and imports | 62,836,144 | 91,088,735 | +28,252,591 | +45.0% |

Wrangler reported these gzip sizes during a dry-run of the same customer bundle. Installed byte counts sum regular file lengths on Linux x86-64. The staged rows count the additional copies used by Wrangler. Package installation includes all three minors. A deployment includes one.

The largest candidate upload was 38.69 MiB uncompressed. Cloudflare lists a 64 MiB uncompressed Worker size limit on Free and Paid plans. [Cloudflare Worker size limits](https://developers.cloudflare.com/workers/platform/limits/#worker-size)

To repeat the upload measurements, set the demo’s `atoms.json` PHP series and run `atoms build --out BUILD`. Pass its archive and manifest to `atoms deploy --env staging --bundle ARCHIVE --manifest BUILD/manifest.json --dry-run --outdir OUTPUT`. Record Wrangler’s `Total Upload` and gzip figures and compare the emitted Wasm digest with the selected release pin.

## Reproduction

The Mancala application and its PHP and frontend dependency locks came from the commit above. The two runs used PHP 8.5.4, Node 26.7.0, npm 11.19.0, Wrangler 4.165.0, workerd 1.20261118.1, Playwright 1.65.1 and Google Chrome for Testing 151.0.7922.34. The browser viewport was 1280 × 720 with reduced motion. This measurement covers a local Linux host. Deployed regional timings remain unmeasured.

In each Atoms checkout, download that revision’s three interpreter artifacts and run `node cloudflare/php-wasm-build/artifacts.mjs seed ARTIFACT_DIRECTORY`, then `npm run package:runtime --prefix cloudflare/worker`. Keep the control and candidate scaffold tarballs in separate directories. Both use the coordinated 0.11.0 version on this stack. [Candidate build artifacts](https://github.com/AtomsPHP/atoms/actions/runs/46111849628)

Use a disposable copy of the demo. Install its locked Composer and frontend dependencies, build the frontend assets, generate a local app key and shared secret, and migrate its SQLite database. Use the public scaffold installer to upgrade `atoms-worker` to each of the two exact tarballs, followed by `npm ci`. Preserve the customer configuration, browser sessions and local Wrangler SQLite directory across switches.

Set `APP_URL=http://127.0.0.1:18000`, `ATOMS_ENDPOINT=http://127.0.0.1:18787` and `MANCALA_TICKET_MINTS_PER_MINUTE=1000`. Start Laravel at port 18000 and use the matching 0.11 CLI to run `atoms dev --env staging --port 18787 --callback-url http://127.0.0.1:18000/atoms/callback`. Set `ATOMS_BENCH_CLI` to that CLI and `CHROMIUM` to the Chromium executable. Set `NODE_PATH` to a directory containing Playwright 1.65.1, or install that version alongside the harness. Run the following harness commands from the demo root, preserving the same private state directory.

| Runtime and PHP selection | Harness arguments after `node mancala.cjs` |
|---|---|
| Control 8.3 | `prepare STATE prepared.json 0 20`, `cold STATE a1.json 0 10`, `fresh STATE af1.json 0 10` |
| Candidate 8.3 | `cold STATE b2.json 10 10`, `cold STATE b1.json 0 10`, `fresh STATE bf.json 0 20`, `direct STATE bd83.json 0 20` |
| Candidate 8.4, then 8.5 | `direct STATE RESULTS.json 0 20` once per selection |
| Control 8.3 | `cold STATE a2.json 10 10`, `fresh STATE af2.json 10 10`, `direct STATE ad83.json 0 20` |
| Control 8.5, then 8.4 | `direct STATE RESULTS.json 0 20` once per selection |

Browser returns are timed from navigation start until Player 1 can move, followed by two animation frames. Fresh-room creation starts at the create-button click and includes the second player joining. Medians average the middle two values. A positive timing change means the candidate took longer. P95 uses nearest rank. The raw file contains every sample, construction count, local Durable Object ID, package size and run order. [Raw measurements](samples.json) · [Browser and HTTP harness](mancala.cjs)
