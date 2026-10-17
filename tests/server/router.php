<?php

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__ready') {
    echo 'sereno-test-server';
    return;
}

$dir = getenv('SERENO_TEST_DIR');
$logFile = $dir.'/requests.log';
$controlFile = $dir.'/control.json';

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}

$lock = fopen($dir.'/lock', 'c');
flock($lock, LOCK_EX);

$index = is_file($logFile) ? count(file($logFile)) : 0;
file_put_contents($logFile, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
])."\n", FILE_APPEND);

flock($lock, LOCK_UN);

$responses = is_file($controlFile) ? json_decode(file_get_contents($controlFile), true) : [];
$response = $responses[min($index, count($responses) - 1)] ?? ['status' => 202, 'body' => '{"accepted":1}'];

if (($response['sleep'] ?? 0) > 0) {
    usleep((int) ($response['sleep'] * 1_000_000));
}

http_response_code($response['status']);
header('Content-Type: application/json');
echo $response['body'] ?? '';
