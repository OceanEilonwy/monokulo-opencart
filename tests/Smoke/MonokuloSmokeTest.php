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
 *    pending, and its dashboard page opens for the merchant;
 * 5. a guest on the shop's onion address buys too, and is sent to the
 *    order's checkout on Monokulo's onion address (the smoke Monokulo has
 *    one, docker/monokulo-smoke.toml), with the entry kept from the first
 *    order.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 * Along the way, what the real Monokulo answers is compared with what the
 * stand-in the other suites use answers, and the stand-in with the examples
 * in the OpenAPI document written at the pinned commit
 * (MONOKULO_OPENAPI_FILE, which scripts/smoke passes on).
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

	/** A customer browsing the shop on its onion address (the shop answers any host as its default store). */
	private const ONION_SHOP = 'shopxyzshopxyzshopxyzshopxyzshopxyzshopxyzshopxyzshopxyz.onion';

	public function test_connect_then_customers_are_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$this->connectTheShop();
		$this->theEntryIsTheStandIns();

		$checkout = $this->buyWithMonero();

		// The Monokulo checkout loads, and the new order is waiting for payment.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());
		$order = $this->readOrder(substr($checkout, strlen($this->monokulo . '/checkout/')));
		$this->assertSame('pending', $order['status']);
		$this->assertSame(0, $order['confirmations']);
		$this->assertSame('/checkout/' . $order['order_id'], $order['_links']['mk:checkout']['href']);
		$this->theOrderIsTheStandIns($order);

		// The order's page on the merchant's dashboard (which its webhooks link, for the notes' "In Monokulo" link): the merchant, signed in, opens it.
		$dashboard = $this->browser->get($this->monokulo . $order['_links']['mk:dashboard-order']['href']);
		$this->assertSame(200, $dashboard->status);
		$this->assertStringContainsString($order['order_id'], $dashboard->body);

		// The shop kept the entry it read, as its Cache-Control says, with the ETag Monokulo has for it.
		$entry = $this->browser->get($this->connection()['api'], ['Authorization' => 'Bearer ' . $this->connection()['secret_token']]);
		$this->assertSame('private, max-age=3600', $entry->header('cache-control'));
		$this->assertSame($entry->header('etag'), $this->connection()['entry_etag']);
		$this->assertGreaterThan(time() + 3500, (int)$this->connection()['entry_expires_at']);

		// A customer on the shop's onion address is sent to the order's checkout on Monokulo's.
		$onion = $this->buyWithMonero(self::ONION_SHOP);
		$this->assertMatchesRegularExpression('#\A' . preg_quote(FakeMonokulo::ONION, '#') . '/checkout/order_[0-9a-f-]+\z#', $onion);
		$order = $this->readOrder(substr($onion, strlen(FakeMonokulo::ONION . '/checkout/')));
		$this->assertSame($onion, $order['_links']['mk:onion-checkout']['href']);
		$history = Shop::rows("SELECT h.comment FROM oc_monokulo_order m JOIN oc_order_history h ON h.order_id = m.order_id WHERE m.monokulo_order_id = " . Shop::quote($order['order_id']));
		$this->assertSame('Pay for this order with Monero here: ' . $onion, $history[0]['comment'], 'their link in the confirmation is the onion one');
	}

	/**
	 * The stand-in Monokulo the other suites use answers as this one does:
	 * checked against the OpenAPI document the entry links to
	 * (`service-desc`), so a change in Monokulo's API shows here. (The
	 * test connects below compare the stand-in with real answers too.)
	 */
	public function test_the_stand_in_matches_monokulos_openapi(): void {
		$entry = $this->browser->get($this->monokulo . '/api/v1')->json();
		$openapi = $this->browser->get($this->monokulo . $entry['_links']['service-desc']['href'])->json();
		$schemas = $openapi['components']['schemas'];
		$schema = static function (array $ref) use ($schemas): array {
			return $schemas[substr($ref['$ref'], strlen('#/components/schemas/'))];
		};
		$keys = static fn(array $a): array => array_keys($a);
		$missing = static fn(array $wanted, array $have): array => array_values(array_diff($wanted, $have));

		// The handshake: its answer has every field the stand-in's has, and the plugin tells apart the problems it names.
		$finish = $openapi['paths']['/plugins/connect/{slug}/finish']['post'];
		$connection = $schema($finish['responses']['200']['content']['application/json']['schema']);
		$this->assertSame([], $missing($keys(FakeMonokulo::connected(onion: true)), $keys($connection['properties'])), 'the stand-in\'s handshake has no field Monokulo lacks');
		$this->assertSame([], $missing($connection['required'], $keys(FakeMonokulo::connected())), 'and every required one');
		$problems = implode(' ', array_column($finish['responses'], 'description'));
		foreach (['invalid-connect-code', 'no-public-address', 'rate-limited', 'internal'] as $problem) {
			$this->assertStringContainsString('problems/' . $problem . ')', $problems);
		}
		$this->assertStringNotContainsString('challenge-required', $problems, 'the handshake is never challenged');

		// The order form takes every field the shop sends.
		$create = $openapi['paths']['/api/v1/orders']['post'];
		$this->assertSame('create-order', $create['x-hal-forms-template']);
		$this->assertContains('sk', $create['x-monokulo-keys']);
		$input = $schema($create['requestBody']['content']['application/json']['schema']);
		$sent = OrderRequest::body(0, 1, 12.5, 'EUR', 2, ['Mug'], 'Order #1', ' + %d more');
		$this->assertSame([], $missing($keys($sent), $keys($input['properties'])));

		// The answer is an order with every field the stand-in's has, and its links: the checkout on both addresses and the dashboard page.
		$order = $schema($create['responses']['201']['content']['application/prs.hal-forms+json']['schema']);
		$fixture = FakeMonokulo::order('order_1', onion: true);
		$this->assertSame([], $missing($order['required'], $keys($fixture)), 'the stand-in has every required field');
		$this->assertSame([], $missing($keys($fixture), $keys($order['properties'])), 'and no field Monokulo lacks');
		$this->assertSame([], $missing($keys($fixture['_links']), $keys($order['properties']['_links']['properties'])));
		foreach (['/checkout/{order_id}', '/stores/{id}/orders/{order_id}'] as $page) {
			$this->assertArrayHasKey('text/html', $openapi['paths'][$page]['get']['responses']['200']['content'], $page . ' is a page');
		}

		// The entry's links and forms are Monokulo's, and it can be kept and revalidated.
		$get = $openapi['paths']['/api/v1']['get'];
		$root = $schema($get['responses']['200']['content']['application/prs.hal-forms+json']['schema']);
		$this->assertSame([], $missing($keys(FakeMonokulo::entry(onion: true)['_links']), $keys($root['properties']['_links']['properties'])));
		$this->assertArrayHasKey('304', $get['responses']);
		$this->assertContains('If-None-Match', array_column($get['parameters'], 'name'));
		$form = $root['properties']['_templates']['additionalProperties'];
		foreach (FakeMonokulo::entry()['_templates'] as $name => $template) {
			$this->assertSame([], $missing($form['required'], $keys($template)), 'the stand-in\'s ' . $name . ' form has every required field');
		}
		$logs = $openapi['paths']['/api/v1/logs']['post'];
		$this->assertSame(['send-logs', ['sk']], [$logs['x-hal-forms-template'], $logs['x-monokulo-keys']], 'where the shop forwards its errors, with its key');
	}

	/**
	 * The stand-in answers as the examples recorded in the OpenAPI document
	 * written at the pinned commit (`cargo xtask api openapi`): the entry
	 * as the secret key sees it (`sk`, `sk-with-onion`), an order made
	 * with that key, the handshake's answer (`no-key`,
	 * `no-key-with-onion`), and the handshake's problems the plugin tells
	 * apart. Each stand-in answer is compared with its own example, on a
	 * Monokulo without an onion address and on one with.
	 */
	public function test_the_stand_in_matches_monokulos_examples(): void {
		$file = getenv('MONOKULO_OPENAPI_FILE');
		$this->assertNotEmpty($file, 'scripts/smoke passes MONOKULO_OPENAPI on as MONOKULO_OPENAPI_FILE');
		$openapi = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
		$examples = static function (array $operation, string $status, string $type): array {
			return array_map(static fn(array $e): array => $e['value'], $operation['responses'][$status]['content'][$type]['examples']);
		};
		$hrefs = static function (array $links): array {
			$out = [];
			foreach ($links as $rel => $link) {
				$out[$rel] = $rel === 'curies' ? array_map(static function (array $curie): array {
					ksort($curie);

					return $curie;
				}, $link) : $link['href'];
			}
			ksort($out);

			return $out;
		};

		$entries = $examples($openapi['paths']['/api/v1']['get'], '200', 'application/prs.hal-forms+json');
		foreach (['sk' => false, 'sk-with-onion' => true] as $name => $onion) {
			$standIn = FakeMonokulo::entry(onion: $onion);
			$this->assertSame($hrefs($standIn['_links']), $hrefs($entries[$name]['_links']), $name);
			$this->assertEqualsCanonicalizing(array_keys($standIn['_templates']), array_keys($entries[$name]['_templates']), $name);
			foreach ($standIn['_templates'] as $form => $template) {
				$this->assertSame([$template['method'], $template['target'], $template['contentType']], [$entries[$name]['_templates'][$form]['method'], $entries[$name]['_templates'][$form]['target'], $entries[$name]['_templates'][$form]['contentType']], $name . ' ' . $form);
			}
		}

		$orders = $examples($openapi['paths']['/api/v1/orders']['post'], '201', 'application/prs.hal-forms+json');
		foreach (['sk' => false, 'sk-with-onion' => true] as $name => $onion) {
			$example = $orders[$name];
			$storeId = explode('/', $example['_links']['mk:dashboard-order']['href'])[2];
			$standIn = FakeMonokulo::order($example['order_id'], onion: $onion);
			$this->assertEqualsCanonicalizing(array_keys($standIn), array_keys($example), $name);
			$this->assertSame($hrefs(json_decode(str_replace(FakeMonokulo::STORE_ID, $storeId, (string)json_encode($standIn['_links'])), true)), $hrefs($example['_links']), $name);
		}

		$finish = $openapi['paths']['/plugins/connect/{slug}/finish']['post'];
		$connections = $examples($finish, '200', 'application/json');
		foreach (['no-key' => false, 'no-key-with-onion' => true] as $name => $onion) {
			$standIn = FakeMonokulo::connected(onion: $onion);
			$this->assertEqualsCanonicalizing(array_keys($standIn), array_keys($connections[$name]), $name);
			$this->assertSame([$standIn['api'], $standIn['onion_api'] ?? null], [$connections[$name]['api'], $connections[$name]['onion_api'] ?? null], $name);
		}
		foreach (['401' => 'invalid-connect-code', '429' => 'rate-limited', '500' => 'internal', '503' => 'no-public-address'] as $status => $problem) {
			$types = array_column($examples($finish, (string)$status, 'application/problem+json'), 'type');
			$this->assertContains('https://docs.monokulo.com/problems/' . $problem, $types, (string)$status);
		}
	}

	/**
	 * The entry the shop's key gets is the stand-in's, link for link and
	 * form for form (the smoke Monokulo has the stand-in's onion address).
	 */
	private function theEntryIsTheStandIns(): void {
		$entry = $this->apiGet($this->connection()['api']);
		$standIn = FakeMonokulo::entry(onion: true);
		$this->assertEqualsCanonicalizing(array_keys($standIn['_links']), array_keys($entry['_links']));
		foreach ($standIn['_links'] as $rel => $link) {
			$this->assertEquals($rel === 'curies' ? $link : $link['href'], $rel === 'curies' ? $entry['_links'][$rel] : $entry['_links'][$rel]['href'], $rel);
		}
		$this->assertSame(array_keys($standIn['_templates']), array_keys($entry['_templates']));
		foreach ($standIn['_templates'] as $name => $form) {
			$this->assertSame([$form['method'], $form['target']], [$entry['_templates'][$name]['method'], $entry['_templates'][$name]['target']], $name);
		}
	}

	/** A real order as the shop's key reads it has the stand-in's fields and links, at the same places. */
	private function theOrderIsTheStandIns(array $order): void {
		$standIn = FakeMonokulo::order($order['order_id'], onion: true);
		$this->assertEqualsCanonicalizing(array_keys($standIn), array_keys($order));
		$this->assertEqualsCanonicalizing(array_keys($standIn['_links']), array_keys($order['_links']));
		$storeId = explode('/', $order['_links']['mk:dashboard-order']['href'])[2];
		foreach ($standIn['_links'] as $rel => $link) {
			if ($rel === 'curies') {
				$this->assertEquals($link, $order['_links'][$rel]);
			} else {
				$this->assertSame(str_replace(FakeMonokulo::STORE_ID, $storeId, $link['href']), $order['_links'][$rel]['href'], $rel);
			}
		}
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
		$connection = $this->connection();
		$this->assertSame($this->monokulo . '/api/v1', $connection['api']);
		$this->assertSame(FakeMonokulo::ONION . '/api/v1', $connection['onion_api'], 'the onion entry, from the handshake');
		$this->assertStringStartsWith('pk_', $connection['public_key']);
		$this->assertNotSame('', $connection['webhook_secret']);
	}

	/**
	 * A guest checkout with Monero, the last step (pay) on `host`, with the
	 * same session (curl matches cookies to a Host header, so it goes by
	 * hand); returns the Monokulo checkout it was sent to.
	 */
	private function buyWithMonero(string $host = ''): string {
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

		$answer = $this->browser->get($shop . 'extension/monokulo/payment/monokulo.confirm&language=en-gb', $host !== '' ? ['Host' => $host, 'Cookie' => 'OCSESSID=' . $this->browser->cookie('OCSESSID')] : [])->json();
		$this->assertArrayHasKey('redirect', $answer, json_encode($answer));
		if ($host === '') {
			$this->assertMatchesRegularExpression('#\A' . preg_quote($this->monokulo, '#') . '/checkout/order_[0-9a-f-]+\z#', $answer['redirect']);
		}

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
