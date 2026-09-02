<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Config\AtomsJson;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms make:atom Name` — scaffold the §2 two-worlds layout: the Atom class
 * (Atom-side) and, optionally, its Methods class (App-side), a first migration, and
 * WebSocket handler stubs. The directory shape is the mental model, so the
 * generator doubles as a teaching tool.
 */
#[AsCommand(name: 'make:atom', description: 'Scaffold an Atom and its two-worlds layout')]
final class MakeAtomCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('name', InputArgument::REQUIRED, 'Atom class name, e.g. GameRoom');
        $this->addOption('with-methods', null, InputOption::VALUE_NONE, 'Also scaffold a Methods class');
        $this->addOption('with-migration', null, InputOption::VALUE_NONE, 'Also scaffold a first migration');
        $this->addOption('websocket', null, InputOption::VALUE_NONE, 'Add WebSocket handler stubs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nameArg = $input->getArgument('name');
        if (!\is_string($nameArg) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $nameArg)) {
            $output->writeln('<error>Atom name must be a valid PHP class name.</error>');

            return self::FAILURE;
        }

        try {
            $config = $this->atomsJson($input);
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $baseNamespace = $this->resolveNamespace($config);
        $atomsDir = $config->atomsDir();
        $websocket = $input->getOption('websocket') === true;

        $written = [];

        $atomPath = $atomsDir . '/' . $nameArg . '.php';
        if (is_file($atomPath)) {
            $output->writeln("<error>{$atomPath} already exists.</error>");

            return self::FAILURE;
        }
        $this->put($atomPath, $this->atomSource($baseNamespace, $nameArg, $websocket));
        $written[] = $atomPath;

        if ($input->getOption('with-methods') === true) {
            $methodsPath = $atomsDir . '/' . $nameArg . '/Methods.php';
            $this->put($methodsPath, $this->methodsSource($baseNamespace . '\\' . $nameArg));
            $written[] = $methodsPath;
        }

        if ($input->getOption('with-migration') === true) {
            $snake = self::snake($nameArg);
            $migrationPath = $atomsDir . '/' . $nameArg . '/migrations/001_create_' . $snake . '_events.sql';
            $this->put($migrationPath, $this->migrationSource($snake));
            $written[] = $migrationPath;
        }

        $output->writeln('<info>✓ Scaffolded ' . $nameArg . '.</info>');
        foreach ($written as $path) {
            $output->writeln('  ' . $path);
        }

        return self::SUCCESS;
    }

    private function resolveNamespace(AtomsJson $config): string
    {
        $default = 'App\\Atoms';
        $composerPath = $config->rootDir . '/composer.json';
        $raw = @file_get_contents($composerPath);
        if ($raw === false) {
            return $default;
        }

        try {
            /** @var array{autoload?: array{'psr-4'?: array<string, string|list<string>>}} $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }

        $psr4 = $decoded['autoload']['psr-4'] ?? [];
        $atomsPath = trim($config->atomsPath, '/');

        foreach ($psr4 as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (!\is_string($dir)) {
                    continue;
                }
                $dir = trim($dir, '/');
                if ($dir !== '' && str_starts_with($atomsPath . '/', $dir . '/')) {
                    $suffix = trim(substr($atomsPath, \strlen($dir)), '/');
                    $ns = rtrim($prefix, '\\');
                    if ($suffix !== '') {
                        $ns .= '\\' . str_replace('/', '\\', $suffix);
                    }

                    return $ns;
                }
            }
        }

        return $default;
    }

    private function atomSource(string $namespace, string $name, bool $websocket): string
    {
        $uses = "use Atoms\\Atom;\n";
        $body = "    // Public methods here are invocable over the platform RPC boundary.\n";

        if ($websocket) {
            $uses .= "use Atoms\\Websocket\\Connection;\n";
            $uses .= "use Atoms\\Websocket\\Message;\n";
            $body .= <<<'PHP'

    public function onConnect(Connection $conn, array $params): void
    {
        // A client connected. Load or initialise per-connection state here.
    }

    public function onMessage(Connection $conn, Message $msg): void
    {
        // Handle an inbound frame. Keep the turn short.
    }

    public function onDisconnect(Connection $conn): void
    {
        // A client disconnected.
    }
PHP;
            $body .= "\n";
        }

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        {$uses}
        /**
         * Atom-side: ships to the platform and runs on the Atoms runtime. Only
         * atoms/core, Shared DTOs, and approved packages are legal in here.
         */
        class {$name} extends Atom
        {
        {$body}}

        PHP;
    }

    private function methodsSource(string $namespace): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Atoms\\AtomMethods;

        /**
         * App-side: stays in the monolith with full framework access. The Atom
         * reaches these via \$this->app(); their signatures are the contract.
         */
        class Methods extends AtomMethods
        {
        }

        PHP;
    }

    private function migrationSource(string $snake): string
    {
        return <<<SQL
        -- Runs once, at Atom activation, under the single-writer guarantee.
        -- Migrations are append-only after they ship: never edit this file.
        CREATE TABLE {$snake}_events (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            payload    TEXT    NOT NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );

        SQL;
    }

    private function put(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir}");
        }
        file_put_contents($path, $contents);
    }

    private static function snake(string $name): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name;

        return strtolower($snake);
    }
}
