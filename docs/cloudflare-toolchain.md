# The Cloudflare toolchain: deploy, runtime auth, and the bundle bridge

**Status:** normative for `atoms/cli`, `atoms/client` and `cloudflare/worker`
as of M3. Supersedes `docs/platform/api-contract.md`, which describes the
retired hosted platform — see the banner on that file.

Atoms deploys into **your** Cloudflare account. There is no Atoms-hosted
service in any path described here, and Atoms never proxies or retains your
credentials.

This document records three decisions that M3 had to make and that everything
downstream inherits. Each is written with its rejected alternatives, because
the alternatives are all reasonable and will otherwise be re-proposed.

## 1. Runtime auth: the client moved, and auth-off is a real posture

### The disagreement

`atoms/client` used to build

```
POST {baseUrl}/v1/{customer}/invoke/{type}/{id}/{method}
Authorization: Bearer {apiKey}
```

The Worker serves

```
POST {baseUrl}/invoke/{type}/{id}/{method}
Authorization: Bearer {ATOMS_APP_KEY}   # only when that var is set
```

### The decision

**The client moved.** The `/v1/{customer}` prefix is gone from
`AtomsClient`, `AtomsClient::destroy()` and `TicketClient`, and
`AtomsConfig::$customer` is deleted.

The prefix existed to route a multi-tenant edge to one customer's Machines. The
Worker is single-tenant by construction: it *is* the customer's deployment,
running in the customer's account, and there is no second tenant for a prefix
to disambiguate. Keeping the prefix would have meant the Worker growing a route
segment whose only possible value is a constant.

Moving the client is also the cheaper edit in the sense that matters:
`atoms/client` is **not** the frozen ABI. Only `atoms/core` is. Changing the
client's URL shape breaks no wire contract, because the wire contract it was
implementing no longer has an implementation.

### Auth-off is deliberate, so the client tolerates it — but only explicitly

The Worker disables its bearer check entirely when `ATOMS_APP_KEY` is unset or
empty (`worker/src/index.js::checkAuth`). That is not an oversight. It is the
local-dev default under `wrangler dev`, and a self-hoster may legitimately put
Cloudflare Access or mTLS in front of the Worker instead of a shared bearer
key. A client that *required* a key would make both of those unusable.

So `AtomsConfig::$apiKey` is nullable, with three states and no fourth:

| `apiKey` | Meaning | Behaviour |
|---|---|---|
| a string | Authenticated | Sends `Authorization: Bearer {key}` |
| `null` | **Explicitly** unauthenticated | Sends no `Authorization` header |
| `''` | Configuration error | Throws at construction |

The empty string is the case worth being strict about. `ATOMS_APP_KEY` or
`ATOMS_API_KEY` resolving to empty — an unset CI secret, a typo'd variable
name — would otherwise produce `Authorization: Bearer ` on every request:
accepted by an auth-off Worker, rejected confusingly by a real one, and in
neither case what the operator believed was deployed. Failing at construction
turns a silent misconfiguration into a startup error.

`null` has to be typed out. It cannot be arrived at by accident.

## 2. How a PHP CLI drives Wrangler: a pinned local binary, never `npx`

Wrangler is a Node program; the CLI is PHP. This decision shapes `deploy`,
`dev`, `status`, `rollback` and `secrets` alike, which is why it is made once
here rather than five times by accident.

### The decision

`atoms/cli` **executes a Wrangler that already exists on the machine.** It
never installs one. Resolution order, most explicit first
(`Atoms\Cli\Cloudflare\WranglerBinary`):

1. `$ATOMS_WRANGLER_BIN` — an absolute path, for unusual layouts and CI images.
2. `{worker_dir}/node_modules/.bin/wrangler` — the pinned install. Normal.
3. `wrangler` on `PATH` — a global install. Honoured, but unpinned.

Nothing found ⇒ **ATOMS-E073**, whose fix line says to run `npm ci` in the
Worker directory.

### Why not `npx wrangler`

`npx` would have been one line and no error code. It also downloads whatever
version the registry serves *at deploy time*: it defeats the pin in the Worker
project's `package-lock.json`, makes deploying depend on npm being reachable,
and lets two machines deploy the same tree with two different toolchains. A
deploy is the last place to discover that the toolchain moved. A missing
Wrangler is a setup problem with a one-line fix, and saying so is better than
papering over it.

### The Worker project directory

