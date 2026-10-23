<?php
namespace Monokulo\OpenCart\Tests\Smoke;

use Monokulo\OpenCart\OrderRequest;
use Monokulo\OpenCart\Tests\Support\Browser;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Tests\Support\Page;
use Monokulo\OpenCart\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * The extension against a real Monokulo (MONOKULO_URL, a fresh instance
 * started with docker/monokulo-smoke.toml), as a merchant and a customer
 * use it, over HTTP with one browser:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. saves the Monokulo address in the shop's admin and presses Connect;
 * 3. on Monokulo, sets up the store for the shop, picks the wallet, and
 *    goes back to OpenCart, which redeems the token: connected;
 * 4. a guest buys something with Monero: the shop creates the Monokulo
 *    order through the API and sends them to its checkout page, which
 *    loads; the order, read back through the API with the store's key, is
 *    pending.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 */
final class MonokuloSmokeTest extends TestCase {
	/** A mainnet watch-only wallet: a fixed view key and its public spend key, for tests only. */
	private const VIEW_KEY = '0707070707070707070707070707070707070707070707070707070707070707';
	private const SPEND_PUBLIC_KEY = '8621f587cfc4d6f869720476565ecd0972451ff7b8dada3498c9d3c2ca54fc90';
	private const PASSWORD = 'correct horse battery staple';
	private const PRODUCT = 40; // iPhone, in stock in OpenCart's sample data

