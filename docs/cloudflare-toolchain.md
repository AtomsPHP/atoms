# The Cloudflare toolchain: deploy, runtime auth, and the bundle bridge

**Status:** normative for `atoms/cli`, `atoms/client` and `cloudflare/worker`.

Atoms deploys into **your** Cloudflare account. There is no Atoms-hosted
service in any path described here, and Atoms never proxies or retains your
credentials.

This document records three decisions that everything
downstream inherits. Each is written with its rejected alternatives, because
the alternatives are all reasonable and will otherwise be re-proposed.

## 1. Runtime auth: prefixless routes, and the bearer is derived

### The route shape

`atoms/client` and the Worker meet at

```
POST {baseUrl}/invoke/{type}/{id}/{method}
Authorization: Bearer $(atoms token)
```

`atoms token` prints the bearer derived from `ATOMS_SHARED_SECRET` — see
"Bearer auth is mandatory" below and `docs/shared-secret.md`, the decision
record for the whole boundary. `/invoke` is the only route the client calls;
WebSocket tickets are issued locally, without an HTTP hop.

### Why no tenant prefix in the routes

**Rejected: a customer-prefixed route.** A prefix routes a multi-tenant edge
to one tenant's compute. The Worker is single-tenant by construction: it *is*
the customer's deployment, running in the customer's account, and there is no
second tenant for a prefix to disambiguate. Carrying one would mean a route
segment whose only possible value is a constant, and `AtomsConfig` holding a
`customer` it never varies.

The client is the right side to carry that shape, because `atoms/client` is
**not** the frozen API — only `atoms/core` is — so its URL shape is free to
match whatever the Worker actually serves.

### Bearer auth is mandatory; `ATOMS_BEARER_AUTH` is the explicit posture switch

`ATOMS_SHARED_SECRET` — 32 random bytes, base64, identical on the monolith and
the Worker — is the one root every key on this boundary derives from
(`worker/src/config.js`, `Atoms\Client\Crypto\KeyDerivation`). It is
mandatory: callbacks cannot be signed without it, so its presence and shape
are checked independently of the auth posture below. If `ATOMS_SHARED_SECRET`
is missing, or does not decode to exactly 32 bytes of base64, every route
except `GET /healthz` answers `misconfigured` (HTTP 500, `retryable: false`)
— a misconfigured Worker is loudly broken, never silently open.

`ATOMS_BEARER_AUTH` is the explicit posture switch: `required` (the default)
or `disabled`. Anything else is treated as `required` — a typo fails closed.
`disabled` exists for exactly one posture: an authenticating proxy such as
Cloudflare Access in front of the Worker. It turns off the bearer *comparison*
only — the secret stays mandatory, and tickets and callbacks stay signed.

`AtomsConfig::$sharedSecret` (`packages/client/src/AtomsConfig.php`) is a
required string, validated at construction: trimmed of ASCII whitespace,
strict base64, exactly 32 decoded bytes, or the constructor throws
(`ATOMS-E105`). There is no unauthenticated posture to express on the client
— a Worker running `ATOMS_BEARER_AUTH=disabled` still receives the bearer on
every call; it just does not check it. The client derives `Authorization:
Bearer {bearer}` from the secret (`KeyDerivation::bearerToken()`) and never
sends the secret itself. `atoms token` prints the same value for a human
running curl.

The full derivation, the reference vector, and why the secret must never
travel are recorded once, normatively, in `docs/shared-secret.md`.

### The bearer is the server-to-server credential; browsers use tickets

A browser's `new WebSocket(url)` cannot set an `Authorization` header, and the
bearer check covers every other route. The application's server calls
`Atoms\Client\Tickets\TicketIssuer::issue($type, $id, $claims)` and hands the
short-TTL, atom-scoped ticket it returns to the browser, which presents it as
`?ticket=` on the `/ws` upgrade. There is no HTTP call in `issue()` — it is
pure local computation, because the application already holds
`ATOMS_SHARED_SECRET` and can derive the ticket-signing key itself, so asking
the Worker to sign on the application's behalf would have been a network hop
to compute something already computable locally. Claims passed to `issue()`
merge over the browser's query params on connect (server wins), so
`onConnect` code reading `$params['client_id']` gets a host-asserted value
the browser cannot forge.

