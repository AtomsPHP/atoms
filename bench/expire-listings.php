<?php
// Retire only this qualification run's directory entries before the reversed round.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$dir = Atoms\Laravel\Facades\Atoms::get(App\Atoms\GameDirectory::class, App\Atoms\GameDirectory::ID);
foreach (json_decode(file_get_contents($argv[1]), true) as $id) {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Invalid test room');
    $dir->updateStatus($id, 'finished');
}