Wrangler needs a project to run in: a wrangler config, `src/`, and
`node_modules`. That directory is the Atoms Cloudflare Worker
(`cloudflare/worker` in this repository). The CLI locates it from, in order:
`--worker-dir`, `environments.<env>.worker_dir` in `atoms.json`, or the default
`.atoms/worker`. An unusable directory is **ATOMS-E076**.

**What remains manual, and why.** Getting that directory onto a user's machine
is not automated in M3. The intended answer is
`npm install @atomsphp/runtime-cloudflare`, and that package cannot exist until
the repository is public and published — which is M7, deliberately
(`atoms-cloudflare-oss-plan-2026-08-05.md` §4, M0/M7). Until then a user
vendors or clones the Worker tree and points `worker_dir` at it. This is
recorded as a known gap rather than hidden behind a scaffolding command that
would have to be replaced.

### Credentials

`CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` — Wrangler's own variable
names, chosen deliberately so that Atoms is visibly a *caller* of the user's
toolchain rather than a broker sitting between them and Cloudflare. They are
read from the environment (the account id may also come from `atoms.json`) and
travel exactly one way: into the Wrangler child process's environment
(`CloudflareTarget::credentialEnv()`). They are never written to a file, never
logged, never echoed, and never sent anywhere but Cloudflare's own API by way
of Wrangler.

The one echo in the whole toolchain is `action/action.yml`'s first step, which
runs `echo "::add-mask::$CLOUDFLARE_API_TOKEN"`. That is GitHub's documented
way to register a value for redaction and the workflow command is read off
stdout, so there is no non-echoing form of it. It is there because an action
input is *not* masked automatically — only values that came from `secrets.*`
are, and a caller may pass this one from a variable or a literal. Registering
it means a later step that prints it by accident gets `***`. The exception
exists to keep the rule true in a log, not to weaken it.

**There is no `--api-token` option, deliberately.** A credential passed as a
command-line argument sits in the CLI's own argv, readable by every other
process on the machine, and usually in shell history too — which would make the
sentence above false at the very first hop. The environment is the only inlet.

`atoms dev` requires neither: `wrangler dev` runs workerd locally, so a
developer with no Cloudflare account can still work.

### Deploying does not mean deployed

Cloudflare propagates a new Worker version and its variables **eventually**.
Measured on a real account: immediately after a successful `wrangler deploy`,
`/healthz` reached the new Worker while the first Atom invocation still 404'd,
and a conformance run against the same URL passed 1/12, then 7/12, then 12/12
as propagation completed. Secret rotation splits the same way — a freshly
addressed Atom saw the new value while an already-warm one still read the old.

Two consequences the commands cannot paper over. Ordering a monolith deploy
immediately after `atoms deploy` can have the monolith calling methods the
serving bundle does not have yet; and a rotated credential is not in force for
Atoms that are already resident. `atoms deploy` and `atoms secrets:set` say so
on completion rather than reporting a success that overstates what happened.
Neither waits for convergence: there is no readiness signal to wait on, and
inventing a poll loop would assert something Cloudflare does not promise.

Propagation runs in both directions. On a real account, deleting a Worker
returned API code 10007 for a lookup while `/healthz` on the same hostname kept
answering 200 for roughly ten more seconds.

**`atoms rollback` moves the version, not the bundle.** A `wrangler secret put`
mints a Worker version just as a deploy does, so on a Worker whose last two
versions are one deploy and one secret rotation, a bare rollback selects the
rotation and the running code does not change — observed on a real account. It
reads like a state operation and is a code-version operation; Wrangler's own
warning that bound resources such as the Durable Object do not roll back points
at the same distinction. The command says so on success.

### Secrets carry a prefix, because the Worker's allowlist does

`atoms secrets:set PAYMENTS_API_KEY` stores a Worker secret named
**`ATOMS_CONFIG_PAYMENTS_API_KEY`**.

This is not decoration. `$this->config('PAYMENTS_API_KEY')` inside an Atom
resolves through the host's allowlist in `worker/src/bridge.js`:

```js
const normalized = configEnvPrefix + key.toUpperCase().replace(/[^A-Z0-9]+/g, '_');
```

A secret stored under its bare name is accepted by Cloudflare and then reads
back as `null` — silently, because `config.get` answers `null` for unknown keys
rather than erroring. `Atoms\Cli\Cloudflare\SecretName` mirrors the
transformation above.

**The prefix is read, not assumed.** `configEnvPrefix` defaults to
`ATOMS_CONFIG_` but comes from `ATOMS_CONFIG_ENV_PREFIX`, which a deployment can
override in `wrangler.jsonc`'s `vars` — as can the two companion variables
`ATOMS_CONFIG_ENV_KEYS` (extra exact names) and `ATOMS_CONFIG_ENV_DENY_KEYS`
(names never readable). A CLI that hardcoded the default would write, under any
override, a name the Worker never looks up, and nothing anywhere would say so.