`issue()` always produces the signed `v1.` form — there is no unsigned form
and nothing gated on `ATOMS_BEARER_AUTH`, since minting no longer goes
through the Worker at all — and `/ws` always verifies the signature, so
browser code paths are identical in local dev and production. The signing
key is HKDF-derived from the decoded `ATOMS_SHARED_SECRET` (info
`atoms/ws-ticket/v1`); rotating the secret now overlaps tickets along with
the bearer and callback keys, via `ATOMS_SHARED_SECRET_PREVIOUS`, rather
than invalidating every outstanding ticket at once. The binding details —
wire format, limits, the expiry rule, the reusable-until-expiry contract, and
the rotation decision — are normative in `docs/ws-ticket-protocol.md`; the
Worker's observable verification behaviour is spec'd in
`cloudflare/docs/runtime-spec.md` §Routing and auth; the key derivation itself is
`docs/shared-secret.md`.

## 2. How a PHP CLI drives Wrangler: a pinned local binary, never `npx`

Wrangler is a Node program; the CLI is PHP. This decision shapes `deploy`,
`dev`, `status`, `rollback` and `secrets` alike, which is why it is made once
here rather than five times by accident.

### The decision

`atoms/cli` **executes a Wrangler that already exists on the machine.** It
never installs one. Resolution order, most explicit first
(`Atoms\Cli\Cloudflare\WranglerBinary`):

1. `$ATOMS_WRANGLER_BIN` — an absolute path, for unusual layouts and CI images.
2. `{worker dir}/node_modules/.bin/wrangler` — the pinned install. Normal.
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

### The Worker directory

Wrangler needs a project to run in: a wrangler config, `src/`, and
`node_modules`. That directory is the Atoms Cloudflare Worker
(`cloudflare/worker` in this repository), scaffolded into your application as
**`atoms-worker/`, beside `atoms.json`, and committed.** The CLI looks there
unless you pass `--worker-dir`; the deploy Action looks there unless you set
`worker-directory`. An unusable directory is **ATOMS-E076**.

**It is committed, deliberately.** Earlier releases scaffolded it into a
gitignored `.atoms/worker`, named per environment by `worker_dir` in
`atoms.json`, and the deploy Action re-scaffolded it on every fresh checkout.
That made every edit to its `wrangler.jsonc` non-durable — which is why
settings such as `debug_endpoints` had to be forwarded from `atoms.json` as
`--var`s — and it let two environments deploy two different runtimes. Now
there is one directory, it is part of the repository like `composer.lock`,
and its `wrangler.jsonc` is yours to edit. `atoms.json` does not name it; a
`worker_dir` key is an ordinary unknown key, tolerated like any other.

**Rejected: a top-level `worker_dir` in atoms.json.** The location is a
convention, like `atoms.json`'s own, and the per-environment form was the
indirection this change removes. A committed override would mean two places
to look, and the deploy Action would have to re-implement the CLI's
resolution in shell to know where to run `npm ci`. The rare layout that
needs another location names it explicitly, per command, with `--worker-dir`,
and in CI with the `worker-directory` input — the same override that already
existed.

**Scaffolding it.** Run the exact command printed by `atoms init`; the
version comes from the same release manifest as the CLI and the Action:

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare init atoms-worker
cd atoms-worker && npm ci
git add atoms-worker
```

The initializer refuses a non-empty directory, and the template carries its
own `package-lock.json`; `npm ci` therefore installs the audited php-wasm and
Wrangler pins rather than resolving current registry versions. This setup
command is deliberately outside the PHP CLI: `atoms` itself still never
downloads a toolchain, and the Action's whole job is to check out, run
`npm ci` in the committed directory, and deploy.

**Version skew.** The Worker directory is co-versioned with the CLI and the
Composer packages, and committing it transfers the upgrade to you. So the
directory carries a stamp, `atoms-runtime.json`, written by `init` and
`upgrade`, recording the release that scaffolded it; `atoms deploy` and
`atoms dev` compare it with the CLI's own release
(`CloudflareTarget::assertRuntimeVersion()`) before anything is built, and
refuse a mismatch with **ATOMS-E108**, naming both releases and the exact
upgrade command. A directory with no stamp — scaffolded before stamps
existed — is the same error with the version reported as unknown. Equality
is exact: every release publishes a new runtime package, and a range would
let a "close enough" runtime deploy against packages it was never tested
with. `status`, `rollback` and the secrets commands ship no code and make
no such check.

**Upgrading it.** The error's command is the upgrade:

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.5.0 -- \
  atoms-runtime-cloudflare upgrade atoms-worker
cd atoms-worker && npm ci
```

