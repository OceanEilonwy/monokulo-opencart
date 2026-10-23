<?php
namespace Monokulo\OpenCart\Tests\Http;

use Monokulo\OpenCart\Tests\Support\Browser;
use Monokulo\OpenCart\Tests\Support\Shop;
use Monokulo\OpenCart\Tests\Support\Vectors;
use PHPUnit\Framework\TestCase;

/**
 * A shop that connected with the version before (its tables without
 * `onion_api` or the kept entry) keeps its connection and its orders once
 * the new files are in place, without connecting again or reinstalling:
 * the columns are added the first time a request finds an old row. It has
 * no onion entry until it connects again; its earlier orders' notes link
 * them in Monokulo all the same, since every webhook carries the link.
 */
final class UpgradeOverHttpTest extends TestCase {
	private int $orderId;

	protected function setUp(): void {
		Shop::exec("DROP TABLE oc_monokulo_connection");
		Shop::exec("DROP TABLE oc_monokulo_order");
		Shop::exec("DELETE FROM oc_monokulo_event");
		// The tables as the version before made them.
		Shop::exec("CREATE TABLE oc_monokulo_connection (
			`host` VARCHAR(255) NOT NULL,
			`api` VARCHAR(255) NOT NULL,
			`public_key` VARCHAR(128) NOT NULL,
			`secret_token` VARCHAR(255) NOT NULL,
			`webhook_secret` VARCHAR(255) NOT NULL,
			`connected_at` INT(11) NOT NULL,
			PRIMARY KEY (`host`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("CREATE TABLE oc_monokulo_order (
			`seq` INT(11) NOT NULL AUTO_INCREMENT,
			`monokulo_order_id` VARCHAR(64) NOT NULL,
			`order_id` INT(11) NOT NULL,
			`store_id` INT(11) NOT NULL,
			`host` VARCHAR(255) NOT NULL,
			`amount` VARCHAR(40) NOT NULL,
			`currency` VARCHAR(8) NOT NULL,
			`created_at` INT(11) NOT NULL,
			PRIMARY KEY (`monokulo_order_id`),
			UNIQUE KEY `seq` (`seq`),
			KEY `order_id` (`order_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO oc_monokulo_connection VALUES ('localhost:8090', 'https://pay.example.com/monokulo/api/v1', 'pk_old', 'sk_old', " . Shop::quote(Vectors::secret()) . ", 1)");
		Shop::exec("INSERT INTO oc_order SET store_id = 0, store_name = 'Your Store', store_url = 'http://localhost:8090/', customer_id = 0, customer_group_id = 1, firstname = 'Ada', lastname = 'Lovelace', email = 'ada@example.test', telephone = '1', payment_method = '{\"name\":\"Monero\",\"code\":\"monokulo.monokulo\"}', shipping_method = '', comment = '', custom_field = '[]', payment_custom_field = '[]', shipping_custom_field = '[]', total = 100, order_status_id = 1, language_id = 1, language_code = 'en-gb', currency_id = 2, currency_code = 'USD', currency_value = 1, ip = '127.0.0.1', date_added = NOW(), date_modified = NOW()");
		$this->orderId = (int)Shop::db()->insert_id;
		Shop::exec("INSERT INTO oc_monokulo_order (monokulo_order_id, order_id, store_id, host, amount, currency, created_at) VALUES (" . Shop::quote(Vectors::orderId()) . ", " . $this->orderId . ", 0, 'localhost:8090', '100', 'USD', 1)");
	}

	protected function tearDown(): void {
		Shop::exec("DELETE FROM oc_order_history WHERE order_id = " . $this->orderId);
		Shop::exec("DELETE FROM oc_order WHERE order_id = " . $this->orderId);
		Shop::exec("DELETE FROM oc_monokulo_connection");
		Shop::exec("DELETE FROM oc_monokulo_order");
		Shop::exec("DELETE FROM oc_monokulo_event");
	}

	/** @return list<string> */
	private function columns(string $table): array {
		return array_column(Shop::rows("SHOW COLUMNS FROM " . $table), 'Field');
	}

	private function assertUpgraded(): void {
		$this->assertSame(['host', 'api', 'onion_api', 'public_key', 'secret_token', 'webhook_secret', 'connected_at', 'entry', 'entry_etag', 'entry_expires_at'], $this->columns('oc_monokulo_connection'));
		$this->assertSame(['seq', 'monokulo_order_id', 'order_id', 'store_id', 'host', 'amount', 'currency', 'created_at'], $this->columns('oc_monokulo_order'), 'nothing added to the orders');
		$row = Shop::rows("SELECT * FROM oc_monokulo_connection")[0];
		$this->assertSame(['https://pay.example.com/monokulo/api/v1', '', 'pk_old', 'sk_old'], [$row['api'], $row['onion_api'], $row['public_key'], $row['secret_token']]);
	}

	public function test_over_http_an_old_connection_is_still_connected(): void {
		[$browser, $token] = Shop::admin();

		$settings = $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token));

		$this->assertSame(200, $settings->status);
		$this->assertStringContainsString('pk_old', $settings->text(), 'still connected');
		$this->assertStringNotContainsString('On Tor at', $settings->text(), 'no onion entry until it connects again');
		$this->assertUpgraded();

		// Once moved, nothing more to do.
		$this->assertStringContainsString('pk_old', $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token))->text());
	}

	/** A webhook for an order made before: applied, with the "In Monokulo" link the webhook carries. */
	public function test_over_http_an_old_orders_webhook_is_applied(): void {
		$delivery = Vectors::delivery('order.paid');
		$t = time();
		$headers = ['X-Monokulo-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret()), 'Content-Type' => 'application/json'] + $delivery['headers'];

		$status = (new Browser())->post(Shop::URL . 'index.php?route=extension/monokulo/payment/monokulo.callback', $delivery['body'], $headers)->status;

		$this->assertSame(200, $status);
		$this->assertSame('2', Shop::rows("SELECT order_status_id FROM oc_order WHERE order_id = " . $this->orderId)[0]['order_status_id']);
		$history = Shop::rows("SELECT comment FROM oc_order_history WHERE order_id = " . $this->orderId . " ORDER BY order_history_id");
		$this->assertSame('Monokulo: paid in full. In Monokulo: ' . json_decode($delivery['body'], true)['_links']['mk:dashboard-order']['href'], end($history)['comment']);
		$this->assertUpgraded();
	}
}
