<?php
namespace Monokulo\OpenCart\Tests\Support;

use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Repository;
use PHPUnit\Framework\TestCase;

/**
 * A test against the installed OpenCart, booted in this process. Each test
 * runs in a database transaction that is rolled back afterwards, so tests
 * leave the shop as they found it. A stand-in Monokulo runs for the class.
 */
abstract class ShopTestCase extends TestCase {
	public const HOST = 'localhost:8090';
	public const SECOND_HOST = '127.0.0.1:8090';

	/** Exchange rates the tests run with, against the default currency (USD). */
	private const RATES = ['USD' => '1.00000000', 'EUR' => '0.89015489', 'GBP' => '0.74617233'];

	/** @var 'catalog'|'admin' */
	protected const SIDE = 'catalog';

	protected static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		OpenCart::define(static::SIDE);
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
		foreach (glob(DIR_CACHE . 'cache.*') ?: [] as $file) {
			@unlink($file);
		}
		$this->db()->query('START TRANSACTION');
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_connection`");
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_connect_nonce`");
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_order`");
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_event`");
		// Fixed exchange rates (OpenCart's sample data), whatever a live update did.
		foreach (self::RATES as $code => $value) {
			$this->db()->query("UPDATE `" . DB_PREFIX . "currency` SET `value` = " . $value . " WHERE `code` = '" . $code . "'");
		}
		$this->setting('payment_monokulo_status', '1');
		$this->setting('payment_monokulo_address', self::$monokulo->url);
		$this->setting('payment_monokulo_forward_errors', '0');
		$this->setting('payment_monokulo_geo_zone_id', '0');
		$this->setting('payment_monokulo_pending_status_id', '1');
		$this->setting('payment_monokulo_waiting_status_id', '1');
		$this->setting('payment_monokulo_partial_status_id', '1');
		$this->setting('payment_monokulo_paid_status_id', '2');
		$this->setting('payment_monokulo_expired_status_id', '14');
	}

	protected function tearDown(): void {
		$this->db()->query('ROLLBACK');
		foreach (glob(DIR_CACHE . 'cache.*') ?: [] as $file) {
			@unlink($file);
		}
	}

	protected function db(): \Opencart\System\Library\DB {
		return OpenCart::db();
	}

	protected function repository(): Repository {
		return new Repository($this->db(), DB_PREFIX);
	}

	/** @return list<array<string, mixed>> */
	protected function rows(string $sql): array {
		return $this->db()->query($sql)->rows;
	}

	/** Sets one of store 0's settings, as saving the settings page would. */
	protected function setting(string $key, string $value, string $code = 'payment_monokulo'): void {
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0 AND `key` = '" . $this->db()->escape($key) . "'");
		$this->db()->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = 0, `code` = '" . $this->db()->escape($code) . "', `key` = '" . $this->db()->escape($key) . "', `value` = '" . $this->db()->escape($value) . "', `serialized` = 0");
	}

	/** Connects `host` to the stand-in Monokulo, as the connect flow would. */
	protected function connect(string $host = self::HOST): Connection {
		$connection = new Connection($host, self::$monokulo->url . '/api/v1', 'pk_test', 'sk_test', Vectors::secret(), 1760000000);
		$this->repository()->saveConnection($connection);

		return $connection;
	}

	/** A second store, on another host of this same shop. */
	protected function addSecondStore(): int {
		$this->db()->query("INSERT INTO `" . DB_PREFIX . "store` SET `name` = 'Second shop', `url` = 'http://" . self::SECOND_HOST . "/'");
		$storeId = (int)$this->db()->getLastId();
		// What the admin's store form saves for a new store.
		foreach (['config_url' => 'http://' . self::SECOND_HOST . '/', 'config_name' => 'Second shop'] as $key => $value) {
			$this->db()->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = " . $storeId . ", `code` = 'config', `key` = '" . $key . "', `value` = '" . $this->db()->escape($value) . "', `serialized` = 0");
		}

		return $storeId;
	}

	/**
	 * An order as OpenCart's checkout leaves it before payment (status 0),
	 * with one line per product name, priced in `currency` at its rate.
	 *
	 * @param list<string> $products
	 */
	protected function order(float $total = 100.0, string $currency = 'USD', array $products = ['Apple Cinema 30"'], int $storeId = 0): int {
		$rate = $this->rows("SELECT `currency_id`, `value` FROM `" . DB_PREFIX . "currency` WHERE `code` = '" . $this->db()->escape($currency) . "'")[0];
		$this->db()->query(
			"INSERT INTO `" . DB_PREFIX . "order` SET `store_id` = " . $storeId . ", `store_name` = 'Your Store', `store_url` = 'http://" . self::HOST . "/',"
			. " `customer_id` = 0, `customer_group_id` = 1, `firstname` = 'Ada', `lastname` = 'Lovelace', `email` = 'ada@example.test', `telephone` = '1',"
			. " `payment_method` = '" . $this->db()->escape(json_encode(['name' => 'Monero (via Monokulo)', 'code' => 'monokulo.monokulo'])) . "',"
			. " `total` = " . $total . ", `order_status_id` = 0, `language_id` = 1, `language_code` = 'en-gb', `currency_id` = " . (int)$rate['currency_id'] . ","
			. " `currency_code` = '" . $this->db()->escape($currency) . "', `currency_value` = " . (float)$rate['value'] . ", `ip` = '127.0.0.1',"
			. " `comment` = '', `shipping_method` = '', `custom_field` = '[]', `payment_custom_field` = '[]', `shipping_custom_field` = '[]',"
			. " `date_added` = NOW(), `date_modified` = NOW()"
		);
		$orderId = (int)$this->db()->getLastId();
		foreach ($products as $name) {
			$this->db()->query(
				"INSERT INTO `" . DB_PREFIX . "order_product` SET `order_id` = " . $orderId . ", `product_id` = 42, `master_id` = 0, `name` = '" . $this->db()->escape(htmlspecialchars($name, ENT_COMPAT, 'UTF-8')) . "',"
				. " `model` = 'm', `quantity` = 1, `price` = " . $total . ", `total` = " . $total . ", `tax` = 0, `reward` = 0"
			);
		}

		return $orderId;
	}

	protected function orderStatus(int $orderId): int {
		return (int)$this->rows("SELECT `order_status_id` FROM `" . DB_PREFIX . "order` WHERE `order_id` = " . $orderId)[0]['order_status_id'];
	}

	/** @return list<array{order_status_id: string, notify: string, comment: string}> oldest first */
	protected function history(int $orderId): array {
		return $this->rows("SELECT `order_status_id`, `notify`, `comment` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = " . $orderId . " ORDER BY `order_history_id`");
	}
}
