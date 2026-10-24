<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * make an order through the API (`/api/v1`, HAL-FORMS), have Monokulo send
 * the shop a test webhook, and (when the merchant turned it on) forward
 * its warnings and errors.
 *
 * Only the API's entry is stored; every other address is a link or a form
 * from there, resolved against the entry's URL. The entry itself is kept
 * (EntryStore) for as long as its `Cache-Control` says and revalidated
 * with its `ETag` after, so making an order is usually one request. When
 * something followed from a kept entry answers `401`, `404` or `405`, the
 * entry is read again and the request sent once more.
 */
final class Client {
	private const HAL = 'application/prs.hal-forms+json';

	/** How to get the entry: the kept one while fresh, asked again with its ETag, or read afresh. */
	private const KEPT = 0;
	private const REVALIDATE = 1;
	private const REREAD = 2;

	/** Answers that mean a kept entry may be out of date: the key changed, or the API moved on. */
	private const STALE = [401, 404, 405];

	/**
	 * @param \Closure(): int|null $clock the time now, in Unix seconds (time() unless given)
	 */
	public function __construct(
		private readonly Trace $trace,
		private readonly EntryStore $entries,
		private readonly ?\Closure $clock = null,
	) {
	}

	/**
	 * `POST {address}/plugins/connect/opencart/finish`: redeems the single-use token
	 * the connect flow handed back, registers `webhookUrl`, and returns the
	 * store's credentials, with `api` (the API's entry) resolved against
	 * the URL called, and `onion_api` the entry on Monokulo's onion address
	 * (`''` when it has none).
	 *
	 * @return array{public_key: string, secret_token: string, api: string, onion_api: string, webhook_signing_secret: string}
	 *
	 * @throws ApiError|TransportError
	 */
	public function finishConnect(string $address, string $token, string $webhookUrl): array {
		$url = rtrim($address, '/') . '/plugins/connect/' . Plugin::SLUG . '/finish';
		$response = $this->request('POST', $url, $this->headers(), (string)json_encode([
			'token'       => $token,
			'webhook_url' => $webhookUrl,
			'name'        => Plugin::NAME,
		]), 30);
		$data = $this->decode($response, 200);
		foreach (['public_key', 'secret_token', 'api', 'webhook_signing_secret'] as $key) {
			if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
				throw new ApiError($response->status, 'missing ' . $key . ': ' . $response->body);
			}
		}
		$onion = isset($data['onion_api']) && is_string($data['onion_api']) && $data['onion_api'] !== '' ? Url::resolve($url, $data['onion_api']) : '';

