<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * The parts of a webhook body (api_version 2) this plugin acts on. Fields it
 * doesn't need are ignored, and fields monokulo adds later don't break it.
 *
 * `orderId` is '' for an event about no order: `webhook.test`, the test
 * delivery a merchant or the settings page's Test connection button sends.
 *
 * `dashboardUrl` is the order's `mk:dashboard-order` link from the body's
 * `_links`: its page on the merchant's dashboard, absolute, on every order
 * event (or '' when a body has none, or not an http(s) address).
 */
final class WebhookEvent {
	/** The test delivery: signed like any event, about no order. */
	public const TEST = 'webhook.test';

	private function __construct(
		public readonly string $event,
		public readonly string $eventId,
		public readonly string $orderId,
		public readonly ?string $txid,
		public readonly string $dashboardUrl,
	) {
	}

	/** The event in `body`, or null when it isn't a webhook envelope. */
	public static function parse(string $body): ?self {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			return null;
		}
		foreach (['event', 'event_id'] as $key) {
			if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
				return null;
			}
		}
		if (array_key_exists('order_id', $data) && (!is_string($data['order_id']) || $data['order_id'] === '')) {
			return null;
		}
		$text = static fn(string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

		$dashboard = $data['_links']['mk:dashboard-order']['href'] ?? null;
		$dashboard = is_string($dashboard) && preg_match('#\Ahttps?://[^\s]+\z#i', $dashboard) ? $dashboard : '';

		return new self($data['event'], $data['event_id'], $data['order_id'] ?? '', $text('txid'), $dashboard);
	}
}
