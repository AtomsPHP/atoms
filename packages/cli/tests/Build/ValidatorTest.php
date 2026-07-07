<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\Validator;
use Atoms\Cli\Build\Violation;
use Atoms\Cli\Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testSampleAppIsClean(): void
    {
        $result = (new Validator())->validate($this->sampleApp());

        self::assertTrue($result->ok(), 'sample-app should have no errors');
        self::assertSame([], $result->errors);
    }

    public function testViolatingAppEmitsExactlyTheExpectedCodes(): void
    {
        $result = (new Validator())->validate($this->violatingApp());

        $errorCodes = array_values(array_unique(array_map(
            static fn (Violation $v): string => $v->code->value,
            $result->errors,
        )));
        sort($errorCodes);

        self::assertSame(
            ['ATOMS-E010', 'ATOMS-E011', 'ATOMS-E012', 'ATOMS-E017', 'ATOMS-E018', 'ATOMS-E030', 'ATOMS-E032', 'ATOMS-E051'],
            $errorCodes,
        );

        $warningCodes = array_map(static fn (Violation $v): string => $v->code->value, $result->warnings);
        self::assertSame(['ATOMS-E001'], $warningCodes);
    }

    public function testFileAttribution(): void
    {
        $result = (new Validator())->validate($this->violatingApp());

        $byCode = [];
        foreach ([...$result->errors, ...$result->warnings] as $v) {
            $byCode[$v->code->value] = $v->file;
        }

        self::assertStringEndsWith('app/Atoms/BadRoom.php', $byCode['ATOMS-E010']);
        self::assertStringEndsWith('app/Atoms/BadRoom.php', $byCode['ATOMS-E030']);
        self::assertStringEndsWith('app/Atoms/BadRoom.php', $byCode['ATOMS-E051']);
        self::assertStringEndsWith('app/Atoms/Helper.php', $byCode['ATOMS-E001']);
    }

    public function testMessagesCarryCodeAndFix(): void
    {
        $result = (new Validator())->validate($this->violatingApp());

        $message = $result->errors[0]->message();
        self::assertStringContainsString('ATOMS-E0', $message);
        self::assertStringContainsString('Fix:', $message);
    }
}
