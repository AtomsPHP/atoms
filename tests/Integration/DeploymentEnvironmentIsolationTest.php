<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Application configuration must not become a deployment input.
 *
 * Atoms resolves deployment configuration in one order — flag, then the
 * caller's environment, then `.env.atoms.<environment>`, then atoms.json — and
 * that order is only as good as the definition of "the caller's environment".
 * A framework entry point widens it for free: `php artisan atoms:deploy`
 * runs after Laravel has loaded the application's `.env`, and the `atoms`
 * child process it starts would inherit the result. A developer's local
 * `ATOMS_CALLBACK_URL` would then outrank the committed production one, with
 * nothing to see.
 *
 * Argument-forwarding tests cannot establish this: the values never travel as
 * arguments. So these cases run a real subprocess that
 *
 * - includes the monorepo's real `vendor/autoload.php`, which is what triggers
 *   `Atoms\Client\Deployment\CallerEnvironment`'s snapshot;
 * - bootstraps a real `Illuminate\Foundation\Application` through Laravel's own
 *   `LoadEnvironmentVariables`, the bootstrapper that reads `.env`;
 * - runs the real `Atoms\Laravel\Console\DeployCommand` through the real
 *   `BinaryRunner`;
 * - and spawns a real child process, which prints the environment it actually
 *   received.
 *
 * Only the identity of the binary is substituted, and the binary's identity is
 * not what is under test.
 */
final class DeploymentEnvironmentIsolationTest extends TestCase
{
    private const CALLBACK = 'ATOMS_CALLBACK_URL';

    /** Every name the probe reports; kept in step with Support/artisan-wrapper-driver.php's probe. */
    private const PROBED = ['ATOMS_CALLBACK_URL', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID', 'ATOMS_PROBE_MARKER'];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            self::rmrf($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    /**
     * The case the whole contract exists for.
     */
    public function testALocalValueInTheApplicationDotenvNeverReachesTheDeployment(): void
    {
        $dir = $this->appDir(".env", self::CALLBACK . "=http://localhost:8000/atoms/callback\n");

        $seen = $this->runWrapper('artisan-wrapper-driver.php', $dir, callerEnv: []);

        self::assertSame('(unset)', $seen[self::CALLBACK]);
    }

    /**
     * ...and the override the contract does promise still works. This is why
     * the fix cannot be "diff against the dotenv file": the shell may have
     * exported the identical value on purpose.
     */
    public function testAValueTheCallerSuppliedStillOverrides(): void
    {
        $dir = $this->appDir(".env", self::CALLBACK . "=http://localhost:8000/atoms/callback\n");

        $seen = $this->runWrapper(
            'artisan-wrapper-driver.php',
            $dir,
            callerEnv: [self::CALLBACK => 'https://ci.example/atoms/callback'],
        );

        self::assertSame('https://ci.example/atoms/callback', $seen[self::CALLBACK]);
    }

    /**
     * Laravel skips dotenv loading entirely when configuration is cached, so
     * the same command run on a machine with a warm cache would otherwise
     * resolve differently from one without. It must not.
     */
    public function testCachedFrameworkConfigurationDoesNotChangeTheResult(): void
    {
        $dir = $this->appDir(".env", self::CALLBACK . "=http://localhost:8000/atoms/callback\n");
        mkdir($dir . '/bootstrap/cache', 0777, true);
        file_put_contents($dir . '/bootstrap/cache/config.php', "<?php return ['app' => []];\n");

        $seen = $this->runWrapper('artisan-wrapper-driver.php', $dir, callerEnv: []);

        self::assertSame('(unset)', $seen[self::CALLBACK]);
    }

    /**
     * A Cloudflare credential in the application's `.env` is application
     * configuration too. It is not this deployment's credential just because
     * it happened to be loaded.
     */
    public function testAnApplicationDotenvCredentialDoesNotReachWrangler(): void
    {
        $dir = $this->appDir(".env", "CLOUDFLARE_API_TOKEN=local-token-do-not-deploy-with\n");

        $seen = $this->runWrapper('artisan-wrapper-driver.php', $dir, callerEnv: []);

        self::assertSame('(unset)', $seen['CLOUDFLARE_API_TOKEN']);
    }

    /**
     * The Symfony bundle's console wrapper shells out through `proc_open`
     * rather than symfony/process, where a supplied environment replaces the
     * child's rather than merging into it. Different mechanism, same
     * guarantee — and worth proving separately, because "replaces" is the
     * failure mode where a child silently loses PATH.
     */
    public function testTheSymfonyConsoleWrapperIsolatesTheSameWay(): void
    {
        $dir = $this->appDir('.env', self::CALLBACK . "=http://localhost:8000/atoms/callback\n");

        $seen = $this->runWrapper('symfony-wrapper-driver.php', $dir, callerEnv: []);
        self::assertSame('(unset)', $seen[self::CALLBACK]);

        $seen = $this->runWrapper(
            'symfony-wrapper-driver.php',
            $dir,
            callerEnv: [self::CALLBACK => 'https://ci.example/atoms/callback'],
        );
        self::assertSame('https://ci.example/atoms/callback', $seen[self::CALLBACK]);
    }

    /**
     * Run one of the real wrappers in a fresh process and return what the
     * child it spawned actually saw.
     *
     * @param array<string, string> $callerEnv
     * @return array<string, string>
     */
    private function runWrapper(string $driver, string $dir, array $callerEnv): array
    {
        // Explicitly unset every probed name that $callerEnv does not supply:
        // Symfony's Process merges this process's own environment for names it
        // is not given, and a CLOUDFLARE_API_TOKEN belonging to whoever is
        // running the suite would otherwise arrive as a legitimate
        // caller-supplied value and make the case vacuous.
        $env = array_fill_keys(self::PROBED, false);
        $process = new Process(
            [\PHP_BINARY, __DIR__ . '/Support/' . $driver, $dir],
            $dir,
            [...$env, ...$callerEnv],
        );
        $process->mustRun();

        /** @var array<string, string> $decoded */
        $decoded = json_decode(trim($process->getOutput()), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * A directory with an application dotenv file and the probe the wrapper
     * will run in place of the `atoms` binary.
     */
    private function appDir(string $envFile, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/atoms-deploy-env-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;

        file_put_contents($dir . '/' . $envFile, $contents);

        $probe = $dir . '/atoms-probe';
        file_put_contents($probe, <<<'PHP'
            #!/usr/bin/env php
            <?php
            // Stands in for the `atoms` binary and reports the environment it
            // was handed. Reads getenv() only: that is what the CLI itself
            // resolves deployment configuration from.
            $names = ['ATOMS_CALLBACK_URL', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_ACCOUNT_ID', 'ATOMS_PROBE_MARKER'];
            $seen = [];
            foreach ($names as $name) {
                $value = getenv($name);
                $seen[$name] = is_string($value) && $value !== '' ? $value : '(unset)';
            }
            echo json_encode($seen), "\n";
            PHP);
        chmod($probe, 0755);

        return $dir;
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