`upgrade` writes every file the release's template ships, except a
user-owned file that already exists; removes runtime-owned files the previous
release shipped and this one does not (so a renamed PHP file cannot linger
and shadow its replacement in the guest autoloader); and writes the stamp
last — so an interrupted upgrade leaves the old version in place and the CLI
still refusing to deploy the half-moved tree. Every write comes from the
template. The committed stamp is read for one thing, the previous release's
file list, and removal is confined to a plain, non-symlinked file at a
canonical relative path under the directory; the stamp cannot reach outside
it. Review the diff, commit.

**The ownership split.** Recorded in the stamp (`runtime_owned` and
`user_owned`, two file lists), documented in the directory's own
`README.md`, and stated in the header of `wrangler.jsonc` itself:

- **User-owned:** `wrangler.jsonc`. Seeded once by `init`, then left as it is.
  The keys the runtime depends on (`main`, `compatibility_date` as a floor,
  `compatibility_flags`, `rules`, the `ATOMS` Durable Object binding, the
  migration tags) are marked `RUNTIME-REQUIRED` in the file. A release that
  changes one says so in its changelog; Wrangler itself rejects a deploy
  missing a migration for a new class.
- **Runtime-owned:** everything else the template ships — `src/`, `php/`,
  `scripts/`, `release/`, `package.json`, `package-lock.json`, `.gitignore`,
  `README.md`, the licence files, and the stamp.
- **Unknown files** — anything you add — are left alone.

*Rejected: splitting `wrangler.jsonc` into a runtime part and a user part.*
Wrangler has no include mechanism, so a split would mean the CLI merging two
files into a third at deploy time, and `wrangler deploy` by hand would no
longer see the same config. *Rejected: keeping `wrangler.jsonc`
runtime-owned and forwarding every user setting from `atoms.json`.* That is
the design being replaced. *Rejected: a structural checker for the user's
`wrangler.jsonc` in `upgrade`.* It would need a JSONC parser of our own and
would check for changes no release has made yet; the changelog and Wrangler's
own validation cover the day one does. One file, owned by the user, with its
requirements marked in place, is the smallest honest shape.

**Generated outputs are gitignored.** The scaffold's `.gitignore` (runtime-
owned; shipped as `gitignore.scaffold` from this repository, distinct from
the monorepo worker's own, which commits the conformance fixture's bundle)
covers everything `deploy`, `dev` and `npm ci` write: `src/bundle.generated.js`,
`node_modules/`, `.php-wasm/`, `.dev.vars`, `.wrangler/`. A deploy never
leaves the committed directory dirty; `test/runtime-package.mjs` asserts the
list.

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
sentence above false at the very first hop. The environment is the only inlet
Atoms itself has.

**A token is not required, though — Wrangler's own login session works.**
When `CLOUDFLARE_API_TOKEN` is absent the CLI injects nothing and lets Wrangler
resolve credentials the way it does for `npx wrangler deploy`: from the OAuth
session `wrangler login` maintains. That is not a relaxation of the rule above
but the strongest possible form of it — on that path no credential passes
through the Atoms process at all, and the developer who is most Cloudflare-
native stops being the one pushed toward `export`ing a long-lived token into a
shell rc file. Atoms never stores, reads or refreshes that session; Wrangler
owns it entirely.

The consequence is that "no credentials" is Wrangler's question to answer, not
one the CLI can pre-empt: only Wrangler knows whether a login session exists.
So the CLI hands off, and reads the answer back out of the failure —
`WranglerResult::isCredentialFailure()` recognises Wrangler's own
no-credentials messages and raises **ATOMS-E072**, whose fix line names both
inlets. That match is deliberately narrow: a credential Cloudflare *rejected*
is a different failure and stays **ATOMS-E074**, whose fix line already names
the permission to check, so E072 keeps meaning what its title says. Matching
Wrangler's wording at all is a deliberate drift risk with a harmless failure
mode: unrecognised phrasing simply lands on E074, which points the reader at
the Wrangler output printed immediately above it.

Nothing about this can hang a headless run. `ProcessWrangler::childEnv()` sets
`CI=true` when the ambient environment has not, so a Wrangler with neither
token nor session errors out immediately instead of waiting on a browser
handoff it could not complete.

