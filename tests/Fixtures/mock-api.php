<?php

/**
 * Router for `php -S`: records each request and answers with the next scripted
 * response from responses.json (the last one repeats). Used by ClientTest.
 */

$dir = (string) getenv('UTOPIA_MOCK_DIR');
$responses = json_decode((string) file_get_contents($dir . '/responses.json'), true);
$count = count(glob($dir . '/request-*.json') ?: []);

file_put_contents(sprintf('%s/request-%03d.json', $dir, $count), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
]));

$response = $responses[min($count, count($responses) - 1)];
http_response_code($response['status'] ?? 200);
header('Content-Type: application/json');
foreach ($response['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($response['json'] ?? new stdClass());
