<?php

declare(strict_types=1);

namespace Atoms\Serialization;

use Atoms\Errors\AtomsError;

/**
 * Thrown when a value cannot cross the Atoms boundary — either it is outside the
 * serialization algebra (normalize) or the wire value does not match the
 * declared type (denormalize). Always carries the relevant ErrorCode.
 */
final class SerializationException extends AtomsError
{
}
