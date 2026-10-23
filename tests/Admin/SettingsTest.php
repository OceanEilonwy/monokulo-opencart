<?php
namespace Monokulo\OpenCart\Tests\Admin;

use Monokulo\OpenCart\Tests\Support\OpenCart;
use Monokulo\OpenCart\Tests\Support\ShopTestCase;

/**
 * The settings page: what it shows, saving it, starting a store's connect
 * flow, and the extension's install step.
 */
final class SettingsTest extends ShopTestCase {
	protected const SIDE = 'admin';

	private const PAGE = 'extension/monokulo/payment/monokulo';
	private const TOKEN = '0123456789abcdef0123456789abcdef';

	/** A request from the signed-in administrator (user 1, every permission). */
	private function admin(string $route, array $get = [], array $post = [], int $userId = 1): array {
		$registry = OpenCart::boot('admin', ['route' => $route, 'user_token' => self::TOKEN] + $get, $post, [], ['user_id' => $userId, 'user_token' => self::TOKEN]);

		return OpenCart::call($registry, $route);
	}

	private function save(array $fields): array {
		$fields += [
			'payment_monokulo_address'           => 'https://pay.example.com/',
			'payment_monokulo_pending_status_id' => '1',
			'payment_monokulo_waiting_status_id' => '1',
			'payment_monokulo_partial_status_id' => '1',
			'payment_monokulo_paid_status_id'    => '2',
			'payment_monokulo_expired_status_id' => '14',
		];

		return json_decode($this->admin(self::PAGE . '.save', [], $fields)['output'], true);
	}

	/** @return array<string, string> */
	private function settings(): array {
		return array_column($this->rows("SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0 AND `code` = 'payment_monokulo'"), 'value', 'key');
	}

	public function test_the_page_shows_each_store_and_its_connection(): void {
		$storeId = $this->addSecondStore();
		$this->connect(self::HOST);

		$html = $this->admin(self::PAGE)['output'];

		$this->assertStringContainsString('Monokulo address', $html);
		$this->assertStringContainsString('value="' . self::$monokulo->url . '"', $html);
		$this->assertStringContainsString('Connected as pk_test', $html);
		$this->assertStringContainsString('http://127.0.0.1:8090/', $html);
		$this->assertStringContainsString('route=extension/monokulo/payment/monokulo.connect&amp;user_token=' . self::TOKEN . '&amp;store_id=0', $html);
		$this->assertStringContainsString('&amp;store_id=' . $storeId . '"', $html);
		foreach (['Order placed', 'Payment seen', 'Part paid', 'Paid', 'Expired'] as $label) {
			$this->assertStringContainsString('>' . $label . '</label>', $html);
		}
		$this->assertMatchesRegularExpression('/name="payment_monokulo_expired_status_id".*?<option value="14" selected/s', $html);
	}

	public function test_no_connect_button_before_there_is_an_address(): void {
		$this->setting('payment_monokulo_address', '');

		$html = $this->admin(self::PAGE)['output'];

		$this->assertStringContainsString('Save your Monokulo address first.', $html);
		$this->assertStringNotContainsString('monokulo.connect&amp;', $html);
	}

	public function test_the_page_says_how_connecting_went(): void {
		$this->assertStringContainsString('Your store is connected. Monero is now offered at checkout.', $this->admin(self::PAGE, ['monokulo_connected' => '1'])['output']);
		$this->assertStringContainsString('has not set its public address', $this->admin(self::PAGE, ['monokulo_connect_error' => 'unavailable'])['output']);
		$this->assertStringNotContainsString('monokulo-connect-error', $this->admin(self::PAGE, ['monokulo_connect_error' => '<script>'])['output']);
	}

	public function test_saving_keeps_every_setting_and_cleans_the_address(): void {
		$answer = $this->save(['payment_monokulo_status' => '1', 'payment_monokulo_forward_errors' => '1', 'payment_monokulo_geo_zone_id' => '4', 'payment_monokulo_sort_order' => '3', 'payment_monokulo_paid_status_id' => '5', 'not_ours' => 'x']);

		$this->assertArrayHasKey('success', $answer);
		$this->assertSame([
			'payment_monokulo_address'           => 'https://pay.example.com',
			'payment_monokulo_expired_status_id' => '14',
			'payment_monokulo_forward_errors'    => '1',
			'payment_monokulo_geo_zone_id'       => '4',
			'payment_monokulo_paid_status_id'    => '5',
			'payment_monokulo_partial_status_id' => '1',
			'payment_monokulo_pending_status_id' => '1',
			'payment_monokulo_sort_order'        => '3',
			'payment_monokulo_status'            => '1',
			'payment_monokulo_waiting_status_id' => '1',
		], $this->sorted($this->settings()));
	}

	public function test_saving_refuses_a_bad_address_or_a_missing_status(): void {
		$answer = $this->save(['payment_monokulo_address' => 'pay.example.com', 'payment_monokulo_paid_status_id' => '']);

		$this->assertSame(['address', 'paid-status'], array_keys($answer['error']));
		$this->assertSame(self::$monokulo->url, $this->settings()['payment_monokulo_address'], 'nothing saved');
	}

