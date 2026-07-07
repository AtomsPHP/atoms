<?php

declare(strict_types=1);

namespace Atoms\Cli;

use Atoms\Cli\Command\AiInstallCommand;
use Atoms\Cli\Command\BuildCommand;
use Atoms\Cli\Command\DeployCommand;
use Atoms\Cli\Command\DiffCommand;
use Atoms\Cli\Command\InitCommand;
use Atoms\Cli\Command\LocalCommand;
use Atoms\Cli\Command\MakeAtomCommand;
use Atoms\Cli\Command\RollbackCommand;
use Atoms\Cli\Command\SecretsListCommand;
use Atoms\Cli\Command\SecretsSetCommand;
use Atoms\Cli\Command\StatusCommand;
use Atoms\Cli\Command\ValidateCommand;
use Atoms\Cli\Platform\CurlPlatformApi;
use Atoms\Cli\Platform\PlatformApi;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Symfony\Component\Console\Application as ConsoleApplication;

/**
 * The `atoms` binary's Symfony Console application. The platform transport and
 * the process runner are injectable so the test suite can drive deploy/local
 * commands against fakes without any network or Docker.
 */
final class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0';

    public function __construct(?PlatformApi $api = null, ?ProcessRunner $runner = null)
    {
        parent::__construct('atoms', self::VERSION);

        $api ??= new CurlPlatformApi();
        $runner ??= new SymfonyProcessRunner();

        $this->addCommands([
            new InitCommand(),
            new MakeAtomCommand(),
            new ValidateCommand(),
            new BuildCommand(),
            new DiffCommand(),
            new DeployCommand($api),
            new RollbackCommand($api),
            new StatusCommand($api),
            new SecretsSetCommand($api),
            new SecretsListCommand($api),
            new LocalCommand($runner),
            new AiInstallCommand(),
        ]);
    }
}
