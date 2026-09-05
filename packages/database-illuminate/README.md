# atoms/database-illuminate

The Laravel query builder and Eloquent models against an Atom's own SQLite
database. This package runs **inside the Atom** — add it to
`atoms-composer.json` (together with `illuminate/database`), not to your
application's `composer.json`:

```json
{
    "require": {
        "atoms/database-illuminate": "^0.6",
        "illuminate/database": "^12.0"
    }
}
```

```php
use Atoms\Atom;
use Atoms\DatabaseIlluminate\EloquentBridge;

class GameRoom extends Atom
{
    public function record(string $player, int $score): array
    {
        $db = EloquentBridge::boot($this->db());

        $db->table('scores')->insert(['player' => $player, 'score' => $score]);

        return Score::query()->orderByDesc('score')->limit(10)->get()->toArray();
    }
}
```

Three deliberate differences from a stock Laravel connection, all inherited
from the runtime the Atom executes in:

- **Nested `transaction()` calls reuse the outer transaction** instead of
  creating savepoints — the same semantics as `db()->transaction()`. Roll an
  inner transaction back by **throwing**; a hand-called `rollBack()` inside a
  nested `transaction()` discards the *entire* write set and the enclosing
  wrappers then fail loudly on the already-closed transaction.
  `afterCommit()`/`afterRollBack()` hooks are unsupported (no transactions
  manager is installed; they throw).
- **Schema work is refused** (`ATOMS-E106`): Atoms migrations own DDL.
- **`getServerVersion()` answers from configuration** (the runtime cannot ask
  the engine), overridable via the `server_version` connection config key.

Eloquent models never cross the Atom boundary — return `->toArray()` shapes
from Atom methods. See the [Atoms documentation](https://docs.atomsphp.dev)
for the full semantics notes, including the wide-integer (int64) limits of
the Cloudflare runtime.

## Development and support

This package is developed in the
[Atoms monorepo](https://github.com/AtomsPHP/atoms). Its standalone repository
is a read-only distribution mirror; report issues and send pull requests to
the monorepo. Licensed under the [MIT License](LICENSE).
