<?php
namespace Monokulo\OpenCart\Tests\Catalog;

use Monokulo\OpenCart\Plugin;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Tests\Support\OpenCart;
use Monokulo\OpenCart\Tests\Support\ShopTestCase;

/**
 * Monero at checkout: when it's offered, and the "Pay with Monero" button's
 * confirm, against real OpenCart orders and a stand-in Monokulo.
 */
final class CheckoutTest extends ShopTestCase {
	private const CONFIRM = 'extension/monokulo/payment/monokulo.confirm';

	/** @return array<string, mixed> */
	private function methods(string $host = self::HOST): array {
		$registry = OpenCart::boot('catalog', [], [], ['HTTP_HOST' => $host]);
		$registry->get('load')->model('extension/monokulo/payment/monokulo');

		return $registry->get('model_extension_monokulo_payment_monokulo')->getMethods(['country_id' => 222, 'zone_id' => 3563]);
	}

	/**
	 * Confirms `orderId` as the customer whose session it is.
	 *
	 * @return array{answer: array<string, mixed>, registry: \Opencart\System\Engine\Registry}
	 */
	private function confirm(int $orderId, string $paymentCode = 'monokulo.monokulo', string $host = self::HOST): array {
		$registry = OpenCart::boot('catalog', ['route' => self::CONFIRM], [], ['HTTP_HOST' => $host], [
			'order_id'       => $orderId,
			'payment_method' => ['name' => 'Monero (via Monokulo)', 'code' => $paymentCode],
			'comment'        => 'leave at the door',
			'cart'           => [],
		]);
		$answer = OpenCart::call($registry, self::CONFIRM);

		return ['answer' => json_decode($answer['output'], true), 'registry' => $registry];
	}

	/** A customer browsing the shop on its onion address. */
	private const ONION_HOST = 'shopxyzshopxyzshopxyzshopxyzshopxyzshopxyzshopxyzshopxyz.onion';

	/** Monokulo's answer to the order: the entry with its form, then `status` and `body` from the form's target. */
	private function monokuloAnswers(int $status, mixed $body): void {
		self::$monokulo->serveEntry();
		self::$monokulo->respond('POST', '/api/v1/orders', $status, $body, headers: ['Content-Type' => $status < 300 ? 'application/prs.hal-forms+json' : 'application/problem+json']);
	}

	/** The order requests Monokulo got (not the entry's). @return list<array<string, mixed>> */
	private function orderRequests(string $prefix = ''): array {
		return array_values(array_filter(self::$monokulo->requests(), static fn(array $r): bool => $r['path'] === $prefix . '/api/v1/orders'));
	}

	/** What the extension wrote to its log during `run`. */
	private function logged(callable $run): string {
		$log = DIR_LOGS . 'monokulo.log';
		$before = is_file($log) ? (string)file_get_contents($log) : '';
		$run();

		return substr((string)file_get_contents($log), strlen($before));
	}

	public function test_monero_is_offered_once_the_store_is_connected(): void {
		$this->assertSame([], $this->methods(), 'not connected yet');

		$this->connect();

		$methods = $this->methods();
		$this->assertSame('monokulo', $methods['code']);
		$this->assertSame('Monero (via Monokulo)', $methods['name']);
		$this->assertSame('monokulo.monokulo', $methods['option']['monokulo']['code']);
	}

	public function test_outside_the_chosen_geo_zone_monero_is_hidden(): void {
		$this->connect();
		$this->setting('config_checkout_payment_address', '1', 'config');
		// Geo zone 4 is "UK Shipping" (country 222); Germany is not in it.
		$this->setting('payment_monokulo_geo_zone_id', '4');

		$this->assertNotSame([], $this->methods());

		$registry = OpenCart::boot('catalog');
		$registry->get('load')->model('extension/monokulo/payment/monokulo');
		$this->assertSame([], $registry->get('model_extension_monokulo_payment_monokulo')->getMethods(['country_id' => 81, 'zone_id' => 0]));
	}

	/** Each store is its own host and connects on its own. */
	public function test_a_second_store_on_another_host_is_not_connected_by_the_first(): void {
		$this->addSecondStore();
		$this->connect(self::HOST);

		$this->assertNotSame([], $this->methods(self::HOST));
		$this->assertSame([], $this->methods(self::SECOND_HOST));

		$this->connect(self::SECOND_HOST);
		$this->assertNotSame([], $this->methods(self::SECOND_HOST));
	}

