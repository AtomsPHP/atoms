# examples/

This directory is executable documentation, not prose. Every file under
`plain-php/src/` is exercised by `tests/Integration/Adapters` (the adapter
conformance suite) via `Atoms\Examples\PlainPhp\PlainPhpApp::handleGlobals()`
and friends — the recipe in `plain-php/README.md` is only trustworthy because
the code it walks through is the same code under test.

Rules for anything added here:

- Never add a framework dependency (Laravel, Symfony, Slim, Mezzio, ...). This
  is the plain-PHP integration; `packages/laravel` and `packages/symfony`
  already own their own worlds. `examples/plain-php/composer.json` may only
  require `php`, `atoms/client`, and PSR-7/17/18 implementations.
- When an example breaks the adapter conformance suite, fix the example,
  never the test. The suite is the acceptance gate for what "plain PHP" means
  here; loosening it to fit broken example code defeats the point of this
  directory existing.
- Keep fixtures (the `Atoms\Examples\PlainPhp\Atoms\GameRoom` Atom and its
  Methods class) minimal and boundary-legal — see `docs/conventions.md`
  §Serialization type algebra.
