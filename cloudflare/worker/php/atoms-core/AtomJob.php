<?php

declare(strict_types=1);

namespace Atoms;

/**
 * App-side base class for dispatched jobs. An Atom dispatches a job with
 * {@see Atom::dispatch()}; the job's `handle()` runs in the monolith.
 *
 * The constructor parameters ARE the dispatch contract: they must be declared as
 * promoted public properties whose types are drawn from the serialization
 * algebra (scalars, null, arrays of allowed types, {@see Serialization\Payload}
 * DTOs, \DateTimeImmutable, \BackedEnum). The job is serialized on dispatch as
 * `{"job": "FQCN", "args": {"param": value, ...}}` and rehydrated by
 * constructor-parameter name on the monolith side. Non-promoted state cannot
 * survive the boundary.
 */
abstract class AtomJob
{
}
