<?php
namespace Monokulo\OpenCart;

/**
 * Maps a monokulo event to what happens to the OpenCart order.
 *
 * - `order.unconfirmed`, `order.confirming`: a payment was seen and is
 *   waiting for confirmations. "Waiting" status (Pending by default).
 * - `order.partial`: less than the amount due has arrived. "Partial"
 *   status (Pending by default), with a note.
 * - `order.paid`: paid in full. "Paid" status (Processing by default), and
 *   the customer is told.
 * - `order.overpaid`: paid with more than was due. Paid, plus a note that
 *   the excess isn't refunded automatically.
 * - `order.expired`: the payment window closed without full payment.
 *   "Expired" status (OpenCart's Expired by default), with a note that any
 *   funds received stay at the payment address.
 * - `order.double_spend_detected`, `order.double_spend_reversed`: a note
 *   only. If the order's status changes because of it, monokulo announces
 *   that as its own `order.<status>` event.
 *
 * Anything else is accepted and ignored (null), so a newer monokulo can add
 * events without breaking the shop.
 */
final class StatusPolicy {
	public static function decide(WebhookEvent $event): ?Decision {
		switch ($event->event) {
			case 'order.unconfirmed':
			case 'order.confirming':
				return new Decision(Decision::STATUS_WAITING, 'note_waiting');
			case 'order.partial':
				return new Decision(Decision::STATUS_PARTIAL, 'note_partial');
			case 'order.paid':
				return new Decision(Decision::STATUS_PAID, 'note_paid', true, true);
			case 'order.overpaid':
				return new Decision(Decision::STATUS_PAID, 'note_overpaid', true, true);
			case 'order.expired':
				return new Decision(Decision::STATUS_EXPIRED, 'note_expired');
			case 'order.double_spend_detected':
				return new Decision(null, 'note_double_spend_detected');
			case 'order.double_spend_reversed':
				return new Decision(null, 'note_double_spend_reversed');
			default:
				return null;
		}
	}
}
