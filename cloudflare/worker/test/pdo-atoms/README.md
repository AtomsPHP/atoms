# Native PDO fixture

Install the root Composer dependencies. Pass a scaffold created by `atoms-runtime-cloudflare init` with its npm dependencies installed.

```sh
composer install
node cloudflare/worker/test/pdo-atoms/run.mjs --embed /path/to/build /path/to/atoms-worker
```

To include Yii, supply its pinned checkout as a second argument. The host PHP CLI needs pdo_sqlite for the Yii control.

```sh
git clone --depth 1 --branch 2.0.53 https://github.com/yiisoft/yii2.git "$work/yii2"
node cloudflare/worker/test/pdo-atoms/run.mjs --embed /path/to/build /path/to/atoms-worker "$work/yii2"
```

The runner stages the private PHP build in a disposable copy of the installed scaffold, runs the CLI build, and starts Wrangler with a fresh SQLite database. Each run prints the guest version, Wasm and glue hashes, SQL results and transaction trace. The [recorded control runs](results.json) are from frozen #228.

Yii remains an experimental dependency of this fixture. A native SQLite control executes the same Yii case and identifies the upstream files it loaded. The runner checks the exact Yii source commit, copies those files and the license to a local Composer path package, and gives this build a private copy of the CLI package allowlist containing Yii. Composer resolves that package offline and the project builder includes it in the portable bundle.

The disposable scaffold wraps the real `transactionSync()` callback, the guest's resume calls and the SQL bridge to record execution order. The rollback assertion requires the insert and both Atom API reads inside that callback, then a resume and another read after workerd rolls it back. The fixture keeps a PDO handle on each of two Atom instances and alternates requests between them. The optional Yii probe repeats those checks with a saved Yii Connection, compares both PDO object identities, and reads the values through both APIs. A retained statement must discard its unread rows, then execute again on the next request. Its saved lazy row can see a new row after a fresh fetch. A saved statement retains its column count after turn-end close and reports the added column when it runs again after a schema change. The fixture also covers dropping an unrelated connection, the owner's destructor, and reuse of the owner after turn-end rollback.

The fixture opens connections with named optional arguments, including the static PDO factory on PHP 8.4 and 8.5. Invalid SQL and a duplicate key exercise silent, warning and exception modes. The checks compare connection and statement diagnostics, count warnings, and verify that successful operations clear the previous error.

The native driver runs the strict and weak quoting cases from the managed PDO comparison. The probe executes each returned literal in a SQLite SELECT, checks the exception for embedded NUL and inspects the connection error after a successful quote.
