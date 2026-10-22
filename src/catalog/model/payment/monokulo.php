<?php
namespace Opencart\Catalog\Model\Extension\Monokulo\Payment;

require_once __DIR__ . '/../../../system/library/monokulo/autoload.php';

use Monokulo\OpenCart\ConnectLink;
use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Decision;
use Monokulo\OpenCart\OrderUpdater;
use Monokulo\OpenCart\Repository;
use Monokulo\OpenCart\WebhookEvent;

/**
 * Monokulo for the storefront: whether Monero is offered at checkout, this
 * store's connection, and how a webhook event changes an order.
 *
 * Can be called from $this->load->model('extension/monokulo/payment/monokulo');
 */
class Monokulo extends \Opencart\System\Engine\Model {
	/**
	 * Monero is offered when the extension is on, this store's host is
	 * connected (by this plugin version's rules), the cart has no
	 * subscription (a Monero payment can't be taken again by itself), and the
	 * payment address is in the chosen geo zone.
	 *
	 * @param array<string, mixed> $address
	 *
	 * @return array<string, mixed>
	 */
	public function getMethods(array $address = []): array {
		$this->load->language('extension/monokulo/payment/monokulo');

		$connection = $this->connection();

		if (!$connection || $connection->needsReconnect()) {
			$status = false;
		} elseif ($this->cart->hasSubscription()) {
			$status = false;
		} elseif (!$this->config->get('config_checkout_payment_address') || !$this->config->get('payment_monokulo_geo_zone_id')) {
			$status = true;
		} else {
			$this->load->model('localisation/geo_zone');

			$status = (bool)$this->model_localisation_geo_zone->getGeoZone((int)$this->config->get('payment_monokulo_geo_zone_id'), (int)($address['country_id'] ?? 0), (int)($address['zone_id'] ?? 0));
		}

		if (!$status) {
			return [];
		}

		return [
			'code'       => 'monokulo',
			'name'       => $this->language->get('heading_title'),
			'option'     => [
				'monokulo' => [
					'code' => 'monokulo.monokulo',
					'name' => $this->language->get('heading_title')
				]
			],
			'sort_order' => $this->config->get('payment_monokulo_sort_order')
		];
	}

	public function repository(): Repository {
		return new Repository($this->db, DB_PREFIX);
	}

	/** This store's host, as monokulo knows the shop (`shop.example`). */
	public function host(): string {
		return ConnectLink::host((string)$this->config->get('config_url'));
	}

	/** This store's connection, or null before it has connected. */
	public function connection(): ?Connection {
		return $this->repository()->connection($this->host());
	}

	/** Turns Monero on at checkout, as connecting a store does. */
	public function enable(): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "setting` SET `value` = '1' WHERE `store_id` = '0' AND `code` = 'payment_monokulo' AND `key` = 'payment_monokulo_status'");
	}

	/**
	 * Applies webhook decisions to OpenCart orders through the order history,
	 * so OpenCart does what it does on any status change: stock, emails,
	 * coupons, the customer's account page.
	 */
	public function updater(): OrderUpdater {
		return new class($this->registry) implements OrderUpdater {
			public function __construct(private readonly \Opencart\System\Engine\Registry $registry) {
			}

			public function apply(int $orderId, Decision $decision, WebhookEvent $event, bool $latest, Connection $connection): void {
				$load = $this->registry->get('load');
				$config = $this->registry->get('config');
				$language = $this->registry->get('language');

				$load->language('extension/monokulo/payment/monokulo');
				$load->model('checkout/order');
				$orders = $this->registry->get('model_checkout_order');

				$order = $orders->getOrder($orderId);

				if (!$order) {
					throw new \RuntimeException('order ' . $orderId . ' no longer exists');
				}

				$current = (int)$order['order_status_id'];
				$link = $event->storeId ? sprintf($language->get('text_see_in_monokulo'), $connection->dashboardOrderUrl($event->storeId, $event->orderId)) : '';

				// Paid, but not the order's newest payment request: the total changed
				// since. Tell the merchant instead of marking it paid.
				if ($decision->paid && !$latest) {
					$orders->addHistory($orderId, $current, $language->get('note_superseded') . $link);

					return;
				}

				$note = $language->get($decision->note);

				if (str_contains($note, '%s')) {
					$note = sprintf($note, $event->txid ?? $language->get('note_unknown_transaction'));
				}

				if ($decision->status === null) {
					$orders->addHistory($orderId, $current, $note . $link);

					return;
				}

				$status = (int)$config->get('payment_monokulo_' . $decision->status . '_status_id');

				if (!$status) {
					throw new \RuntimeException('no order status is set for "' . $decision->status . '"');
				}

				if ($decision->notify) {
					$orders->addHistory($orderId, $status, $language->get('note_paid_customer'), true);
				}

				$orders->addHistory($orderId, $status, $note . $link);
			}
		};
	}
}
