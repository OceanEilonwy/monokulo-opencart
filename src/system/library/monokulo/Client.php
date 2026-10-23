<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * make an order through the API (`/api/v1`, HAL-FORMS), and (when the
 * merchant turned it on) forward its warnings and errors.
 *
 * Only the API's entry is stored; the order form, the order and its
 * checkout page are links and forms from there, resolved against the
 * entry's address.
 */
final class Client {
	private const HAL = 'application/prs.hal-forms+json';

	public function __construct(
		private readonly Trace $trace,
	) {
	}

	/**
	 * `POST {address}/plugins/connect/opencart/finish`: redeems the single-use token
	 * the connect flow handed back, registers `webhookUrl`, and returns the
	 * store's credentials, with `api` (the API's entry) resolved against
	 * `address`.
	 *
	 * @return array{public_key: string, secret_token: string, api: string, webhook_signing_secret: string}
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

		return [
			'public_key'             => $data['public_key'],
			'secret_token'           => $data['secret_token'],
			'api'                    => Url::resolve($url, $data['api']),
			'webhook_signing_secret' => $data['webhook_signing_secret'],
		];
	}

	/**
	 * Makes an order with the entry's `create-order` form and the store's
	 * secret key. Returns its id and its `mk:checkout` page, where the
	 * customer pays.
	 *
	 * @param array<string, mixed> $body see OrderRequest
	 *
	 * @return array{order_id: string, checkout_url: string}
	 *
	 * @throws ApiError|TransportError
	 */
	public function createOrder(Connection $connection, array $body): array {
		$headers = $this->headers() + ['Authorization' => 'Bearer ' . $connection->secretToken];
		$entry = $this->request('GET', $connection->api, $headers, null, 30);
		$form = $this->decode($entry, 200)['_templates']['create-order'] ?? null;
		if (!is_array($form) || !isset($form['target']) || !is_string($form['target'])) {
			throw new ApiError($entry->status, 'the API entry has no create-order form for this key: ' . $entry->body);
		}
		$method = isset($form['method']) && is_string($form['method']) ? strtoupper($form['method']) : 'POST';
		$target = Url::resolve($connection->api, $form['target']);

		$response = $this->request($method, $target, $headers, (string)json_encode($body), 30);
		$order = $this->decode($response, 201);
		$checkout = $order['_links']['mk:checkout']['href'] ?? null;
		if (!isset($order['order_id']) || !is_string($order['order_id']) || $order['order_id'] === '' || !is_string($checkout) || $checkout === '') {
			throw new ApiError($response->status, 'not an order with a checkout link: ' . $response->body);
		}

		return ['order_id' => $order['order_id'], 'checkout_url' => Url::resolve($target, $checkout)];
	}

	/**
	 * `POST {api}/logs`: the shop's warnings and errors, for the merchant to
	 * read in Monokulo. Best effort: a failure here (the store's client
	 * logs are off, Monokulo is down) is ignored.
	 *
	 * @param list<array{level: string, message: string, trace_id: string}> $entries
	 */
	public function forwardLogs(Connection $connection, array $entries): void {
		if (!$entries) {
			return;
		}
		$headers = $this->headers() + ['Authorization' => 'Bearer ' . $connection->secretToken];
		try {
			$this->request('POST', $connection->logsUrl(), $headers, (string)json_encode(['entries' => $entries]), 3);
		} catch (TransportError) {
			// Nothing to do: these are reports about other failures.
		}
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
		$curl = curl_init($url);
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
			CURLOPT_TIMEOUT        => $timeoutSeconds,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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

		return new HttpResponse((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$answer);
	}

	/** @return array<string, string> */
	private function headers(): array {
		return [
			'Content-Type'    => 'application/json',
			'Accept'          => self::HAL . ', application/json, application/problem+json',
			'Monokulo-Client' => Plugin::client(),
			'traceparent'     => $this->trace->traceparent(),
		];
	}

	/**
	 * The JSON object an `expected` answer carries. Anything else is an
	 * ApiError: a problem (`application/problem+json`) by its `detail` (or
	 * `title`) and the name in its `type`, the connect flow's
	 * `{"error": …}` by that, and anything else by its body.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError
	 */
	private function decode(HttpResponse $response, int $expected): array {
		$data = json_decode($response->body, true);
		if ($response->status !== $expected) {
			$text = static fn(string $key): ?string => is_array($data) && isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;
			$type = $text('type');
			$problem = $type !== null ? basename((string)parse_url($type, PHP_URL_PATH)) : '';

			throw new ApiError($response->status, $text('detail') ?? $text('title') ?? $text('error') ?? $response->body, $problem);
		}
		if (!is_array($data)) {
			throw new ApiError($response->status, 'not JSON: ' . $response->body);
		}

		return $data;
	}
}
