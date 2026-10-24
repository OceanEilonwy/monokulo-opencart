<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * A shop host's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host, so every OpenCart store on the same host shares
 * one connection.
 *
 * `api` is the API's entry as an absolute URL (`https://pay.example.com/api/v1`,
 * or `https://example.com/monokulo/api/v1` for a Monokulo under a path): the
 * client finds every other API address from there. `onionApi` is the same
 * entry on Monokulo's onion address, when it had one as the shop connected
 * (`''` otherwise, and for shops that connected before it was kept).
 * `lastTestEventId` and `lastTestAt` are the last `webhook.test` the shop's
 * receiver took for this host (its event id, and when it arrived), or ''
 * and 0 when none has since it connected.
 */
final class Connection {
	public function __construct(
		public readonly string $host,
		public readonly string $api,
		public readonly string $publicKey,
		public readonly string $secretToken,
		public readonly string $webhookSecret,
		public readonly int $connectedAt,
		public readonly string $onionApi = '',
		public readonly string $lastTestEventId = '',
		public readonly int $lastTestAt = 0,
	) {
	}
}
