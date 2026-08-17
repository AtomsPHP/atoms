<?php

declare(strict_types=1);

namespace Atoms\Laravel\Queue;

use Atoms\AtomJob;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\SerializationException;
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
     * hint its dependencies, same as any other Laravel job — the Serializer
     * included, which is why it is a parameter rather than a `new` here.
     */
    public function handle(?Serializer $serializer = null): void
    {
        $job = $this->reconstruct($serializer ?? new Serializer());

        if (!method_exists($job, 'handle')) {
            return;
        }

        if (function_exists('app')) {
            app()->call([$job, 'handle']);

            return;
        }

        $job->handle();
    }

    /**
     * The binding algebra lives in the core Serializer (docs/conventions.md),
     * so this envelope and the callback kernel hydrate a job identically.
     *
     * @throws AtomsError            ATOMS-E033 when the class is not an AtomJob
     * @throws SerializationException ATOMS-E024 when an argument is missing or
     *                                of the wrong type
     */
    private function reconstruct(Serializer $serializer): AtomJob
    {
        if (!class_exists($this->jobClass) || !is_subclass_of($this->jobClass, AtomJob::class)) {
            throw new AtomsError(ErrorCode::NotAnAtomJob, ErrorCatalog::format(
                ErrorCode::NotAnAtomJob,
                ['atom' => 'The queued envelope', 'class' => $this->jobClass === '' ? '(none)' : $this->jobClass],
            ));
        }

        $reflection = new \ReflectionClass($this->jobClass);

        /** @var AtomJob $instance */
        $instance = $reflection->newInstanceArgs(
            $serializer->denormalizeNamedArguments($this->jobClass, $this->args),
        );

        return $instance;
    }
}
