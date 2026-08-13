# Adapters

An **adapter** is the host-framework half of an Atoms integration: the code
that sits between a customer's Laravel app, Symfony app, plain-PHP app, or
anything else, and the framework-free `atoms/client` package underneath it.
`atoms/client` supplies the RPC stub proxies, the outbound transport, and the
inbound `CallbackKernel`; an adapter's whole job is wiring those into one
specific host — its config system, its container, its router, its queue.

The M4 claim is narrower than "four integrations exist": it is that the four
integrations expose the **same explicit, provable contract**. Every adapter
supplies the same set of contracts, and one conformance suite
(`tests/Integration/Adapters/`) runs the same case table against all of them
unmodified. This document is that contract, written down so a fifth adapter —
or a change to one of the first four — has something authoritative to be
checked against.

## What each host supplies

| Contract | Contract type | Laravel | Symfony | Plain-PHP example | Bare kernel |
|---|---|---|---|---|---|
| HTTP client | PSR-18 `Psr\Http\Client\ClientInterface` + PSR-17 factories (supply contract) | Auto-bound Guzzle singleton; overridden via `config('atoms.http_client')` or a prior container binding (`AtomsServiceProvider::registerHttpClient()`) | Guzzle-backed service, resolved in a compiler pass (`HttpClientPass`); overridden via the `http_client` bundle option | Constructor parameters to `AtomsBootstrap::create()` — no discovery | Constructor parameters to `Atoms\Client\Callback\CallbackKernelFactory::create()` / direct `AtomsClient` construction |
| Queue | `Atoms\Client\Callback\QueueBridge` (Atoms-owned interface) | `Atoms\Laravel\Queue\LaravelQueueBridge`, wrapping `Illuminate\Contracts\Bus\Dispatcher` | `Atoms\Symfony\Messenger\MessengerQueueBridge` when `symfony/messenger` and a bus are present, else `Atoms\Client\Callback\NullQueueBridge` (E103) | `queueBridge:` parameter to `AtomsBootstrap::create()`; `NullQueueBridge` if omitted (E103) | `queueBridge:` parameter to `CallbackKernelFactory::create()`; `NullQueueBridge` if omitted |
| Configuration | `Atoms\Client\AtomsConfig::fromArray()` (written supply contract — see below) | `config/atoms.php`, merged via `mergeConfigFrom()`, mapped in `registerAtomsConfig()` | `config/packages/atoms.yaml`, mapped in `AtomsBundle::registerConfig()` | Named parameters to `AtomsBootstrap::create()` | Named args straight into `AtomsConfig::fromArray()` |
| Route mounting | Written contract (below) — no PHP interface | `Route::post()` in `bootCallbackRoute()`, path/middleware from config | `Atoms\Symfony\Routing\AtomsRouteLoader`, a `routing.loader`-tagged service, resolving the `atoms` resource type | Caller wires `$router->post($path, fn($req) => $app->handle($req))`, or the `public/atoms-callback.php` front controller + `PlainPhpApp::handleGlobals()` | N/A — no router; the suite calls `CallbackKernel::handle()` directly |
| Logging | PSR-3 `Psr\Log\LoggerInterface` into both `AtomsClient` and `CallbackKernel` | The app's bound `LoggerInterface`, if any, else `null` | The app's `logger` service, if any (`IGNORE_ON_INVALID_REFERENCE` resolves a missing one to `null`) | `logger:` parameter, forwarded to both | `logger:` parameter to `CallbackKernelFactory::create()` |
| Methods instantiation | PSR-11 `Psr\Container\ContainerInterface` (optional; see S6 in `AdapterConformanceTestCase`) | The app's own container, passed straight through as `$container` | A `ServiceLocator` built from `methods_classes`, passed as `$container` | `container:` parameter to `AtomsBootstrap::create()`; `new $class()` when a Methods class isn't in it (default: nothing is) | `container:` parameter to `CallbackKernelFactory::create()`; `new $class()` when a Methods class isn't in it (default: nothing is) |
| Replay store | `Atoms\Client\Callback\NonceStore` (overridable) | `Atoms\Client\Callback\InMemoryNonceStore` singleton; alias `NonceStore` to your own for a multi-process deployment | Same default; alias `NonceStore` to your own | `nonceStore:` parameter; default `InMemoryNonceStore` | `nonceStore:` parameter to `CallbackKernelFactory::create()`; default `InMemoryNonceStore` |

The "bare kernel" column is `tests/Integration/Adapters/Host/BareKernelHost.php`
— not a shipped product, but the floor every other host is checked against:
`CallbackKernelFactory::create()` wired directly, with no router and no
framework container (it CAN take a plain PSR-11 `container:` argument — see
the Methods instantiation row — it just has no framework of its own supplying
one). It is where the case table in `CallbackCases::all()` is developed and
verified first, because it has nothing between the request and the kernel to
blur a failure's cause.

## Why configuration is not a PHP interface

