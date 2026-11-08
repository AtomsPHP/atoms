<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\GeneratedWranglerConfig;
use Atoms\Cli\Cloudflare\ProcessWrangler;
use Atoms\Cli\Tests\Support\FakeProcessRunner;
use Atoms\Cli\Tests\TestCase;

final class ProcessWranglerTest extends TestCase
{
    public function testDevAndDeployPassTheirOwnConfigEvenWhenAStaleRedirectExists(): void
    {
        $dir = $this->freshDir() . '/worker with spaces';
        mkdir($dir . '/.wrangler/deploy', 0777, true);
        file_put_contents($dir . '/wrangler.jsonc', '{"name":"source-worker","main":"src/index.js"}');
        // Explicit configuration must bypass even a redirect that cannot parse.
        file_put_contents($dir . '/.wrangler/deploy/config.json', 'invalid stale redirect');
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'test-token', $dir);
        $generated = GeneratedWranglerConfig::generate($target, $target->runtimeVars());
        $path = $generated->write();
        $runner = new FakeProcessRunner(onPath: ['wrangler' => '/test/wrangler']);
        $wrangler = new ProcessWrangler($runner);

        $wrangler->deploy($target, $path);
        $wrangler->dev($target, '8787', $path);

        self::assertSame(['/test/wrangler', 'deploy', '--config', $path], $runner->runs[0]['command']);
        self::assertSame(['/test/wrangler', 'dev', '--config', $path, '--port', '8787'], $runner->runs[1]['command']);
        foreach ($runner->runs as $run) {
            self::assertSame($dir, $run['cwd']);
            self::assertSame('test-token', $run['env']['CLOUDFLARE_API_TOKEN']);
            self::assertNotContains('test-token', $run['command']);
        }
        self::assertSame('invalid stale redirect', file_get_contents($dir . '/.wrangler/deploy/config.json'));
    }
}