	/** A user whose group may not modify the extension can't save. */
	public function test_saving_needs_permission(): void {
		$this->db()->query("INSERT INTO `" . DB_PREFIX . "user_group` SET `name` = 'Viewers', `permission` = '" . $this->db()->escape(json_encode(['access' => ['extension/monokulo/payment/monokulo'], 'modify' => []])) . "'");
		$group = (int)$this->db()->getLastId();
		$this->db()->query("INSERT INTO `" . DB_PREFIX . "user` SET `user_group_id` = " . $group . ", `username` = 'viewer', `password` = '', `firstname` = 'V', `lastname` = 'V', `email` = 'v@example.test', `image` = '', `status` = 1, `date_added` = NOW()");
		$viewer = (int)$this->db()->getLastId();

		$answer = json_decode($this->admin(self::PAGE . '.save', [], ['payment_monokulo_address' => 'https://x.example'], $viewer)['output'], true);
		$this->assertArrayHasKey('warning', $answer['error']);

		$connect = $this->admin(self::PAGE . '.connect', ['store_id' => '0'], [], $viewer);
		$this->assertStringEndsWith('monokulo_connect_error=permission', OpenCart::location($connect));
		$this->assertSame([], $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_connect_nonce`"));
	}

	/**
	 * Connect: a single-use nonce for the store, then off to Monokulo with
	 * the store's address, its own return address and the plugin's name.
	 */
	public function test_connect_sends_the_admin_to_monokulo_for_that_store(): void {
		$answer = $this->admin(self::PAGE . '.connect', ['store_id' => '0']);

		$location = OpenCart::location($answer);
		$this->assertStringStartsWith(self::$monokulo->url . '/plugins/connect/opencart?', $location);
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
		$nonce = $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_connect_nonce`")[0];
		$this->assertSame([
			'site_url'   => 'http://localhost:8090/',
			'return_url' => 'http://localhost:8090/index.php?route=extension/monokulo/payment/monokulo.connect',
			'nonce'      => $nonce['nonce'],
			'name'       => 'OpenCart',
		], $query);
		$this->assertSame(self::HOST, $nonce['host']);
		$this->assertSame('http://localhost:8090/admin/index.php?route=extension/monokulo/payment/monokulo&user_token=' . self::TOKEN, $nonce['admin_return']);
		$this->assertEqualsWithDelta(time() + 600, (int)$nonce['expires_at'], 5);
	}

	/** The second store returns to its own host, which Monokulo requires. */
	public function test_connect_for_a_second_store_returns_to_its_host(): void {
		$storeId = $this->addSecondStore();

		parse_str((string)parse_url(OpenCart::location($this->admin(self::PAGE . '.connect', ['store_id' => (string)$storeId])), PHP_URL_QUERY), $query);

		$this->assertSame('http://127.0.0.1:8090/', $query['site_url']);
		$this->assertSame('http://127.0.0.1:8090/index.php?route=extension/monokulo/payment/monokulo.connect', $query['return_url']);
		$this->assertSame(self::SECOND_HOST, $this->rows("SELECT `host` FROM `" . DB_PREFIX . "monokulo_connect_nonce`")[0]['host']);
	}

	public function test_connect_needs_an_address_and_a_real_store(): void {
		$this->assertStringEndsWith('monokulo_connect_error=address', OpenCart::location($this->admin(self::PAGE . '.connect', ['store_id' => '99'])));

		$this->setting('payment_monokulo_address', '');
		$this->assertStringEndsWith('monokulo_connect_error=address', OpenCart::location($this->admin(self::PAGE . '.connect', ['store_id' => '0'])));
		$this->assertSame([], $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_connect_nonce`"));
	}

	/** Old attempts are cleared out as new ones start. */
	public function test_expired_nonces_are_cleared(): void {
		$this->repository()->createNonce(0, self::HOST, 'x', time() - 3600);

		$this->admin(self::PAGE . '.connect', ['store_id' => '0']);

		$this->assertCount(1, $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_connect_nonce`"));
	}

	/**
	 * Install sets the defaults (Pending, Processing, Expired; off until a
	 * store connects) and keeps any connection a previous install made.
	 */
	public function test_install_sets_defaults_and_keeps_connections(): void {
		$this->connect();
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'payment_monokulo'");

		$this->admin(self::PAGE . '.install');

		$this->assertSame([
			'payment_monokulo_address'           => 'https://monokulo.com',
			'payment_monokulo_expired_status_id' => '14',
			'payment_monokulo_forward_errors'    => '0',
			'payment_monokulo_geo_zone_id'       => '0',
			'payment_monokulo_paid_status_id'    => '2',
			'payment_monokulo_partial_status_id' => '1',
			'payment_monokulo_pending_status_id' => '1',
			'payment_monokulo_sort_order'        => '0',
			'payment_monokulo_status'            => '0',
			'payment_monokulo_waiting_status_id' => '1',
		], $this->sorted($this->settings()));
		$this->assertNotNull($this->repository()->connection(self::HOST));
	}

	private function sorted(array $settings): array {
		ksort($settings);

		return $settings;
	}
}
