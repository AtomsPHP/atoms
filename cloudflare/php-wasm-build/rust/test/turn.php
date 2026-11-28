<?php
declare(strict_types=1);

function door(string $tag, array $message): array
{
    $reply = post_message_to_js($tag . json_encode($message, JSON_THROW_ON_ERROR));
    return json_decode($reply, true, flags: JSON_THROW_ON_ERROR);
}

function sql(string $statement, array $bindings = []): array
{
    $reply = door('!', ['op' => 'sql.exec', 'sql' => $statement, 'bindings' => $bindings]);
    if (!$reply['ok']) {
        throw new RuntimeException($reply['error']['message']);
    }
    return $reply['rows'];
}

register_shutdown_function(static function (): void {
    sql("INSERT INTO entries VALUES ('shutdown')");
});

$result = null;
while (true) {
    $turn = door('~', ['op' => 'turn.await', 'result' => $result]);
    $key = $turn['key'];
    try {
        switch ($turn['mode']) {
            case 'read':
                $result = sql('SELECT k FROM entries ORDER BY k');
                break;
            case 'write':
                sql('INSERT INTO entries VALUES (?)', [$key]);
                $result = sql('SELECT k FROM entries WHERE k = ?', [$key]);
                break;
            case 'catch':
                throw new DomainException('caught');
            default:
                door('~', ['op' => 'tx.begin']);
                sql('INSERT INTO entries VALUES (?)', [$key]);
                $result = sql('SELECT k FROM entries WHERE k = ?', [$key]);
                if ($result !== [['k' => $key]]) {
                    throw new LogicException('Transaction could not read its write');
                }
                if ($turn['mode'] === 'compile') {
                    require '/fixture/compile.php';
                }
                if ($turn['mode'] === 'oom') {
                    ini_set('memory_limit', '8M');
                    door('!', ['op' => 'fixture.largeReply']);
                }
                door('~', ['op' => 'tx.rollback']);
        }
    } catch (DomainException $exception) {
        $result = $exception->getMessage();
    }
}
