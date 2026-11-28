<?php
declare(strict_types=1);

function post_message_to_js(string $message): string
{
    static $stream;
    $stream ??= fopen('/dev/atoms-reply', 'rb');
    $length = atoms_exchange_length($message);
    if ($length < 0 || $stream === false) {
        throw new RuntimeException('Invalid Atoms bridge transfer');
    }
    if ($length === 0) {
        return '';
    }
    // php_stream_read_to_str allocates the Zend string before reading the
    // device. A memory-limit bailout stays on the guarded PHP execution stack.
    $reply = fread($stream, $length);
    if ($reply === false || strlen($reply) !== $length) {
        throw new RuntimeException('Incomplete Atoms bridge reply');
    }
    return $reply;
}