`CLOUDFLARE_ACCOUNT_ID` follows the same rule, for the same reason. Credentials
that reach exactly one account resolve it without being told, and how many they
reach is Wrangler's knowledge, not ours — so an absent account id is no longer a
precondition either. Where it genuinely is ambiguous, Wrangler says so and that
becomes **ATOMS-E075**, with its own listing of the accounts it can see printed
above. Setting `account_id` in `atoms.json` is still the recommendation: it
makes the deploy target explicit in the repository rather than dependent on
whose login is running, and it is the only way to be sure a multi-account
login deploys where you meant. It is simply no longer demanded up front.

Both codes now arrive from the same seam, and neither pre-empts Wrangler on a
question only Wrangler can answer. The cost is that a run missing both fails
after the build and stage rather than before them; the benefit is that it fails
only when it genuinely could not have worked.

`atoms dev` requires neither: `wrangler dev` runs workerd locally, so a
developer with no Cloudflare account can still work.

### Getting a token

The credential above has to come from somewhere, and the first encounter with
it is more often a laptop than a CI runner. `action/README.md` is written for
the runner and links here rather than restating any of this, so the two
journeys cannot drift apart.

**Create a scoped custom token.** Cloudflare dashboard → **My Profile → API
Tokens → Create Token → Create Custom Token**:

- **Workers Scripts: Edit** — required. It is what publishes the Worker, and
  it is the permission the E074 fix line names when Cloudflare rejects a
  deploy.
- **Account Settings: Read** — add it if Wrangler reports an authorisation
  failure; some account-lookup paths want it.
- Under **Account Resources**, scope the token to the **one account** you
  deploy into, rather than "All accounts".

Exactly which Cloudflare permission each Wrangler operation demands is
Cloudflare's to define and change, and this repository has no way to measure
it (§Known gaps). Start from the two above; when Wrangler reports an
authorisation failure, its error names the missing permission verbatim.

**Where the account id lives.** Cloudflare dashboard → **Workers & Pages** →
the overview page shows **Account ID** in the right-hand column. It may also
be committed, as `environments.<env>.account_id` in `atoms.json`; an explicit
value there wins over `CLOUDFLARE_ACCOUNT_ID` in the environment
(`CloudflareTarget::resolve()`).

**Which commands need it.** `deploy`, `rollback`, `status`, `secrets:list`,
`secrets:set`, `shared-secret:set` and `shared-secret:unset` contact
Cloudflare through Wrangler, which resolves credentials itself — from
`CLOUDFLARE_API_TOKEN` if set, otherwise from its own login session. A
missing or ambiguous credential surfaces as **ATOMS-E072** (no credentials)
or **ATOMS-E075** (no account id) from Wrangler's own output. (The two
commands that take a secret value read it first, so a piped value is
consumed before the deploy begins.) `build`, `init`, `token` and `dev` need
neither — among others — so the whole write-build-run loop is reachable
without a Cloudflare account at all.

**Where to keep the token on a workstation.** Roughly in order of preference:

- **A secret manager, read per command.** `CLOUDFLARE_API_TOKEN=$(op read
  op://vault/cloudflare/token) atoms deploy --env production`, or the
  equivalent for `pass`, `gopass`, Vault, or your platform keychain.
- **A per-project, gitignored `.env`**, loaded deliberately — `direnv`, or
  `set -a; . ./.env; set +a` in the shell that is about to deploy. Add it to
  `.gitignore` *before* writing the token into it.
- **A session-scoped export**, typed into the terminal doing a one-off deploy.

What the CLI does with the token is §Credentials, immediately above: it reads
the environment and places the value in the Wrangler child process's
environment. See that section for the full contract.

**Rotation.** Rotating this token takes effect for the next command you run;
there is nothing to propagate. Worker secrets set *through* it are different:
`atoms secrets:set` writes a value that resident Atoms pick up only over time
— see §Deploying does not mean deployed, and `ATOMS_SHARED_SECRET` needs a
whole rotation window (`docs/shared-secret.md` §Rotation).

### The callback channel: `ATOMS_CALLBACK_URL` and the derived signing key

`app()`/`dispatch()` need one Worker var and one Worker secret, and they
travel to the Worker by two different, deliberately asymmetric paths
(`packages/cli/src/Command/DevCommand.php`):

