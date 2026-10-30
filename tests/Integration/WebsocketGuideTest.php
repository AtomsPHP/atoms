<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Cli\Build\Validator;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;

final class WebsocketGuideTest extends TestCase
{
    public function testDocumentedBroadcastOnlyDashboardEnablesWebsockets(): void
    {
        $guide = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/docs-site/src/content/docs/guides/websockets-timers.md',
        );
        self::assertSame(1, preg_match(
            '/```php title="app\/Atoms\/Dashboard.php"\R(.*?)\R```/s',
            $guide,
            $example,
        ));

        $dir = $this->tempCopy('ws-app');
        file_put_contents($dir . '/app/Atoms/Dashboard.php', $example[1]);
        $result = (new Validator())->validate(AtomsJson::load($dir . '/atoms.json'));

        self::assertSame([], $result->errors);
        $byType = array_column($result->manifest['atoms'], null, 'type');
        self::assertTrue($byType['Dashboard']['websocket']);
        self::assertSame(['publish'], array_column($byType['Dashboard']['methods'], 'name'));
    }
}
