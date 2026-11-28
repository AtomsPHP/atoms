<?php
declare(strict_types=1);

namespace Atoms\Cf {
    // Controlled return values exercise the driver's cleanup of a rejected
    // reply. The separate DO test loads the production NativePdo function.
    function native_pdo(string $op, int $handle): mixed
    {
        return match ($op) {
            'open' => 1,
            'owns' => true,
            'begin', 'rollback' => \post_message_to_js('~' . $op),
            'exec' => new \InvalidReply($GLOBALS['db']),
        };
    }
}
namespace {
    final class InvalidReply
    {
        public function __construct(private PDO $db) {}
        public function __destruct()
        {
            $this->db->beginTransaction();
            echo 'resumed in destructor', "\n";
            $mode = file_get_contents('/fixture/mode');
            if ($mode === 'compile') {
                require '/fixture/compile.php';
            }
            if ($mode === 'oom') {
                ini_set('memory_limit', '8M');
                post_message_to_js('!large');
            }
            $this->db->rollBack();
        }
    }
    $db = new PDO('atoms:');
    try {
        $db->exec('invalid return fixture');
    } catch (PDOException $exception) {
        echo $exception->getMessage(), "\n";
    }
    echo 'returned to caller', "\n";
}
