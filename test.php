<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$args = $_SERVER['argv'];
array_shift($args);

if (empty($args)) {
    $args = ['--configuration', __DIR__ . '/phpunit.xml'];
}

$_SERVER['argv'] = array_merge(['phpunit'], $args);
exit((new PHPUnit\TextUI\Application)->run($_SERVER['argv']));
