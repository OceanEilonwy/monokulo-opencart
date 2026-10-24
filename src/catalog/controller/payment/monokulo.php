<?php
namespace Opencart\Catalog\Controller\Extension\Monokulo\Payment;

require_once __DIR__ . '/../../../system/library/monokulo/autoload.php';

use Monokulo\OpenCart\ApiError;
use Monokulo\OpenCart\Client;
use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Logger;
use Monokulo\OpenCart\OrderRequest;
use Monokulo\OpenCart\Trace;
use Monokulo\OpenCart\TransportError;
use Monokulo\OpenCart\Url;
use Monokulo\OpenCart\WebhookReceiver;

/**
 * Monokulo on the storefront:
 *
 * - index: the "Pay with Monero" button on the checkout's confirm step;
 * - confirm: creates the Monokulo order and sends the customer to pay it;
 * - callback: Monokulo's webhooks;
 * - connect: where the connect flow comes back with its token.
 */
class Monokulo extends \Opencart\System\Engine\Controller {
	private ?Trace $trace = null;
	private ?Logger $logger = null;

	public function index(): string {
		$this->load->language('extension/monokulo/payment/monokulo');

		$data['language'] = $this->config->get('config_language');

		return $this->load->view('extension/monokulo/payment/monokulo', $data);
	}

	/**
	 * Creates the Monokulo order for the session's OpenCart order, marks the
	 * order placed (the merchant's "order placed" status, Pending by default,
	 * with the payment link in its history and confirmation email), empties
	 * the cart as OpenCart's own success page does, and answers with the
	 * Monokulo checkout to send the customer to.
	 *
	 * A customer on the shop's onion address stays on Tor: they are sent to
	 * the order's checkout on Monokulo's onion address, never its web one.
	 * When Monokulo has no onion address (its entry has no `mk:onion-api`)
	 * they are told Monero can't be paid over Tor here before any Monokulo
	 * order is made, and the order is left as it was.
	 */
	public function confirm(): void {
		$this->load->language('extension/monokulo/payment/monokulo');
		$this->load->model('checkout/order');
		$this->load->model('extension/monokulo/payment/monokulo');

		$json = [];
		$order_info = [];

		if (isset($this->session->data['order_id'])) {
			$order_info = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);
		}

