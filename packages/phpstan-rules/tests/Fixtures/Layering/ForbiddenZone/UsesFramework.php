<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Tests\Fixtures\Layering\ForbiddenZone;

use Illuminate\Support\Str;
use Illuminate\Support\{Arr, Collection};
use function Illuminate\Support\str_slug;

#[\Symfony\Component\Routing\Annotation\Route('/foo')]
final class UsesFramework implements \Illuminate\Contracts\Support\Arrayable
{
    use \Illuminate\Support\Traits\Macroable;

    private \Doctrine\ORM\EntityManagerInterface $em;

    public function make(\GuzzleHttp\Client $client): \Illuminate\Support\Collection
    {
        $collection = new \Illuminate\Support\Collection();

        \Illuminate\Support\Str::slug('x');

        $code = \Illuminate\Http\Response::HTTP_OK;

        $class = \Illuminate\Support\Collection::class;

        if ($collection instanceof \Illuminate\Support\Collection) {
        }

        try {
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
        }

        config('app.name');

        $type = 'Illuminate\Http\Request';

        /** @var \Illuminate\Support\Collection $typed */
        $typed = $collection;

        \config('app.name');

        \app('foo');

        return $typed;
    }
}