So `Atoms\Cli\Cloudflare\WorkerConfig` reads all three out of the Worker
project the deploy is going to use. Only top-level `vars` are read; Wrangler's
per-environment `env.<name>.vars` sections are ignored on purpose, because
`atoms deploy` selects the Worker with `--name` and never passes `-e`, so those
sections do not apply to what it deploys. Parsing goes through
`colinodell/json5`, because Wrangler's config is JSON with comments and trailing
commas and a hand-written stripper that is subtly wrong reintroduces the very
bug this removes.

**Known gap: JSON5 is a superset of what Wrangler parses.** Wrangler accepts
JSON plus comments and trailing commas. JSON5 accepts all of that *and*
unquoted keys, single-quoted strings, hex numbers, leading `+`, and more. So
the CLI reads configurations Wrangler will reject — this file parses here and
dies at `wrangler deploy` with `InvalidSymbol` on the unquoted `name`:

```json5
{ name: 'worker', vars: { ATOMS_CONFIG_ENV_PREFIX: 'JSON5_', VALUE: 0x10 } }
```

Most of the time that is harmless, because a deploy follows and fails loudly
within seconds. The case that is *not* harmless is `secrets:set` against a
Worker that is **already running**, whose `wrangler.jsonc` has since been
edited into JSON5-only syntax. The CLI resolves a prefix from a file the live
Worker was never deployed from, reports success, and stores a name that Worker
does not resolve — so `$this->config('KEY')` reads back `null`, silently. That
is precisely the failure ATOMS-E077 and the whole "read the prefix, don't
assume it" design exist to prevent, arriving through the parser instead.

It is accepted rather than fixed because the fix is worse. No parser matching
Wrangler's exact grammar exists for PHP, so closing it means hand-writing one —
which is what produced the comment-stripper that silently rewrote string
*values* while parsing cleanly, the bug this dependency replaced. Trading a
loud, immediate failure for a fresh way to be quietly wrong is the wrong
direction. The right long-term fix is to ask Wrangler to resolve its own
config and read the answer, rather than re-implementing its parser; that is a
larger change than the gap warrants today. Until then, the mitigation is the
one already in place: `secrets:set` prints the prefix it resolved and the file
it came from, so a stale or unparsed-as-Wrangler-would config is visible in the
output rather than something to take on trust.

**This is better than assuming, and it is not authoritative.** The Worker's real
`env` is whatever the last deploy of that Worker name established, which a
working-tree file cannot see: the prefix could have been set as a secret rather
than a var, or the live Worker deployed from another branch, another machine, or
with `wrangler deploy -e`. So `atoms secrets:set` prints the prefix it resolved
and the file it came from, making a mismatch visible rather than something to
take on trust.

A config file that is absent falls back to the documented defaults, the same
fallback the Worker makes for an absent variable. A config file that exists but
will not parse is an **error**, not a fallback — defaulting there would be
indistinguishable from "no override configured", which is exactly the silent
failure being removed.

This also lets both commands be honest about names that can never work.
`atoms secrets:set` refuses with **ATOMS-E077** when the resolved name is on the
deny list; when it is one of the three variables that configure the allowlist
itself (`atoms secrets:set ENV_PREFIX` would not store a value, it would change
how every other key resolves); or when the key contains non-ASCII characters,
because PHP uppercases byte-wise and the Worker's JavaScript uppercases by
Unicode — `straße` becomes `STRA_E` here and `STRASSE` there, so the name
written would not be the name read.

`atoms secrets:list` classifies against the same config, and requires a name to
round-trip through the transform rather than merely carry the prefix: no key
normalizes onto `ATOMS_CONFIG_foo` or `ATOMS_CONFIG_A__B`, so the Worker
resolves both to null and neither is reported readable.

Both commands therefore require a usable Worker directory (E076), as `deploy`
already did. Reporting readability without reading the config would mean
guessing, and guessing is the defect this replaced.

## 3. The bundle: two formats, one translator

### The decision

**Neither format moves. The missing piece was the translation, and that is what
M3 built.**

- `atoms build` keeps emitting `bundle-{sha256}.tar.gz` + schema-1
  `manifest.json`. This is the **portable** artifact: content-addressed,
  byte-reproducible, archivable, signable, and produced without executing
  customer code. It carries the customer's app and nothing else.