	/**
	 * Confirm creates the Monokulo order in the customer's currency, places
	 * the order with the payment link in its history (and confirmation
	 * email), empties the cart and sends the customer to pay.
	 */
	public function test_confirm_creates_the_monokulo_order_and_sends_the_customer_to_pay(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_42'));
		$orderId = $this->order(100.0, 'EUR', ['Blue mug', 'Tea & "Biscuits"', 'Spoon']);

		['answer' => $answer, 'registry' => $registry] = $this->confirm($orderId);

		$checkout = self::$monokulo->url . '/checkout/ord_42';
		$this->assertSame(['redirect' => $checkout], $answer);

		$request = $this->orderRequests()[0];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('opencart/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		// 100 in the store's default currency, at OpenCart's EUR rate.
		$this->assertSame([
			'amount'                 => '89.02',
			'currency'               => 'EUR',
			'merchant_order_id'      => (string)$orderId,
			'show_merchant_order_id' => false,
			'description'            => 'Order #' . $orderId . ': Blue mug + 2 more',
			'idempotency_key'        => 'oc:0:' . $orderId . ':89.02:EUR',
		], json_decode($request['body'], true));

		$this->assertSame(1, $this->orderStatus($orderId), 'placed, as Pending');
		$history = $this->history($orderId);
		$this->assertCount(1, $history);
		$this->assertSame('1', $history[0]['notify'], 'the customer gets the link');
		$this->assertSame('Pay for this order with Monero here: ' . $checkout, $history[0]['comment']);

		$this->assertSame([[
			'monokulo_order_id' => 'ord_42',
			'order_id'          => (string)$orderId,
			'store_id'          => '0',
			'host'              => self::HOST,
			'amount'            => '89.02',
			'currency'          => 'EUR',
			'dashboard_url'     => self::$monokulo->url . '/stores/' . FakeMonokulo::STORE_ID . '/orders/ord_42',
		]], $this->rows("SELECT `monokulo_order_id`, `order_id`, `store_id`, `host`, `amount`, `currency`, `dashboard_url` FROM `" . DB_PREFIX . "monokulo_order`"));

		$session = $registry->get('session')->data;
		foreach (['order_id', 'payment_method', 'comment'] as $key) {
			$this->assertArrayNotHasKey($key, $session, "$key is cleared, as OpenCart's success page does");
		}
	}

	/** The API's entry is kept: after the first order, each order is one request. */
	public function test_the_entry_is_kept_between_orders(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));

		$this->confirm($this->order());
		$this->confirm($this->order());

		$this->assertSame(['/api/v1', '/api/v1/orders', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
		$this->assertSame(FakeMonokulo::ENTRY_ETAG, $this->rows("SELECT `entry_etag` FROM `" . DB_PREFIX . "monokulo_connection`")[0]['entry_etag']);
	}

	/** Connecting again forgets the entry the old key was shown. */
	public function test_connecting_again_reads_the_entry_afresh(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));
		$this->confirm($this->order());

		$this->connect();
		$this->confirm($this->order());

