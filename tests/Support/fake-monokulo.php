<?php
/**
 * A stand-in Monokulo for tests, as a router for PHP's built-in server
 * (`php -S 127.0.0.1:<port> fake-monokulo.php`). FAKE_MONOKULO_DIR holds
 * its state: `responses.json` says what to answer ("POST /path" →
 * {status, body}), and every request it gets is appended to
 * `requests.jsonl` for the test to read back. An answer's `headers` replace
 * the default `Content-Type: application/json`; one with an `ETag` answers
 * `304 Not Modified` to a request whose `If-None-Match` is that tag.
 */
$dir = getenv('FAKE_MONOKULO_DIR');
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$key = $_SERVER['REQUEST_METHOD'] . ' ' . $path;

$headers = [];
foreach ($_SERVER as $name => $value) {
	if (strncmp($name, 'HTTP_', 5) === 0) {
		$headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
	}
}
if (isset($_SERVER['CONTENT_TYPE'])) {
	$headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

file_put_contents($dir . '/requests.jsonl', json_encode([
	'method'  => $_SERVER['REQUEST_METHOD'],
	'path'    => $path,
	'query'   => (string)($_SERVER['QUERY_STRING'] ?? ''),
	'headers' => $headers,
	'body'    => (string)file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$responses = is_file($dir . '/responses.json') ? json_decode((string)file_get_contents($dir . '/responses.json'), true) : [];
$response = $responses[$key] ?? ['status' => 404, 'body' => ['type' => 'https://docs.monokulo.com/problems/not-found', 'title' => 'Not found', 'status' => 404], 'headers' => ['Content-Type' => 'application/problem+json']];

if (!empty($response['delay'])) {
	sleep((int)$response['delay']);
}

$answer = $response['headers'] ?? ['Content-Type' => 'application/json'];
// An answer with an ETag is a 304 with no body to a request that has it, as Monokulo's entry is.
if (isset($answer['ETag'], $headers['if-none-match']) && $headers['if-none-match'] === $answer['ETag']) {
	http_response_code(304);
	header('ETag: ' . $answer['ETag']);
	header('Cache-Control: ' . ($answer['Cache-Control'] ?? 'no-store'));

	return;
}

http_response_code((int)$response['status']);
foreach ($answer as $name => $value) {
	header($name . ': ' . $value);
}
echo is_string($response['body']) ? $response['body'] : json_encode($response['body']);
