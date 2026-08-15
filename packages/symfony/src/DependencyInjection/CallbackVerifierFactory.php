<?php

declare(strict_types=1);

namespace Atoms\Symfony\DependencyInjection;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Callback\HmacVerifier;

/**
 * Builds the callback {@see HmacVerifier} from the configured secrets at
 * service-instantiation time.
 *
 * Deriving here rather than while the container compiles is what lets
 * `shared_secret` be written as `'%env(ATOMS_SHARED_SECRET)%'`: an env
 * placeholder holds no value until the container resolves it, and the
 * compiled container never contains key material.
 */
final class CallbackVerifierFactory
{
    /**
     * @throws \InvalidArgumentException when either secret is absent or malformed (ATOMS-E105).
     */
    public static function create(AtomsConfig $config): HmacVerifier
    {
        return new HmacVerifier($config->callbackKeys());
    }
}
