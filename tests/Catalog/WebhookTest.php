<?php
namespace Monokulo\OpenCart\Tests\Catalog;

use Monokulo\OpenCart\Logger;
use Monokulo\OpenCart\Trace;
use Monokulo\OpenCart\WebhookReceiver;
use Monokulo\OpenCart\Tests\Support\OpenCart;
use Monokulo\OpenCart\Tests\Support\ShopTestCase;
use Monokulo\OpenCart\Tests\Support\Vectors;

/**
 * Monokulo's published webhook deliveries, byte for byte, through the
 * receiver and the catalog model's order updater, against real OpenCart
 * orders and OpenCart's own order history. The clock is the vectors'
 * `sent_at`, so their own signatures check out.
 */
final class WebhookTest extends ShopTestCase {
	private int $orderId;

	/** @var list<string> */
	private array $log = [];

	protected function setUp(): void {
		parent::setUp();
		$this->connect();
		$this->orderId = $this->order(100.0, 'USD');
		// As confirm leaves it: placed, mapped to the Monokulo order the vectors are about.
		$this->repository()->recordOrder(Vectors::orderId(), $this->orderId, 0, self::HOST, '100.00', 'USD', 1760000000);
		$this->registry()->get('load')->model('checkout/order');
		$this->registry()->get('model_checkout_order')->addHistory($this->orderId, 1, 'placed');
	}

	private ?\Opencart\System\Engine\Registry $registry = null;

	/** Settings are read when a request starts: change one, then start a new request. */
	protected function setting(string $key, string $value, string $code = 'payment_monokulo'): void {
		parent::setting($key, $value, $code);
		$this->registry = null;
	}

	private function registry(): \Opencart\System\Engine\Registry {
		return $this->registry ??= OpenCart::boot('catalog');
	}

	/** Delivers `body` signed with `signature` as of the vectors' clock; returns the HTTP status. */
	private function deliver(string $body, string $signature, ?int $now = null): int {
		$registry = $this->registry();
		$registry->get('load')->model('extension/monokulo/payment/monokulo');
		$model = $registry->get('model_extension_monokulo_payment_monokulo');
		$logger = new Logger(function (string $line): void {
			$this->log[] = $line;
		}, new Trace());

		return (new WebhookReceiver($model->repository(), $model->updater(), $logger))->handle($model->connection(), $body, $signature, $now ?? Vectors::sentAt());
	}

	private function deliverVector(string $name): int {
		$delivery = Vectors::delivery($name);

		return $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']);
	}

	/** @return array{order_status_id: string, notify: string, comment: string} */
	private function lastHistory(): array {
		$history = $this->history($this->orderId);

		return end($history);
	}

