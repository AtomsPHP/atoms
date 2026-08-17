<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\WranglerResult;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

final class WranglerResultTest extends TestCase
{
    public function testASuccessfulResultAssertsThrough(): void
    {
        $result = new WranglerResult(['wrangler', 'deploy'], 0, 'Deployed acme', '');

        self::assertSame($result, $result->assertOk());
        self::assertNull($result->setupFailure());
    }

    /**
     * The failure `atoms deploy` used to pre-empt with its own E072 before the
     * API token became optional: Wrangler with no credential to use.
     */
    public function testACredentialFailureIsE072RatherThanE074(): void
    {
        $cases = [
            'no token in a non-interactive environment' =>
                "✘ [ERROR] In a non-interactive environment, it's necessary to set a CLOUDFLARE_API_TOKEN "
                . "environment variable for wrangler to work.\n",
            'no login session' =>
                "✘ [ERROR] You are not authenticated. Please run `wrangler login`.\n",
        ];

        foreach ($cases as $label => $stderr) {
            $result = $this->failure($stderr);
            self::assertSame(ErrorCode::DeployCredentialsMissing, $result->setupFailure(), $label);

            $error = $this->errorFrom($result);
            self::assertSame(ErrorCode::DeployCredentialsMissing, $error->errorCode, $label);
            self::assertStringContainsString('ATOMS-E072', $error->getMessage(), $label);
            // Both inlets are named. That fix line is the whole reason this
            // failure keeps its own code rather than folding into E074.
            self::assertStringContainsString('CLOUDFLARE_API_TOKEN', $error->getMessage(), $label);
            self::assertStringContainsString('wrangler login', $error->getMessage(), $label);
        }
    }

    public function testTheMarkersAreReadFromStdoutToo(): void
    {
        // Wrangler splits its output across both streams depending on the
        // subcommand; neither is the credential channel by convention.
        $result = $this->failure(
            '',
            "▲ [WARNING] Update available\n✘ [ERROR] You are not authenticated. Please run `wrangler login`.\n",
        );

        self::assertSame(ErrorCode::DeployCredentialsMissing, $result->setupFailure());
    }

    public function testAnUnselectableAccountIsE075(): void
    {
        // Wrangler with credentials that reach several accounts and no
        // account id to choose between them. `childEnv()` forces CI=true, so
        // Wrangler cannot ask — this is the failure the CLI stopped
        // pre-empting, arriving where it can actually be known.
        $result = $this->failure(
            "✘ [ERROR] More than one account available but unable to select one in non-interactive mode.\n"
            . "  Please set the appropriate `account_id` or assign it to the CLOUDFLARE_ACCOUNT_ID "
            . "environment variable.\n",
        );

        self::assertSame(ErrorCode::CloudflareAccountMissing, $result->setupFailure());

        $error = $this->errorFrom($result);
        self::assertStringContainsString('ATOMS-E075', $error->getMessage());
        self::assertStringContainsString('account_id', $error->getMessage());
        self::assertStringContainsString('CLOUDFLARE_ACCOUNT_ID', $error->getMessage());
    }

    public function testARejectedCredentialIsE074AndNotConfusedForAMissingOne(): void
    {
        // E072 says "missing", and a revoked or under-permissioned token is
        // not missing — E074's fix line is the one that names the permission
        // to check, so this must not be reclassified.
        $result = $this->failure(
            "✘ [ERROR] A request to the Cloudflare API failed.\n  Authentication error [code: 10000]\n"
        );

        self::assertNull($result->setupFailure());
        self::assertSame(ErrorCode::WranglerFailed, $this->errorFrom($result)->errorCode);
    }

    public function testEveryOtherFailureStaysE074(): void
    {
        $result = $this->failure(
            "✘ [ERROR] A request to the Cloudflare API failed.\n  workers.api.error [code: 10021]\n"
        );

        self::assertNull($result->setupFailure());

        $error = $this->errorFrom($result);
        self::assertSame(ErrorCode::WranglerFailed, $error->errorCode);
        self::assertStringContainsString('ATOMS-E074', $error->getMessage());
    }

    public function testUnrecognisedCredentialWordingDegradesToE074(): void
    {
        // The documented failure mode of matching Wrangler's own wording: a
        // phrase that drifts lands on E074, whose fix line sends the reader to
        // the Wrangler output the command already printed. Nothing is hidden.
        $result = $this->failure("✘ [ERROR] Credentials could not be established, somehow.\n");

        self::assertNull($result->setupFailure());
        self::assertStringContainsString('ATOMS-E074', $this->errorFrom($result)->getMessage());
    }

    private function failure(string $stderr, string $stdout = ''): WranglerResult
    {
        return new WranglerResult(['wrangler', 'deploy', '--name', 'acme'], 1, $stdout, $stderr);
    }

    private function errorFrom(WranglerResult $result): AtomsError
    {
        try {
            $result->assertOk();
        } catch (AtomsError $e) {
            return $e;
        }

        self::fail('expected assertOk() to raise an AtomsError');
    }
}
