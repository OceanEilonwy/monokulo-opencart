<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * Monokulo answered, but not with what was asked for. `status` is its HTTP
 * status (a 2xx when the answer was a success with the wrong shape),
 * `detail` what went wrong (a problem's `detail`, or the raw body), for the
 * merchant's log, and `problem` the problem's name from its `type`
 * (`invalid-amount`), or '' when the answer wasn't a problem.
 * `retryAfter` is how many seconds Monokulo asked to wait before trying
 * again (the problem's `retry_after`, else its `Retry-After`), when it
 * said.
 */
final class ApiError extends \RuntimeException {
	public function __construct(
		public readonly int $status,
		public readonly string $detail,
		public readonly string $problem = '',
		public readonly ?int $retryAfter = null,
	) {
		parent::__construct(sprintf('HTTP %d: %s', $status, $detail));
	}

	/**
	 * Whether the same request may simply be sent again later: Monokulo
	 * made nothing (no exchange rate right now, a busy payment engine, too
	 * many requests). A retried order keeps its idempotency key, so it
	 * still can't make two.
	 */
	public function retryable(): bool {
		return $this->status === 429 || $this->status === 503;
	}

	/** The language key of what the customer is told. */
	public function customerMessageKey(): string {
		return match (true) {
			$this->problem === 'invalid-amount'           => 'error_currency',
			$this->problem === 'rates-unavailable'        => 'error_rates',
			$this->retryable()                            => 'error_busy',
			$this->status === 401 || $this->status === 403 => 'error_unavailable',
			default                                       => 'error_generic',
		};
	}

	/** A pointer for the merchant reading the log, or ''. */
	public function merchantHint(): string {
		$hint = match (true) {
			$this->problem === 'invalid-amount'       => ' (Monokulo can\'t price this order: check that the store in Monokulo has an exchange rate for its currency)',
			$this->problem === 'idempotency-conflict' => ' (Monokulo already has a different order for this payment attempt)',
			$this->problem === 'rates-unavailable'    => ' (Monokulo has no exchange rate for this currency right now: its rate providers are down or rate limiting it. No order was made; the customer was asked to try again shortly, and trying again asks for the same order)',
			$this->status === 401                     => ' (the store secret key was rejected: connect the store again from the extension settings)',
			$this->status === 403                     => ' (Monokulo refused the order with this store\'s key: connect the store again from the extension settings)',
			$this->status === 404                     => ' (no Monokulo API at the saved address: check the Monokulo address and connect the store again)',
			$this->status === 429                     => ' (rate limited: Monokulo is receiving too many orders from this store. No order was made; trying again asks for the same order)',
			$this->status === 503                     => ' (Monokulo\'s payment engine is busy or unreachable. No order was made; the customer was asked to try again shortly, and trying again asks for the same order)',
			default                                   => '',
		};

		return $this->retryAfter !== null && $this->retryable() ? $hint . sprintf(' Monokulo asked to wait %d seconds before trying again.', $this->retryAfter) : $hint;
	}
}
