<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The smallest possible PSR-11 container: an array of pre-built instances
 * keyed by class-string, nothing else — no autowiring, no lazy building.
 * Lets {@see \Atoms\Tests\Integration\Adapters\Host\BareKernelHost} and
 * {@see \Atoms\Tests\Integration\Adapters\Host\PlainPhpHost} thread
 * {@see \Atoms\Tests\Integration\Adapters\Host\HostOptions::$containerBindings}
 * through to the same `container:` parameter a real framework-free host
 * could pass to {@see \Atoms\Client\Callback\CallbackKernelFactory::create()}
 * / {@see \Atoms\Examples\PlainPhp\AtomsBootstrap::create()} — proving the
 * "Methods instantiation" port's PSR-11 half for those two hosts too (S6),
 * not just Laravel/Symfony's real containers.
 */
final class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<class-string, object> $services
     */
    public function __construct(private readonly array $services = [])
    {
    }

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new class ("ArrayContainer has no service '{$id}'.") extends \RuntimeException implements NotFoundExceptionInterface {
        };
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
