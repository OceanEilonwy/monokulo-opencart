<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\ApiError;
use Monokulo\OpenCart\Client;
use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Entry;
use Monokulo\OpenCart\Plugin;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Tests\Support\MemoryEntries;
use Monokulo\OpenCart\Trace;
use Monokulo\OpenCart\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The client against a stand-in Monokulo over real HTTP on this machine. */
final class ClientTest extends TestCase {
	private static FakeMonokulo $monokulo;

	private MemoryEntries $entries;
	private int $now = 1760000000;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
		$this->entries = new MemoryEntries();
	}

	private function client(?Trace $trace = null): Client {
		return new Client($trace ?? new Trace(), $this->entries, fn(): int => $this->now);
	}

	private function connection(string $prefix = ''): Connection {
		return new Connection('shop.example', self::$monokulo->url . $prefix . '/api/v1', 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	/** @return list<string> "METHOD path" of each request Monokulo got */
	private function requested(): array {
		return array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests());
	}

	public function test_finishing_connect_sends_the_token_webhook_and_name(): void {
		self::$monokulo->finishes();
		$trace = new Trace();

		$credentials = $this->client($trace)->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/index.php?route=x');

		$this->assertSame(['public_key' => 'pk_new', 'secret_token' => 'sk_new', 'api' => self::$monokulo->url . '/api/v1', 'onion_api' => '', 'webhook_signing_secret' => 'whsec_new'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/index.php?route=x', 'name' => 'OpenCart'], json_decode($request['body'], true));
		$this->assertSame('opencart/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** A Monokulo with an onion address hands over its entry there too. */
	public function test_finishing_connect_keeps_the_onion_entry(): void {
		self::$monokulo->finishes(onion: true);

		$this->assertSame(FakeMonokulo::ONION . '/api/v1', $this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/')['onion_api']);
	}

	/** A Monokulo under a path: the handshake is under it, and so is the entry it gives. */
	public function test_finishing_connect_under_a_path_keeps_the_path(): void {
		self::$monokulo->finishes('/monokulo');

		$credentials = $this->client()->finishConnect(self::$monokulo->url . '/monokulo/', 'tok', 'https://shop.example/');

		$this->assertSame(self::$monokulo->url . '/monokulo/api/v1', $credentials['api']);
		$this->assertSame(['POST /monokulo/plugins/connect/opencart/finish'], $this->requested());
	}

	/** An entry given as a full address (another host) is kept as it is. */
	public function test_an_absolute_api_entry_is_kept(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, ['api' => 'https://pay.example.com/api/v1'] + FakeMonokulo::connected());

		$this->assertSame('https://pay.example.com/api/v1', $this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/')['api']);
	}

	/** Without a webhook secret the shop could never trust a webhook: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		$answer = FakeMonokulo::connected();
		unset($answer['webhook_signing_secret']);
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, $answer);

		$this->expectException(ApiError::class);
		$this->expectExceptionMessage('missing webhook_signing_secret');

		$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	public static function handshakeProblems(): array {
		return [
			'bad token'         => [401, 'invalid-connect-code'],
			'no public address' => [503, 'no-public-address'],
			'bad request'       => [400, 'invalid-request'],
			'busy'              => [429, 'rate-limited'],
			'failed'            => [500, 'internal'],
			'unknown slug'      => [404, 'not-found'],
			'too large'         => [413, 'too-large'],
			'not JSON'          => [415, 'unsupported-media-type'],
		];
	}

	/** The handshake's problems, as Monokulo's OpenAPI records them, come back by name, with their detail. */
	#[DataProvider('handshakeProblems')]
	public function test_a_refused_handshake_names_its_problem(int $status, string $name): void {
		self::$monokulo->answersWith('POST /plugins/connect/{slug}/finish', $name);

		try {
			$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([$status, FakeMonokulo::recorded('POST /plugins/connect/{slug}/finish', $name)['detail'], $name], [$e->status, $e->detail, $e->problem]);
		}
	}

	/** The entry with the key, then its create-order form; the checkout is the order's link. */
	public function test_creating_an_order_follows_the_entrys_form_with_the_secret_key(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');

		$order = $this->client()->createOrder($this->connection(), ['amount' => '1', 'currency' => 'EUR']);

		$this->assertSame([
			'order_id'           => 'order_1',
			'checkout_url'       => self::$monokulo->url . '/checkout/order_1',
			'onion_checkout_url' => '',
		], $order);
		[$entry, $create] = self::$monokulo->requests();
		$this->assertSame(['GET', '/api/v1'], [$entry['method'], $entry['path']]);
		$this->assertSame('Bearer sk_test', $entry['headers']['authorization']);
		$this->assertStringContainsString('application/prs.hal-forms+json', $entry['headers']['accept']);
		$this->assertSame(['POST', '/api/v1/orders'], [$create['method'], $create['path']]);
		$this->assertSame('Bearer sk_test', $create['headers']['authorization']);
		$this->assertSame('opencart/' . Plugin::VERSION, $create['headers']['monokulo-client']);
		$this->assertSame('application/json', $create['headers']['content-type']);
		$this->assertSame(['amount' => '1', 'currency' => 'EUR'], json_decode($create['body'], true));
	}

	/**
	 * Monokulo leaves out a field with no value: an order without an
	 * amount, currency, merchant order id, description or cancellation
	 * time is still an order.
	 */
	public function test_an_order_without_its_optional_fields_is_an_order(): void {
		$order = FakeMonokulo::order('order_1');
		unset($order['amount'], $order['currency'], $order['merchant_order_id'], $order['description']);
		$this->assertArrayNotHasKey('cancelled_at', $order);
		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/orders', 201, $order, headers: ['Content-Type' => 'application/prs.hal-forms+json']);

		$this->assertSame([
			'order_id'           => 'order_1',
			'checkout_url'       => self::$monokulo->url . '/checkout/order_1',
			'onion_checkout_url' => '',
		], $this->client()->createOrder($this->connection(), []));
	}

	/** While Monokulo has an onion address, the order's checkout there comes back too, as it is. */
	public function test_an_order_carries_its_onion_checkout(): void {
		self::$monokulo->serveEntry(onion: true);
		self::$monokulo->createsOrder('order_1', onion: true);

		$order = $this->client()->createOrder($this->connection(), []);

		$this->assertSame(FakeMonokulo::ONION . '/checkout/order_1', $order['onion_checkout_url']);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $order['checkout_url']);
	}

	/** Whether Monokulo has an onion address is its entry's `mk:onion-api`, read from the kept entry. */
	public function test_the_entry_says_whether_monokulo_has_an_onion_address(): void {
		self::$monokulo->serveEntry();
		$this->assertFalse($this->client()->hasOnionAddress($this->connection()));

		self::$monokulo->serveEntry(onion: true);
		$this->entries->kept = [];
		$this->assertTrue($this->client()->hasOnionAddress($this->connection()));
		$this->assertTrue($this->client()->hasOnionAddress($this->connection()));

		$this->assertSame(['GET /api/v1', 'GET /api/v1'], $this->requested(), 'once per kept entry');
	}

	/** A kept entry without `mk:onion-api` is asked about again before the answer is no: a 304 keeps it, a new entry with one says yes. */
	public function test_a_kept_entry_without_an_onion_address_is_revalidated_before_saying_no(): void {
		$this->entries->kept['sk_test'] = new Entry(FakeMonokulo::entry(), FakeMonokulo::ENTRY_ETAG, $this->now + 3000);
		self::$monokulo->serveEntry();

		$this->assertFalse($this->client()->hasOnionAddress($this->connection()));
		$this->assertSame(FakeMonokulo::ENTRY_ETAG, self::$monokulo->requests()[0]['headers']['if-none-match']);
		$this->assertSame($this->now + 3600, $this->entries->kept['sk_test']->expiresAt, 'the 304 keeps it another hour');

		self::$monokulo->reset();
		self::$monokulo->respond('GET', '/api/v1', 200, FakeMonokulo::entry(onion: true), headers: ['Content-Type' => 'application/prs.hal-forms+json', 'Cache-Control' => 'private, max-age=3600', 'ETag' => '"with-onion"']);

		$this->assertTrue($this->client()->hasOnionAddress($this->connection()));
		$this->assertSame('"with-onion"', $this->entries->kept['sk_test']->etag);
		$this->assertSame(['GET /api/v1'], $this->requested());
	}

	/** Under a path every link carries it: nothing is built from `/api/v1`. */
	public function test_under_a_path_every_link_keeps_it(): void {
		self::$monokulo->serveEntry('/monokulo');
		self::$monokulo->createsOrder('order_1', '/monokulo');
		self::$monokulo->respond('POST', '/monokulo/api/v1/logs', 204, '');

		$order = $this->client()->createOrder($this->connection('/monokulo'), []);
		$this->client()->forwardLogs($this->connection('/monokulo'), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame(self::$monokulo->url . '/monokulo/checkout/order_1', $order['checkout_url']);
		$this->assertSame(['GET /monokulo/api/v1', 'POST /monokulo/api/v1/orders', 'POST /monokulo/api/v1/logs'], $this->requested());
	}

	/** Wherever the form says to go, the order goes there. */
	public function test_the_forms_target_and_method_are_followed(): void {
		$entry = FakeMonokulo::entry();
		$entry['_templates']['create-order'] = ['method' => 'PUT', 'target' => '/api/v1/elsewhere'];
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		self::$monokulo->respond('PUT', '/api/v1/elsewhere', 201, FakeMonokulo::order('order_2'));

		$this->assertSame('order_2', $this->client()->createOrder($this->connection(), [])['order_id']);
	}

	/** The entry is kept for its max-age: the next order is one request. */
	public function test_the_entry_is_kept_for_its_max_age(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');

		$this->client()->createOrder($this->connection(), []);
		$this->now += 3599;
		$this->client()->createOrder($this->connection(), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders', 'POST /api/v1/orders'], $this->requested());
		$kept = $this->entries->kept['sk_test'];
		$this->assertSame([FakeMonokulo::ENTRY_ETAG, 1760003600], [$kept->etag, $kept->expiresAt]);
	}

	/** Once it has expired it is asked for again with its ETag: unchanged, a 304 keeps it another hour. */
	public function test_an_expired_entry_is_revalidated(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');
		$this->client()->createOrder($this->connection(), []);
		self::$monokulo->reset();
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');
		$this->now += 3600;

		$this->client()->createOrder($this->connection(), []);

		[$entry] = self::$monokulo->requests();
		$this->assertSame(FakeMonokulo::ENTRY_ETAG, $entry['headers']['if-none-match']);
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], $this->requested());
		$this->assertSame($this->now + 3600, $this->entries->kept['sk_test']->expiresAt);
		$this->assertSame(FakeMonokulo::entry(), $this->entries->kept['sk_test']->body, 'the kept body, since the 304 has none');
	}

	/** An entry with no Cache-Control is not kept: it is read for every order. */
	public function test_an_entry_without_a_max_age_is_read_each_time(): void {
		self::$monokulo->respond('GET', '/api/v1', 200, FakeMonokulo::entry());
		self::$monokulo->createsOrder('order_1');

		$this->client()->createOrder($this->connection(), []);
		$this->client()->createOrder($this->connection(), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders', 'GET /api/v1', 'POST /api/v1/orders'], $this->requested());
	}

	public static function staleAnswers(): array {
		return [
			'401: the key changed'  => [401, 'unauthorized'],
			'404: the API moved on' => [404, 'not-found'],
			'405: the API moved on' => [405, 'method-not-allowed'],
		];
	}

	/** A form from a kept entry that is refused with 401, 404 or 405 has the entry read again, and is sent once more where it now says. */
	#[DataProvider('staleAnswers')]
	public function test_a_stale_entry_is_read_again_when_its_form_is_refused(int $status, string $name): void {
		$old = FakeMonokulo::entry();
		$old['_templates']['create-order']['target'] = '/api/v1/old-orders';
		$this->entries->kept['sk_test'] = new Entry($old, '"old"', $this->now + 100);
		self::$monokulo->problem('POST', '/api/v1/old-orders', $status, $name, 'gone');
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');

		$this->assertSame('order_1', $this->client()->createOrder($this->connection(), [])['order_id']);

		$this->assertSame(['POST /api/v1/old-orders', 'GET /api/v1', 'POST /api/v1/orders'], $this->requested());
		$this->assertArrayNotHasKey('if-none-match', self::$monokulo->requests()[1]['headers'], 'read afresh');
		$this->assertSame(FakeMonokulo::ENTRY_ETAG, $this->entries->kept['sk_test']->etag);
	}

	/** A fresh entry's form refused is the answer: no loop. */
	public function test_a_refusal_from_a_fresh_entry_is_not_retried(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->answersWith('POST /api/v1/orders', 'unauthorized');

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(401, $e->status);
		}
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], $this->requested());
	}

	/** A kept entry without the form is read again; a fresh one without it fails before ordering. */
	public function test_a_key_without_the_form_is_refused_before_ordering(): void {
		$entry = FakeMonokulo::entry();
		unset($entry['_templates']);
		$this->entries->kept['sk_test'] = new Entry($entry, '', $this->now + 100);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertStringContainsString('no create-order form', $e->detail);
			$this->assertSame('error_generic', $e->customerMessageKey());
		}
		$this->assertSame(['GET /api/v1'], $this->requested());
	}

	public static function failures(): array {
		$problem = static function (string $name, string $customer): array {
			$recorded = FakeMonokulo::recorded('POST /api/v1/orders', $name);

			return [$recorded['status'], (string)json_encode($recorded), $recorded['detail'], $name, $customer];
		};

		return [
			'rate limited'   => $problem('rate-limited', 'error_busy'),
			'engine down'    => $problem('engine-unavailable', 'error_busy'),
			'no rate'        => $problem('rates-unavailable', 'error_rates'),
			'refused'        => $problem('forbidden', 'error_unavailable'),
			'currency'       => $problem('invalid-amount', 'error_currency'),
			'already used'   => $problem('idempotency-conflict', 'error_generic'),
			'failed'         => $problem('internal', 'error_generic'),
			'title only'     => [500, '{"type":"https://docs.monokulo.com/problems/internal","title":"Internal error"}', 'Internal error', 'internal', 'error_generic'],
			'not JSON'       => [500, 'oops', 'oops', '', 'error_generic'],
			'not created'    => [200, '{"order_id":"x"}', '{"order_id":"x"}', '', 'error_generic'],
			'no checkout'    => [201, '{"order_id":"x"}', 'not an order with a checkout link: {"order_id":"x"}', '', 'error_generic'],
			'not an object'  => [201, 'null', 'not JSON: null', '', 'error_generic'],
		];
	}

	#[DataProvider('failures')]
	public function test_a_failed_order_says_what_to_tell_the_customer(int $status, string $body, string $detail, string $problem, string $customer): void {
		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/orders', $status, $body, headers: ['Content-Type' => $status < 300 ? 'application/prs.hal-forms+json' : 'application/problem+json']);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($problem, $e->problem);
			$this->assertSame($customer, $e->customerMessageKey());
		}
	}

	/** Only a problem is read as one: anything else is reported as it came. */
	public function test_an_error_that_is_not_a_problem_is_reported_by_its_body(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/orders', 502, '{"detail":"from a proxy"}', headers: ['Content-Type' => 'application/json']);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([502, '{"detail":"from a proxy"}', ''], [$e->status, $e->detail, $e->problem]);
		}
	}

	/** A rate-limited entry is no order: the customer tries again shortly, and the log says how long Monokulo asked to wait. */
	public function test_a_rate_limited_entry_is_retryable(): void {
		self::$monokulo->answersWith('GET /api/v1', 'rate-limited');

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([429, 'rate-limited', 120, 'error_busy'], [$e->status, $e->problem, $e->retryAfter, $e->customerMessageKey()]);
			$this->assertStringEndsWith('Monokulo asked to wait 120 seconds before trying again.', $e->merchantHint());
		}
		$this->assertSame([], $this->entries->kept, 'nothing kept');
	}

	/** A key Monokulo no longer knows fails at the entry already. */
	public function test_a_replaced_key_fails_at_the_entry(): void {
		self::$monokulo->answersWith('GET /api/v1', 'unauthorized');

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([401, FakeMonokulo::recorded('GET /api/v1', 'unauthorized')['detail'], 'unauthorized', 'error_unavailable'], [$e->status, $e->detail, $e->problem, $e->customerMessageKey()]);
			$this->assertStringContainsString('connect the store again', $e->merchantHint());
		}
		$this->assertSame([], $this->entries->kept, 'nothing kept');
	}

	/** How long to wait comes from the problem's `retry_after`, else the `Retry-After` header; neither, and it isn't known. */
	public function test_a_problem_says_how_long_to_wait(): void {
		$cases = [
			'in the body'      => [['retry_after' => 30], ['Retry-After' => '30'], 30],
			'header only'      => [[], ['Retry-After' => '12'], 12],
			'body over header' => [['retry_after' => 5], ['Retry-After' => '60'], 5],
			'neither'          => [[], [], null],
			'an HTTP date'     => [[], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null],
		];
		foreach ($cases as $name => [$fields, $headers, $wait]) {
			self::$monokulo->serveEntry();
			self::$monokulo->respond('POST', '/api/v1/orders', 503, (string)json_encode($fields + array_diff_key(FakeMonokulo::recorded('POST /api/v1/orders', 'rates-unavailable'), ['retry_after' => true])), headers: ['Content-Type' => 'application/problem+json'] + $headers);

			try {
				$this->client()->createOrder($this->connection(), []);
				$this->fail('no error');
			} catch (ApiError $e) {
				$this->assertSame($wait, $e->retryAfter, $name);
				$this->assertTrue($e->retryable(), $name);
			}
		}
	}

	/** Only "made nothing, try again" answers may be sent again as they are. */
	public function test_only_429_and_503_are_retryable(): void {
		foreach ([429 => true, 503 => true, 400 => false, 401 => false, 409 => false, 500 => false, 502 => false] as $status => $retryable) {
			$this->assertSame($retryable, (new ApiError($status, ''))->retryable(), (string)$status);
		}
	}

	public function test_a_hint_for_the_merchant_on_the_answers_that_have_one(): void {
		$this->assertStringContainsString('no exchange rate', (new ApiError(503, '', 'rates-unavailable'))->merchantHint());
		$this->assertStringContainsString('No order was made', (new ApiError(503, '', 'rates-unavailable'))->merchantHint());
		$this->assertStringEndsWith('Monokulo asked to wait 30 seconds before trying again.', (new ApiError(503, '', 'rates-unavailable', 30))->merchantHint());
		$this->assertStringEndsWith('Monokulo asked to wait 7 seconds before trying again.', (new ApiError(503, '', 'engine-unavailable', 7))->merchantHint());
		$this->assertStringEndsNotWith('before trying again.', (new ApiError(400, '', 'invalid-amount', 7))->merchantHint());
		$this->assertStringContainsString('exchange rate', (new ApiError(400, '', 'invalid-amount'))->merchantHint());
		$this->assertStringContainsString('different order', (new ApiError(409, '', 'idempotency-conflict'))->merchantHint());
		$this->assertStringContainsString('connect the store again', (new ApiError(401, ''))->merchantHint());
		$this->assertStringContainsString('connect the store again', (new ApiError(403, ''))->merchantHint());
		$this->assertStringContainsString('Monokulo address', (new ApiError(404, ''))->merchantHint());
		$this->assertStringContainsString('rate limited', (new ApiError(429, ''))->merchantHint());
		$this->assertStringContainsString('busy', (new ApiError(503, ''))->merchantHint());
		$this->assertStringContainsString('Monokulo\'s logs', (new ApiError(500, ''))->merchantHint());
		$this->assertSame('', (new ApiError(418, ''))->merchantHint());
	}

	public function test_nothing_listening_is_a_transport_error(): void {
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		$this->client()->createOrder($connection, []);
	}

	/** Errors go with the entry's send-logs form and the secret key. */
	public function test_errors_are_forwarded_with_the_send_logs_form(): void {
		$entry = FakeMonokulo::entry();
		$entry['_templates']['send-logs']['target'] = '/api/v1/somewhere-for-logs';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		self::$monokulo->respond('POST', '/api/v1/somewhere-for-logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		$this->client()->forwardLogs($this->connection(), $entries);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/somewhere-for-logs'], $this->requested());
		$request = self::$monokulo->requests()[1];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	/** Nothing to send, or sent: nothing to note. */
	public function test_forwarding_says_nothing_when_sent(): void {
		$this->assertNull($this->client()->forwardLogs($this->connection(), []));
		$this->assertSame([], self::$monokulo->requests());

		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$this->assertNull($this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]));
	}

	public static function logsProblems(): array {
		return [
			'client logs off' => ['logs-off', 'the store\'s client logs are off in Monokulo. Turn them on in the store\'s settings in Monokulo, or turn off "Send errors to Monokulo" here'],
			'key rejected'    => ['unauthorized', 'Monokulo refused this store\'s key: connect the store again from the extension settings'],
			'key not allowed' => ['forbidden', 'Monokulo refused this store\'s key: connect the store again from the extension settings'],
			'rate limited'    => ['rate-limited', 'Monokulo is rate limiting this store\'s reports'],
			'too large'       => ['too-large', 'they were too large for Monokulo to read'],
			'unreadable'      => ['invalid-request', 'Monokulo could not read them. This is a bug in the extension: please report it with this log line'],
			'not JSON'        => ['unsupported-media-type', 'Monokulo could not read them. This is a bug in the extension: please report it with this log line'],
		];
	}

	/** Each problem send-logs answers with drops the reports, and says why for the shop's own log. */
	#[DataProvider('logsProblems')]
	public function test_reports_monokulo_refuses_are_dropped_with_a_reason(string $name, string $reason): void {
		self::$monokulo->serveEntry();
		self::$monokulo->answersWith('POST /api/v1/logs', $name);
		$problem = FakeMonokulo::recorded('POST /api/v1/logs', $name);

		$note = $this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame(sprintf('Could not send 1 error report(s) to Monokulo: %s. HTTP %d: %s', $reason, $problem['status'], $problem['detail']), $note);
		$this->assertSame(['GET /api/v1', 'POST /api/v1/logs'], $this->requested(), 'sent once, not retried');
	}

	/** An entry without the form, a dead Monokulo: dropped, with a reason. */
	public function test_forwarding_is_best_effort(): void {
		$one = [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']];
		$entry = FakeMonokulo::entry();
		unset($entry['_templates']['send-logs']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		$this->assertStringStartsWith('Could not send 1 error report(s) to Monokulo: ', (string)$this->client()->forwardLogs($this->connection(), $one));

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		$this->assertStringStartsWith('Could not send 1 error report(s) to Monokulo: could not reach it: ', (string)$this->client()->forwardLogs($dead, $one));
	}
	/** The Test connection button: the entry's send-test-webhook form, with `{}` and the store's key; Monokulo's answer as it came. */
	public function test_a_test_webhook_follows_the_entrys_form(): void {
		self::$monokulo->serveEntry('/monokulo');
		self::$monokulo->sendsTestWebhook(prefix: '/monokulo');

		$outcome = $this->client()->sendTestWebhook($this->connection('/monokulo'));

		$this->assertSame(['webhook_id' => 'wh_00000000000000000000000000000001', 'event_id' => 'evt_0000000000000002', 'delivered' => true, 'status' => 200, 'duration_ms' => 12, 'error' => null, 'next_attempt_at' => null], $outcome);
		$this->assertSame(['GET /monokulo/api/v1', 'POST /monokulo/api/v1/webhook-tests'], $this->requested());
		$request = self::$monokulo->requests()[1];
		$this->assertSame('{}', $request['body'], 'an object: the plugin\'s own webhook');
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('application/json', $request['headers']['content-type']);
	}

	public function test_an_undelivered_test_webhook_says_why_and_when_it_is_tried_again(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->sendsTestWebhook(delivered: false);

		$outcome = $this->client()->sendTestWebhook($this->connection());

		$this->assertFalse($outcome['delivered']);
		$this->assertSame([500, 'answered 500', FakeMonokulo::NEXT_ATTEMPT], [$outcome['status'], $outcome['error'], $outcome['next_attempt_at']]);
	}

	/** @return array<string, array{string, int, ?int}> */
	public static function webhookTestProblems(): array {
		return [
			'rate-limited' => ['rate-limited', 429, 120],
			'not-found'    => ['not-found', 404, null],
			'internal'     => ['internal', 500, null],
		];
	}

	#[DataProvider('webhookTestProblems')]
	public function test_a_test_webhook_monokulo_refuses_is_its_problem(string $name, int $status, ?int $wait): void {
		self::$monokulo->serveEntry();
		self::$monokulo->answersWith('POST /api/v1/webhook-tests', $name);

		try {
			$this->client()->sendTestWebhook($this->connection());
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([$status, $name, $wait], [$e->status, $e->problem, $e->retryAfter]);
		}
	}

	/** A kept entry from before Monokulo had the form is read again, once. */
	public function test_a_kept_entry_without_the_form_is_read_again(): void {
		$old = FakeMonokulo::entry();
		unset($old['_templates']['send-test-webhook']);
		$this->entries->keepEntry($this->connection(), new Entry($old, '"old"', $this->now + 3600));
		self::$monokulo->serveEntry();
		self::$monokulo->sendsTestWebhook();

		$this->assertTrue($this->client()->sendTestWebhook($this->connection())['delivered']);
		$this->assertSame(['GET /api/v1', 'POST /api/v1/webhook-tests'], $this->requested());
	}

	public function test_an_answer_that_is_not_an_outcome_is_an_error(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/webhook-tests', 200, ['hello' => 'world']);

		$this->expectException(ApiError::class);
		$this->client()->sendTestWebhook($this->connection());
	}
}
