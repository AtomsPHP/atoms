<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\BundleStager;
use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;

final class BundleStagerTest extends TestCase
{
    private function workerDir(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', '{}');
        mkdir($dir . '/scripts', 0777, true);
        file_put_contents($dir . '/' . BundleStager::SCRIPT, '');

        return $dir;
    }

    public function testRelativePathsResolveAgainstCurrentDirectoryBeforeRunningInWorkerDir(): void
    {
        $callerDir = $this->freshDir();
        $workerDir = $this->workerDir();
        $target = new CloudflareTarget('production', 'acme', '', null, $workerDir);
        $runner = new FakeProcessRunner();
        $stager = new BundleStager($runner);

        $previousCwd = getcwd();
        self::assertIsString($previousCwd);
        chdir($callerDir);
        try {
            $resolvedCallerDir = (string) getcwd();
            $stager->stage($target, '.atoms/build/bundle.tar.gz', '.atoms/build/manifest.json');

            self::assertCount(1, $runner->runs);
            self::assertSame($workerDir, $runner->runs[0]['cwd']);
            self::assertSame($resolvedCallerDir . '/.atoms/build/bundle.tar.gz', $runner->runs[0]['command'][2]);
            self::assertSame($resolvedCallerDir . '/.atoms/build/manifest.json', $runner->runs[0]['command'][3]);
        } finally {
            chdir($previousCwd);
        }
    }

    public function testAbsolutePathsPassThroughUntouched(): void
    {
        $workerDir = $this->workerDir();
        $target = new CloudflareTarget('production', 'acme', '', null, $workerDir);
        $runner = new FakeProcessRunner();
        $stager = new BundleStager($runner);

        $stager->stage($target, '/tmp/prebuilt/bundle.tar.gz', '/tmp/prebuilt/manifest.json');

        self::assertCount(1, $runner->runs);
        self::assertSame('/tmp/prebuilt/bundle.tar.gz', $runner->runs[0]['command'][2]);
        self::assertSame('/tmp/prebuilt/manifest.json', $runner->runs[0]['command'][3]);
    }
}
