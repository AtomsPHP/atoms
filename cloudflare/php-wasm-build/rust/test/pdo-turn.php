<?php
declare(strict_types=1);

use Atoms\Cf\NativePdo;
use Atoms\Cf\SqlBridge;

foreach (['host', 'int64', 'BridgeSqlException', 'NamedParams', 'FetchMode', 'NativePdo', 'SqlBridge'] as $name) {
    require "/fixture/$name.php";
}
function park(array $message): array
{
    return json_decode(post_message_to_js('~' . json_encode($message, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
}
function runSql(PDO $pdo, string $sql, array $params = []): array
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

// Convert a reference while the native driver is executing. These failures
// occur below the PDO callback, with another live statement on the handle.
final class FailingBinding implements Stringable
{
    public function __construct(private string $mode) {}
    public function __toString(): string
    {
        if ($this->mode === 'compile') {
            require '/fixture/compile.php';
        }
        ini_set('memory_limit', '8M');
        return post_message_to_js('!{"op":"fixture.largeReply"}');
    }
}

$bridge = new SqlBridge();
NativePdo::enter($bridge);
$pdo = new PDO('atoms:');
register_shutdown_function(static function () use ($pdo): void {
    $pdo->exec("INSERT INTO entries VALUES ('shutdown')");
});
$result = null;
while (true) {
    NativePdo::leave();
    $turn = park(['op' => 'turn.await', 'result' => $result]);
    NativePdo::enter($bridge);
    $key = $turn['key'];
    try {
        switch ($turn['mode']) {
            case 'read':
                $result = runSql($pdo, 'SELECT k FROM entries ORDER BY k');
                break;
            case 'write':
                runSql($pdo, 'INSERT INTO entries VALUES (?)', [$key]);
                $result = runSql($pdo, 'SELECT k FROM entries WHERE k = ?', [$key]);
                break;
            case 'catch':
                throw new DomainException('caught');
            default:
                $pdo->beginTransaction();
                runSql($pdo, 'INSERT INTO entries VALUES (?)', [$key]);
                $result = runSql($pdo, 'SELECT k FROM entries WHERE k = ?', [$key]);
                if ($result !== [['k' => $key]]) {
                    throw new LogicException('PDO could not read its write inside the transaction');
                }
                if (in_array($turn['mode'], ['compile', 'oom'], true)) {
                    $held = $pdo->query('SELECT k FROM entries ORDER BY k');
                    $stmt = $pdo->prepare('SELECT ?');
                    $value = 'before';
                    $stmt->bindParam(1, $value, PDO::PARAM_STR);
                    $value = new FailingBinding($turn['mode']);
                    $stmt->execute();
                    throw new LogicException('The failing PDO conversion returned');
                }
                $pdo->rollBack();
        }
    } catch (DomainException $exception) {
        $result = $exception->getMessage();
    }
}