- **`ATOMS_CALLBACK_URL`** — not a secret, the monolith's callback endpoint.
  Both `atoms dev` and `atoms deploy` pass it to Wrangler as an ordinary
  `--var ATOMS_CALLBACK_URL:<url>`, exactly the way any other var reaches the
  Worker, and both echo the URL they wired so it is visible in the terminal
  that started the dev server or ran the deploy. `CloudflareTarget::resolve()`
  applies this precedence:
  `--callback-url` > `ATOMS_CALLBACK_URL` in the CLI's own process
  environment > `atoms.json`'s `callback_url.<env>`. The environment slot is
  the one for a value that differs per machine — a tunnel host, a local
  port — so it never has to be committed or wrapped into every invocation.
  The name is deliberately the Worker's own: setting it in the shell that
  runs `atoms dev` reads as "give the Worker this var", and one name for
  one concept beats a second `ATOMS_DEV_*` spelling. A deploy that resolves
  no URL at all still deploys — the var may be set on the Cloudflare side —
  but says so, naming `ATOMS-E080`.
- **The callback signing key** — HKDF-derived on the Worker from
  `ATOMS_SHARED_SECRET` (info `atoms/callback/v1`), not a variable of its own.
  The CLI **never** passes the secret. In production it is
  `wrangler secret put ATOMS_SHARED_SECRET`, run directly, the same as any
  other Worker secret. Locally, `atoms dev` provisions a fresh per-machine dev
  secret into the Worker project's gitignored `.dev.vars` — creating the file
  if needed, never overwriting a value already there, and warning if the file
  is not gitignored — so a developer never types the secret in by hand. The
  app's `.env` (or `.env.local` where `.env` is committed) is the source of
  truth; `.dev.vars` is a generated projection of it, rewritten when the two
  differ, and exists only because `wrangler dev` reads that file and nothing
  else. See `docs/shared-secret.md`.

**Why the CLI never carries the secret on its own argv.** Putting it on
`atoms dev`'s argv or behind a `--var` flag would place it in the process
table and in shell history — precisely what the CLI-never-holds-a-credential
rule (root `AGENTS.md`) exists to prevent. `.dev.vars` and
`wrangler secret put` are Wrangler's own delivery vehicles for a secret;
`atoms dev` writes the dev secret to the app's dotenv file and projects it
into `.dev.vars` rather than passing the value as an argument anywhere, and
`atoms shared-secret:set` pipes it to `wrangler secret put` on stdin. The CLI
otherwise only ever reaches for the one variable (`ATOMS_CALLBACK_URL`) that
is not a secret at all.

**`ATOMS_SHARED_SECRET` is not settable via `atoms secrets:set`, and that is
deliberate.** `SecretsSetCommand` always maps a name through
`SecretName::toWorker()`, which prefixes it with `ATOMS_CONFIG_`
(§"Secrets carry a prefix", above) — so `atoms secrets:set ATOMS_SHARED_SECRET`
would store `ATOMS_CONFIG_ATOMS_SHARED_SECRET`, a name nothing in the
bearer/ticket/callback paths reads — and one guest code *could* read. So the
command refuses the raw name before prefixing (`ATOMS-E077`):
`WorkerConfig::CREDENTIAL_KEYS` names `ATOMS_SHARED_SECRET` and
`ATOMS_SHARED_SECRET_PREVIOUS`, and `keyRefusalReason()` checks the input
against it ahead of any transform. A root this central does not belong behind
a command whose whole contract is prefixing keys for guest code to read.

**`atoms shared-secret:set --env X` is the CLI path instead**, in its own
namespace so that the two can never be confused: it writes the exact,
unprefixed name and nothing else, takes the value on stdin only, and
validates it as 32 bytes of base64 before sending. It is idempotent, leaving
an existing value alone unless `--force`, so a pipeline can run it on every
deploy without minting a Worker version each time; `--previous` writes
`ATOMS_SHARED_SECRET_PREVIOUS` for a rotation window. The deploy Action wraps
it (`action/README.md` §The shared secret), and `docs/shared-secret.md`
§"Setting it" is the runbook. `wrangler secret put ATOMS_SHARED_SECRET` by
hand and `atoms dev`'s dev-secret provisioning remain the other two ways in.

### Debug endpoints: off by default, enabled in atoms.json

The Worker's `GET /debug/:type/:id/info` route is a first-line diagnostic
(residency info, turn counts, boot timings, the `ws`/`timers`/callback debug
blocks) gated on the Worker's `ATOMS_DEBUG_ENDPOINTS` variable, whose default
is off (`worker/src/config.js`). The scaffolded Worker project does not set
it, so **a customer deployment ships with debug endpoints off** unless the
project turns them on.