		$this->assertSame(['/api/v1', '/api/v1/orders', '/api/v1', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	/** A Monokulo under a path: the order, the customer's checkout and the merchant's link all keep it. */
	public function test_a_monokulo_under_a_path(): void {
		$this->connect(prefix: '/monokulo');
		self::$monokulo->serveEntry('/monokulo');
		self::$monokulo->createsOrder('ord_1', '/monokulo');
		$orderId = $this->order();

		$answer = $this->confirm($orderId)['answer'];

		$this->assertSame(['redirect' => self::$monokulo->url . '/monokulo/checkout/ord_1'], $answer);
		$this->assertCount(1, $this->orderRequests('/monokulo'));
		$this->assertSame(self::$monokulo->url . '/monokulo/stores/' . FakeMonokulo::STORE_ID . '/orders/ord_1', $this->rows("SELECT `dashboard_url` FROM `" . DB_PREFIX . "monokulo_order`")[0]['dashboard_url']);
	}

	/** A customer on the shop's onion address pays on Monokulo's: the link in their order history is that one too. */
	public function test_a_customer_on_tor_is_sent_to_the_onion_checkout(): void {
		$this->connect();
		self::$monokulo->serveEntry(onion: true);
		self::$monokulo->createsOrder('ord_1', onion: true);
		$orderId = $this->order();

		$answer = $this->confirm($orderId, host: self::ONION_HOST)['answer'];

		$this->assertSame(['redirect' => FakeMonokulo::ONION . '/checkout/ord_1'], $answer);
		$this->assertSame('Pay for this order with Monero here: ' . FakeMonokulo::ONION . '/checkout/ord_1', $this->history($orderId)[0]['comment']);
	}

	/** The same order for a customer on the web: the web checkout. */
	public function test_a_customer_on_the_web_is_sent_to_the_web_checkout_when_there_is_an_onion_one(): void {
		$this->connect();
		self::$monokulo->serveEntry(onion: true);
		self::$monokulo->createsOrder('ord_1', onion: true);

		$this->assertSame(['redirect' => self::$monokulo->url . '/checkout/ord_1'], $this->confirm($this->order())['answer']);
	}

	/**
	 * Without an onion address at Monokulo (no `mk:onion-api` on its entry) a
	 * Tor customer is never sent to the web, and no Monokulo order is made
	 * for them: they're told, the merchant's log says why, the order is
	 * untouched.
	 */
	public function test_a_customer_on_tor_is_not_sent_to_the_web_when_monokulo_has_no_onion_address(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));
		$orderId = $this->order();

		$logged = $this->logged(function () use ($orderId, &$result): void {
			$result = $this->confirm($orderId, host: self::ONION_HOST);
		});

		$this->assertSame(['error' => 'Monero can\'t be paid over Tor at this shop right now. Please contact the store owner.'], $result['answer']);
		$this->assertSame(0, $this->orderStatus($orderId));
		$this->assertSame([], $this->history($orderId));
		$this->assertSame([], $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_order`"));
		$this->assertSame($orderId, $result['registry']->get('session')->data['order_id'], 'the cart is kept');
		$this->assertStringContainsString('your Monokulo has no onion address (its API entry has no mk:onion-api), so no Monokulo order was made', $logged);
		$this->assertSame(['/api/v1'], array_column(self::$monokulo->requests(), 'path'), 'the entry only: no order request');
	}

	/** An entry with an onion address but an order without its onion checkout: still never the web. */
	public function test_a_customer_on_tor_is_not_sent_to_the_web_when_the_order_has_no_onion_checkout(): void {
		$this->connect();
		self::$monokulo->serveEntry(onion: true);
		self::$monokulo->createsOrder('ord_1');
		$orderId = $this->order();

		$logged = $this->logged(function () use ($orderId, &$result): void {
			$result = $this->confirm($orderId, host: self::ONION_HOST);
		});

		$this->assertSame(['error' => 'Monero can\'t be paid over Tor at this shop right now. Please contact the store owner.'], $result['answer']);
		$this->assertSame([], $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_order`"));
		$this->assertStringContainsString('Monokulo order ord_1 has no onion checkout (mk:onion-checkout)', $logged);
	}

	/** With the entry kept, a Tor customer's order is still one request: the onion check reads the kept entry. */
	public function test_a_customer_on_tor_with_the_entry_kept_is_one_request(): void {
		$this->connect();
		self::$monokulo->serveEntry(onion: true);
		self::$monokulo->createsOrder('ord_1', onion: true);
		$this->confirm($this->order());
		self::$monokulo->reset();
		self::$monokulo->createsOrder('ord_2', onion: true);

		$this->assertSame(['redirect' => FakeMonokulo::ONION . '/checkout/ord_2'], $this->confirm($this->order(), host: self::ONION_HOST)['answer']);
		$this->assertSame(['/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	/**
	 * The amount goes in the currency's own decimal places, without
	 * trailing zeros.
	 */
	public function test_the_amount_has_the_currencys_places_and_no_trailing_zeros(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));
		$this->confirm($this->order(20.0, 'USD'));
		$this->db()->query("UPDATE `" . DB_PREFIX . "currency` SET `decimal_place` = '0' WHERE `code` = 'EUR'");
		foreach (glob(DIR_CACHE . 'cache.*') ?: [] as $file) {
			@unlink($file);
		}
		$this->confirm($this->order(100.0, 'EUR'));

		$amounts = array_map(static fn(array $r): string => json_decode($r['body'], true)['amount'], $this->orderRequests());
		$this->assertSame(['20', '89'], $amounts);
	}

	/** A product name with characters OpenCart stores escaped goes to Monokulo as the customer typed it. */
	public function test_product_names_are_sent_unescaped(): void {
		$this->connect();
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));
		$orderId = $this->order(5.0, 'USD', ['Tea & "Biscuits"']);

		$this->confirm($orderId);

		$this->assertSame('Order #' . $orderId . ': Tea & "Biscuits"', json_decode($this->orderRequests()[0]['body'], true)['description']);
	}

	public static function failures(): array {
		return [
			'busy'          => [429, '{"type":"https://docs.monokulo.com/problems/rate-limited","detail":"slow down"}', 'Monero payments are busy right now. Please wait a moment and try again.', 'rate limited'],
			'key rejected'  => [401, '{"type":"https://docs.monokulo.com/problems/unauthorized","detail":"unknown key"}', 'Monero payments are not available for this store right now. Please contact the store owner.', 'connect the store again'],
			'currency'      => [400, '{"type":"https://docs.monokulo.com/problems/invalid-amount","detail":"unsupported currency: EUR"}', 'Monero payments can\'t be taken in this order\'s currency right now. Please choose another currency or payment method.', 'HTTP 400: unsupported currency: EUR (Monokulo can\'t price this order'],
			'anything else' => [500, 'oops', 'The Monero payment could not be started. Please try again, or contact the store.', 'HTTP 500: oops'],
		];
	}