		return [
			'public_key'             => $data['public_key'],
			'secret_token'           => $data['secret_token'],
			'api'                    => Url::resolve($url, $data['api']),
			'onion_api'              => $onion,
			'webhook_signing_secret' => $data['webhook_signing_secret'],
		];
	}

	/**
	 * Makes an order with the entry's `create-order` form and the store's
	 * secret key. Returns its id; its `mk:checkout` page, where the customer
	 * pays; its `mk:onion-checkout`, the same page on Monokulo's onion
	 * address (`''` when Monokulo has none). The order's page for the
	 * merchant comes with each of its webhooks.
	 *
	 * @param array<string, mixed> $body see OrderRequest
	 *
	 * @return array{order_id: string, checkout_url: string, onion_checkout_url: string}
	 *
	 * @throws ApiError|TransportError
	 */
	public function createOrder(Connection $connection, array $body): array {
		[$response, $target] = $this->submit($connection, 'create-order', $body, 30);
		$order = $this->decode($response, 201);
		$link = static function (string $rel) use ($order, $target): string {
			$href = $order['_links'][$rel]['href'] ?? null;

			return is_string($href) && $href !== '' ? Url::resolve($target, $href) : '';
		};
		$checkout = $link('mk:checkout');
		if (!isset($order['order_id']) || !is_string($order['order_id']) || $order['order_id'] === '' || $checkout === '') {
			throw new ApiError($response->status, 'not an order with a checkout link: ' . $response->body);
		}

		return [
			'order_id'           => $order['order_id'],
			'checkout_url'       => $checkout,
			'onion_checkout_url' => $link('mk:onion-checkout'),
		];
	}

	/**
	 * Whether Monokulo has an onion address: the entry (kept, as for
	 * orders) links `mk:onion-api` only then. A kept entry without it is
	 * asked about again (with its ETag: unchanged, a `304`) before the
	 * answer is no, so a Monokulo that has just gained an onion address
	 * is never taken for one without it.
	 *
	 * @throws ApiError|TransportError
	 */
	public function hasOnionAddress(Connection $connection): bool {
		$onion = static function (Entry $entry): bool {
			$href = $entry->body['_links']['mk:onion-api']['href'] ?? null;

			return is_string($href) && $href !== '';
		};
		[$entry, $fresh] = $this->entry($connection, self::KEPT, 30);
		if (!$onion($entry) && !$fresh) {
			[$entry] = $this->entry($connection, self::REVALIDATE, 30);
		}

		return $onion($entry);
	}

	/**
	 * The entry's `send-test-webhook` form, with `{}`: Monokulo sends the
	 * webhook this shop registered when it connected a signed
	 * `webhook.test` now, and answers once the shop's receiver has. A `200`
	 * whatever the receiver said: `delivered` is whether it answered `2xx`;
	 * `status` is what it answered (null when nothing did), `error` why it
	 * wasn't delivered, and `next_attempt_at` when Monokulo tries it again
	 * (null once delivered, or when it won't). A problem (`rate-limited`,
	 * `not-found`) is an ApiError.
	 *
	 * @return array{webhook_id: string, event_id: string, delivered: bool, status: ?int, duration_ms: int, error: ?string, next_attempt_at: ?int}
	 *
	 * @throws ApiError|TransportError
	 */
	public function sendTestWebhook(Connection $connection): array {
		// Monokulo answers once the receiver has (within its delivery timeout, 5 s unless changed).
		[$response] = $this->submit($connection, 'send-test-webhook', new \stdClass(), 30);
		$answer = $this->decode($response, 200);
		$text = static fn(string $key): ?string => isset($answer[$key]) && is_string($answer[$key]) && $answer[$key] !== '' ? $answer[$key] : null;
		$int = static fn(string $key): ?int => isset($answer[$key]) && is_int($answer[$key]) ? $answer[$key] : null;
		if ($text('event_id') === null || !isset($answer['delivered']) || !is_bool($answer['delivered'])) {
			throw new ApiError($response->status, 'not a test webhook\'s outcome: ' . $response->body);
		}

		return [
			'webhook_id'      => $text('webhook_id') ?? '',
			'event_id'        => $text('event_id'),
			'delivered'       => $answer['delivered'],
			'status'          => $int('status'),
			'duration_ms'     => $int('duration_ms') ?? 0,
			'error'           => $text('error'),
			'next_attempt_at' => $int('next_attempt_at'),
		];
	}

	/**
	 * The entry's `send-logs` form: the shop's warnings and errors, for the
	 * merchant to read in Monokulo. Best effort: a failure here (the
	 * store's client logs are off, Monokulo is down) drops the reports and
	 * is returned as a sentence for the shop's own log; null when they
	 * were sent, or there was nothing to send.
	 *
	 * @param list<array{level: string, message: string, trace_id: string}> $entries
	 */
	public function forwardLogs(Connection $connection, array $entries): ?string {
		if (!$entries) {
			return null;
		}
		try {
			[$response] = $this->submit($connection, 'send-logs', ['entries' => $entries], 3);
			if ($response->status < 200 || $response->status >= 300) {
				$this->decode($response, 204);
			}
		} catch (ApiError $e) {
			return sprintf('Could not send %d error report(s) to Monokulo: %s. HTTP %d: %s', count($entries), $e->logsHint(), $e->status, $e->detail);
		} catch (TransportError $e) {
			return sprintf('Could not send %d error report(s) to Monokulo: could not reach it: %s', count($entries), $e->getMessage());
		}

		return null;
	}

	/**
	 * Sends `body` with the entry's form `name`; returns the answer and the
	 * URL it came from. A form missing from a kept entry, or an answer of
	 * `401`, `404` or `405` to one, has the entry read again and the form
	 * sent once more.
	 *
	 * @param array<string, mixed>|\stdClass $body (`{}` as an empty stdClass)
	 *
	 * @return array{0: HttpResponse, 1: string}
	 *
	 * @throws ApiError|TransportError
	 */
	private function submit(Connection $connection, string $name, array|\stdClass $body, int $timeoutSeconds): array {
		[$entry, $fresh] = $this->entry($connection, self::KEPT, $timeoutSeconds);
		while (true) {
			$form = $entry->form($name);
			if ($form === null) {
				if (!$fresh) {
					[$entry, $fresh] = $this->entry($connection, self::REREAD, $timeoutSeconds);

					continue;
				}

				throw new ApiError(200, 'the API entry has no ' . $name . ' form for this key: ' . (string)json_encode($entry->body));
			}
			$target = Url::resolve($connection->api, $form['target']);
			$response = $this->request($form['method'], $target, $this->headers($connection), (string)json_encode($body), $timeoutSeconds);
			if (!$fresh && in_array($response->status, self::STALE, true)) {
				[$entry, $fresh] = $this->entry($connection, self::REREAD, $timeoutSeconds);

				continue;
			}

			return [$response, $target];
		}
	}

	/**
	 * The API's entry for `connection`'s key, and whether it was read just
	 * now: the kept one while it's fresh (KEPT), else asked for again with
	 * the kept one's ETag so an unchanged entry is a `304` (KEPT once
	 * expired, or REVALIDATE), or read afresh (REREAD).
	 *
	 * @return array{0: Entry, 1: bool}
	 *
	 * @throws ApiError|TransportError
	 */
	private function entry(Connection $connection, int $how, int $timeoutSeconds): array {
		$now = $this->clock ? ($this->clock)() : time();
		$kept = $this->entries->entry($connection);
		if ($kept && $how === self::KEPT && $kept->expiresAt > $now) {
			return [$kept, false];
		}
		$headers = $this->headers($connection);
		if ($kept && $how !== self::REREAD && $kept->etag !== '') {
			$headers['If-None-Match'] = $kept->etag;
		}
		$response = $this->request('GET', $connection->api, $headers, null, $timeoutSeconds);
		$expires = $now + self::maxAge($response);
		if ($kept && $how !== self::REREAD && $response->status === 304) {
			$entry = new Entry($kept->body, $response->header('etag') ?? $kept->etag, $expires);
		} else {
			$entry = new Entry($this->decode($response, 200), $response->header('etag') ?? '', $expires);
		}
		$this->entries->keepEntry($connection, $entry);

		return [$entry, true];
	}

	/** How long an answer may be kept, from its `Cache-Control` (0: not at all). */
	private static function maxAge(HttpResponse $response): int {
		$cache = (string)$response->header('cache-control');
		if (preg_match('/\bno-store\b/i', $cache) || !preg_match('/\bmax-age=(\d+)/i', $cache, $m)) {
			return 0;
		}

		return (int)$m[1];
	}

	/**
	 * One request through PHP's curl extension (which OpenCart requires).
	 * Redirects aren't followed: the secret key goes only where it was sent.
	 *
	 * @param array<string, string> $headers
	 *
	 * @throws TransportError when no answer came back (refused, timed out, TLS)
	 */
	private function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse {
		if ($body === null) {
			unset($headers['Content-Type']);
		}
		$lines = [];
		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}
		$received = [];
		$curl = curl_init($url);
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
			CURLOPT_TIMEOUT        => $timeoutSeconds,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
				$colon = strpos($line, ':');
				if ($colon !== false) {
					$received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
				}

				return strlen($line);
			},
		];
		if ($body !== null) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($curl, $options);
		$answer = curl_exec($curl);
		if ($answer === false) {
			$error = curl_error($curl);

			throw new TransportError($error !== '' ? $error : 'no response');
		}

		return new HttpResponse((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$answer, $received);
	}

	/** @return array<string, string> */
	private function headers(?Connection $connection = null): array {
		$headers = [
			'Content-Type'    => 'application/json',
			'Accept'          => self::HAL . ', application/problem+json',
			'Monokulo-Client' => Plugin::client(),
			'traceparent'     => $this->trace->traceparent(),
		];
		if ($connection) {
			$headers['Authorization'] = 'Bearer ' . $connection->secretToken;
		}

		return $headers;
	}

	/**
	 * The JSON object an `expected` answer carries. Anything else is an
	 * ApiError: a problem (`application/problem+json`) by its `detail` (or
	 * `title`), the name in its `type` and how long to wait before trying
	 * again (`retry_after`, or the answer's `Retry-After` in seconds),
	 * anything else by its body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError
	 */
	private function decode(HttpResponse $response, int $expected): array {
		$data = json_decode($response->body, true);
		if ($response->status !== $expected) {
			$problem = is_array($data) && str_starts_with((string)$response->header('content-type'), 'application/problem+json') ? $data : [];
			$text = static fn(string $key): ?string => isset($problem[$key]) && is_string($problem[$key]) && $problem[$key] !== '' ? $problem[$key] : null;
			$type = $text('type');
			$name = $type !== null ? basename((string)parse_url($type, PHP_URL_PATH)) : '';

			$wait = $problem['retry_after'] ?? $response->header('retry-after');
			$wait = is_int($wait) || (is_string($wait) && ctype_digit($wait)) ? (int)$wait : null;

			throw new ApiError($response->status, $text('detail') ?? $text('title') ?? $response->body, $name, $wait);
		}
		if (!is_array($data)) {
			throw new ApiError($response->status, 'not JSON: ' . $response->body);
		}

		return $data;
	}
}
