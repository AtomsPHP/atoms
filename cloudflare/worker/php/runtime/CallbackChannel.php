<?php

/**
 * Shared machinery for `app()` and `dispatch()`: the one place that maps a
 * host door failure (`{"ok":false,"error":{"code":...}}`) onto the typed
 * exception a customer should see. Both {@see CallbackAppProxy} (park door)
 * and {@see CfAtomContext::dispatch()} (sync door) cross on different doors
 * but must fail identically for the codes they share, so this is the one
 * place that decides.
 *
 * No declare(strict_types=1) — see the note in host.php.
 */

namespace Atoms\Cf;

final class CallbackChannel
{
    /**
     * @param array<string, mixed> $error the reply's "error" object
     * @param string $atom "{type}/{id}"
     * @param string $method the app() method name or dispatch()'d job class
     * @return CallbackError
     */
    public static function exceptionFor(array $error, $atom, $method)
    {
        $code = isset($error['code']) ? (string) $error['code'] : 'unknown';
        $message = isset($error['message']) ? (string) $error['message'] : 'unknown error';

        switch ($code) {
            case 'callback_not_configured':
                return CallbackNotConfigured::create();

            case 'callback_unsigned':
                return CallbackUnsigned::create($message);

            case 'turn_deadline_exceeded':
                $elapsed = isset($error['elapsed_ms']) ? (int) $error['elapsed_ms'] : 0;
                $budget = isset($error['budget_ms']) ? (int) $error['budget_ms'] : 0;

                return TurnDeadlineExceeded::create($elapsed, $budget);

            case 'tx_state':
                // Host-side defence in depth: the guest-side guard in
                // CallbackAppProxy is supposed to catch this first.
                return CallbackInTransaction::for($method);

            // callback_timeout, callback_transport, callback_too_large,
            // callback_body_invalid, dispatch_limit, and anything else the
            // door can answer with.
            default:
                return CallbackFailed::forOp($atom, $method, $code, $message);
        }
    }
}
