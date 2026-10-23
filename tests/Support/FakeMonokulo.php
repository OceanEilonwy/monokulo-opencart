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

	/** Monokulo's onion address, when a test gives it one (the Tor Project's own, as Monokulo's tests use). */
	public const ONION = 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion';

	/** The ETag the stand-in's entry has. */
	public const ENTRY_ETAG = '"0123456789abcdef0123456789abcdef"';

	/**
	 * The API's entry at `{prefix}/api/v1`, as Monokulo shows it to a
	 * store's secret key, kept as Monokulo's is (an hour, with an ETag).
	 * `prefix` is the path of a Monokulo served under one (`/monokulo`);
	 * `onion` gives it an onion address.
	 */
	public function serveEntry(string $prefix = '', bool $onion = false): void {
		$this->respond('GET', $prefix . '/api/v1', 200, self::entry($prefix, $onion), headers: [
			'Content-Type'  => 'application/prs.hal-forms+json',
			'Cache-Control' => 'private, max-age=3600',
			'Vary'          => 'Authorization',
			'ETag'          => self::ENTRY_ETAG,
		]);
	}

	/** @return array<string, mixed> the entry's body, the shape Monokulo's has (the smoke test checks it against Monokulo's OpenAPI) */
	public static function entry(string $prefix = '', bool $onion = false): array {
		$links = [
			'self'         => ['href' => $prefix . '/api/v1'],
			'mk:store'     => ['href' => $prefix . '/api/v1/store'],
			'mk:orders'    => ['href' => $prefix . '/api/v1/orders'],
			'mk:events'    => ['href' => $prefix . '/api/v1/events', 'type' => 'text/event-stream'],
			'service-desc' => ['href' => $prefix . '/api/v1/openapi.json', 'type' => 'application/openapi+json'],
			'service-doc'  => ['href' => 'https://docs.monokulo.com/api/', 'type' => 'text/html'],
			'curies'       => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
		];
		if ($onion) {
			$links['mk:onion-api'] = ['href' => self::ONION . '/api/v1'];
		}

		return [
			'_links'     => $links,
			'_templates' => [
				'create-order' => ['method' => 'POST', 'target' => $prefix . '/api/v1/orders', 'contentType' => 'application/json', 'properties' => []],
				'send-logs'    => ['method' => 'POST', 'target' => $prefix . '/api/v1/logs', 'contentType' => 'application/json', 'properties' => []],
			],
		];
	}

	/** Makes `POST {prefix}/api/v1/orders` answer 201 with order `orderId`, as Monokulo does. */
	public function createsOrder(string $orderId, string $prefix = '', bool $onion = false): void {
		$this->respond('POST', $prefix . '/api/v1/orders', 201, self::order($orderId, $prefix, $onion), headers: ['Content-Type' => 'application/prs.hal-forms+json', 'Location' => $prefix . '/api/v1/orders/' . $orderId]);
	}

	/** The store the stand-in's orders are in, on the merchant's dashboard. */
	public const STORE_ID = '917701c2-15c7-4bcb-bbc8-72d9ab28e785';

	/** @return array<string, mixed> an order's body as the store's secret key sees it, the shape Monokulo's has (the smoke test checks it against Monokulo's OpenAPI) */
	public static function order(string $orderId, string $prefix = '', bool $onion = false): array {
		$links = [
			'self'               => ['href' => $prefix . '/api/v1/orders/' . $orderId],
			'collection'         => ['href' => $prefix . '/api/v1/orders'],
			'mk:checkout'        => ['href' => $prefix . '/checkout/' . $orderId, 'type' => 'text/html'],
			'mk:dashboard-order' => ['href' => $prefix . '/stores/' . self::STORE_ID . '/orders/' . $orderId, 'type' => 'text/html'],
			'mk:order-events'    => ['href' => $prefix . '/api/v1/orders/' . $orderId . '/events', 'type' => 'text/event-stream'],
		];
		if ($onion) {
			$links['mk:onion-checkout'] = ['href' => self::ONION . '/checkout/' . $orderId, 'type' => 'text/html'];
		}

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
			'_links'                   => $links,
		];
	}

	/**
	 * Makes `POST {prefix}/plugins/connect/opencart/finish` hand over the
	 * store's credentials, as Monokulo does: the entry under `prefix`, and
	 * `onion_api` when `onion`.
	 */
	public function finishes(string $prefix = '', bool $onion = false): void {
		$this->respond('POST', $prefix . '/plugins/connect/opencart/finish', 200, self::connected($prefix, $onion));
	}

	/** @return array<string, string> the plugin handshake's answer, the shape Monokulo's has (the smoke test checks it against Monokulo's OpenAPI) */
	public static function connected(string $prefix = '', bool $onion = false): array {
		return ['public_key' => 'pk_new', 'secret_token' => 'sk_new', 'api' => $prefix . '/api/v1']
			+ ($onion ? ['onion_api' => self::ONION . '/api/v1'] : [])
			+ ['webhook_signing_secret' => 'whsec_new'];
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
