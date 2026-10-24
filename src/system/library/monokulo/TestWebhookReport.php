<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * What the settings page's Test connection button tells the admin, from
 * how Monokulo's `send-test-webhook` went:
 *
 * - delivered, and this shop's receiver took that very event (checked its
 *   signature and kept its id): the status and how long it took;
 * - delivered, but not to this shop's receiver: the webhook goes somewhere
 *   else;
 * - not delivered: Monokulo's reason, and when it tries again;
 * - a problem: `rate-limited` with how long to wait, `not-found`, a refused
 *   key, or Monokulo out of reach.
 *
 * `key` is the language key and `args` what fills it in; message() puts
 * them together, escaped, for OpenCart's admin alerts (which show HTML).
 */
final class TestWebhookReport {
	/**
	 * @param list<int|string> $args
	 * @param list<int> $dates which args are Unix times, shown as dates
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $key,
		public readonly array $args = [],
		private readonly array $dates = [],
	) {
	}

	/**
	 * From Monokulo's answer (Client::sendTestWebhook); `received` is the
	 * last test webhook this shop's receiver took, by event id.
	 *
	 * @param array{event_id: string, delivered: bool, status: ?int, duration_ms: int, error: ?string, next_attempt_at: ?int} $outcome
	 */
	public static function fromOutcome(array $outcome, string $received): self {
		$id = $outcome['event_id'];
		if ($outcome['delivered']) {
			return $received === $id
				? new self(true, 'text_test_delivered', [$id, (int)$outcome['status'], $outcome['duration_ms']])
				: new self(false, 'error_test_elsewhere', [$id, (int)$outcome['status'], $outcome['duration_ms']]);
		}
		$why = $outcome['error'] ?? ($outcome['status'] !== null ? 'answered ' . $outcome['status'] : 'no answer');

		return $outcome['next_attempt_at'] !== null
			? new self(false, 'error_test_retry', [$id, $why, $outcome['next_attempt_at']], [2])
			: new self(false, 'error_test_failed', [$id, $why]);
	}

	public static function fromError(ApiError|TransportError $e): self {
		if ($e instanceof TransportError) {
			return new self(false, 'error_test_unreachable', [$e->getMessage()]);
		}

		return match (true) {
			$e->problem === 'rate-limited' || $e->status === 429 => new self(false, 'error_test_rate_limited', [$e->retryAfter ?? 60]),
			$e->problem === 'not-found'                          => new self(false, 'error_test_not_found'),
			$e->status === 401 || $e->status === 403             => new self(false, 'error_test_key'),
			default                                              => new self(false, 'error_test_other', [$e->status, $e->detail]),
		};
	}

	/** A store with no connection yet: nothing to test. */
	public static function notConnected(): self {
		return new self(false, 'error_test_not_connected');
	}

	/**
	 * The sentence, HTML-escaped: `text` gives a language key's text,
	 * `dateFormat` is how dates read (OpenCart's `datetime_format`).
	 *
	 * @param \Closure(string): string $text
	 */
	public function message(\Closure $text, string $dateFormat): string {
		$args = [];
		foreach ($this->args as $i => $arg) {
			$arg = in_array($i, $this->dates, true) ? date($dateFormat, (int)$arg) : $arg;
			$args[] = is_string($arg) ? htmlspecialchars($arg, ENT_QUOTES, 'UTF-8') : $arg;
		}

		return vsprintf($text($this->key), $args);
	}
}