**What the flag is, and is not.** `/debug` sits behind the Worker's auth
check like every route except `/healthz`: under the default
`ATOMS_BEARER_AUTH=required` posture — which includes local dev, since
`atoms dev` guarantees a dev secret — reaching `/debug` takes the derived
bearer whether or not the flag is set. The flag is therefore a second gate —
defense in depth over what the route reveals — not the only one. The posture
to keep in mind is the inverse one: under `ATOMS_BEARER_AUTH=disabled` — the
deployment that terminates access control in front of the Worker, Cloudflare
Access and the like — the flag is the *only* thing between whatever that
proxy admits and `/debug`. That is why it defaults off and why enabling it
is an explicit, per-environment declaration.

**How to turn it on** — one setting, in a file the user owns:

```json
"environments": {
  "staging": { "endpoint": "…", "debug_endpoints": true }
}
```

Both `atoms dev` and `atoms deploy` read it from the environment they target
and forward it to Wrangler as `--var ATOMS_DEBUG_ENDPOINTS:1`
(`CloudflareTarget::runtimeVars()`), so the two always agree on what the one
declaration means, and both print an `ATOMS_DEBUG_ENDPOINTS=1` line when it
is in force. It must be a JSON boolean; a string is refused (**ATOMS-E070**)
rather than coerced, so `"false"` can never silently enable a debug surface.

**Why atoms.json and not the Worker's wrangler.jsonc.** The Worker
directory is committed, so an edit to its `wrangler.jsonc` is durable. The
reason for forwarding is that `wrangler.jsonc` is **one file for every
environment**: the CLI selects the
Worker with `--name` and never passes Wrangler's `-e`, so a var set there
applies to staging and production alike, and this is the one setting that
must be able to differ between them. atoms.json is already where the
per-environment settings live, and `--var` is already how the callback URL
reaches `wrangler dev` — this reuses that channel on both paths. The
scaffold's `wrangler.jsonc` says so in its header, and the package test
asserts the template does not set the var. (The conformance harness in
`cloudflare/worker` is intentionally different: its own `wrangler.jsonc`
keeps the flag on because checks 5/10/12 need it, and that file is not what
customers receive — `wrangler.scaffold.jsonc` is.)

### Deploying does not mean deployed

Cloudflare propagates a new Worker version and its variables **eventually**.
Measured on a real account: immediately after a successful `wrangler deploy`,
`/healthz` reached the new Worker while the first Atom invocation still 404'd,
and a conformance run against the same URL passed 1/12, then 7/12, then 12/12
as propagation completed. Secret rotation is eventual the same way, and the
2026-08-12 deployed review found the ORDER in which warm versus freshly
addressed objects pick up a rotated secret is not guaranteed — it observed both
warm and fresh Atoms lagging, inconsistently, so neither can be assumed to see a
new value before the other.

Two consequences the commands cannot paper over. Ordering a monolith deploy
immediately after `atoms deploy` can have the monolith calling methods the
serving bundle does not have yet; and a rotated credential may not yet be in
force for any given Atom — warm or freshly addressed — until propagation
converges, so neither can be assumed to have picked it up. `atoms deploy` and
`atoms secrets:set` say so on completion rather than reporting a success that
overstates what happened.
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

**Neither format moves. The missing piece is the translation, and the CLI
supplies it.**

- `atoms build` keeps emitting `bundle-{sha256}.tar.gz` + schema-1
  `manifest.json`. This is the **portable** artifact: content-addressed,
  byte-reproducible, archivable, signable, and produced without executing
  customer code. It carries the customer's app and — since the vendor stage
  (below) — the resolved `atoms-composer.json` packages the app's Atoms use;
  nothing else.
- The Worker keeps loading `src/bundle.generated.js`, an ES module exporting
  `{manifest, files}` at `bundle_format: 0`. This is the **deploy** artifact.
  It has to be a JS module because the Worker script is what `wrangler deploy`
  uploads, and it has to carry the `Atoms\Cf` runtime and the vendored
  `atoms/core` sources — neither of which `atoms build` has any business
  knowing about.
- `cloudflare/worker/scripts/bundle-from-cli.mjs` reads the first and emits the
  second. `atoms deploy` runs it (`Atoms\Cli\Cloudflare\BundleStager`) before
  invoking `wrangler deploy`.

