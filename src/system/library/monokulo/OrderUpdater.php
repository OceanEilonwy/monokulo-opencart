<?php
namespace Monokulo\OpenCart;

/**
 * Changes an OpenCart order for one webhook event: the catalog model
 * implements it with OpenCart's order history.
 */
interface OrderUpdater {
	/**
	 * @param bool $latest whether `event` is about the order's newest Monokulo order
	 */
	public function apply(int $orderId, Decision $decision, WebhookEvent $event, bool $latest, Connection $connection): void;
}
