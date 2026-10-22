<?php
namespace Monokulo\OpenCart\Tests\Http;

use Monokulo\OpenCart\Plugin;
use Monokulo\OpenCart\Tests\Support\Browser;
use Monokulo\OpenCart\Tests\Support\Shop;
use Monokulo\OpenCart\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * Webhooks as Monokulo delivers them: real HTTP posts to the shop's
 * callback address, through Apache and OpenCart's front controller, with
 * Monokulo's published bodies byte for byte.
 *
 * The shop's clock is today's, not the vectors' `sent_at`, so each body is
 * signed again now with the vectors' secret, exactly as Monokulo signs
 * (`t=<now>,v1=HMAC-SHA256(secret, "<now>.<body>")`). The published
 * signatures themselves are checked at `sent_at` by the unit and catalog
 * suites; here, being old, they must be refused.
 */
final class WebhookOverHttpTest extends TestCase {
	private const CALLBACK = Shop::URL . 'index.php?route=extension/monokulo/payment/monokulo.callback';

	private int $orderId;

	protected function setUp(): void {
		Shop::exec("DELETE FROM oc_monokulo_connection");
		Shop::exec("DELETE FROM oc_monokulo_order");
		Shop::exec("DELETE FROM oc_monokulo_event");
		Shop::exec("INSERT INTO oc_monokulo_connection SET host = 'localhost:8090', endpoint = 'https://pay.example.com', public_key = 'pk_test', secret_token = 'sk_test', webhook_secret = " . Shop::quote(Vectors::secret()) . ", connection_version = " . Shop::quote(Plugin::CONNECTION_VERSION) . ", connected_at = 1");
		Shop::exec("INSERT INTO oc_order SET store_id = 0, store_name = 'Your Store', store_url = 'http://localhost:8090/', customer_id = 0, customer_group_id = 1, firstname = 'Ada', lastname = 'Lovelace', email = 'ada@example.test', telephone = '1', payment_method = '{\"name\":\"Monero\",\"code\":\"monokulo.monokulo\"}', shipping_method = '', comment = '', custom_field = '[]', payment_custom_field = '[]', shipping_custom_field = '[]', total = 100, order_status_id = 1, language_id = 1, language_code = 'en-gb', currency_id = 2, currency_code = 'USD', currency_value = 1, ip = '127.0.0.1', date_added = NOW(), date_modified = NOW()");
		$this->orderId = (int)Shop::db()->insert_id;
		Shop::exec("INSERT INTO oc_monokulo_order SET monokulo_order_id = " . Shop::quote(Vectors::orderId()) . ", order_id = " . $this->orderId . ", store_id = 0, host = 'localhost:8090', amount = '100.00', currency = 'USD', created_at = 1");
	}

	protected function tearDown(): void {
		Shop::exec("DELETE FROM oc_order_history WHERE order_id = " . $this->orderId);
		Shop::exec("DELETE FROM oc_order WHERE order_id = " . $this->orderId);
		Shop::exec("DELETE FROM oc_monokulo_connection");
		Shop::exec("DELETE FROM oc_monokulo_order");
		Shop::exec("DELETE FROM oc_monokulo_event");
	}

	private function post(string $body, array $headers, string $url = self::CALLBACK): int {
		return (new Browser())->post($url, $body, $headers + ['Content-Type' => 'application/json'])->status;
	}

