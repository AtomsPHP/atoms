<?php

declare(strict_types=1);

namespace Atoms\Cli;

use Atoms\Cli\Cloudflare\ProcessWrangler;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Command\AiInstallCommand;
use Atoms\Cli\Command\BuildCommand;
use Atoms\Cli\Command\DeployCommand;
use Atoms\Cli\Command\DevCommand;
use Atoms\Cli\Command\DiffCommand;
use Atoms\Cli\Command\InitCommand;
use Atoms\Cli\Command\MakeAtomCommand;
use Atoms\Cli\Command\RollbackCommand;
use Atoms\Cli\Command\SecretsListCommand;
use Atoms\Cli\Command\SharedSecretSetCommand;
use Atoms\Cli\Command\SharedSecretUnsetCommand;
use Atoms\Cli\Command\SecretsSetCommand;
use Atoms\Cli\Command\StatusCommand;
use Atoms\Cli\Command\TokenCommand;
use Atoms\Cli\Command\ValidateCommand;
use Atoms\Cli\Release\RuntimeVersion;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * The `atoms` binary's Symfony Console application. The Wrangler seam is
 * injectable so the test suite can drive deploy/status/rollback/secrets against
 * a fake — `wrangler deploy` talks to Cloudflare, and tests never hit the
 * network.
 */
final class Application extends ConsoleApplication
{
    public const VERSION = RuntimeVersion::VERSION;

    public function __construct(?Wrangler $wrangler = null)
    {
        parent::__construct('atoms', self::VERSION);

        $wrangler ??= new ProcessWrangler();

        $this->addCommands([
            new InitCommand(),
            new MakeAtomCommand(),
            new ValidateCommand(),
            new BuildCommand(),
            new DiffCommand(),
            new DeployCommand($wrangler),
            new RollbackCommand($wrangler),
            new StatusCommand($wrangler),
            new SecretsSetCommand($wrangler),
            new SecretsListCommand($wrangler),
            new SharedSecretSetCommand($wrangler),
            new SharedSecretUnsetCommand($wrangler),
            new DevCommand($wrangler),
            new TokenCommand(),
            new AiInstallCommand(),
        ]);
    }
}
