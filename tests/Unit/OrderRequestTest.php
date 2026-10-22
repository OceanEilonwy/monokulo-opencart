<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\OrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderRequestTest extends TestCase {
	public function test_the_body_monokulo_gets(): void {
		$body = OrderRequest::body(0, 1042, 12.5, 'eur', 2, ['Blue mug', 'Tea', 'Spoon'], 'Order #1042', ' + %d more');

		$this->assertSame([
			'amount'                 => '12.50',
			'currency'               => 'EUR',
			'merchant_order_id'      => '1042',
			'show_merchant_order_id' => false,
			'description'            => 'Order #1042: Blue mug + 2 more',
			'idempotency_key'        => 'oc:0:1042:12.50:EUR',
		], $body);
	}

	/** The same order and total retried is the same key; a changed total or another store is not. */
	public function test_the_idempotency_key_follows_store_order_and_total(): void {
		$key = static fn(int $store, int $order, float $total): string => OrderRequest::body($store, $order, $total, 'EUR', 2, [], 'x', '%d')['idempotency_key'];

		$this->assertSame($key(0, 7, 10.0), $key(0, 7, 10.0));
		$this->assertNotSame($key(0, 7, 10.0), $key(0, 7, 10.5));
		$this->assertNotSame($key(0, 7, 10.0), $key(1, 7, 10.0));
		$this->assertLessThanOrEqual(100, strlen(OrderRequest::body(2147483647, 2147483647, 99999999.99, 'XMR', 12, [], 'x', '%d')['idempotency_key']));
	}

	public static function amounts(): array {
		return [
			'two decimals'           => [12.5, 'EUR', 2, '12.50'],
			'rounded'                => [12.345, 'USD', 2, '12.35'],
			'no decimals currency'   => [1000.0, 'JPY', 0, '1000.00'],
			'XMR keeps its places'   => [0.123456789012, 'XMR', 12, '0.123456789012'],
			'XMR capped at 12'       => [1.5, 'xmr', 15, '1.500000000000'],
			'fiat capped at 8'       => [1.5, 'BTC', 12, '1.50000000'],
			'no thousands separator' => [1234567.0, 'EUR', 2, '1234567.00'],
		];
	}

	#[DataProvider('amounts')]
	public function test_amounts_are_plain_decimals(float $total, string $currency, int $decimals, string $expected): void {
		$this->assertSame($expected, OrderRequest::amount($total, $currency, $decimals));
	}

	public function test_one_item_has_no_more(): void {
		$this->assertSame('Order #5: Mug', OrderRequest::description('Order #5', ['Mug'], ' + %d more'));
		$this->assertSame('Order #5', OrderRequest::description('Order #5', [], ' + %d more'));
	}

	public function test_the_description_is_one_line(): void {
		$this->assertSame('Order #5: Blue mug, large', OrderRequest::description('Order #5', ["Blue\tmug,\r\n large"], ' + %d more'));
	}

	public function test_a_long_description_is_cut_at_140_characters(): void {
		$description = OrderRequest::description('Order #5', [str_repeat('é', 200)], ' + %d more');

		$this->assertSame(140, mb_strlen($description, 'UTF-8'));
		$this->assertStringEndsWith('…', $description);
		$this->assertTrue(mb_check_encoding($description, 'UTF-8'));
	}
}
