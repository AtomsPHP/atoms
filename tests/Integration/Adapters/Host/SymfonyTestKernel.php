<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The committed fixture app's kernel — MicroKernelTrait over the
 * {@see self::getProjectDir()} `symfony-app/` directory, so
 * {@see SymfonyHost} boots the SAME wiring a real Symfony app mounting
 * `atoms/symfony` per its README would: `config/bundles.php`,
 * `config/packages/*.yaml`, `config/routes/atoms.yaml` (which only resolves
 * via {@see \Atoms\Symfony\Routing\AtomsRouteLoader}, never a test-defined
 * route), `config/services.php`.
 *
 * Two things this class does that a real app's kernel wouldn't:
 *
 *  - The environment string carries a random suffix (`self::$instanceId`)
 *    instead of a fixed 'test', so each instance's generated container class
 *    name ({@see Kernel::getContainerClass()} folds environment + debug into
 *    it) is unique. Without that, a second `SymfonyHost::boot()` in the SAME
 *    PHP process — which is every test after the first, since PHPUnit runs
 *    this suite in one process — would `include` a freshly dumped container
 *    file declaring a class PHP already declared from the first boot's dump,
 *    a fatal "cannot redeclare class" the first boot never surfaces alone.
 *    `config/packages/{env}/*.yaml` overrides are unused here, so varying the
 *    environment string changes nothing else framework.yaml/atoms.yaml rely
 *    on (verified: nothing in FrameworkBundle branches on the literal string
 *    "test", only on framework.yaml's `test: true` config value).
 *  - getCacheDir()/getBuildDir()/getLogDir() point at a fresh, unique temp
 *    directory per instance rather than a fixed `var/` under the project
 *    dir, so a `callback_path` (or any other) config change between boots
 *    always compiles a fresh container instead of resurrecting a stale one —
 *    see {@see SymfonyHost::shutdown()} for the matching cleanup.
 */
final class SymfonyTestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $varDir;

    public function __construct(bool $debug = false)
    {
        parent::__construct('test_' . bin2hex(random_bytes(8)), $debug);

        $this->varDir = sys_get_temp_dir() . '/atoms-symfony-host-' . bin2hex(random_bytes(8));
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/symfony-app';
    }

    public function getCacheDir(): string
    {
        return $this->varDir . '/cache';
    }

    public function getBuildDir(): string
    {
        return $this->varDir . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir . '/log';
    }

    /**
     * The directory {@see SymfonyHost::shutdown()} recursively removes once
     * this kernel is done with it.
     */
    public function varDir(): string
    {
        return $this->varDir;
    }
}
