<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\ApiError;
use Monokulo\OpenCart\Client;
use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Plugin;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Trace;
use Monokulo\OpenCart\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The client against a stand-in Monokulo over real HTTP on this machine. */
final class ClientTest extends TestCase {
	private static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
	}

	private function connection(): Connection {
		return new Connection('shop.example', self::$monokulo->url . '/api/v1', 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	public function test_finishing_connect_sends_the_token_webhook_and_name(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_1']);
		$trace = new Trace();

		$credentials = (new Client($trace))->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/index.php?route=x');

		$this->assertSame(['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => self::$monokulo->url . '/api/v1', 'webhook_signing_secret' => 'whsec_1'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/index.php?route=x', 'name' => 'OpenCart'], json_decode($request['body'], true));
		$this->assertSame('opencart/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** An entry given as a full address (another host, an onion) is kept as it is. */
	public function test_an_absolute_api_entry_is_kept(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => 'https://pay.example.com/api/v1', 'webhook_signing_secret' => 'whsec_1']);

		$this->assertSame('https://pay.example.com/api/v1', (new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/')['api']);
	}

	/** Without a webhook secret the shop could never trust a webhook: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1']);

		$this->expectException(ApiError::class);
		$this->expectExceptionMessage('missing webhook_signing_secret');

		(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	/** The old handshake's answer, with `endpoint` and no `api`, is no connection. */
	public function test_finishing_without_an_api_entry_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_1']);

		$this->expectException(ApiError::class);
		$this->expectExceptionMessage('missing api');

		(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	public function test_a_monokulo_without_a_public_address_says_why(): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', 503, ['error' => 'no public address yet']);

		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(503, $e->status);
			$this->assertSame('no public address yet', $e->detail);
		}
	}

	/** The entry with the key, then its create-order form; the checkout is the order's link. */
	public function test_creating_an_order_follows_the_entrys_form_with_the_secret_key(): void {
		self::$monokulo->serveEntry();
		self::$monokulo->createsOrder('order_1');

		$order = (new Client(new Trace()))->createOrder($this->connection(), ['amount' => '1', 'currency' => 'EUR']);

		$this->assertSame(['order_id' => 'order_1', 'checkout_url' => self::$monokulo->url . '/checkout/order_1'], $order);
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

	/** Wherever the form says to go, the order goes there. */
	public function test_the_forms_target_and_method_are_followed(): void {
		$entry = FakeMonokulo::entry();
		$entry['_templates']['create-order']['target'] = '/api/v1/elsewhere';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		self::$monokulo->respond('POST', '/api/v1/elsewhere', 201, FakeMonokulo::order('order_2'));

		$this->assertSame('order_2', (new Client(new Trace()))->createOrder($this->connection(), [])['order_id']);
	}

	public function test_a_key_without_the_form_is_refused_before_ordering(): void {
		$entry = FakeMonokulo::entry();
		unset($entry['_templates']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertStringContainsString('no create-order form', $e->detail);
			$this->assertSame('error_generic', $e->customerMessageKey());
		}
		$this->assertCount(1, self::$monokulo->requests());
	}

	public static function failures(): array {
		$problem = static fn(string $name, string $detail): string => (string)json_encode(['type' => 'https://docs.monokulo.com/problems/' . $name, 'title' => 'T', 'detail' => $detail]);

		return [
			'rate limited'   => [429, $problem('rate-limited', 'slow down'), 'slow down', 'rate-limited', 'error_busy'],
			'engine down'    => [503, $problem('engine-unavailable', 'busy'), 'busy', 'engine-unavailable', 'error_busy'],
			'bad key'        => [401, $problem('unauthorized', 'no key'), 'no key', 'unauthorized', 'error_unavailable'],
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
		self::$monokulo->respond('POST', '/api/v1/orders', $status, $body, headers: ['Content-Type' => 'application/problem+json']);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($problem, $e->problem);
			$this->assertSame($customer, $e->customerMessageKey());
		}
	}

	/** A key Monokulo no longer knows fails at the entry already. */
	public function test_a_replaced_key_fails_at_the_entry(): void {
		self::$monokulo->problem('GET', '/api/v1', 401, 'unauthorized', 'unknown key');

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([401, 'unknown key', 'error_unavailable'], [$e->status, $e->detail, $e->customerMessageKey()]);
			$this->assertStringContainsString('connect the store again', $e->merchantHint());
		}
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

		(new Client(new Trace()))->createOrder($connection, []);
	}

	public function test_errors_are_forwarded_with_the_secret_key(): void {
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		(new Client(new Trace()))->forwardLogs($this->connection(), $entries);

		$request = self::$monokulo->requests()[0];
		$this->assertSame('/api/v1/logs', $request['path']);
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	public function test_nothing_to_forward_sends_nothing_and_a_dead_monokulo_is_ignored(): void {
		(new Client(new Trace()))->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		(new Client(new Trace()))->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->addToAssertionCount(1);
	}
}