		if (!$order_info) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']['code']) || $this->session->data['payment_method']['code'] != 'monokulo.monokulo') {
			$json['error'] = $this->language->get('error_payment_method');
		}

		$connection = $this->model_extension_monokulo_payment_monokulo->connection();

		if (!$json && !$connection) {
			$json['error'] = $this->language->get('error_not_connected');
		}

		if (!$json) {
			$order_id = (int)$order_info['order_id'];
			$names = [];

			foreach ($this->model_checkout_order->getProducts($order_id) as $product) {
				$names[] = html_entity_decode($product['name'], ENT_QUOTES, 'UTF-8');
			}

			$currency = (string)$order_info['currency_code'];
			$total = (float)$this->currency->format((float)$order_info['total'], $currency, (float)$order_info['currency_value'], false);
			$body = OrderRequest::body((int)$order_info['store_id'], $order_id, $total, $currency, $this->currency->getDecimalPlace($currency), $names, sprintf($this->language->get('text_order_description'), $order_id), $this->language->get('text_more'));

			$on_tor = Url::isOnion((string)($this->request->server['HTTP_HOST'] ?? ''));

			try {
				// The entry says up front whether Monokulo has an onion address: without one,
				// a Tor customer has nowhere to pay, so no order is made for them.
				if ($on_tor && !$this->client()->hasOnionAddress($connection)) {
					$this->log()->error(sprintf('Order %d: the customer is on the shop\'s onion address, but your Monokulo has no onion address (its API entry has no mk:onion-api), so no Monokulo order was made and they were not sent to pay on the web.', $order_id));
					$json['error'] = $this->language->get('error_no_onion');
				} else {
					$monokulo_order = $this->client()->createOrder($connection, $body);
				}
			} catch (ApiError $e) {
				$this->log()->error(sprintf('Creating the Monokulo order for order %d failed: HTTP %d: %s%s', $order_id, $e->status, $e->detail, $e->merchantHint()));
				$json['error'] = $this->language->get($e->customerMessageKey());
			} catch (TransportError $e) {
				$this->log()->error(sprintf('Creating the Monokulo order for order %d failed: could not reach %s: %s', $order_id, $connection->api, $e->getMessage()));
				$json['error'] = $this->language->get('error_unreachable');
			}
		}

		if (!$json) {
			$checkout = $on_tor ? $monokulo_order['onion_checkout_url'] : $monokulo_order['checkout_url'];

			if ($checkout === '') {
				$this->log()->error(sprintf('Order %d: the customer is on the shop\'s onion address, but Monokulo order %s has no onion checkout (mk:onion-checkout), so they were not sent to pay on the web.', $order_id, $monokulo_order['order_id']));
				$json['error'] = $this->language->get('error_no_onion');
			}
		}

		if (!$json) {
			$this->model_extension_monokulo_payment_monokulo->repository()->recordOrder($monokulo_order['order_id'], $order_id, (int)$order_info['store_id'], $connection->host, $body['amount'], $body['currency'], time());
			$this->model_checkout_order->addHistory($order_id, (int)$this->config->get('payment_monokulo_pending_status_id'), sprintf($this->language->get('note_started'), $checkout), true);
			$this->log()->info(sprintf('Order %d: customer sent to Monokulo order %s.', $order_id, $monokulo_order['order_id']));

			$this->cart->clear();

			unset($this->session->data['order_id']);
			unset($this->session->data['payment_method']);
			unset($this->session->data['payment_methods']);
			unset($this->session->data['shipping_method']);
			unset($this->session->data['shipping_methods']);
			unset($this->session->data['comment']);
			unset($this->session->data['agree']);
			unset($this->session->data['coupon']);
			unset($this->session->data['reward']);

			$json['redirect'] = $checkout;
		}

		$this->forward($connection);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Monokulo's webhooks for this store's host, registered when it
	 * connected. See WebhookReceiver for what each answer means.
	 */
	public function callback(): void {
		$this->load->model('extension/monokulo/payment/monokulo');

		$this->trace()->adopt((string)($this->request->server['HTTP_TRACEPARENT'] ?? ''));

		$model = $this->model_extension_monokulo_payment_monokulo;
		$connection = $model->connection();
		$receiver = new WebhookReceiver($model->repository(), $model->updater(), $this->log());

		$status = $receiver->handle($connection, (string)file_get_contents('php://input'), (string)($this->request->server['HTTP_X_MONOKULO_SIGNATURE'] ?? ''), time());

		$this->forward($connection);

		http_response_code($status);
		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
		$this->response->setOutput((string)$status);
	}

	/**
	 * The end of the connect flow. Monokulo sends the admin's browser back
	 * here, on the store's own host, with the single-use `token` and the
	 * `nonce` the admin page made. The nonce is used up whatever happens;
	 * when it was this store's and still fresh, the token is redeemed from
	 * the server for the store's credentials, and the browser goes back to
	 * the admin page saying how it went.
	 */
	public function connect(): void {
		$this->load->language('extension/monokulo/payment/monokulo');
		$this->load->model('extension/monokulo/payment/monokulo');

		$model = $this->model_extension_monokulo_payment_monokulo;
		$token = (string)($this->request->get['token'] ?? '');
		$attempt = $model->repository()->consumeNonce((string)($this->request->get['nonce'] ?? ''), time());

		if (!$attempt || $token === '') {
			$this->log()->warning('Connect return refused: no token, or a nonce this shop did not make, already used or expired.');

			http_response_code(400);
			$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
			$this->response->setOutput($this->language->get('text_connect_failed'));

			return;
		}

		$host = $model->host();

		if ($attempt['host'] !== $host) {
			$this->log()->warning(sprintf('Connect return refused: started for %s but came back to %s.', $attempt['host'], $host));
			$result = 'monokulo_connect_error=host';
		} else {
			$result = $this->finishConnect($host);
		}

		$this->redirect($attempt['admin_return'] . (str_contains($attempt['admin_return'], '?') ? '&' : '?') . $result);
	}

	/** Redeems the connect token; the query string telling the admin page how it went. */
	private function finishConnect(string $host): string {
		$model = $this->model_extension_monokulo_payment_monokulo;
		$address = (string)$this->config->get('payment_monokulo_address');
		$webhook_url = $this->url->link('extension/monokulo/payment/monokulo.callback', '', true);

		try {
			$credentials = $this->client()->finishConnect($address, (string)$this->request->get['token'], $webhook_url);
		} catch (ApiError $e) {
			$this->log()->error(sprintf('Connect finish at %s failed: HTTP %d: %s%s', $address, $e->status, $e->detail, $e->connectHint()));

			return 'monokulo_connect_error=' . match ($e->problem) {
				'no-public-address'    => 'unavailable',
				'rate-limited'         => 'busy',
				'invalid-connect-code' => 'expired',
				default             => 'finish',
			};
		} catch (TransportError $e) {
			$this->log()->error(sprintf('Connect finish at %s failed: could not reach it: %s', $address, $e->getMessage()));

			return 'monokulo_connect_error=unreachable';
		}

		$model->repository()->saveConnection(new Connection($host, $credentials['api'], $credentials['public_key'], $credentials['secret_token'], $credentials['webhook_signing_secret'], time(), $credentials['onion_api']));
		$model->enable();
		$this->log()->info(sprintf('%s connected to Monokulo as %s%s.', $host, $credentials['public_key'], $credentials['onion_api'] !== '' ? ', on Tor at ' . $credentials['onion_api'] : ''));

		return 'monokulo_connected=1';
	}

	private function client(): Client {
		$this->load->model('extension/monokulo/payment/monokulo');

		return new Client($this->trace(), $this->model_extension_monokulo_payment_monokulo->repository());
	}

	private function trace(): Trace {
		return $this->trace ??= new Trace();
	}

	private function log(): Logger {
		if (!$this->logger) {
			$file = new \Opencart\System\Library\Log('monokulo.log');
			$this->logger = new Logger(static function (string $line) use ($file): void {
				$file->write($line);
			}, $this->trace());
		}

		return $this->logger;
	}

	/**
	 * Sends the browser to `url`. OpenCart sends a response's headers only
	 * with a body (and its own redirect() exits), so the redirect carries a
	 * link too.
	 */
	private function redirect(string $url): void {
		$this->response->addHeader('Location: ' . $url);
		$this->response->setOutput('<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>');
	}

	/** Sends this request's warnings and errors to Monokulo, when the merchant turned that on. */
	private function forward(?Connection $connection): void {
		if ($this->logger && $connection && $this->config->get('payment_monokulo_forward_errors')) {
			$failed = $this->client()->forwardLogs($connection, $this->logger->forwardable());
			if ($failed !== null) {
				// Only to the shop's log: forward() runs once per request, so this isn't sent on.
				$this->logger->warning($failed);
			}
		}
	}
}