	public function test_a_payment_seen_waits_in_the_waiting_status(): void {
		$this->setting('payment_monokulo_waiting_status_id', '15'); // Processed, so the change shows
		$this->assertSame(200, $this->deliverVector('order.unconfirmed'));
		$this->assertSame(15, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('waiting for confirmations', $this->lastHistory()['comment']);
		$this->assertSame('0', $this->lastHistory()['notify']);

		$this->assertSame(200, $this->deliverVector('order.confirming'));
		$this->assertSame(15, $this->orderStatus($this->orderId));
	}

	public function test_part_paid_goes_to_the_partial_status_with_a_note(): void {
		$this->setting('payment_monokulo_partial_status_id', '15');
		$this->assertSame(200, $this->deliverVector('order.partial'));
		$this->assertSame(15, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('part of the amount due has arrived', $this->lastHistory()['comment']);
	}

	/** Paid: the paid status, the customer told, and the merchant's note with the dashboard link. */
	public function test_paid_moves_the_order_to_paid_and_tells_the_customer(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$history = array_slice($this->history($this->orderId), -2);
		$this->assertSame(['2', '1', 'Your Monero payment has arrived. Thank you!'], array_values($history[0]));
		$this->assertSame('0', $history[1]['notify']);
		$this->assertStringContainsString('Monokulo: paid in full.', $history[1]['comment']);
		$this->assertStringContainsString(self::$monokulo->url . '/stores/917701c2-15c7-4bcb-bbc8-72d9ab28e785/orders/' . Vectors::orderId(), $history[1]['comment']);
	}

	public function test_paid_in_xmr_is_paid_too(): void {
		$this->assertSame(200, $this->deliverVector('order.paid priced in XMR'));
		$this->assertSame(2, $this->orderStatus($this->orderId));
	}

	public function test_overpaid_is_paid_with_a_note_about_the_excess(): void {
		$this->assertSame(200, $this->deliverVector('order.overpaid'));
		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('more than the amount due', $this->lastHistory()['comment']);
	}

	/** Expired: OpenCart's Expired status, which also puts the stock back. */
	public function test_expired_goes_to_expired(): void {
		$this->assertSame(200, $this->deliverVector('order.expired'));
		$this->assertSame(14, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('payment window closed', $this->lastHistory()['comment']);
	}

	/** A double spend only adds a note: the status change, if any, comes as its own event. */
	public function test_double_spend_alerts_are_notes_only(): void {
		$this->deliverVector('order.paid');
		$this->assertSame(200, $this->deliverVector('order.double_spend_detected'));
		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('FRAUD ALERT', $this->lastHistory()['comment']);
		$this->assertMatchesRegularExpression('/transaction [0-9a-f]{64}/', $this->lastHistory()['comment']);

		$this->assertSame(200, $this->deliverVector('order.double_spend_reversed'));
		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('was wrong and is withdrawn', $this->lastHistory()['comment']);
	}

	/**
	 * Once paid, a later event that isn't a payment (the order expired, or
	 * went back to part paid after a double spend) leaves the order paid,
	 * with a note: the merchant decides.
	 */
	public function test_a_paid_order_is_not_moved_back(): void {
		$this->deliverVector('order.paid');

		$this->assertSame(200, $this->deliverVector('order.partial'));
		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('payment window closed', $this->lastHistory()['comment']);
		$this->assertStringContainsString('The order was already paid, so its status is left as it is', $this->lastHistory()['comment']);
	}

	/** An order the merchant moved on to a complete status (Complete, Shipped) counts as paid too. */
	public function test_a_completed_order_is_not_moved_back(): void {
		$this->registry()->get('model_checkout_order')->addHistory($this->orderId, 5, 'completed by hand');

		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame(5, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('already paid', $this->lastHistory()['comment']);
	}

	/** Every published delivery, in order, on one order: each applies once, and the order ends paid. */
	public function test_every_published_delivery_is_accepted(): void {
		foreach (Vectors::deliveries() as $name => $delivery) {
			$this->assertSame(200, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']), $name);
		}
		$this->assertCount(1 + count(Vectors::deliveries()) + 3, $this->history($this->orderId), 'one entry each, two for each paid');
		$this->assertSame(2, $this->orderStatus($this->orderId));
	}

	/** A delivery whose answer was lost is sent again; the second changes nothing. */
	public function test_a_repeated_delivery_changes_nothing(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$count = count($this->history($this->orderId));

		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertCount($count, $this->history($this->orderId));
	}

	public function test_every_published_forgery_is_refused_and_changes_nothing(): void {
		foreach (Vectors::refused() as $why => $refuse) {
			$this->assertSame(401, $this->deliver($refuse['body'], $refuse['headers']['X-Monokulo-Signature']), $why);
		}
		$this->assertSame(1, $this->orderStatus($this->orderId));
		$this->assertCount(1, $this->history($this->orderId));
	}

	public function test_a_genuine_delivery_arriving_too_late_is_refused(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->assertSame(401, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt() + 301));
	}

	public function test_a_store_that_never_connected_refuses_everything(): void {
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_connection`");

		$this->assertSame(401, $this->deliverVector('order.paid'));
	}

	public function test_signed_but_not_an_envelope_is_a_bad_request(): void {
		$body = '{"hello":"world"}';
		$t = Vectors::sentAt();

		$this->assertSame(400, $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret())));
	}

	/** Retried, in case the order is still being saved. */
	public function test_an_order_this_shop_never_made_is_not_found(): void {
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_order`");

		$this->assertSame(404, $this->deliverVector('order.paid'));
	}

	public function test_an_event_this_version_does_not_know_is_accepted_and_ignored(): void {
		$body = json_encode(['api_version' => 2, 'event_id' => 'evt_new', 'event' => 'order.refunded', 'created_at' => 1, 'order_id' => Vectors::orderId()]);
		$t = Vectors::sentAt();

		$this->assertSame(200, $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret())));
		$this->assertCount(1, $this->history($this->orderId));
		$this->assertStringContainsString('unknown event "order.refunded"', implode("\n", $this->log));
	}

	/**
	 * The customer changed the order after a first payment request, so a
	 * newer one exists. The older one being paid doesn't mark the order paid.
	 */
	public function test_paying_an_older_request_is_flagged_not_marked_paid(): void {
		$this->repository()->recordOrder('ord_newer', $this->orderId, 0, self::HOST, '120.00', 'USD', 1760000001);

		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame(1, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('older payment request for this order was paid', $this->lastHistory()['comment']);
	}

	/** Applying fails (no status chosen): 500, and the event is given back so the retry applies it. */
	public function test_a_failed_change_is_retried(): void {
		$this->setting('payment_monokulo_paid_status_id', '0');
		$this->assertSame(500, $this->deliverVector('order.paid'));
		$this->assertSame(1, $this->orderStatus($this->orderId));

		$this->setting('payment_monokulo_paid_status_id', '2');
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame(2, $this->orderStatus($this->orderId));
	}

	public function test_an_order_deleted_since_is_retried(): void {
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "order` WHERE `order_id` = " . $this->orderId);

		$this->assertSame(500, $this->deliverVector('order.paid'));
		$this->assertStringContainsString('no longer exists', implode("\n", $this->log));
	}
}
