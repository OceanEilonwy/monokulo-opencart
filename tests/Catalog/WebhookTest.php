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
		// As confirm leaves it: placed, and mapped to the Monokulo order the vectors are about.
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

	/** Delivers the body `data`, signed as Monokulo signs, at the vectors' clock. */
	private function deliverSigned(array $data): int {
		$body = (string)json_encode($data);
		$t = Vectors::sentAt();

		return $this->deliver($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret()));
	}

	/** The order's page on the merchant's dashboard, as the vectors' webhooks link it. */
	private static function dashboard(): string {
		return json_decode(Vectors::delivery('order.paid')['body'], true)['_links']['mk:dashboard-order']['href'];
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

	/** Paid: the paid status, the customer told, and the merchant's note with the webhook's dashboard link. */
	public function test_paid_moves_the_order_to_paid_and_tells_the_customer(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$history = array_slice($this->history($this->orderId), -2);
		$this->assertSame(['2', '1', 'Your Monero payment has arrived. Thank you!'], array_values($history[0]));
		$this->assertSame('0', $history[1]['notify']);
		$this->assertStringContainsString('Monokulo: paid in full.', $history[1]['comment']);
		$this->assertSame(' In Monokulo: ' . self::dashboard(), substr($history[1]['comment'], strlen('Monokulo: paid in full.')));
		$this->assertStringStartsWith('https://', self::dashboard());
	}

	/** Every event links the order's dashboard page, whatever it is about (a double spend, an expiry). */
	public function test_every_note_links_the_dashboard_page(): void {
		foreach (['order.confirming', 'order.double_spend_detected', 'order.expired', 'order.cancelled'] as $event) {
			$this->assertSame(200, $this->deliverVector($event));
			$this->assertStringEndsWith(' In Monokulo: ' . self::dashboard(), $this->lastHistory()['comment'], $event);
		}
	}

	/** A webhook that links no dashboard page (or not on the web): the note goes without a link. */
	public function test_a_webhook_without_a_dashboard_page_gets_its_note_without_a_link(): void {
		$data = json_decode(Vectors::delivery('order.paid')['body'], true);
		unset($data['_links']['mk:dashboard-order']);
		$this->assertSame(200, $this->deliverSigned($data));
		$this->assertSame('Monokulo: paid in full.', $this->lastHistory()['comment']);

		$data = json_decode(Vectors::delivery('order.overpaid')['body'], true);
		$data['_links']['mk:dashboard-order']['href'] = 'javascript:alert(1)';
		$this->assertSame(200, $this->deliverSigned($data));
		$this->assertStringNotContainsString('In Monokulo', $this->lastHistory()['comment']);
	}

	/** From a Monokulo with an onion address: paid all the same, with the web dashboard link. */
	public function test_paid_from_a_monokulo_with_an_onion_address(): void {
		$this->assertSame(200, $this->deliverVector('order.paid from a Monokulo with an onion address'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringEndsWith(' In Monokulo: ' . self::dashboard(), $this->lastHistory()['comment']);
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

	/** Cancelled (only the POS cancels, but a shop order is handled anyway): as expired, with its own note. */
	public function test_cancelled_goes_to_expired(): void {
		$this->assertSame(200, $this->deliverVector('order.cancelled'));
		$this->assertSame(14, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('was cancelled in Monokulo before it was paid', $this->lastHistory()['comment']);
	}

	/** Paid after it was cancelled (money sent anyway): paid, as for an expired order paid late. */
	public function test_a_cancelled_order_paid_late_is_paid(): void {
		$this->deliverVector('order.cancelled');

		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
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
		$this->assertSame(200, $this->deliverVector('order.cancelled'));
		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertStringContainsString('payment window closed', $this->lastHistory()['comment']);
		$this->assertStringContainsString('The order was already paid, so its status is left as it is', $this->lastHistory()['comment']);
	}

	/** An order the merchant moved on to a complete status (Complete, Shipped) counts as paid too. */
	public function test_a_completed_order_is_not_moved_back(): void {
		$this->registry()->get('model_checkout_order')->addHistory($this->orderId, 5, 'completed by hand');

		foreach (['order.expired', 'order.cancelled'] as $event) {
			$this->assertSame(200, $this->deliverVector($event));
			$this->assertSame(5, $this->orderStatus($this->orderId), $event);
			$this->assertStringContainsString('already paid', $this->lastHistory()['comment']);
		}
	}

	/** Every published delivery, in order, on one order: each applies once, and the order ends paid. */
	public function test_every_published_delivery_is_accepted(): void {
		foreach (Vectors::deliveries() as $name => $delivery) {
			$this->assertSame(200, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']), $name);
		}
		$this->assertCount(1 + count(Vectors::deliveries()) + 1, $this->history($this->orderId), 'one entry each, and the customer told once, on the first payment');
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
	 * Monokulo's delivery `name`, as if about Monokulo order `monokuloOrderId`
	 * instead (a new event id, and its own links), signed as Monokulo signs.
	 */
	private function deliverFor(string $name, string $monokuloOrderId): int {
		$data = json_decode(str_replace(Vectors::orderId(), $monokuloOrderId, Vectors::delivery($name)['body']), true);
		$data['event_id'] = 'evt_' . bin2hex(random_bytes(8));

		return $this->deliverSigned($data);
	}

	/**
	 * The customer was sent to pay again (the total changed), so the order
	 * has a newer Monokulo order, and the vectors' one is an earlier one.
	 */
	private function sentToPayAgain(): void {
		$this->repository()->recordOrder('ord_newer', $this->orderId, 0, self::HOST, '120.00', 'USD', 1760000001);
	}

	/** Every Monokulo order the shop order had is known: an earlier one's webhook is taken, not refused. */
	public function test_an_earlier_monokulo_order_is_still_this_orders(): void {
		$this->sentToPayAgain();

		$this->assertSame(200, $this->deliverVector('order.confirming'));
		$this->assertSame(200, $this->deliverFor('order.confirming', 'ord_newer'));
		$this->assertSame(404, $this->deliverFor('order.confirming', 'ord_someone_elses'));
	}

	/** Money arriving on an earlier Monokulo order pays the order: it arrived. */
	public function test_paying_an_earlier_request_marks_the_order_paid(): void {
		$this->sentToPayAgain();

		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$history = array_slice($this->history($this->orderId), -2);
		$this->assertSame(['2', '1', 'Your Monero payment has arrived. Thank you!'], array_values($history[0]));
		$this->assertStringContainsString('Monokulo: paid in full.', $history[1]['comment']);
		$this->assertStringContainsString('earlier payment request for this order', $history[1]['comment']);
	}

	public static function notPayments(): array {
		return [
			'expired'   => ['order.expired', 'payment window closed'],
			'cancelled' => ['order.cancelled', 'was cancelled in Monokulo'],
			'part paid' => ['order.partial', 'part of the amount due has arrived'],
			'seen'      => ['order.confirming', 'waiting for confirmations'],
		];
	}

	/** Anything but a payment on an earlier Monokulo order is a note: the newest one decides the status. */
	#[\PHPUnit\Framework\Attributes\DataProvider('notPayments')]
	public function test_an_earlier_requests_other_events_are_notes_only(string $event, string $note): void {
		$this->sentToPayAgain();
		// Statuses unlike the order's Pending, so any change would show.
		$this->setting('payment_monokulo_waiting_status_id', '15');
		$this->setting('payment_monokulo_partial_status_id', '15');

		$this->assertSame(200, $this->deliverVector($event));

		$this->assertSame(1, $this->orderStatus($this->orderId));
		$last = $this->lastHistory();
		$this->assertSame(['1', '0'], [$last['order_status_id'], $last['notify']]);
		$this->assertStringContainsString($note, $last['comment']);
		$this->assertStringContainsString('earlier payment request', $last['comment']);
		$this->assertStringContainsString('newest payment request decides its status', $last['comment']);
	}

	/** The newest Monokulo order's events still move the order. */
	public function test_the_newest_requests_events_move_the_order(): void {
		$this->sentToPayAgain();

		$this->assertSame(200, $this->deliverFor('order.expired', 'ord_newer'));

		$this->assertSame(14, $this->orderStatus($this->orderId));
		$this->assertStringNotContainsString('earlier payment request', $this->lastHistory()['comment']);
		$this->assertStringEndsWith('/orders/ord_newer', $this->lastHistory()['comment'], 'linked to the newer order\'s own page');
	}

	/**
	 * Paid twice (both Monokulo orders paid): the order stays paid, the
	 * customer isn't told again, and the merchant gets a note to refund.
	 */
	public function test_a_second_payment_is_a_note_for_the_merchant(): void {
		$this->sentToPayAgain();
		$this->assertSame(200, $this->deliverFor('order.paid', 'ord_newer'));
		$before = count($this->history($this->orderId));

		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$history = $this->history($this->orderId);
		$this->assertCount($before + 1, $history, 'one note, no message to the customer');
		$this->assertSame(['2', '0'], [end($history)['order_status_id'], end($history)['notify']]);
		$this->assertStringContainsString('already paid, so this is more money than it needed', end($history)['comment']);
	}

	/** More money on the same Monokulo order after it was paid: a note too. */
	public function test_an_overpayment_after_paid_is_a_note(): void {
		$this->deliverVector('order.paid');
		$before = count($this->history($this->orderId));

		$this->assertSame(200, $this->deliverVector('order.overpaid'));

		$this->assertSame(2, $this->orderStatus($this->orderId));
		$this->assertCount($before + 1, $this->history($this->orderId));
		$this->assertStringContainsString('more than the amount due', $this->lastHistory()['comment']);
		$this->assertStringContainsString('refund it from your wallet', $this->lastHistory()['comment']);
	}

	/** Nothing an earlier Monokulo order says moves a paid order back. */
	public function test_a_paid_order_never_goes_back_whichever_request_speaks(): void {
		$this->sentToPayAgain();
		$this->deliverFor('order.paid', 'ord_newer');

		foreach (['order.expired', 'order.cancelled', 'order.partial', 'order.confirming'] as $event) {
			$this->assertSame(200, $this->deliverVector($event));
			$this->assertSame(2, $this->orderStatus($this->orderId), $event);
		}
		foreach (['order.expired', 'order.cancelled'] as $event) {
			$this->assertSame(200, $this->deliverFor($event, 'ord_newer'));
			$this->assertSame(2, $this->orderStatus($this->orderId), $event);
			$this->assertStringContainsString('already paid', $this->lastHistory()['comment']);
		}
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