	/** Monokulo's delivery `name`, signed now. */
	private function deliver(string $name, array $extra = []): int {
		$delivery = Vectors::delivery($name);
		$t = time();
		$headers = ['X-Monokulo-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret())] + $delivery['headers'];

		return $this->post($delivery['body'], $extra + $headers);
	}

	private function orderStatus(): int {
		return (int)Shop::rows("SELECT order_status_id FROM oc_order WHERE order_id = " . $this->orderId)[0]['order_status_id'];
	}

	public function test_over_http_paid_marks_the_order_paid(): void {
		$this->assertSame(200, $this->deliver('order.paid'));
		$this->assertSame(2, $this->orderStatus());

		$history = Shop::rows("SELECT notify, comment FROM oc_order_history WHERE order_id = " . $this->orderId . " ORDER BY order_history_id");
		$this->assertSame(['1', 'Your Monero payment has arrived. Thank you!'], array_values($history[0]));
		$this->assertStringContainsString('Monokulo: paid in full.', $history[1]['comment']);
	}

	/** Every published event, in order, over HTTP: each accepted, ending where the last one leaves it. */
	public function test_over_http_every_published_delivery_is_accepted(): void {
		foreach (array_keys(Vectors::deliveries()) as $name) {
			$this->assertSame(200, $this->deliver($name), $name);
		}
		$this->assertSame(2, $this->orderStatus(), 'the last one is a payment in XMR');
	}

	public function test_over_http_a_repeat_changes_nothing(): void {
		$this->assertSame(200, $this->deliver('order.expired'));
		$this->assertSame(200, $this->deliver('order.expired'));

		$this->assertSame(14, $this->orderStatus());
		$this->assertCount(1, Shop::rows("SELECT * FROM oc_order_history WHERE order_id = " . $this->orderId));
	}

	/** As published, the signatures are from `sent_at`: far more than five minutes ago. */
	public function test_over_http_a_replayed_published_delivery_is_refused(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->assertSame(401, $this->post($delivery['body'], $delivery['headers']));
		$this->assertSame(1, $this->orderStatus());
	}

	public function test_over_http_every_published_forgery_is_refused(): void {
		foreach (Vectors::refused() as $why => $refuse) {
			$this->assertSame(401, $this->post($refuse['body'], $refuse['headers']), $why);
		}
		$this->assertSame(401, $this->post(Vectors::delivery('order.paid')['body'], []), 'no signature at all');
		$this->assertSame(1, $this->orderStatus());
	}

	/** A second store, on its own host, has no connection: nothing sent to its address is trusted. */
	public function test_over_http_the_host_decides_which_connection_checks_it(): void {
		Shop::exec("INSERT INTO oc_store SET name = 'Second shop', url = 'http://127.0.0.1:8090/'");
		$storeId = (int)Shop::db()->insert_id;
		Shop::exec("INSERT INTO oc_setting SET store_id = " . $storeId . ", code = 'config', `key` = 'config_url', value = 'http://127.0.0.1:8090/', serialized = 0");
		array_map('unlink', glob('/var/www/html/system/storage/cache/cache.*') ?: []);

		try {
			$this->deliverToSecondStore();
		} finally {
			Shop::exec("DELETE FROM oc_setting WHERE store_id = " . $storeId);
			Shop::exec("DELETE FROM oc_store WHERE store_id = " . $storeId);
			array_map('unlink', glob('/var/www/html/system/storage/cache/cache.*') ?: []);
		}
	}

	private function deliverToSecondStore(): void {
		$delivery = Vectors::delivery('order.paid');
		$t = time();
		$signature = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret());

		$this->assertSame(401, $this->post($delivery['body'], ['X-Monokulo-Signature' => $signature], 'http://127.0.0.1:8090/index.php?route=extension/monokulo/payment/monokulo.callback'));
		$this->assertSame(1, $this->orderStatus());
	}

	/** The shop joins the webhook's trace, so its log lines match Monokulo's. */
	public function test_over_http_the_log_names_the_webhooks_trace(): void {
		$trace = bin2hex(random_bytes(16));

		$this->assertSame(200, $this->deliver('order.unconfirmed', ['traceparent' => '00-' . $trace . '-00f067aa0ba902b7-01']));

		$this->assertStringContainsString('applied to order ' . $this->orderId . '. [trace ' . $trace . ']', (string)file_get_contents('/var/www/html/system/storage/logs/monokulo.log'));
	}
}