	private string $monokulo;
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->browser = new Browser();
		Shop::exec("DELETE FROM oc_monokulo_connection");
	}

	public function test_connect_then_a_customer_is_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$this->connectTheShop();
		$checkout = $this->buyWithMonero();

		// The Monokulo checkout loads, and the new order is waiting for payment.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());
		$order = $this->readOrder(substr($checkout, strlen($this->monokulo . '/checkout/')));
		$this->assertSame('pending', $order['status']);
		$this->assertSame(0, $order['confirmations']);
		$this->assertSame('/checkout/' . $order['order_id'], $order['_links']['mk:checkout']['href']);
	}

	/**
	 * The stand-in Monokulo the other suites use answers as this one does:
	 * checked against the OpenAPI document the entry links to
	 * (`service-desc`), so a change in Monokulo's API shows here.
	 */
	public function test_the_stand_in_matches_monokulos_openapi(): void {
		$entry = $this->browser->get($this->monokulo . '/api/v1')->json();
		$openapi = $this->browser->get($this->monokulo . $entry['_links']['service-desc']['href'])->json();
		$schemas = $openapi['components']['schemas'];
		$schema = static function (array $ref) use ($schemas): array {
			return $schemas[substr($ref['$ref'], strlen('#/components/schemas/'))];
		};

		// The order form takes every field the shop sends.
		$create = $openapi['paths']['/api/v1/orders']['post'];
		$this->assertSame('create-order', $create['x-hal-forms-template']);
		$this->assertContains('sk', $create['x-monokulo-keys']);
		$input = $schema($create['requestBody']['content']['application/json']['schema']);
		$sent = OrderRequest::body(0, 1, 12.5, 'EUR', 2, ['Mug'], 'Order #1', ' + %d more');
		$this->assertSame([], array_values(array_diff(array_keys($sent), array_keys($input['properties']))));

		// The answer is an order with every field the stand-in's has, and its links.
		$order = $schema($create['responses']['201']['content']['application/prs.hal-forms+json']['schema']);
		$fixture = FakeMonokulo::order('order_1');
		$this->assertSame([], array_values(array_diff($order['required'], array_keys($fixture))), 'the stand-in has every required field');
		$this->assertSame([], array_values(array_diff(array_keys($fixture), array_keys($order['properties']))), 'and no field Monokulo lacks');
		$this->assertSame([], array_values(array_diff(array_keys($fixture['_links']), array_keys($order['properties']['_links']['properties']))));

		// The entry's links and form are Monokulo's.
		$root = $schema($openapi['paths']['/api/v1']['get']['responses']['200']['content']['application/prs.hal-forms+json']['schema']);
		$this->assertSame([], array_values(array_diff(array_keys(FakeMonokulo::entry()['_links']), array_keys($root['properties']['_links']['properties']))));
		$form = $root['properties']['_templates']['additionalProperties'];
		$this->assertSame([], array_values(array_diff($form['required'], array_keys(FakeMonokulo::entry()['_templates']['create-order']))), 'the stand-in\'s form has every required field');
		$this->assertArrayHasKey('/api/v1/logs', $openapi['paths'], 'where the shop forwards its errors');
	}

	/**
	 * The order `orderId` as the API has it for the shop's key: from the
	 * entry to the store's orders, then the order's own `self` link.
	 *
	 * @return array<string, mixed>
	 */
	private function readOrder(string $orderId): array {
		$entry = $this->apiGet($this->connection()['api']);
		$orders = $this->apiGet($this->monokulo . $entry['_links']['mk:orders']['href']);
		foreach ($orders['orders'] as $order) {
			if ($order['order_id'] === $orderId) {
				return $this->apiGet($this->monokulo . $order['_links']['self']['href']);
			}
		}
		$this->fail('the store\'s orders have no ' . $orderId . ': ' . json_encode($orders));
	}

	/** @return array<string, mixed> */
	private function apiGet(string $url): array {
		$page = $this->browser->get($url, ['Authorization' => 'Bearer ' . $this->connection()['secret_token']]);
		$this->assertSame(200, $page->status, $url . ': ' . $page->body);

		return $page->json();
	}

	/** @return array<string, string> the shop's saved connection */
	private function connection(): array {
		return Shop::rows("SELECT * FROM oc_monokulo_connection WHERE host = 'localhost:8090'")[0];
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/signup');
			$done = $this->browser->submit($page, '/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		}
		$this->assertContains($done->status, [302, 303], 'signed up: ' . $done->text());

		$form = $this->browser->get($this->monokulo . '/account/wallets/import?network=mainnet');
		$added = $this->browser->submit($form, '/account/wallets/import', [
			'name'             => 'Shop takings',
			'view_key_hex'     => self::VIEW_KEY,
			'spend_pubkey_hex' => self::SPEND_PUBLIC_KEY,
			'network'          => 'mainnet',
		]);
		$this->assertSame(303, $added->status, 'wallet added: ' . $added->text());
	}

	private function connectTheShop(): void {
		$token = $this->shopAdmin();

		$settings = $this->browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token));
		$saved = $this->browser->submit($settings, 'monokulo.save', ['payment_monokulo_address' => $this->monokulo, 'payment_monokulo_status' => '1'])->json();
		$this->assertArrayHasKey('success', $saved, json_encode($saved));

		// Connect: off to Monokulo, signed in already, with no store for the shop yet: store setup.
		$settings = $this->browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token));
		$connect = html_entity_decode($this->connectLink($settings));
		$toMonokulo = $this->browser->get($connect);
		$this->assertStringStartsWith($this->monokulo . '/plugins/connect/opencart?', (string)$toMonokulo->location(), $connect . ' answered ' . $toMonokulo->status . $toMonokulo->headers . substr($toMonokulo->body, 0, 2000));

		$setup = $this->browser->follow($toMonokulo);
		$this->assertStringContainsString('OpenCart', $setup->text(), 'Monokulo names the plugin');
		$wallet = $this->browser->follow($this->browser->submit($setup, '/setup', ['store_name' => 'Smoke test shop']));

		$done = $this->browser->follow($this->browser->submit($wallet, '/setup/wallet/existing'));
		$this->assertStringContainsString('Back to OpenCart', $done->text());

		// Back to OpenCart: Monokulo returns to the shop with the token, the
		// shop redeems it and sends the browser to its admin page.
		$back = $this->browser->submit($done, '/plugins/connect/opencart');
		$this->assertStringStartsWith(Shop::URL . 'index.php?route=extension/monokulo/payment/monokulo.connect', (string)$back->location());
		$return = $this->browser->get((string)$back->location());
		$this->assertStringContainsString('monokulo_connected=1', (string)$return->location(), $return->body);

		$admin = $this->browser->get((string)$return->location());
		$this->assertStringContainsString('Your store is connected', $admin->text());
		$connection = Shop::rows("SELECT * FROM oc_monokulo_connection WHERE host = 'localhost:8090'")[0];
		$this->assertSame($this->monokulo . '/api/v1', $connection['api']);
		$this->assertStringStartsWith('pk_', $connection['public_key']);
		$this->assertNotSame('', $connection['webhook_secret']);
	}

	/** A guest checkout with Monero; returns the Monokulo checkout it was sent to. */
	private function buyWithMonero(): string {
		$shop = Shop::URL . 'index.php?route=';
		$this->ok($this->browser->post($shop . 'checkout/cart.add&language=en-gb', ['product_id' => self::PRODUCT, 'quantity' => '1']));

		$address = ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'company' => '', 'address_1' => '12 Analytical Row', 'address_2' => '', 'city' => 'London', 'postcode' => 'N1 1AA', 'country_id' => '222', 'zone_id' => '3563'];
		$register = ['account' => '0', 'customer_group_id' => '1', 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.test', 'telephone' => '0123456', 'address_match' => '0', 'agree' => '1'];
		foreach ($address as $key => $value) {
			$register['payment_' . $key] = $value;
			$register['shipping_' . $key] = $value;
		}
		$this->ok($this->browser->post($shop . 'checkout/register.save&language=en-gb', $register));

		$quote = $this->browser->get($shop . 'checkout/shipping_method.quote&language=en-gb')->json();
		$this->assertArrayHasKey('shipping_methods', $quote, json_encode($quote));
		$this->ok($this->browser->post($shop . 'checkout/shipping_method.save&language=en-gb', ['shipping_method' => 'flat.flat']));

		$methods = $this->browser->get($shop . 'checkout/payment_method.getMethods&language=en-gb')->json();
		$this->assertArrayHasKey('monokulo', $methods['payment_methods'] ?? [], 'Monero is offered: ' . json_encode($methods));
		$this->ok($this->browser->post($shop . 'checkout/payment_method.save&language=en-gb', ['payment_method' => 'monokulo.monokulo']));

		$confirmStep = $this->browser->get($shop . 'checkout/confirm.confirm&language=en-gb');
		$this->assertStringContainsString('Pay with Monero', $confirmStep->body);

		$answer = $this->browser->get($shop . 'extension/monokulo/payment/monokulo.confirm&language=en-gb')->json();
		$this->assertArrayHasKey('redirect', $answer, json_encode($answer));
		$this->assertMatchesRegularExpression('#\A' . preg_quote($this->monokulo, '#') . '/checkout/order_[0-9a-f-]+\z#', $answer['redirect']);

		$order = Shop::rows("SELECT o.order_id, o.order_status_id FROM oc_monokulo_order m JOIN oc_order o ON o.order_id = m.order_id ORDER BY m.seq DESC LIMIT 1")[0];
		$this->assertSame('1', $order['order_status_id'], 'placed as Pending');

		return $answer['redirect'];
	}

	/** One checkout step that OpenCart answered with success. */
	private function ok(Page $step): void {
		$this->assertArrayHasKey('success', $step->json(), $step->url . ': ' . $step->body);
	}

	/** Signs in to the shop's admin; returns the user_token. */
	private function shopAdmin(): string {
		$page = $this->browser->get(Shop::URL . 'admin/index.php?route=common/login');
		$login = $page->form('common/login.login');
		$answer = $this->browser->post($login['action'], ['username' => Shop::ADMIN_USER, 'password' => Shop::ADMIN_PASSWORD])->json();
		$this->assertMatchesRegularExpression('/user_token=([0-9a-f]{32})/', $answer['redirect'] ?? '');
		preg_match('/user_token=([0-9a-f]{32})/', $answer['redirect'], $m);

		return $m[1];
	}

	/** The default store's Connect button. */
	private function connectLink(Page $settings): string {
		$this->assertMatchesRegularExpression('/href="([^"]*monokulo\.connect[^"]*store_id=0)"/', $settings->body);
		preg_match('/href="([^"]*monokulo\.connect[^"]*store_id=0)"/', $settings->body, $m);

		return $m[1];
	}
}
