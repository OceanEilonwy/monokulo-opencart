<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\Tests\Support\Vectors;
use Monokulo\OpenCart\WebhookEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase {
	#[DataProviderExternal(Vectors::class, 'deliveryCases')]
	public function test_every_published_delivery_parses(array $delivery): void {
		$event = WebhookEvent::parse($delivery['body']);

		$this->assertNotNull($event);
		$this->assertSame($delivery['headers']['X-Monokulo-Event'], $event->event);
		$this->assertSame($delivery['headers']['X-Monokulo-Event-Id'], $event->eventId);
		if ($event->event === WebhookEvent::TEST) {
			$this->assertSame('', $event->orderId, 'a test is about no order');
			$this->assertSame('', $event->dashboardUrl);

			return;
		}
		$this->assertSame(Vectors::orderId(), $event->orderId);
		$this->assertSame(json_decode($delivery['body'], true)['_links']['mk:dashboard-order']['href'], $event->dashboardUrl);
		$this->assertStringEndsWith('/orders/' . Vectors::orderId(), $event->dashboardUrl);
	}

	/** The published test delivery: signed like the rest, naming the store and the webhook, with no order. */
	public function test_the_test_delivery_has_no_order(): void {
		$delivery = Vectors::delivery(WebhookEvent::TEST);
		$body = json_decode($delivery['body'], true);

		$this->assertSame(WebhookEvent::TEST, $delivery['headers']['X-Monokulo-Event']);
		$this->assertArrayNotHasKey('order_id', $body);
		$this->assertArrayHasKey('id', $body['webhook']);
		$this->assertSame(WebhookEvent::TEST, WebhookEvent::parse($delivery['body'])->event);
	}

	/** The order's dashboard page comes only as an http(s) address in `_links`; anything else is no link. */
	public function test_the_dashboard_page_is_an_http_address_or_nothing(): void {
		$with = static fn(string $links): string => WebhookEvent::parse('{"event":"order.paid","event_id":"evt_1","order_id":"o1","_links":' . $links . '}')->dashboardUrl;

		$this->assertSame('https://pay.example.com/stores/s/orders/o1', $with('{"mk:dashboard-order":{"href":"https://pay.example.com/stores/s/orders/o1"}}'));
		$this->assertSame('http://x.onion/stores/s/orders/o1', $with('{"mk:dashboard-order":{"href":"http://x.onion/stores/s/orders/o1"}}'));
		$this->assertSame('', $with('{"mk:dashboard-order":{"href":"javascript:alert(1)"}}'));
		$this->assertSame('', $with('{"mk:dashboard-order":{"href":"/stores/s/orders/o1"}}'));
		$this->assertSame('', $with('{"mk:dashboard-order":{"href":5}}'));
		$this->assertSame('', $with('{"mk:checkout":{"href":"https://pay.example.com/checkout/o1"}}'));
		$this->assertSame('', $with('"nope"'));
	}

	public function test_the_double_spend_events_carry_their_transaction(): void {
		foreach (['order.double_spend_detected', 'order.double_spend_reversed'] as $name) {
			$this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string)WebhookEvent::parse(Vectors::delivery($name)['body'])->txid, $name);
		}
		$this->assertNull(WebhookEvent::parse(Vectors::delivery('order.paid')['body'])->txid);
	}

	public static function notEnvelopes(): array {
		return [
			'not JSON'          => ['nope'],
			'a list'            => ['[1,2]'],
			'no event'          => ['{"event_id":"evt_1","order_id":"o1"}'],
			'no event id'       => ['{"event":"order.paid","order_id":"o1"}'],
			'empty event id'    => ['{"event":"order.paid","event_id":"","order_id":"o1"}'],
			'empty order id'    => ['{"event":"order.paid","event_id":"evt_1","order_id":""}'],
			'null order id'     => ['{"event":"order.paid","event_id":"evt_1","order_id":null}'],
			'numeric order id'  => ['{"event":"order.paid","event_id":"evt_1","order_id":5}'],
		];
	}

	#[DataProvider('notEnvelopes')]
	public function test_anything_else_is_not_an_event(string $body): void {
		$this->assertNull(WebhookEvent::parse($body));
	}

	/** Fields Monokulo adds later, or optional ones missing, don't stop it. */
	public function test_unknown_and_missing_optional_fields_are_fine(): void {
		$event = WebhookEvent::parse('{"event":"order.refunded","event_id":"evt_9","order_id":"o1","new_field":{"x":1}}');

		$this->assertSame('order.refunded', $event->event);
		$this->assertNull($event->txid);
		$this->assertSame('', $event->dashboardUrl);
	}

	/** An event about no order parses, its order id ''; the receiver decides what that means. */
	public function test_an_event_without_an_order_parses(): void {
		$this->assertSame('', WebhookEvent::parse('{"event":"order.paid","event_id":"evt_1"}')->orderId);
	}
}