### Why not make one side adopt the other

*Teaching `atoms build` to emit the Worker module* would put the guest
runtime and the vendored core inside a PHP package — a second copy of files
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
the Worker conformance suite was untouched by the CLI integration (see
`cloudflare/docs/runtime-spec.md` §Conformance suite), and the vendored
`atoms/core` copy needed no re-vendor on this account.

`build-bundle.mjs` is the conformance fixture builder. It is not a stand-in
for the real `atoms build`.

### The vendor stage (2026-08-30)

A project whose Atoms use approved packages (`atoms-composer.json`, gated by
`packages/cli/resources/allowed-packages.json`) needs those packages **in the
guest**. The build's vendor stage (`Atoms\Cli\Build\VendorStage`) supplies
them:

- `composer install --no-dev --no-scripts --no-plugins` runs in an isolated
  temp directory — a build never executes customer or dependency code. A
  missing composer, an unresolvable constraint, or any other failure refuses
  loudly with **ATOMS-E079**; there is no silent fallback to an
  unshippable bundle.
- **Determinism**: the first successful resolution writes
  `atoms-composer.lock` (Composer's own lock format) back next to
  `atoms-composer.json`; committed, it pins every later resolution. The
  resolved tree is cached under `.atoms/vendor-cache/<key>` (key = sha256 of
  atoms-composer.json + lock), so repeat builds — `atoms dev`'s rebuild loop
  included — are offline and composer-free until the lock changes.
- **What ships**: every vendor `.php` file (data files like Carbon's locale
  tables are `.php` too) plus package LICENSE files, under `vendor/…` in the
  tar, and one generated `vendor/atoms-vendor-autoload.php` — a classmap +
  eager function-file loader built from Composer's own optimized autoload
  output, `__DIR__`-relative so it works at any guest mount point. The
  manifest records it as the additive `vendor` key (autoload path + resolved
  package versions).
- **Unprefixed, deliberately.** php-scoper is no longer run: prefixing vendor
  namespaces without also rewriting the customer's Atom code (which names
  those namespaces at every call site) would break the app against its own
  vendor tree, and the guest has no other occupant to collide with — it loads
  exactly `atoms/core`, the `Atoms\Cf` runtime, and this bundle. Namespace
  isolation returns as future hardening only as a whole-bundle rewrite
  (vendor **and** app together). The manifest's `toolchain.scoper_prefix`
  keeps its original meaning: a content fingerprint of the customer tree.
- `--fast` skips the stage — legal only when `atoms-composer.json` declares
  nothing. With dependencies declared it refuses (**ATOMS-E107**): a
  vendor-less bundle would deploy cleanly and fatal in the guest on the first
  vendor class an Atom touches, and the cache already makes the full build
  composer-free. `atoms validate` remains the fast no-bundle check.
- Files a package might read as runtime data (`.json`, `.txt`, `.csv`, …) are
  pruned by the ship rule and named in the build output, so a package that
  needs one fails with a visible cause at build time rather than a bare
  `file_get_contents` error in the guest.

`atoms validate` runs no vendor stage, so its manifest (and manifest hash)
describes the customer tree only; `atoms build`'s manifest is the one with
the `vendor` key. The content hash always covers everything in the tar,
vendor included.

### Three additive manifest fields

The Worker needs three things the schema-1 manifest did not record. All are
additive; `schema` stays `1`.

- **`atoms[].file`** — the bundle-relative path of the file declaring the Atom
  class. `bootstrap.php` must `require_once` exactly this file. Re-deriving it
  by scanning the tarball for a class declaration would duplicate, in another
  language, work the build already did correctly.
- **`atoms[].migrations.files[].path`** — the bundle-relative path of each
  migration. `MigrationEntry::$name` is the *descriptive* part only
  (`MigrationSet` parses `NNN_name.sql` and keeps `name`), so the filename is
  not reconstructable from the manifest at all.
- **`vendor.autoload`** — the bundle-relative path of the generated vendor
  autoload file (see §The vendor stage). The translator mounts the tar's
  `vendor/…` entries under `/app/vendor/…` like everything else, carries this
  key through guest-pathed (exactly as it does `atoms[].file`), and verifies
  the declared file is actually in the archive; `bootstrap.php` excludes the
  vendor subtree from its line-scanning autoloader (the classmap is exact,
  and scanning a vendor tree at every activation is pure boot cost) and
  `require`s the declared file. Absent key ⇒ no vendor tree, nothing changes;
  `bundle_format` stays `0`. Conformance check 45 pins the guest behaviour.

