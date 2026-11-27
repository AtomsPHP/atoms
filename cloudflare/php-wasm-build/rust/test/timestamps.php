<?php
declare(strict_types=1);
echo PHP_VERSION, ' int=', PHP_INT_SIZE, "\n";
foreach ([7, 8] as $second) {
    $value = gmmktime(3, 14, $second, 1, 19, 2038);
    echo json_encode($value), "\n";
    if ($value !== 2147483640 + $second) throw new RuntimeException('UTC timestamp mismatch');
}
