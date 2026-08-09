<?php

declare(strict_types=1);

namespace Atoms\Cli\Platform;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Maps a non-2xx platform response (the API-contract error envelope) onto the
 * Atoms error catalog: 422 validation failures become ATOMS-E042, auth failures
 * ATOMS-E072, and retryable capacity/conflict statuses ATOMS-E062 with a hint.
 */
final class PlatformError
{
    public static function from(HttpResponse $response): AtomsError
    {
        $envelope = $response->error() ?? [];
        $message = \is_string($envelope['message'] ?? null) ? $envelope['message'] : 'the platform returned an error';
        $retryable = ($envelope['retryable'] ?? false) === true;

        return match (true) {
            $response->status === 422 => new AtomsError(
                ErrorCode::BundleRejected,
                ErrorCatalog::format(ErrorCode::BundleRejected, ['reason' => $message]),
            ),
            $response->status === 401 || $response->status === 403 => new AtomsError(
                ErrorCode::DeployCredentialsMissing,
                'ATOMS-E072: platform rejected the credentials (' . $message . '). Fix: check ATOMS_API_KEY / the environment.',
            ),
            $response->status === 409 || $retryable => new AtomsError(
                ErrorCode::CapacityRefused,
                ErrorCatalog::format(ErrorCode::CapacityRefused, ['reason' => $message])
                    . ($response->status === 409 ? ' (a deploy is already in progress — retry shortly)' : ''),
            ),
            default => new AtomsError(
                ErrorCode::BundleRejected,
                'ATOMS-E042: platform request failed (HTTP ' . $response->status . '): ' . $message,
            ),
        };
    }
}
