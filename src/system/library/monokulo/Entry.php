<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * The API's entry (`GET {api}`) as a store's key sees it, kept as long as
 * its `Cache-Control: max-age` says: `body` its links and forms, `etag` to
 * revalidate it with (`If-None-Match`), `expiresAt` when to.
 */
final class Entry {
	/**
	 * @param array<string, mixed> $body
	 */
	public function __construct(
		public readonly array $body,
		public readonly string $etag,
		public readonly int $expiresAt,
	) {
	}

	/**
	 * The form `name` (`create-order`, `send-logs`), when it has a target.
	 *
	 * @return array{method: string, target: string}|null
	 */
	public function form(string $name): ?array {
		$form = $this->body['_templates'][$name] ?? null;
		if (!is_array($form) || !isset($form['target']) || !is_string($form['target']) || $form['target'] === '') {
			return null;
		}

		return [
			'method' => isset($form['method']) && is_string($form['method']) ? strtoupper($form['method']) : 'POST',
			'target' => $form['target'],
		];
	}
}
