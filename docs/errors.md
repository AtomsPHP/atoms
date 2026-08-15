# Error Catalog

All errors in the Atoms platform carry a stable, machine-readable code (`ATOMS-E###`) that persists across versions and surfaces in PHPStan output, build failures, CLI messages, platform rejections, and the generated agent skills.

This catalog is the single source of truth: `packages/core/resources/errors.json`. Changes are append-only; codes are never renumbered.

| Code | Title | Severity | Phase | Fix |
|------|-------|----------|-------|-----|
| ATOMS-E001 | Unclassifiable file under the Atoms path | warning | build | Move non-Atoms code out of the Atoms path, or make the class extend the appropriate atoms/core base class. |
| ATOMS-E010 | Framework symbol referenced inside Atom code | error | build | Move the behavior into a Methods class (runs in your app via $this->app()) or use the atoms/core equivalent. |
| ATOMS-E011 | Global framework helper called inside Atom code | error | build | Use $this->config() for configuration, new \DateTimeImmutable() for time, or move the call into a Methods class. |
| ATOMS-E012 | Monolith class referenced inside Atom code | error | build | Pass data across the boundary as a Shared DTO (implement Atoms\Serialization\Payload) or call it via $this->app(). |
| ATOMS-E013 | Package not declared in atoms-composer.json | error | build | Add the package to atoms-composer.json if it is on the approved list; otherwise remove the dependency. |
| ATOMS-E014 | Facade used inside Atom code | error | build | Move the behavior into a Methods class and call it via $this->app(). |
| ATOMS-E015 | Shared class references a non-core symbol | error | build | Keep Shared classes pure data: promoted scalar/Payload properties, accessors and factories only. |
| ATOMS-E016 | Shared class contains behavior | warning | build | Shared classes are DTOs. Move behavior to the Atom (World A) or a Methods class (World B). |
| ATOMS-E017 | env() called inside Atom code | error | build | Use `atoms secrets set KEY --env <environment>` and read it with $this->config('KEY'). |
| ATOMS-E018 | Native PHP serialization at a boundary | error | build | Boundary values cross as JSON through the Atoms serializer; implement Atoms\Serialization\Payload for structured data. |
| ATOMS-E019 | PHP extension not available in the runtime image | error | build | Check the supported extension list in the docs, or move the dependent code into a Methods class. |
| ATOMS-E020 | Boundary type outside the serialization algebra | error | static-analysis | Allowed: scalars, null, arrays of allowed types, Payload DTOs, DateTimeImmutable, backed enums. |
| ATOMS-E021 | ORM object crossing the boundary | error | static-analysis | Pass a Shared DTO instead, e.g. PlayerSnapshot::fromUser($user) or $user->only([...]). |
| ATOMS-E022 | Unserializable value in a boundary payload | error | runtime | Closures, resources, and container-bound objects cannot be serialized. Pass plain data or a Payload DTO. |
| ATOMS-E023 | Payload class is not hydratable | error | static-analysis | Use public readonly promoted constructor properties; remove non-promoted state. |
| ATOMS-E024 | Value does not match the declared boundary type | error | runtime | The wire value and the declared PHP type disagree — check the manifest for version skew (atoms diff). |
| ATOMS-E030 | Unknown Methods method called from an Atom | error | build | Add the method to the Methods class, or fix the call — method names are checked statically against the manifest. |
| ATOMS-E031 | Methods call-site signature mismatch | error | build | Update the call site or the Methods signature; both sides are part of the deployed contract. |
| ATOMS-E032 | AtomJob constructor signature mismatch | error | build | AtomJob constructor parameters are the dispatch contract — keep the promoted properties and the dispatch site in sync. The array keys you pass to $this->dispatch() are those parameter names. |
| ATOMS-E033 | Dispatched class is not an AtomJob | error | build | Extend Atoms\AtomJob, or run the code inline in the Atom instead of dispatching it. |
| ATOMS-E040 | Manifest hash mismatch (version skew) | warning | runtime | Deploy order matters: additive Atom changes deploy Atoms-first; contractions deploy monolith-first. Run `atoms diff`. |
| ATOMS-E041 | Method not in deployed version | error | runtime | The monolith is ahead of the Atom deploy. Deploy Atoms first for additive changes (`atoms deploy`), then the monolith. |
| ATOMS-E042 | Bundle rejected by platform validation | error | deploy | Run `atoms validate` locally — it applies the same checks and must reproduce the failure. |
| ATOMS-E043 | atoms/core version outside the runtime's supported range | error | deploy | composer update atoms/core to a supported version and rebuild. |
| ATOMS-E050 | Shipped migration was edited | error | build | Migrations are append-only once shipped. Restore the original file and add a new migration instead. |
| ATOMS-E051 | Migration numbering conflict | error | build | Number migrations with strictly increasing NNN_ prefixes (001_, 002_, ...). |
| ATOMS-E052 | Migration likely exceeds the activation budget | warning | build | Migrations run at Atom activation. Keep them tiny, or restructure as lazy per-row upgrades in Atom code. |
| ATOMS-E053 | Migration failed at activation | error | runtime | The Atom stays deactivated. Fix the migration by appending a corrective one — never edit the failed file — and redeploy. |
| ATOMS-E060 | Atom type not deployed | error | runtime | Deploy the Atom (`atoms deploy`) or check the class basename — types are matched by basename. |
| ATOMS-E061 | Turn deadline exceeded | error | runtime | Turns must stay short. Move slow work to an AtomJob via $this->dispatch(), and check for accidental blocking I/O. |
| ATOMS-E062 | Capacity refused / rate limited | error | runtime | The SDK retries automatically where safe. Persistent refusals mean the fleet is at capacity — check the dashboard. |
| ATOMS-E063 | Remote Atom exception | error | runtime | This is your Atom code throwing. The sanitized remote trace is attached to the exception. |
| ATOMS-E064 | Callback signature verification failed | error | runtime | Check the configured platform public key. If keys were rotated, update config and retry. |
| ATOMS-E065 | Callback replay detected | error | runtime | Usually clock skew (>300s) on the app host, or a genuine replay. Sync clocks; investigate repeated nonces. |
| ATOMS-E066 | No Methods class for callback target | error | runtime | Create {expectedClass} (or mark a class with #[MethodsFor({atomType}::class)]). |
| ATOMS-E067 | WebSocket ticket acquisition failed | error | runtime | The Worker mints tickets at POST /tickets/{type}/{id}; when ATOMS_APP_KEY is set the client must send the same key as its bearer. Check the key configuration, that the atom type is deployed, and that it does not declare "websocket": false. Tickets are short-lived and reusable until they expire: on any connection failure, mint a fresh one. |
| ATOMS-E070 | atoms.json missing or invalid | error | cli | Run `atoms init` to create it, or fix the reported JSON error. |
| ATOMS-E071 | atoms-composer.json invalid or package not allowed | error | cli | atoms-composer.json may only contain `require` (from the approved package list) and `repositories`. |
| ATOMS-E072 | Deploy credentials missing | error | cli | Export CLOUDFLARE_API_TOKEN. It is never accepted as a command-line option — a credential in argv is visible to every process on the machine. In CI, supply it to the deploy action as `cloudflare-api-token`. |
| ATOMS-E073 | Wrangler not found | error | cli | Run `npm install` in the Worker directory so node_modules/.bin/wrangler exists, or set ATOMS_WRANGLER_BIN to an absolute path. Atoms never downloads Wrangler for you. |
| ATOMS-E074 | Wrangler command failed | error | cli | Read Wrangler's own output above; it reports Cloudflare API rejections verbatim. Check that the API token has Workers Scripts:Edit on the target account. |
| ATOMS-E075 | Cloudflare account not configured | error | cli | Add "account_id" to that environment in atoms.json, or set CLOUDFLARE_ACCOUNT_ID. Find it on the Workers & Pages overview in the Cloudflare dashboard. |
| ATOMS-E076 | Worker directory missing or incomplete | error | cli | Point "worker_dir" in atoms.json at a checkout of the Atoms Cloudflare Worker (cloudflare/worker), and run `npm ci` inside it. |
| ATOMS-E077 | Secret would not be readable from Atom code | error | cli | Pick a different key. $this->config() resolves through the Worker's ATOMS_CONFIG_ENV_PREFIX allowlist, and a name outside it — or on its deny list — reads back as null with no error. |
| ATOMS-E078 | Bundle path too long for the archive format | error | build | The bundle is a ustar tar: a path needs a "/" positioned so the tail is at most 100 bytes and the head at most 155. Shorten the class or directory name. |
| ATOMS-E080 | Callback channel not configured | error | runtime | Set ATOMS_CALLBACK_URL on the Worker to your monolith's callback endpoint (the route where Atoms\Client\Callback\CallbackKernel is mounted), and ATOMS_CALLBACK_SIGNING_KEY to the base64 of a 32-byte Ed25519 seed. `atoms dev --callback-url` sets the first for local runs. |
| ATOMS-E081 | Callback signing key missing or unusable | error | runtime | ATOMS_CALLBACK_URL is set but the Worker has no usable signing key, and Atoms never sends an unsigned callback. Set ATOMS_CALLBACK_SIGNING_KEY (wrangler secret put) to the base64 of a 32-byte Ed25519 seed, and give the matching public key to Ed25519Verifier in your app. |
| ATOMS-E082 | app() called inside a database transaction | error | runtime | Read what you need from the monolith before opening the transaction, or close the transaction and re-open it after the call. $this->dispatch() is allowed inside a transaction — it is queued on commit and dropped on rollback. |
| ATOMS-E083 | Callback request to the monolith failed | error | runtime | The Atom reached your app but the call did not succeed. Check that the callback route is mounted and reachable from Cloudflare, that the Methods class exists for this Atom type, and that ATOMS_CALLBACK_TIMEOUT_MS is long enough for the endpoint. |
| ATOMS-E084 | Dispatched job cannot be encoded | error | runtime | Pass the constructor arguments to $this->dispatch() as a map keyed by parameter name — ['orderId' => $id], not [$id] — and make sure every value is a serialization-algebra type. An AtomJob's constructor parameters are its dispatch contract and must be promoted public properties, so your app can rebuild it from that map. |
| ATOMS-E085 | Invalid timer name | error | runtime | Pick a name for $this->timers()->schedule($name, $at) that is non-empty and within the runtime's byte limit for timer names. |
| ATOMS-E086 | Timer limit exceeded | error | runtime | Raise the per-Atom cap on scheduled timers (ATOMS_TIMERS_MAX on the Worker), or cancel/coalesce timers you no longer need before scheduling new ones. |
| ATOMS-E100 | Package layering violation | error | static-analysis | Move the code that needs the symbol up into the adapter package that already depends on it (atoms/laravel, atoms/symfony), or express the need as a PSR interface the lower package can accept. The layers are defined in docs/conventions.md §Layering. |
| ATOMS-E101 | Sleep called inside Atom code | error | static-analysis | Delete the sleep. To act later, schedule a timer with $this->timers()->schedule($name, $at) and do the work in onTimer(); to hand work to your app, use $this->dispatch(). Nothing in an Atom may wait on wall-clock time. |
| ATOMS-E102 | Elapsed-time wait loop inside Atom code | error | static-analysis | Rewrite the loop so it terminates on data rather than on elapsed time, or move the wait out of the Atom: $this->timers()->schedule() delivers onTimer() in a later turn, and $this->dispatch() runs the work in your app. |
| ATOMS-E103 | No queue bridge configured for Atoms callbacks | error | runtime | Bind a QueueBridge. Laravel wires Atoms\Laravel\Queue\LaravelQueueBridge automatically; Symfony wires Atoms\Symfony\Messenger\MessengerQueueBridge once symfony/messenger and a message bus are present; any other host passes its own QueueBridge to CallbackKernel. |
| ATOMS-E104 | AtomJob constructed inside Atom code | error | build | Pass the class name and its constructor arguments by name instead: $this->dispatch(TheJob::class, ['param' => $value, ...]). Naming a class does not require it to ship, and your app reconstructs the real job from those arguments. |
