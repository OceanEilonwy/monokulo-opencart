<?php
namespace Monokulo\OpenCart\Tests\Http;

use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * The connect flow's two redirects as a browser sees them, through Apache:
 * the admin's Connect button to Monokulo, and Monokulo's return to the
 * shop, which redeems the token and sends the browser back to the admin.
 * A stand-in Monokulo plays Monokulo's part.
 */
final class ConnectOverHttpTest extends TestCase {
	private FakeMonokulo $monokulo;
	private string $address;

	protected function setUp(): void {
		$this->monokulo = new FakeMonokulo();
		$this->address = Shop::rows("SELECT value FROM oc_setting WHERE store_id = 0 AND `key` = 'payment_monokulo_address'")[0]['value'] ?? '';
		Shop::exec("UPDATE oc_setting SET value = " . Shop::quote($this->monokulo->url) . " WHERE store_id = 0 AND `key` = 'payment_monokulo_address'");
		Shop::exec("DELETE FROM oc_monokulo_connection");
	}

	protected function tearDown(): void {
		$this->monokulo->stop();
		Shop::exec("UPDATE oc_setting SET value = " . Shop::quote($this->address) . " WHERE store_id = 0 AND `key` = 'payment_monokulo_address'");
		Shop::exec("DELETE FROM oc_monokulo_connection");
	}

	public function test_over_http_connect_goes_to_monokulo_and_back(): void {
		[$browser, $token] = Shop::admin();

		$toMonokulo = $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo.connect', $token, 'store_id=0'));
		$this->assertSame(302, $toMonokulo->status);
		$location = (string)$toMonokulo->location();
		$this->assertStringStartsWith($this->monokulo->url . '/plugins/connect/opencart?', $location);
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
		$this->assertSame('OpenCart', $query['name']);

		// Monokulo sends the browser back to return_url with its token.
		$this->monokulo->finishes(onion: true);
		$back = $browser->get($query['return_url'] . '&token=tok_http&nonce=' . $query['nonce']);

		$this->assertSame(302, $back->status);
		$this->assertSame(Shop::adminUrl('extension/monokulo/payment/monokulo', $token) . '&monokulo_connected=1', $back->location());
		$this->assertSame('pk_new', Shop::rows("SELECT public_key FROM oc_monokulo_connection WHERE host = 'localhost:8090'")[0]['public_key']);
		$admin = $browser->get((string)$back->location())->text();
		$this->assertStringContainsString('Your store is connected', $admin);
		$this->assertStringContainsString('On Tor at ' . FakeMonokulo::ONION, $admin);

		// The same return again: the nonce is spent.
		$again = $browser->get($query['return_url'] . '&token=tok_http&nonce=' . $query['nonce']);
		$this->assertSame(400, $again->status);
	}
}
