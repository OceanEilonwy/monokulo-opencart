<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase {
	public function test_links_resolve_against_the_address_called(): void {
		$base = 'http://abc.onion/api/v1';

		$this->assertSame('http://abc.onion/checkout/order_1', Url::resolve($base, '/checkout/order_1'));
		$this->assertSame('http://abc.onion/api/orders', Url::resolve($base, 'orders'));
		$this->assertSame('https://pay.example.com/x', Url::resolve($base, 'https://pay.example.com/x'));
		$this->assertSame('http://cdn.example/x', Url::resolve($base, '//cdn.example/x'));
		$this->assertSame('http://abc.onion/api/v1', Url::resolve('http://abc.onion/plugins/connect/opencart/finish', '/api/v1'));
		$this->assertSame('', Url::origin('not a url'));
	}

	/** A path on Monokulo's address is kept: everything under a prefix stays under it. */
	public function test_paths_keep_a_prefix(): void {
		$base = 'https://example.com/monokulo/api/v1';

		$this->assertSame('https://example.com/monokulo/api/v1/orders', Url::resolve($base, '/monokulo/api/v1/orders'));
		$this->assertSame('https://example.com/monokulo/api/v1', Url::resolve('https://example.com/monokulo/plugins/connect/opencart/finish', '/monokulo/api/v1'));
	}

	public function test_onion_hosts(): void {
		foreach (['2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion', 'shop.ONION:8080', 'abc.onion.'] as $host) {
			$this->assertTrue(Url::isOnion($host), $host);
		}
		foreach (['shop.example', 'onion.example', 'localhost:8090', '', 'shop.onion.example'] as $host) {
			$this->assertFalse(Url::isOnion($host), $host);
		}
	}
}