	/** Monokulo said no: the customer is told in their terms, the merchant's log says why, the order is untouched. */
	#[\PHPUnit\Framework\Attributes\DataProvider('failures')]
	public function test_a_refused_order_leaves_the_checkout_as_it_was(int $status, string $body, string $customer, string $logged): void {
		$this->connect();
		$this->monokuloAnswers($status, $body);
		$orderId = $this->order();
		$log = DIR_LOGS . 'monokulo.log';
		$before = is_file($log) ? (string)file_get_contents($log) : '';

		['answer' => $answer, 'registry' => $registry] = $this->confirm($orderId);

		$this->assertSame(['error' => $customer], $answer);
		$this->assertSame(0, $this->orderStatus($orderId));
		$this->assertSame([], $this->history($orderId));
		$this->assertSame($orderId, $registry->get('session')->data['order_id'], 'the customer can try again');
		$this->assertStringContainsString($logged, substr((string)file_get_contents($log), strlen($before)));
	}

	public function test_an_unreachable_monokulo_says_so(): void {
		$this->connect();
		$this->repository()->saveConnection(new \Monokulo\OpenCart\Connection(self::HOST, 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk_test', 'sk_test', 'wh', 1));

		['answer' => $answer] = $this->confirm($this->order());

		$this->assertSame(['error' => 'The Monero payment service could not be reached. Please try again shortly.'], $answer);
	}

	/** Trying again after a lost answer gets the same Monokulo order (same idempotency key). */
	public function test_confirming_twice_asks_for_the_same_order(): void {
		$this->connect();
		$this->monokuloAnswers(500, 'lost');
		$orderId = $this->order();
		$this->confirm($orderId);
		$this->monokuloAnswers(201, FakeMonokulo::order('ord_1'));
		$this->confirm($orderId);

		$keys = array_map(static fn(array $r): string => json_decode($r['body'], true)['idempotency_key'], $this->orderRequests());
		$this->assertCount(2, $keys);
		$this->assertSame($keys[0], $keys[1]);
	}

	public function test_confirm_needs_the_customers_order_and_monero_chosen(): void {
		$this->connect();

		$this->assertSame(['error' => 'Your order could not be found. Please start the checkout again.'], $this->confirm(999999)['answer']);
		$this->assertSame(['error' => 'Monero is not the selected payment method.'], $this->confirm($this->order(), 'cod.cod')['answer']);
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_confirm_on_a_store_that_is_not_connected_refuses(): void {
		$this->assertSame(['error' => 'Monero payments are not set up for this store yet. Please contact the store owner.'], $this->confirm($this->order())['answer']);
	}

	/** With "Send errors to Monokulo" on, a failure is also reported to Monokulo; off, it isn't. */
	public function test_errors_are_forwarded_only_when_turned_on(): void {
		$this->connect();
		$this->monokuloAnswers(429, '{"type":"https://docs.monokulo.com/problems/rate-limited","detail":"slow down"}');
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');

		$this->confirm($this->order());
		$this->assertSame(['/api/v1', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));

		self::$monokulo->reset();
		$this->monokuloAnswers(429, '{"type":"https://docs.monokulo.com/problems/rate-limited","detail":"slow down"}');
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$this->setting('payment_monokulo_forward_errors', '1');

		$this->confirm($this->order());
		$requests = self::$monokulo->requests();
		$this->assertSame(['/api/v1/orders', '/api/v1/logs'], array_column($requests, 'path'), 'with the entry kept, its send-logs form');
		$this->assertSame('Bearer sk_test', $requests[1]['headers']['authorization']);
		$entries = json_decode($requests[1]['body'], true)['entries'];
		$this->assertSame('error', $entries[0]['level']);
		$this->assertStringContainsString('HTTP 429: slow down', $entries[0]['message']);
		$this->assertSame(substr($requests[0]['headers']['traceparent'], 3, 32), $entries[0]['trace_id'], 'the same trace as the failed call');
	}

	public function test_the_button_calls_confirm(): void {
		$this->connect();
		$registry = OpenCart::boot('catalog');

		$html = OpenCart::call($registry, 'extension/monokulo/payment/monokulo')['returned'];

		$this->assertStringContainsString('Pay with Monero', $html);
		$this->assertStringContainsString("index.php?route=extension/monokulo/payment/monokulo.confirm&language=en-gb", $html);
	}
}
