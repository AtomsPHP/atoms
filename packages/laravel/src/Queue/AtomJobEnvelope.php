<?php

declare(strict_types=1);

namespace Atoms\Laravel\Queue;

use Atoms\AtomJob;
use Atoms\Serialization\Serializer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queueable wrapper around an inbound {@see AtomJob}. Carries only the job's
 * class name and its normalized (JSON-safe) constructor arguments — never the
 * object graph — so Laravel's own queue serialization has nothing exotic to
 * serialize and this code never touches native serialize()/unserialize().
 *
 * The AtomJob is reconstructed from those wire-safe args on the worker side,
 * in {@see self::handle()}, via the same {@see Serializer} the callback
 * kernel uses to hydrate it the first time.
 */
final class AtomJobEnvelope implements ShouldQueue
{
    use Queueable;

    /**
     * @param class-string<AtomJob>  $jobClass
     * @param array<string, mixed>   $args      Normalized (JSON-safe) constructor arguments, keyed by parameter name.
     */
    public function __construct(
        public readonly string $jobClass,
        public readonly array $args,
    ) {
    }

    /**
     * Build an envelope from a live {@see AtomJob}, normalizing every promoted
     * constructor property through the serializer.
     */
    public static function fromAtomJob(AtomJob $job, ?Serializer $serializer = null): self
    {
        $serializer ??= new Serializer();
        $reflection = new \ReflectionClass($job);
        $constructor = $reflection->getConstructor();

        $args = [];
        foreach ($constructor?->getParameters() ?? [] as $param) {
            if (!$param->isPromoted()) {
                continue;
            }

            $property = $reflection->getProperty($param->getName());
            $args[$param->getName()] = $serializer->normalize($property->getValue($job));
        }

        return new self($job::class, $args);
    }

    /**
     * Runs in the monolith's queue worker: reconstruct the AtomJob from its
     * normalized args and invoke its handle(), if it has one. DI-resolved via
     * the container when one is available so the job's own handle() can type
     * hint its dependencies, same as any other Laravel job.
     */
    public function handle(): void
    {
        $job = $this->reconstruct();

        if (!method_exists($job, 'handle')) {
            return;
        }

        if (function_exists('app')) {
            app()->call([$job, 'handle']);

            return;
        }

        $job->handle();
    }

    private function reconstruct(): AtomJob
    {
        $serializer = new Serializer();
        $reflection = new \ReflectionClass($this->jobClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var AtomJob $instance */
            $instance = $reflection->newInstance();

            return $instance;
        }

        $callArgs = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $this->args)) {
                $type = $this->parameterType($param);
                $callArgs[] = $type === 'mixed' ? $this->args[$name] : $serializer->denormalize($this->args[$name], $type);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $callArgs[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $callArgs[] = null;
                continue;
            }

            throw new \RuntimeException("Missing required argument \${$name} reconstructing {$this->jobClass}.");
        }

        /** @var AtomJob $instance */
        $instance = $reflection->newInstanceArgs($callArgs);

        return $instance;
    }

    private function parameterType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }

        $name = $type->getName();

        if ($name === 'mixed') {
            return 'mixed';
        }

        return ($type->allowsNull() && $name !== 'null') ? '?' . $name : $name;
    }
}
