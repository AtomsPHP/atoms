<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Atoms\Examples\PlainPhp\ArrayQueueBridge;
use Atoms\Examples\PlainPhp\AtomsBootstrap;
use GuzzleHttp\Psr7\HttpFactory;

$factory = new HttpFactory(); // one class, every PSR-17 role AtomsBootstrap::create() asks for.

// This endpoint only answers inbound platform callbacks, so AtomsClient's PSR-18
// client is never actually dialed here. Wire a real one (e.g. guzzlehttp/guzzle's
// Client) the moment this monolith also calls out via $app->client()->call().
$http = new class implements \Psr\Http\Client\ClientInterface {
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        throw new \LogicException('No outbound HTTP client wired here — see the comment above.');
    }
};

$sharedSecretPrevious = getenv('ATOMS_SHARED_SECRET_PREVIOUS');

$app = AtomsBootstrap::create(
    endpoint: (string) getenv('ATOMS_ENDPOINT'),
    sharedSecret: (string) getenv('ATOMS_SHARED_SECRET'),
    callbackPath: '/atoms/callback',
    http: $http,
    requestFactory: $factory,
    serverRequestFactory: $factory,
    responseFactory: $factory,
    streamFactory: $factory,
    queueBridge: new ArrayQueueBridge(),
    sharedSecretPrevious: $sharedSecretPrevious !== false ? $sharedSecretPrevious : null,
);

$response = $app->handleGlobals($_SERVER, (string) file_get_contents('php://input'));

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
echo $response->getBody();
