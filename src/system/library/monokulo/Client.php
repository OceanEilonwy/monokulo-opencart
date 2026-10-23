<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * make an order through the API (`/api/v1`, HAL-FORMS), and (when the
 * merchant turned it on) forward its warnings and errors.
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
	 * address (`''` when Monokulo has none); and its `mk:dashboard-order`,
	 * the order's page for the merchant (`''` if Monokulo gave none).
	 *
	 * @param array<string, mixed> $body see OrderRequest
	 *
	 * @return array{order_id: string, checkout_url: string, onion_checkout_url: string, dashboard_url: string}
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
			'dashboard_url'      => $link('mk:dashboard-order'),
		];
	}

	/**
	 * Whether Monokulo has an onion address: the entry (kept, as for
	 * orders) links `mk:onion-api` only then.
	 *
	 * @throws ApiError|TransportError
	 */
	public function hasOnionAddress(Connection $connection): bool {
		[$entry] = $this->entry($connection, false, 30);
		$href = $entry->body['_links']['mk:onion-api']['href'] ?? null;

		return is_string($href) && $href !== '';
	}

	/**
	 * The entry's `send-logs` form: the shop's warnings and errors, for the
	 * merchant to read in Monokulo. Best effort: a failure here (the
	 * store's client logs are off, Monokulo is down) is ignored.
	 *
	 * @param list<array{level: string, message: string, trace_id: string}> $entries
	 */
	public function forwardLogs(Connection $connection, array $entries): void {
		if (!$entries) {
			return;
		}
		try {
			$this->submit($connection, 'send-logs', ['entries' => $entries], 3);
		} catch (ApiError|TransportError) {
			// Nothing to do: these are reports about other failures.
		}
	}

	/**
	 * Sends `body` with the entry's form `name`; returns the answer and the
	 * URL it came from. A form missing from a kept entry, or an answer of
	 * `401`, `404` or `405` to one, has the entry read again and the form
	 * sent once more.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array{0: HttpResponse, 1: string}
	 *
	 * @throws ApiError|TransportError
	 */
	private function submit(Connection $connection, string $name, array $body, int $timeoutSeconds): array {
		[$entry, $fresh] = $this->entry($connection, false, $timeoutSeconds);
		while (true) {
			$form = $entry->form($name);
			if ($form === null) {
				if (!$fresh) {
					[$entry, $fresh] = $this->entry($connection, true, $timeoutSeconds);

					continue;
				}

				throw new ApiError(200, 'the API entry has no ' . $name . ' form for this key: ' . (string)json_encode($entry->body));
			}
			$target = Url::resolve($connection->api, $form['target']);
			$response = $this->request($form['method'], $target, $this->headers($connection), (string)json_encode($body), $timeoutSeconds);
			if (!$fresh && in_array($response->status, self::STALE, true)) {
				[$entry, $fresh] = $this->entry($connection, true, $timeoutSeconds);

				continue;
			}

			return [$response, $target];
		}
	}

	/**
	 * The API's entry for `connection`'s key, and whether it was read just
	 * now: the kept one while it's fresh (unless `reread`), else read again,
	 * with the kept one's ETag so an unchanged entry is a `304`.
	 *
	 * @return array{0: Entry, 1: bool}
	 *
	 * @throws ApiError|TransportError
	 */
	private function entry(Connection $connection, bool $reread, int $timeoutSeconds): array {
		$now = $this->clock ? ($this->clock)() : time();
		$kept = $this->entries->entry($connection);
		if ($kept && !$reread && $kept->expiresAt > $now) {
			return [$kept, false];
		}
		$headers = $this->headers($connection);
		if ($kept && !$reread && $kept->etag !== '') {
			$headers['If-None-Match'] = $kept->etag;
		}
		$response = $this->request('GET', $connection->api, $headers, null, $timeoutSeconds);
		$expires = $now + self::maxAge($response);
		if ($kept && !$reread && $response->status === 304) {
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
	 * `title`) and the name in its `type`, anything else by its body.
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

			throw new ApiError($response->status, $text('detail') ?? $text('title') ?? $response->body, $name);
		}
		if (!is_array($data)) {
			throw new ApiError($response->status, 'not JSON: ' . $response->body);
		}

		return $data;
	}
}
