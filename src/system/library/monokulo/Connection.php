<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * A shop host's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host, so every OpenCart store on the same host shares
 * one connection.
 *
 * `api` is the API's entry as an absolute URL (`https://pay.example.com/api/v1`):
 * the client finds every other API address from there.
 */
final class Connection {
	public function __construct(
		public readonly string $host,
		public readonly string $api,
		public readonly string $publicKey,
		public readonly string $secretToken,
		public readonly string $webhookSecret,
		public readonly int $connectedAt,
	) {
	}

	/**
	 * Where the shop's warnings and errors go: `logs` under the entry (the
	 * entry has no link to it).
	 */
	public function logsUrl(): string {
		return rtrim($this->api, '/') . '/logs';
	}

	/** The order's page in the merchant's Monokulo. */
	public function dashboardOrderUrl(string $storeId, string $orderId): string {
		return Url::origin($this->api) . '/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId);
	}
}
