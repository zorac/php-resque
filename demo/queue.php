<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (empty($argv[1])) {
    die('Specify the name of a job to add. e.g, php queue.php PHP_Job');
}

date_default_timezone_set('GMT');
Resque\Resque::setBackend('127.0.0.1:6379');

$args = [
    'time' => time(),
    'array' => [
        'test' => 'test',
    ],
];

$jobId = Resque\Resque::enqueue('default', $argv[1], $args, true);
echo "Queued job ".$jobId."\n\n";
