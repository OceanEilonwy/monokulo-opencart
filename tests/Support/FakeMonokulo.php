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
			'curies'             => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
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

	/**
	 * Monokulo's problems the plugin meets, by route and name, as the
	 * examples in Monokulo's OpenAPI document record them (the smoke test
	 * checks each against the document written at the pinned commit).
	 */
	public const PROBLEMS = [
		'POST /api/v1/orders' => [
			'invalid-amount'         => ['detail' => 'unknown currency: NOPE', 'status' => 400, 'title' => 'The order can\'t be priced', 'type' => 'https://docs.monokulo.com/problems/invalid-amount'],
			'invalid-request'        => ['detail' => 'Failed to deserialize the JSON body into the target type: amount: Amount must match ^[0-9]+(\\.[0-9]{1,12}0*)?$, like 12.50 at line 1 column 18', 'status' => 400, 'title' => 'Invalid request', 'type' => 'https://docs.monokulo.com/problems/invalid-request'],
			'unauthorized'           => ['detail' => 'This key was not accepted. Check it, or connect the store or app again.', 'status' => 401, 'title' => 'No valid key', 'type' => 'https://docs.monokulo.com/problems/unauthorized'],
			'forbidden'              => ['detail' => 'This store only accepts orders from its verified websites, or from its own server using the store\'s secret key.', 'status' => 403, 'title' => 'Not allowed with this key', 'type' => 'https://docs.monokulo.com/problems/forbidden'],
			'idempotency-conflict'   => ['detail' => 'this idempotency_key was already used for a different order', 'status' => 409, 'title' => 'Key used for another order', 'type' => 'https://docs.monokulo.com/problems/idempotency-conflict'],
			'too-large'              => ['detail' => 'Failed to buffer the request body: length limit exceeded', 'status' => 413, 'title' => 'Request too large', 'type' => 'https://docs.monokulo.com/problems/too-large'],
			'unsupported-media-type' => ['detail' => 'Expected request with `Content-Type: application/json`', 'status' => 415, 'title' => 'Send JSON', 'type' => 'https://docs.monokulo.com/problems/unsupported-media-type'],
			'rate-limited'           => ['detail' => 'Rate limit exceeded; retry in 120 s.', 'retry_after' => 120, 'status' => 429, 'title' => 'Too many requests', 'type' => 'https://docs.monokulo.com/problems/rate-limited'],
			'internal'               => ['detail' => 'Something went wrong on this Monokulo. Try again in a moment.', 'status' => 500, 'title' => 'Internal error', 'type' => 'https://docs.monokulo.com/problems/internal'],
			'engine-unavailable'     => ['detail' => 'The payment engine is busy or unreachable. Try again in a moment.', 'status' => 503, 'title' => 'Payment engine unavailable', 'type' => 'https://docs.monokulo.com/problems/engine-unavailable'],
			'rates-unavailable'      => ['detail' => 'There\'s no exchange rate for USD right now: the rate providers are busy or unreachable. Try again in 42 s.', 'retry_after' => 42, 'status' => 503, 'title' => 'No exchange rate right now', 'type' => 'https://docs.monokulo.com/problems/rates-unavailable'],
			'store-unavailable'      => ['detail' => 'This store\'s settings can\'t be read right now. Please try again shortly.', 'status' => 503, 'title' => 'Store unavailable', 'type' => 'https://docs.monokulo.com/problems/store-unavailable'],
		],
		'GET /api/v1' => [
			'unauthorized' => ['detail' => 'This key was not accepted. Check it, or connect the store or app again.', 'status' => 401, 'title' => 'No valid key', 'type' => 'https://docs.monokulo.com/problems/unauthorized'],
			'rate-limited' => ['detail' => 'Rate limit exceeded; retry in 120 s.', 'retry_after' => 120, 'status' => 429, 'title' => 'Too many requests', 'type' => 'https://docs.monokulo.com/problems/rate-limited'],
		],
		'POST /api/v1/logs' => [
			'invalid-request'        => ['detail' => 'Failed to deserialize the JSON body into the target type: missing field `entries` at line 1 column 2', 'status' => 400, 'title' => 'Invalid request', 'type' => 'https://docs.monokulo.com/problems/invalid-request'],
			'unauthorized'           => ['detail' => 'This key was not accepted. Check it, or connect the store or app again.', 'status' => 401, 'title' => 'No valid key', 'type' => 'https://docs.monokulo.com/problems/unauthorized'],
			'forbidden'              => ['detail' => 'A pk key may not do this. It takes sk.', 'status' => 403, 'title' => 'Not allowed with this key', 'type' => 'https://docs.monokulo.com/problems/forbidden'],
			'logs-off'               => ['detail' => 'This store hasn\'t turned on client logs. Its owner can turn them on in the store\'s settings.', 'status' => 403, 'title' => 'Client logs are off for this store', 'type' => 'https://docs.monokulo.com/problems/logs-off'],
			'too-large'              => ['detail' => 'Failed to buffer the request body: length limit exceeded', 'status' => 413, 'title' => 'Request too large', 'type' => 'https://docs.monokulo.com/problems/too-large'],
			'unsupported-media-type' => ['detail' => 'Expected request with `Content-Type: application/json`', 'status' => 415, 'title' => 'Send JSON', 'type' => 'https://docs.monokulo.com/problems/unsupported-media-type'],
			'rate-limited'           => ['detail' => 'Rate limit exceeded; retry in 120 s.', 'retry_after' => 120, 'status' => 429, 'title' => 'Too many requests', 'type' => 'https://docs.monokulo.com/problems/rate-limited'],
		],
		'POST /plugins/connect/{slug}/finish' => [
			'invalid-request'        => ['detail' => 'A webhook URL must start with http:// or https://.', 'status' => 400, 'title' => 'Invalid request', 'type' => 'https://docs.monokulo.com/problems/invalid-request'],
			'invalid-connect-code'   => ['detail' => 'This connect code is unknown, already used or expired. Start connecting again.', 'status' => 401, 'title' => 'Connect code not valid', 'type' => 'https://docs.monokulo.com/problems/invalid-connect-code'],
			'not-found'              => ['detail' => 'Presta! isn\'t a plugin\'s slug: a lowercase letter, then 1 to 31 lowercase letters, digits or hyphens.', 'status' => 404, 'title' => 'Not found', 'type' => 'https://docs.monokulo.com/problems/not-found'],
			'too-large'              => ['detail' => 'Failed to buffer the request body: length limit exceeded', 'status' => 413, 'title' => 'Request too large', 'type' => 'https://docs.monokulo.com/problems/too-large'],
			'unsupported-media-type' => ['detail' => 'Expected request with `Content-Type: application/json`', 'status' => 415, 'title' => 'Send JSON', 'type' => 'https://docs.monokulo.com/problems/unsupported-media-type'],
			'rate-limited'           => ['detail' => 'Rate limit exceeded; retry in 120 s.', 'retry_after' => 120, 'status' => 429, 'title' => 'Too many requests', 'type' => 'https://docs.monokulo.com/problems/rate-limited'],
			'internal'               => ['detail' => 'Something went wrong on this Monokulo. Try again in a moment.', 'status' => 500, 'title' => 'Internal error', 'type' => 'https://docs.monokulo.com/problems/internal'],
			'no-public-address'      => ['detail' => 'This Monokulo instance can\'t connect plugins yet: its operator hasn\'t set its public address (the public URI setting on the admin settings page). Plugins need it to create orders and send customers to the checkout. Ask the operator to set it, then try connecting again.', 'status' => 503, 'title' => 'No public address yet', 'type' => 'https://docs.monokulo.com/problems/no-public-address'],
		],
	];

	/** @return array<string, mixed> the problem `name` as `route` (`POST /api/v1/orders`) answers it, recorded in PROBLEMS */
	public static function recorded(string $route, string $name): array {
		return self::PROBLEMS[$route][$name] ?? throw new \LogicException('no recorded ' . $name . ' for ' . $route);
	}

	/**
	 * Makes `route` (`POST /api/v1/orders`, its `{slug}` this plugin's,
	 * under `prefix`) answer with its recorded problem `name`, and with
	 * `Retry-After` when the problem has a `retry_after`, as Monokulo does.
	 */
	public function answersWith(string $route, string $name, string $prefix = ''): void {
		$problem = self::recorded($route, $name);
		[$method, $path] = explode(' ', str_replace('{slug}', 'opencart', $route), 2);
		$this->respond($method, $prefix . $path, $problem['status'], $problem, headers: ['Content-Type' => 'application/problem+json'] + (isset($problem['retry_after']) ? ['Retry-After' => (string)$problem['retry_after']] : []));
	}

	/** Makes `METHOD path` answer with a hand-built problem (`application/problem+json`), for an answer Monokulo's OpenAPI has no example of. */
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
