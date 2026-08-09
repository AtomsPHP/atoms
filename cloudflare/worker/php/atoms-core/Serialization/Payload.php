<?php

declare(strict_types=1);

namespace Atoms\Serialization;

/**
 * Marker interface for boundary DTOs. A Payload's state must be declared as
 * promoted public constructor properties whose types are drawn from the
 * serialization algebra; the {@see Serializer} normalizes it to a JSON object of
 * those properties and hydrates it back by parameter name.
 */
interface Payload
{
}
