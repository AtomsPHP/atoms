<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * The outcome of one Wrangler invocation.
 *
 * Wrangler reports Cloudflare's API rejections in its own output, and it does
 * so better than a re-wrapping layer could — so a failure carries the exit
 * status and the raw streams, and the command that ran it prints them
 * unedited before raising ATOMS-E074.
 */
final class WranglerResult
{
    /**
     * @param list<string> $command argv as executed, credentials excluded
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * The Wrangler sub-command, for error messages: "versions list", "deploy".
     */
    public function subcommand(): string
    {
        $words = [];
        foreach (\array_slice($this->command, 1) as $arg) {
            if (str_starts_with($arg, '-')) {
                break;
            }
            $words[] = $arg;
        }

        return $words === [] ? 'wrangler' : implode(' ', $words);
    }

    /**
     * Decode `--json` output.
     *
     * Wrangler prints warnings on stdout alongside the JSON document — proxy
     * notices, update nags — so the stream is not pure JSON. Seeking the first
     * `[` is not enough either: `▲ [WARNING] …` contains one, and starting
     * there decodes nothing. So every line that opens a JSON value is tried in
     * turn and the first that parses wins.
     *
     * Returns null when nothing decodable is present; the caller shows the raw
     * output rather than claiming an empty result.
     *
     * @return array<array-key, mixed>|null
     */
    public function json(): ?array
    {
        foreach ($this->candidateOffsets() as $offset) {
            try {
                /** @var mixed $decoded */
                $decoded = json_decode(substr($this->stdout, $offset), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * The secret names in a `wrangler secret list --json` document.
     *
     * Every caller that reads that document goes through here, so the
     * warning-tolerant decoding above is applied once rather than per command
     * — decoding it by hand reads an existing secret as absent the moment
     * Wrangler prefixes its JSON with a notice.
     *
     * Returns null when the output does not decode at all. That is not the
     * same fact as "no secrets are set", and the two callers that ask draw
     * opposite conclusions from it, so the distinction stays theirs to make.
     *
     * @return list<string>|null
     */
    public function secretNames(): ?array
    {
        $decoded = $this->json();
        if ($decoded === null) {
            return null;
        }

        $names = [];
        foreach ($decoded as $entry) {
            /** @var mixed $name */
            $name = \is_array($entry) ? ($entry['name'] ?? null) : $entry;
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Offsets where a JSON document could begin: the start of any line whose
     * first non-space character opens an array or an object.
     *
     * @return list<int>
     */
    private function candidateOffsets(): array
    {
        $offsets = [];
        $offset = 0;

        foreach (explode("\n", $this->stdout) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $offsets[] = $offset + (\strlen($line) - \strlen($trimmed));
            }
            $offset += \strlen($line) + 1;
        }

        return $offsets;
    }

    /**
     * @throws AtomsError E074 when Wrangler exited non-zero
     */
    public function assertOk(): self
    {
        if ($this->ok()) {
            return $this;
        }

        throw new AtomsError(
            ErrorCode::WranglerFailed,
            ErrorCatalog::format(ErrorCode::WranglerFailed, [
                'command' => $this->subcommand(),
                'status' => (string) $this->exitCode,
            ]),
        );
    }
}
