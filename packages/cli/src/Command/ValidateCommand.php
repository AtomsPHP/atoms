<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\ValidationResult;
use Atoms\Cli\Build\Validator;
use Atoms\Cli\Build\Violation;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms validate` — the fast, network-free PR check. Runs the extraction
 * pipeline's static stages and reports every finding with its ATOMS-E### code and
 * catalog fix line. Exits non-zero only when there are errors (warnings pass).
 */
#[AsCommand(name: 'validate', description: 'Statically validate Atom code against the boundary rules')]
final class ValidateCommand extends AbstractCommand
{
    public function __construct(private readonly Validator $validator = new Validator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->atomsJson($input);
            $result = $this->validator->validate($config);
        } catch (AtomsError $e) {
            return $this->fail($output, $input->getOption('json') === true, $e);
        }

        if ($input->getOption('json') === true) {
            $output->writeln($this->json($result));

            return $result->ok() ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($output, $result);

        return $result->ok() ? self::SUCCESS : self::FAILURE;
    }

    private function renderHuman(OutputInterface $output, ValidationResult $result): void
    {
        if ($result->errors === [] && $result->warnings === []) {
            $output->writeln('<info>✓ No boundary violations.</info>');
        }

        $this->renderGroup($output, 'Errors', $result->errors, 'error');
        $this->renderGroup($output, 'Warnings', $result->warnings, 'comment');

        $output->writeln('');
        $output->writeln(sprintf(
            'manifest hash: %s  (%d error(s), %d warning(s))',
            $result->manifestHash(),
            \count($result->errors),
            \count($result->warnings),
        ));
    }

    /**
     * @param list<Violation> $violations
     */
    private function renderGroup(OutputInterface $output, string $heading, array $violations, string $style): void
    {
        if ($violations === []) {
            return;
        }

        $output->writeln('');
        $output->writeln("<{$style}>{$heading}</{$style}>");
        foreach ($violations as $v) {
            $output->writeln(sprintf('  %s:%d', $v->file, $v->line));
            $output->writeln('    ' . $v->message());
        }
    }

    private function json(ValidationResult $result): string
    {
        return json_encode([
            'ok' => $result->ok(),
            'manifest_hash' => $result->manifestHash(),
            'errors' => array_map($this->violationToArray(...), $result->errors),
            'warnings' => array_map($this->violationToArray(...), $result->warnings),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{code: string, file: string, line: int, message: string}
     */
    private function violationToArray(Violation $v): array
    {
        return [
            'code' => $v->code->value,
            'file' => $v->file,
            'line' => $v->line,
            'message' => $v->message(),
        ];
    }

    private function fail(OutputInterface $output, bool $json, AtomsError $e): int
    {
        if ($json) {
            $output->writeln(json_encode([
                'ok' => false,
                'error' => ['code' => $e->errorCode->value, 'message' => $e->getMessage()],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
        }

        return self::FAILURE;
    }
}