**`atoms[].websocket` is three-valued, and the third value is "no answer".**
The Worker reads the key as: absent ⇒ allowed, `true` ⇒ allowed, `false` ⇒
refuse `GET /ws/:type/:id` with 501 before any Durable Object is touched. So
`false` is a *claim*, and `ManifestGenerator` may only make it when it can
actually see that no handler exists. Discovery parses files; it does not load
classes. For `class Room extends BaseRoom` it cannot follow `BaseRoom`
— which may live in a vendor package and may itself extend something else — so
it cannot know whether `onMessage` is declared up the chain. The generator
therefore emits `true` when the class declares a handler **itself** (matched
case-insensitively, because PHP method names are), `false` only when the class
extends `Atoms\Atom` **directly** and declares none, and **omits the key
entirely** in every other case, leaving the decision to the runtime's own
dispatch. The limitation this documents is real and deliberate: a project that
puts its handlers on an intermediate base class gets no build-time 501
shortcut for its non-WebSocket types. The alternative — guessing `false` —
produced a wrongful 501 on handlers that worked perfectly, which is the worse
failure by a wide margin: a build-time guess breaking a runtime that was
correct.

`tests/Integration/BundleBridgeTest.php` runs a real build through the real
translator and is the test that catches either half drifting away from it.

## What `atoms deploy` does, end to end

```
atoms deploy --env production
```

1. Load `atoms.json`; resolve the environment.
2. Resolve the Cloudflare target: worker name, account id, API token, worker
   directory (`atoms-worker/`, or `--worker-dir`). No credential check happens
   here any more — a missing token may be a `wrangler login` session, and a
   missing account id may be the only account that session reaches. Both are
   knowable only at step 6.
3. Verify the Worker directory exists and has a Wrangler config (E076), and
   that its `atoms-runtime.json` names this CLI's release (E108). Before the
   build, so a stale directory costs seconds, not a build.
4. `atoms build` → `.atoms/build/bundle-{sha}.tar.gz` + `manifest.json`.
   Deterministic; executes no customer code. (`--bundle` skips this and deploys
   a prebuilt one.)
5. Run `node scripts/bundle-from-cli.mjs <bundle> <manifest> src/bundle.generated.js`
   inside the Worker directory. The translator refuses a manifest paired with
   the wrong bundle, and refuses a manifest naming a file the bundle does not
   contain. The output is gitignored there.
6. `wrangler deploy --name {worker}` in that directory, with whatever
   credentials this process resolved in its environment — possibly none, in
   which case Wrangler uses its own login session — and Wrangler's own output
   passed through unedited.
7. Non-zero exit ⇒ **ATOMS-E074**, with Wrangler's diagnosis already printed;
   or **ATOMS-E072** when that diagnosis is that it had no credentials at all,
   or **ATOMS-E075** when it could not choose between several accounts.

Nothing in this sequence contacts a service operated by Atoms.

## Known gaps

- **The callback URL is wired on both paths.** `--callback-url`,
  `ATOMS_CALLBACK_URL` in the environment, or `atoms.json`'s
  `callback_url.<env>` reaches the Worker as an `ATOMS_CALLBACK_URL` var via
  `wrangler dev --var` and `wrangler deploy --var` alike, and the Worker half
  is real: `Atom::app()`/`dispatch()` call back through it (`cloudflare/docs/
  runtime-spec.md` §The callback channel). `DevCommand` prints the URL it wired
  and provisions the Worker project's `.dev.vars` with a per-machine
  `ATOMS_SHARED_SECRET` if one is not already there, so `app()`/`dispatch()`
  has a usable signing key (`ATOMS-E081` covers the case where it still does
  not) without the operator discovering it mid-request. See "The callback
  channel" above for `ATOMS_CALLBACK_URL`, the derived key, and why the CLI
  never carries the secret itself.
- **`AtomsClient::destroy()` has no Worker route.** It targets
  `DELETE {baseUrl}/atoms/{type}/{id}`, which the Worker answers `not_found`.
  The URL shape is settled; the route is not implemented.
- **A real `wrangler deploy` is unproven in CI**, and deliberately: no job in
  this repository may need a Cloudflare account or an API token.
