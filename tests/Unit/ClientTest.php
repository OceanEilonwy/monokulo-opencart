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
		];
	}

	/** The handshake's problems come back by name, with their detail. */
	#[DataProvider('handshakeProblems')]
	public function test_a_refused_handshake_names_its_problem(int $status, string $name): void {
		self::$monokulo->problem('POST', '/plugins/connect/opencart/finish', $status, $name, 'why: ' . $name);

		try {
			$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([$status, 'why: ' . $name, $name], [$e->status, $e->detail, $e->problem]);
		}
	}

	/** The entry with the key, then its create-order form; the checkout and the dashboard page are the order's links. */
	public function test_creating_an_order_follows_the_entrys_form_with_the_secret_key(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');

		$order = $this->client()->createOrder($this->connection(), ['amount' => '1', 'currency' => 'EUR']);

		$this->assertSame([
			'order_id'           => 'order_1',
			'checkout_url'       => self::$monokulo->url . '/checkout/order_1',
			'onion_checkout_url' => '',
			'dashboard_url'      => self::$monokulo->url . '/stores/' . FakeMonokulo::STORE_ID . '/orders/order_1',
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

	/** Under a path every link carries it: nothing is built from `/api/v1`. */
	public function test_under_a_path_every_link_keeps_it(): void {
		self::$monokulo->serveEntry('/monokulo');
		self::$monokulo->createsOrder('order_1', '/monokulo');
		self::$monokulo->respond('POST', '/monokulo/api/v1/logs', 204, '');

		$order = $this->client()->createOrder($this->connection('/monokulo'), []);
		$this->client()->forwardLogs($this->connection('/monokulo'), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame(self::$monokulo->url . '/monokulo/checkout/order_1', $order['checkout_url']);
		$this->assertSame(self::$monokulo->url . '/monokulo/stores/' . FakeMonokulo::STORE_ID . '/orders/order_1', $order['dashboard_url']);
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
		self::$monokulo->problem('POST', '/api/v1/orders', 401, 'unauthorized', 'unknown key');

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
		$problem = static fn(string $name, string $detail): string => (string)json_encode(['type' => 'https://docs.monokulo.com/problems/' . $name, 'title' => 'T', 'detail' => $detail]);

		return [
			'rate limited'   => [429, $problem('rate-limited', 'slow down'), 'slow down', 'rate-limited', 'error_busy'],
			'engine down'    => [503, $problem('engine-unavailable', 'busy'), 'busy', 'engine-unavailable', 'error_busy'],
			'refused'        => [403, $problem('forbidden', 'not this key'), 'not this key', 'forbidden', 'error_unavailable'],
			'currency'       => [400, $problem('invalid-amount', 'unsupported currency: NOK'), 'unsupported currency: NOK', 'invalid-amount', 'error_currency'],
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

	/** A key Monokulo no longer knows fails at the entry already. */
	public function test_a_replaced_key_fails_at_the_entry(): void {
		self::$monokulo->problem('GET', '/api/v1', 401, 'unauthorized', 'unknown key');

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([401, 'unknown key', 'unauthorized', 'error_unavailable'], [$e->status, $e->detail, $e->problem, $e->customerMessageKey()]);
			$this->assertStringContainsString('connect the store again', $e->merchantHint());
		}
		$this->assertSame([], $this->entries->kept, 'nothing kept');
	}

	public function test_a_hint_for_the_merchant_on_the_answers_that_have_one(): void {
		$this->assertStringContainsString('exchange rate', (new ApiError(400, '', 'invalid-amount'))->merchantHint());
		$this->assertStringContainsString('different order', (new ApiError(409, '', 'idempotency-conflict'))->merchantHint());
		$this->assertStringContainsString('connect the store again', (new ApiError(401, ''))->merchantHint());
		$this->assertStringContainsString('connect the store again', (new ApiError(403, ''))->merchantHint());
		$this->assertStringContainsString('Monokulo address', (new ApiError(404, ''))->merchantHint());
		$this->assertStringContainsString('rate limited', (new ApiError(429, ''))->merchantHint());
		$this->assertStringContainsString('busy', (new ApiError(503, ''))->merchantHint());
		$this->assertSame('', (new ApiError(500, ''))->merchantHint());
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

	/** Nothing to send, a store with client logs off, an entry without the form, a dead Monokulo: all quietly nothing. */
	public function test_forwarding_is_best_effort(): void {
		$this->client()->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$one = [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']];
		self::$monokulo->serveEntry();
		self::$monokulo->problem('POST', '/api/v1/logs', 403, 'logs-off', 'client logs are off');
		$this->client()->forwardLogs($this->connection(), $one);
		$this->assertSame(['GET /api/v1', 'POST /api/v1/logs'], $this->requested());

		$entry = FakeMonokulo::entry();
		unset($entry['_templates']['send-logs']);
		$this->entries->kept = [];
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		$this->client()->forwardLogs($this->connection(), $one);

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		$this->client()->forwardLogs($dead, $one);
		$this->addToAssertionCount(1);
	}
}