- The Worker keeps loading `src/bundle.generated.js`, an ES module exporting
  `{manifest, files}` at `bundle_format: 0`. This is the **deploy** artifact.
  It has to be a JS module because the Worker script is what `wrangler deploy`
  uploads, and it has to carry the `Atoms\Cf` runtime prelude and the vendored
  `atoms/core` sources — neither of which `atoms build` has any business
  knowing about.
- `cloudflare/worker/scripts/bundle-from-cli.mjs` reads the first and emits the
  second. `atoms deploy` runs it (`Atoms\Cli\Cloudflare\BundleStager`) before
  invoking `wrangler deploy`.

### Why not make one side adopt the other

*Teaching `atoms build` to emit the Worker module* would put the runtime
prelude and the vendored core inside a PHP package — a second copy of files
that version with the Worker, and the first thing to drift. It would also make
the portable artifact un-portable: a JS module is not something you archive,
diff or re-verify.

*Teaching the Worker to load the tar.gz at runtime* would mean gunzipping and
untarring inside the isolate on every cold start, against a cold-start budget
already measured at ~740ms.

*A third format replacing both* costs two migrations and buys nothing that the
translator does not already give.

The translator lives in the Worker tree, not the CLI, for the same reason: it
needs `php/runtime/` and `php/atoms-core/`, which are the Worker's own.

### What this bought

**Nothing under `cloudflare/worker/src/**` or `cloudflare/worker/php/**`
changed.** The emitted module is exactly the shape the host already reads, so
the twelve-check conformance suite is untouched by the CLI integration and the
vendored `atoms/core` copy needed no re-vendor on this account.

`build-bundle.mjs` stays, with its scope corrected: it is the conformance
fixture builder, not — as its header used to say — a stand-in for the real
`atoms build`.

### Two additive manifest fields

The Worker needs two things the schema-1 manifest did not record. Both are
additive; `schema` stays `1`.

- **`atoms[].file`** — the bundle-relative path of the file declaring the Atom
  class. `bootstrap.php` must `require_once` exactly this file. Re-deriving it
  by scanning the tarball for a class declaration would duplicate, in another
  language, work the build already did correctly.
- **`atoms[].migrations.files[].path`** — the bundle-relative path of each
  migration. `MigrationEntry::$name` is the *descriptive* part only
  (`MigrationSet` parses `NNN_name.sql` and keeps `name`), so the filename is
  not reconstructable from the manifest at all.

`tests/Integration/BundleBridgeTest.php` runs a real build through the real
translator and is the test that catches either half drifting away from it.

## What `atoms deploy` does, end to end

```
atoms deploy --env production
```

1. Load `atoms.json`; resolve the environment.
2. Resolve the Cloudflare target: worker name, account id, API token, worker
   directory. Missing credentials fail here — E072 (token), E075 (account) —
   before anything runs.
3. `atoms build` → `.atoms/build/bundle-{sha}.tar.gz` + `manifest.json`.
   Deterministic; executes no customer code. (`--bundle` skips this and deploys
   a prebuilt one.)
4. Verify the Worker directory (E076), then run
   `node scripts/bundle-from-cli.mjs <bundle> <manifest> src/bundle.generated.js`
   inside it. The translator refuses a manifest paired with the wrong bundle,
   and refuses a manifest naming a file the bundle does not contain.
5. `wrangler deploy --name {worker}` in that directory, with the credentials in
   its environment and Wrangler's own output passed through unedited.
6. Non-zero exit ⇒ **ATOMS-E074**, with Wrangler's diagnosis already printed.

Nothing in this sequence contacts a service operated by Atoms.

## Known gaps at M3

- **The Worker project is placed by hand** (see §2). `@atomsphp/runtime-cloudflare`
  needs M7.
- **`atoms dev`'s callback URL is plumbed, not wired.** `--callback-url`
  reaches the Worker as an `ATOMS_CALLBACK_URL` var and the Worker ignores it:
  the monolith half of the callback channel is real (`CallbackKernel` verifies
  Ed25519-signed callbacks today), but the Worker half is `Atom::app()`, which
  throws `AtomsNotSupported` by design until M2. `atoms dev` says so at
  startup.
- **`AtomsClient::destroy()` has no Worker route.** It targets
  `DELETE {baseUrl}/atoms/{type}/{id}`, which the Worker answers `not_found`.
  The URL shape is settled; the route is not implemented.
- **A real `wrangler deploy` is unproven in CI**, and deliberately: no job in
  this repository may need a Cloudflare account or an API token.