A `ConfigurationSource` interface would have exactly one caller per adapter —
each host's own config-mapping method — and would change no runtime behavior:
`Atoms\Client\AtomsConfig::fromArray()` already is the contract, an array
shape, and every host already funnels its native config into it. Interposing
an interface between a host's config system and that one call site adds a
type to implement without removing any of the actual risk. The risk lives in
the *mapping*, above all the `apiKey` tri-state: a non-empty string sends
`Authorization: Bearer <key>`, `null` is explicitly-unauthenticated and sends
no header, and `''` is a misconfiguration that `AtomsConfig`'s constructor
throws on. A host's config layer can silently collapse `null` and `''`
together (an unset Laravel config key vs. an empty env var both stringifying
the same way, for instance) in a way no interface signature would catch. The
conformance suite proves this the only way that means anything: it drives
each host's own native config path — `config/atoms.php`, the Symfony bundle
extension, `AtomsBootstrap::create()`'s named parameters — end to end (see
S1/S2 in `AdapterConformanceTestCase`), rather than asserting against a
hypothetical interface no production code would ever call through.

## Why route mounting is not a PHP interface

A `mount(): void` interface has no honest signature across four adapters:
Laravel registers a route on `Illuminate\Routing\Router`, Symfony resolves a
`routing.loader`-tagged service that Symfony's own router consults, a
Slim/Mezzio host registers a PSR-15 handler on its own router, and the
no-framework recipe reads PHP's superglobals in a front controller with no
router object at all. Any signature general enough to cover all four either
takes `mixed` (which asserts nothing) or leaks a specific framework's routing
types into `atoms/client`, which must stay framework-free (`docs/conventions.md`
§Layering). So the contract is written instead, verbatim below, and it is
conformance-tested rather than compiled against: `AdapterConformanceTestCase`'s
M1/M2/M4 cases drive a real request through each routing-capable host and
check the observable behavior the six sentences promise.

## The mounting contract

> An adapter MUST expose CallbackKernel at the configured callback path, POST
> only. The kernel MUST receive the body byte-for-byte and every X-Atoms-*
> header unmodified. The route MUST NOT sit in a session/CSRF group, MUST NOT
> require a CSRF token, MUST NOT set a session cookie. Reconfiguring the path
> MUST move the route. Other methods at that path are rejected by the host
> router, with the host's own error body.

## The frozen-clock rules

`cloudflare/docs/mvp-spec.md`'s appendix of measured platform deviations
records that the guest clock does not advance inside a turn on deployed
workerd: a spin-probe returned the same reading across six million loop
iterations and across a host round trip. Inside an Atom (WORLD_A code), a
`sleep()` call or a loop waiting for elapsed wall-clock time is therefore
never "slow" — it is a hang that runs until the turn deadline (`ATOMS-E061`)
kills it. `AtomSleepCallRule` (`ATOMS-E101`) and `AtomTimeWaitLoopRule`
(`ATOMS-E102`), both in `atoms/phpstan-rules`, exist to catch that class of
bug at CI time rather than at a customer's deploy.

- `AtomSleepCallRule` flags direct calls to `sleep()`, `usleep()`,
  `time_nanosleep()` and `time_sleep_until()` written inside a WORLD_A class's
  own methods.
- `AtomTimeWaitLoopRule` flags a `while`/`do`-`while`/`for` loop in a WORLD_A
  method whose condition reads the clock (`time()`, `microtime()`, `hrtime()`,
  `gettimeofday()`, `new \DateTime`/`\DateTimeImmutable`) at any depth, or —
  for an unconditional loop (`while (true)`, `do { ... } while (true)`,
  `for (;;)`) — whose body does. That second branch is a strictly broader
  net than "waits for elapsed time": because an unconditional loop has no
  data condition of its own for the rule to inspect, ANY clock read anywhere
  in its body trips it, even one that plays no part in the loop's actual
  `break` — `while (true) { if (done($x)) break; log(time()); }` is flagged
  even though it genuinely terminates on `$x`, not on elapsed time. The rule
  cannot tell a real spin-wait from a merely-logging loop-body clock read
  without evaluating what the `break` depends on, so it conservatively
  flags both.

Both are AST walks over one method at a time, so **method-call indirection is
not chased**: a `sleep()` tucked inside a helper the Atom merely calls is
invisible to either rule. That gap is deliberate rather than an oversight —
closing it statically would mean walking an unbounded call graph across
package boundaries — and it is why `ATOMS-E061`, the runtime turn deadline,
exists as the backstop: it kills a hung turn regardless of what caused the
hang, static analysis or not.

## Writing a fifth adapter

1. Implement every row of the table above against your host's own
   config system, container, router and queue.
2. Mount `CallbackKernel` exactly per "The mounting contract."
3. Add a host to the conformance suite: implement
   `Atoms\Tests\Integration\Adapters\Host\AdapterHost`
   (`tests/Integration/Adapters/Host/AdapterHost.php`) for your adapter, and
   run it through `Atoms\Tests\Integration\Adapters\AdapterConformanceTestCase`
   with the existing case table (`Atoms\Tests\Integration\Adapters\CallbackCases::all()`)
   unmodified. That AdapterHost interface and that case table are the
   definition of done — not a bespoke test suite for the new adapter.
