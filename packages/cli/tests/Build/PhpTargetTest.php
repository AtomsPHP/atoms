<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Build\TargetSyntax;
use Atoms\Cli\Build\Validator;
use Atoms\Cli\Build\VendorStage;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Config\PhpTarget;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;

final class PhpTargetTest extends TestCase
{
    public function testSyntaxChecksUseTheTargetRatherThanTheCliInterpreter(): void
    {
        $old = new TargetSyntax(PhpTarget::forCompiler('php-wasm'));
        $new = new TargetSyntax(PhpTarget::forCompiler('typephp'));
        foreach ([
            '<?php const F = static fn ($value) => $value;',
            '<?php const F = strlen(...);',
            '<?php new Example()->method();',
            '<?php clone($value, ["name" => "value"]);',
            '<?php exit(status: 0);',
            '<?php #[Example] const VALUE = 1;',
            '<?php class Example { public private(set) string $name; }',
        ] as $source) {
            $new->check($source, 'example.php');
            try {
                $old->check($source, 'example.php');
                self::fail('PHP 8.3 accepted newer syntax: ' . $source);
            } catch (AtomsError $error) {
                self::assertStringContainsString('targets PHP 8.3.32', $error->getMessage());
            }
        }
        $old->check('<?php (new Example())->method(); $fn = strlen(...); exit(0);', 'example.php');
    }

    public function testPortable85BuildIsDeterministicAndDoesNotExecuteSource(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/app/Atoms/GameRoom/Support/NewSyntax.php', <<<'PHP'
<?php
namespace App\Atoms\GameRoom\Support;
throw new \RuntimeException('Build must not execute this file');
final class NewSyntax {
    public string $name { get => 'name'; }
    #[\Deprecated]
    public function name(): string { return $this->name |> trim(...); }
}
PHP);
        $config = AtomsJson::load($root . '/atoms.json')->withPhp('8.5');
        $builder = new Builder(runner: CannedComposer::runner());
        $one = $builder->build($config, $this->freshDir());
        $two = $builder->build($config, $this->freshDir());
        self::assertSame(hash_file('sha256', $one->bundlePath), hash_file('sha256', $two->bundlePath));
        $manifest = json_decode((string) file_get_contents($one->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('8.5', $manifest['toolchain']['php']);
        self::assertSame('8.5.9', $manifest['toolchain']['php_version']);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('targets PHP 8.3.32');
        $builder->build($config->withPhp('8.3'), $this->freshDir());
    }

    public function testTargetSyntaxAlsoChecksMigrationsAndVendorFiles(): void
    {
        $source = '<?php return " value " |> trim(...);';
        (new TargetSyntax(PhpTarget::forCompiler('typephp')))->check($source, 'migration.php');
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/app/Atoms/GameRoom/migrations/003_pipe.php', $source);
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('003_pipe.php targets PHP 8.3.32');
        (new Validator())->validate(AtomsJson::load($root . '/atoms.json'));
    }

    public function testVendorSyntaxIsCheckedEvenWhenThePackageHasNoPhpRequirement(): void
    {
        $root = $this->tempCopy('sample-app');
        $builder = new Builder(runner: CannedComposer::runner([
            'acme/lib/new.php' => '<?php return " value " |> trim(...);',
        ]));
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('vendor/acme/lib/new.php targets PHP 8.3.32');
        $builder->build(AtomsJson::load($root . '/atoms.json'), $this->freshDir());
    }

    public function testComposerUsesTheGuestVersionAndCachesEachTargetSeparately(): void
    {
        $root = $this->tempCopy('sample-app');
        $runner = CannedComposer::runner();
        $stage = new VendorStage($runner);
        $stage->resolve($root, '8.3.32');
        $stage->resolve($root, '8.5.9');
        self::assertCount(2, $runner->runs);
        $stage->resolve($root, '8.5.9');
        self::assertCount(2, $runner->runs);
        $staged = json_decode(VendorStage::stagedComposerJson($root, '{"require":{"php":"^8.5"}}', '8.5.9'), true);
        self::assertSame(['php' => '8.5.9', 'php-64bit' => '8.5.9'], $staged['config']['platform']);
        self::assertNotContains('--ignore-platform-reqs', VendorStage::composerCommand());
        VendorStage::assertPhpRequirements('{"require":{"php":"^8.5"}}', null, '8.5.9');
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('Atom runtime target is PHP 8.3.32');
        VendorStage::assertPhpRequirements('{"require":{"php":"^8.5"}}', null, '8.3.32');
    }

    public function testLockedTransitivePhpConstraintsAreEnforcedBeforeComposer(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/atoms-composer.lock', '{"packages":[{"name":"transitive/old","require":{"php":"^8.3"},"conflict":{"php":">=8.5"}}]}');
        $runner = CannedComposer::runner();
        try {
            (new VendorStage($runner))->resolve($root, '8.5.9');
            self::fail('A locked package incompatible with the Atom target must fail');
        } catch (AtomsError $error) {
            self::assertStringContainsString('transitive/old', $error->getMessage());
            self::assertStringContainsString('8.5.9', $error->getMessage());
            self::assertSame([], $runner->runs);
        }
    }

    public function testUnknownTargetsFailBeforeBuilding(): void
    {
        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('Unsupported Atom PHP target 8.4');
        (new Validator())->validate($this->sampleApp()->withPhp('8.4'));
    }

    public function testNewBuiltinSymbolsUseTheGuestTargetOnEveryCliVersion(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/app/Atoms/GameRoom/Support/NewBuiltins.php', <<<'PHP'
<?php
namespace App\Atoms\GameRoom\Support;
class NewBuiltins {
    public function value(): mixed { return array_first([\RoundingMode::HalfAwayFromZero]); }
}
PHP);
        $config = AtomsJson::load($root . '/atoms.json');
        $new = (new Validator())->validate($config->withPhp('8.5'));
        self::assertTrue($new->ok());
        self::assertContains('standard', $new->manifest['toolchain']['extensions']);
        $old = (new Validator())->validate($config);
        self::assertFalse($old->ok());
        self::assertCount(1, $old->errors);
    }
}
