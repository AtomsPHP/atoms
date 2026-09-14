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
 * unedited before raising ATOMS-E074 — or one of the three failures this class
 * reads the output to recognise, because only Wrangler can know them: having
 * no credential at all (E072), being unable to choose an account (E075), and
 * a deploy that uploaded the script but could not attach its routing (E110).
 * See {@see self::setupFailure()} and {@see self::routingFailure()}.
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
     * Which setup failure Wrangler is reporting, if this is one of the two the
     * CLI stopped pre-empting: no credential at all (**E072**), or a login that
     * can reach several accounts with none named (**E075**).
     *
     * Atoms cannot answer either question in advance. An unset
     * `CLOUDFLARE_API_TOKEN` means Wrangler falls back to a session only
     * Wrangler knows about, and how many accounts that session reaches is
     * likewise its knowledge, not ours. So both answers arrive as output, and
     * this is where they are read.
     *
     * Deliberately narrow on each. A credential Cloudflare *rejected* — a
     * revoked token, one without Workers Scripts:Edit — is not "missing": it
     * stays ATOMS-E074, whose fix line already names the permission to check.
     * Each code keeps meaning exactly what its title says.
     *
     * Matching Wrangler's own wording is a drift risk, taken deliberately
     * because the way it degrades is harmless: an unmatched phrasing is simply
     * ATOMS-E074, whose fix line sends the reader to the Wrangler output
     * printed directly above it. Nothing is hidden either way.
     */
    public function setupFailure(): ?ErrorCode
    {
        // Keyed by code, and checked in this order: a no-credentials failure
        // happens before Wrangler ever gets far enough to choose an account.
        $markers = [
            // Wrangler's non-interactive no-token message, and the
            // login-session equivalent it prints where it can name a remedy.
            ErrorCode::DeployCredentialsMissing->value => [
                'necessary to set a cloudflare_api_token',
                'you are not authenticated',
                'run `wrangler login`',
            ],
            // A login reaching more than one account, with none selected and
            // no way to ask — `childEnv()` makes every run non-interactive.
            ErrorCode::CloudflareAccountMissing->value => [
                'more than one account available',
                'unable to select one in non-interactive mode',
            ],
        ];

        $output = strtolower($this->stdout . "\n" . $this->stderr);
        foreach ($markers as $code => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($output, $phrase)) {
                    return ErrorCode::from($code);
                }
            }
        }

        return null;
    }

    /**
     * Whether this failure is a deploy that uploaded the script and then could
     * not attach the routing — **ATOMS-E110**.
     *
     * Worth its own code because the resulting state is not the one a failed
     * command usually leaves. Measured against a real account: `Uploaded …`
     * prints, and only then does the route step fail. The new bundle is live;
     * the routing it was supposed to arrive on is not. A reader told only
     * "wrangler deploy exited 1" would reasonably assume nothing shipped.
     *
     * Two causes were measured, and both land here because both leave that
     * same half-applied state:
     *
     *   - the pattern belongs to another Worker. Cloudflare refuses it (API
     *     10020) rather than moving it — a deploy cannot take a route from
     *     another Worker, whatever an explicit API call could do.
     *   - the credential can upload but not route: Workers Scripts:Edit
     *     without Zone → Workers Routes:Edit, which fails on
     *     `/workers/routes` alone.
     *
     * Narrow twice over. The phrases are Wrangler's and Cloudflare's own, and
     * the upload marker must be present too — without it there is no evidence
     * the script shipped, and claiming it did would be worse than the generic
     * ATOMS-E074 this then falls back to. A custom domain is deliberately not
     * recognised here: a clashing one is handed over silently and reports
     * success, so it never reaches this method at all.
     */
    public function routingFailure(): bool
    {
        $output = strtolower($this->stdout . "\n" . $this->stderr);

        // Wrangler's own pre-flight wording, Cloudflare's API message behind
        // it, and the endpoint that can only be a routing call.
        $routing = [
            "can't deploy routes that are assigned to another worker",
            'a route with the same pattern already exists',
            'code: 10020',
            // The API path, as Wrangler prints it in a failed-request line.
            // Leading slash included: a bare "workers/routes" could appear in
            // ordinary deploy output, and this must only match the endpoint.
            '/workers/routes',
        ];

        $matched = false;
        foreach ($routing as $phrase) {
            if (str_contains($output, $phrase)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return false;
        }

        // Only claim the script shipped when Wrangler said so: `Uploaded <name>
        // (7.15 sec)` on a deploy, `Total Upload: …` on the size line.
        return str_contains($output, 'uploaded ') || str_contains($output, 'total upload');
    }

    /**
     * @throws AtomsError E072 when Wrangler had no credentials at all,
     *                    E075 when it could not choose an account,
     *                    E110 when a deploy uploaded but its routing did not attach,
     *                    E074 for any other non-zero exit
     */
    public function assertOk(): self
    {
        if ($this->ok()) {
            return $this;
        }

        $setup = $this->setupFailure();
        if ($setup !== null) {
            throw new AtomsError(
                $setup,
                ErrorCatalog::format($setup, ['command' => $this->subcommand()]),
            );
        }

        if ($this->routingFailure()) {
            throw new AtomsError(
                ErrorCode::RoutingNotAttached,
                ErrorCatalog::format(ErrorCode::RoutingNotAttached, ['command' => $this->subcommand()]),
            );
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
