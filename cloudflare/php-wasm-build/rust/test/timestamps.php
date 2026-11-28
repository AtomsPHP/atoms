<?php
declare(strict_types=1);
echo PHP_VERSION, ' int=', PHP_INT_SIZE, "\n";
foreach ([
    [2038, 1, 19, 3, 14, 7, 2147483647],
    [2038, 1, 19, 3, 14, 8, 2147483648],
    [1901, 12, 13, 20, 45, 52, -2147483648],
    [1901, 12, 13, 20, 45, 51, -2147483649],
] as [$year, $month, $day, $hour, $minute, $second, $expected]) {
    $value = gmmktime($hour, $minute, $second, $month, $day, $year);
    echo json_encode($value), "\n";
    if ($value !== $expected) {
        throw new RuntimeException("Expected UTC timestamp $expected");
    }
}
