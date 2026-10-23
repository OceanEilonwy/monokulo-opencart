<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Url;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase {
	public function test_where_logs_and_the_merchant_go(): void {
		$c = new Connection('shop.example', 'https://pay.example.com:8443/api/v1/', 'pk_a', 'sk_x', 'whsec_x', 1);

		$this->assertSame('https://pay.example.com:8443/api/v1/logs', $c->logsUrl());
		$this->assertSame('https://pay.example.com:8443/stores/st%201/orders/o1', $c->dashboardOrderUrl('st 1', 'o1'));
	}

	public function test_links_resolve_against_the_address_called(): void {
		$base = 'http://abc.onion/api/v1';

		$this->assertSame('http://abc.onion/checkout/order_1', Url::resolve($base, '/checkout/order_1'));
		$this->assertSame('http://abc.onion/api/orders', Url::resolve($base, 'orders'));
		$this->assertSame('https://pay.example.com/x', Url::resolve($base, 'https://pay.example.com/x'));
		$this->assertSame('http://cdn.example/x', Url::resolve($base, '//cdn.example/x'));
		$this->assertSame('http://abc.onion/api/v1', Url::resolve('http://abc.onion/plugins/connect/opencart/finish', '/api/v1'));
		$this->assertSame('', Url::origin('not a url'));
	}
}
