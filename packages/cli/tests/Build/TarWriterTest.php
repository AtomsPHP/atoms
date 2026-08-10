<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\TarWriter;
use Atoms\Errors\AtomsError;
use PHPUnit\Framework\TestCase;

/**
 * ustar addresses a path across two fixed fields — `prefix` (155 bytes) and
 * `name` (100) — joined by a `/`. A path that does not fit cannot be stored,
 * and the writer used to truncate it, producing an archive whose entry names
 * disagreed with the manifest that described them. The build reported success
 * and nothing downstream could recover the real path.
 */
final class TarWriterTest extends TestCase
{
    public function testShortPathsUseTheNameFieldAlone(): void
    {
        $tar = TarWriter::build([['name' => 'app/Atoms/GameRoom.php', 'contents' => 'x']]);

        self::assertSame('app/Atoms/GameRoom.php', rtrim(substr($tar, 0, 100), "\0"));
        self::assertSame('', rtrim(substr($tar, 345, 155), "\0"));
    }

    public function testLongPathsSplitAcrossPrefixAndName(): void
    {
        $dir = 'app/Atoms/' . str_repeat('Deep/', 20);
        $path = $dir . 'GameRoom.php';
        self::assertGreaterThan(100, \strlen($path));

        $tar = TarWriter::build([['name' => $path, 'contents' => 'x']]);

        $name = rtrim(substr($tar, 0, 100), "\0");
        $prefix = rtrim(substr($tar, 345, 155), "\0");

        self::assertLessThanOrEqual(100, \strlen($name));
        self::assertLessThanOrEqual(155, \strlen($prefix));
        self::assertSame($path, $prefix . '/' . $name, 'the split must reconstruct the original path exactly');
    }

    /**
     * A single component longer than the name field has no split point, so the
     * path is unrepresentable. Refuse it rather than silently shortening it.
     */
    public function testAnUnsplittableComponentIsRefusedRatherThanTruncated(): void
    {
        $path = 'app/Atoms/' . str_repeat('A', 114) . '.php';

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E078/');
        TarWriter::build([['name' => $path, 'contents' => 'x']]);
    }

    /**
     * The old split took the last `/` within the first 155 bytes without
     * checking the tail, so a path could "split cleanly" and still lose bytes
     * in the 100-byte name field.
     */
    public function testATailTooLongForTheNameFieldIsRefused(): void
    {
        // One separator early, then a single component of 120 bytes.
        $path = 'app/' . str_repeat('B', 120);

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E078/');
        TarWriter::build([['name' => $path, 'contents' => 'x']]);
    }
}
