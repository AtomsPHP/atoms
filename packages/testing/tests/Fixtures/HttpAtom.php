<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests\Fixtures;

use Atoms\Atom;
use Atoms\Http\HttpResponse;

final class HttpAtom extends Atom
{
    public const URL = 'https://api.example.com/status';

    protected function onActivation(): void
    {
        $this->http()->request('HEAD', self::URL);
    }

    public function send(array $headers = [], ?string $body = null, string $url = self::URL): HttpResponse
    {
        return $this->http()->request('POST', $url, $headers, $body);
    }
}
