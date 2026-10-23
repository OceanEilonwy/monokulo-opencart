<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

final class HttpResponse {
	/**
	 * @param array<string, string> $headers by lower-case name (the last of a repeated one)
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = [],
	) {
	}

	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}
}
