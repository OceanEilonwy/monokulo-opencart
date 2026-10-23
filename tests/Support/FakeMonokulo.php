<?php
namespace Monokulo\OpenCart\Tests\Support;

/**
 * Runs tests/Support/fake-monokulo.php on a free local port for the length
 * of a test class, and lets a test say what it answers and read back what
 * it was sent.
 */
final class FakeMonokulo {
	/** @var resource */
	private $process;
	private string $dir;
	public readonly string $url;

	public function __construct() {
		$this->dir = sys_get_temp_dir() . '/fake-monokulo-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$port = self::freePort();
		$this->url = 'http://127.0.0.1:' . $port;
		$this->process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fake-monokulo.php'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
			$pipes,
			null,
			['FAKE_MONOKULO_DIR' => $this->dir]
		);
		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket) {
				fclose($socket);

				return;
			}
			usleep(50000);
		}

		throw new \RuntimeException('the fake Monokulo did not start: ' . (string)@file_get_contents($this->dir . '/server.log'));
	}

	/**
	 * Answers `METHOD path` with `status` and `body` (a string, or
	 * JSON-encoded), as `application/json` unless `headers` say otherwise.
	 *
	 * @param array<string, string>|null $headers
	 */
	public function respond(string $method, string $path, int $status, mixed $body, int $delaySeconds = 0, ?array $headers = null): void {
		$responses = is_file($this->dir . '/responses.json') ? json_decode((string)file_get_contents($this->dir . '/responses.json'), true) : [];
		$responses[$method . ' ' . $path] = ['status' => $status, 'body' => $body, 'delay' => $delaySeconds] + ($headers !== null ? ['headers' => $headers] : []);
		file_put_contents($this->dir . '/responses.json', json_encode($responses));
	}

	/**
	 * The API's entry at `/api/v1`, as Monokulo shows it to a store's secret
	 * key: its links and the `create-order` form, whose target is `/api/v1/orders`.
	 */
	public function serveEntry(): void {
		$this->respond('GET', '/api/v1', 200, self::entry(), headers: ['Content-Type' => 'application/prs.hal-forms+json']);
	}

	/** @return array<string, mixed> the entry's body, the shape Monokulo's has (the smoke test checks it against Monokulo's OpenAPI) */
	public static function entry(): array {
		return [
			'_links'     => [
				'self'      => ['href' => '/api/v1'],
				'mk:orders' => ['href' => '/api/v1/orders'],
				'curies'    => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
			],
			'_templates' => [
				'create-order' => ['method' => 'POST', 'target' => '/api/v1/orders', 'contentType' => 'application/json', 'properties' => []],
			],
		];
	}

	/** Makes `POST /api/v1/orders` answer 201 with order `orderId`, as Monokulo does. */
	public function createsOrder(string $orderId): void {
		$this->respond('POST', '/api/v1/orders', 201, self::order($orderId), headers: ['Content-Type' => 'application/prs.hal-forms+json', 'Location' => '/api/v1/orders/' . $orderId]);
	}

	/** @return array<string, mixed> an order's body, the shape Monokulo's has (the smoke test checks it against Monokulo's OpenAPI) */
	public static function order(string $orderId): array {
		return [
			'order_id'                 => $orderId,
			'status'                   => 'pending',
			'is_final'                 => false,
			'address'                  => '8...',
			'xmr_amount_piconero'      => 1,
			'amount_received_piconero' => 0,
			'amount'                   => '1',
			'currency'                 => 'EUR',
			'merchant_order_id'        => '1',
			'description'              => 'Order #1',
			'show_merchant_order_id'   => false,
			'confirmations'            => 0,
			'confirmations_required'   => 10,
			'created_at'               => 1,
			'expires_at'               => 2,
			'_links'                   => [
				'self'            => ['href' => '/api/v1/orders/' . $orderId],
				'collection'      => ['href' => '/api/v1/orders'],
				'mk:checkout'     => ['href' => '/checkout/' . $orderId],
				'mk:order-events' => ['href' => '/api/v1/orders/' . $orderId . '/events'],
			],
		];
	}

	/** Makes `METHOD path` answer with a problem (`application/problem+json`). */
	public function problem(string $method, string $path, int $status, string $name, string $detail): void {
		$this->respond($method, $path, $status, ['type' => 'https://docs.monokulo.com/problems/' . $name, 'title' => 'A problem', 'status' => $status, 'detail' => $detail], headers: ['Content-Type' => 'application/problem+json']);
	}

	/** @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> */
	public function requests(): array {
		if (!is_file($this->dir . '/requests.jsonl')) {
			return [];
		}
		$out = [];
		foreach (file($this->dir . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$out[] = json_decode($line, true);
		}

		return $out;
	}

	/** Forgets every answer and request so far. */
	public function reset(): void {
		@unlink($this->dir . '/responses.json');
		@unlink($this->dir . '/requests.jsonl');
	}

	public function stop(): void {
		proc_terminate($this->process);
		proc_close($this->process);
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	/** A port nothing listens on, closed again: the server takes it next. */
	public static function freePort(): int {
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$name = stream_socket_get_name($socket, false);
		fclose($socket);

		return (int)substr((string)strrchr((string)$name, ':'), 1);
	}
}
