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

## 1. Runtime auth: the client moved, and the bearer is derived

### The disagreement

`atoms/client` used to build

```
POST {baseUrl}/v1/{customer}/invoke/{type}/{id}/{method}
Authorization: Bearer {apiKey}
```

The Worker serves

```
POST {baseUrl}/invoke/{type}/{id}/{method}
Authorization: Bearer $(atoms token)
```

`atoms token` prints the bearer derived from `ATOMS_SHARED_SECRET` — see
"Bearer auth is mandatory" below and `docs/shared-secret.md`, the decision
record for the whole boundary.

### The decision

**The client moved.** The `/v1/{customer}` prefix is gone from
`AtomsClient`, `AtomsClient::destroy()` and `TicketClient`, and
`AtomsConfig::$customer` is deleted. (`TicketClient` was a sketch when this
decision was recorded; as of M4 the Worker implements
`POST /tickets/{type}/{id}` and the client is real — see "The bearer is
the server-to-server credential" below.)

The prefix existed to route a multi-tenant edge to one customer's Machines. The
Worker is single-tenant by construction: it *is* the customer's deployment,
running in the customer's account, and there is no second tenant for a prefix
to disambiguate. Keeping the prefix would have meant the Worker growing a route
segment whose only possible value is a constant.

Moving the client is also the cheaper edit in the sense that matters:
`atoms/client` is **not** the frozen ABI. Only `atoms/core` is. Changing the
client's URL shape breaks no wire contract, because the wire contract it was
implementing no longer has an implementation.

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
`Atoms\Client\Tickets\TicketClient::acquire($type, $id, $claims)` →
`POST /tickets/{type}/{id}` (bearer-gated under `ATOMS_BEARER_AUTH=required`),
and hands the short-TTL, atom-scoped ticket to the browser, which presents it
as `?ticket=` on the `/ws` upgrade. Claims minted by the server merge over the
browser's query params (server wins), so `onConnect` code reading
`$params['client_id']` gets a host-asserted value the browser cannot forge.

`POST /tickets` always mints the signed `v1.` form — under
`ATOMS_BEARER_AUTH=disabled` too, since a shared secret, and therefore a
signing key, is always configured — and `/ws` always verifies the signature,
so browser code paths are identical in local dev and production. The
signing key is HKDF-derived from the decoded `ATOMS_SHARED_SECRET` (info
`atoms/ws-ticket/v1`); rotating the secret invalidates every outstanding
ticket at once, with no overlap window. The binding details — format,
validation order, the reusable-until-expiry contract — live in
`cloudflare/docs/mvp-spec.md` §Routing and auth; the derivation itself is
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

**Installing the Worker project.** M7 publishes a version-matched template and
initializer. Run the exact command printed by `atoms init`:

```sh
npm exec --yes --package=@atomsphp/runtime-cloudflare@0.1.0 -- \
  atoms-runtime-cloudflare init .atoms/worker
cd .atoms/worker && npm ci
```

The explicit package version comes from the same release manifest as the CLI
and deploy Action. The initializer refuses to overwrite a non-empty directory,
and the template carries its own `package-lock.json`; `npm ci` therefore
installs the audited php-wasm and Wrangler pins rather than resolving current
registry versions. This setup command is deliberately outside the PHP CLI:
`atoms` itself still never downloads a toolchain. The deploy Action performs
the same pinned scaffold automatically for its default `.atoms/worker` path;
a custom Worker directory remains the caller's responsibility.

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

### The callback channel: `ATOMS_CALLBACK_URL` and the derived signing key

`app()`/`dispatch()` need one Worker var and one Worker secret, and they
travel to the Worker by two different, deliberately asymmetric paths
(`packages/cli/src/Command/DevCommand.php`):

- **`ATOMS_CALLBACK_URL`** — not a secret, the monolith's callback endpoint.
  `atoms dev --callback-url <url>` (or `atoms.json`'s
  `callback_url.<env>`) passes it to `wrangler dev` as an ordinary
  `--var ATOMS_CALLBACK_URL:<url>`, exactly the way any other var reaches the
  Worker. `DevCommand` echoes the URL it wired at startup, so it is visible in
  the same terminal that started the dev server.
- **The callback signing key** — HKDF-derived on the Worker from
  `ATOMS_SHARED_SECRET` (info `atoms/callback/v1`), not a variable of its own.
  The CLI **never** passes the secret. In production it is
  `wrangler secret put ATOMS_SHARED_SECRET`, run directly, the same as any
  other Worker secret. Locally, `atoms dev` provisions a fresh per-machine dev
  secret into the Worker project's gitignored `.dev.vars` — creating the file
  if needed, never overwriting a value already there, and warning if the file
  is not gitignored — so a developer never types the secret in by hand.

**Why the CLI never carries the secret on its own argv.** Putting it on
`atoms dev`'s argv or behind a `--var` flag would place it in the process
table and in shell history — precisely what the CLI-never-holds-a-credential
rule (root `AGENTS.md`) exists to prevent. `.dev.vars` and
`wrangler secret put` are Wrangler's own delivery vehicles for a secret;
`atoms dev` writes straight to the `.dev.vars` file it provisions rather than
passing the value as an argument anywhere, and otherwise only ever reaches for
the one variable (`ATOMS_CALLBACK_URL`) that is not a secret at all.

**`ATOMS_SHARED_SECRET` is not settable via `atoms secrets:set`, and that is
deliberate.** `SecretsSetCommand` always maps a name through
`SecretName::toWorker()`, which prefixes it with `ATOMS_CONFIG_`
(§"Secrets carry a prefix", above) — so `atoms secrets:set ATOMS_SHARED_SECRET`
would store `ATOMS_CONFIG_ATOMS_SHARED_SECRET`, a name nothing in the
bearer/ticket/callback paths reads — and one guest code *could* read. So the
command refuses the raw name before prefixing (`ATOMS-E077`):
`WorkerConfig::CREDENTIAL_KEYS` names `ATOMS_SHARED_SECRET` and
`ATOMS_SHARED_SECRET_PREVIOUS`, and `keyRefusalReason()` checks the input
against it ahead of any transform. `wrangler secret put
ATOMS_SHARED_SECRET` and `atoms dev`'s `.dev.vars` provisioning are the only
paths, exactly as this section describes — there is no CLI shortcut for this
secret, and there is not meant to be one: a root this central does not belong
behind a command whose whole contract is prefixing keys for guest code to
read.

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
the Worker conformance suite was untouched by the CLI integration (see
`cloudflare/docs/mvp-spec.md` §Conformance suite), and the vendored
`atoms/core` copy needed no re-vendor on this account.

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

**`atoms[].websocket` is three-valued, and the third value is "no answer".**
The Worker reads the key as: absent ⇒ allowed, `true` ⇒ allowed, `false` ⇒
refuse `GET /ws/:type/:id` with 501 before any Durable Object is touched. So
`false` is a *claim*, and `ManifestGenerator` may only make it when it can
actually see that no handler exists. Discovery parses files; it does not load
classes. For `final class Room extends BaseRoom` it cannot follow `BaseRoom`
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

## Known gaps after M7 packaging

- **`atoms dev`'s callback URL is wired, as of M2.** `--callback-url` (or
  `atoms.json`'s `callback_url.<env>`) reaches the Worker as an
  `ATOMS_CALLBACK_URL` var via `wrangler dev --var`, and the Worker half is
  real: `Atom::app()`/`dispatch()` call back through it (`cloudflare/docs/
  mvp-spec.md` §The callback channel). `DevCommand` prints the URL it wired
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
