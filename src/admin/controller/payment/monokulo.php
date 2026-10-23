<?php
namespace Opencart\Admin\Controller\Extension\Monokulo\Payment;

require_once __DIR__ . '/../../../system/library/monokulo/autoload.php';

use Monokulo\OpenCart\ConnectLink;
use Monokulo\OpenCart\Repository;

/**
 * Monokulo's settings page: the Monokulo address, which order status each
 * step of a Monero payment moves an order to, and each store's connection.
 */
class Monokulo extends \Opencart\System\Engine\Controller {
	/** The order statuses the merchant chooses, with OpenCart's default ids. */
	private const STATUSES = [
		'pending' => 1, // Pending
		'waiting' => 1, // Pending
		'partial' => 1, // Pending
		'paid'    => 2, // Processing
		'expired' => 14 // Expired
	];

	private const CONNECT_ERRORS = ['permission', 'address', 'host', 'finish', 'unavailable', 'unreachable'];

	public function index(): void {
		$this->load->language('extension/monokulo/payment/monokulo');

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/monokulo/payment/monokulo', $token)]
		];

		$data['save'] = $this->url->link('extension/monokulo/payment/monokulo.save', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

		$data['payment_monokulo_address'] = (string)$this->config->get('payment_monokulo_address');
		$data['payment_monokulo_geo_zone_id'] = (int)$this->config->get('payment_monokulo_geo_zone_id');
		$data['payment_monokulo_forward_errors'] = (bool)$this->config->get('payment_monokulo_forward_errors');
		$data['payment_monokulo_status'] = (bool)$this->config->get('payment_monokulo_status');
		$data['payment_monokulo_sort_order'] = $this->config->get('payment_monokulo_sort_order');

		$data['statuses'] = [];

		foreach (self::STATUSES as $key => $default) {
			$value = $this->config->get('payment_monokulo_' . $key . '_status_id');

			$data['statuses'][] = [
				'key'   => $key,
				'name'  => 'payment_monokulo_' . $key . '_status_id',
				'entry' => $this->language->get('entry_' . $key . '_status'),
				'help'  => $this->language->get('help_' . $key . '_status'),
				'value' => $value !== null && $value !== '' ? (int)$value : $default
			];
		}

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$repository = new Repository($this->db, DB_PREFIX);
		$data['stores'] = [];

		foreach ($this->stores() as $store) {
			$host = ConnectLink::host($store['url']);
			$connection = $repository->connection($host);

			$data['stores'][] = [
				'name'      => $store['name'],
				'url'       => $store['url'],
				'host'      => $host,
				'connected' => $connection ? sprintf($this->language->get('text_connected_as'), $connection->publicKey, date($this->language->get('datetime_format'), $connection->connectedAt)) : '',
				'connect'   => $this->url->link('extension/monokulo/payment/monokulo.connect', $token . '&store_id=' . $store['store_id'])
			];
		}

		$data['can_connect'] = $data['payment_monokulo_address'] !== '';

		$data['success'] = isset($this->request->get['monokulo_connected']) ? $this->language->get('text_connected') : '';
		$error = (string)($this->request->get['monokulo_connect_error'] ?? '');
		$data['error'] = in_array($error, self::CONNECT_ERRORS, true) ? $this->language->get('error_connect_' . $error) : '';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/monokulo/payment/monokulo', $data));
	}

	public function save(): void {
		$this->load->language('extension/monokulo/payment/monokulo');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/monokulo/payment/monokulo')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;
		$address = ConnectLink::address(html_entity_decode((string)($post['payment_monokulo_address'] ?? ''), ENT_QUOTES, 'UTF-8'));

		if ($address === null) {
			$json['error']['address'] = $this->language->get('error_address');
		}

		foreach (array_keys(self::STATUSES) as $key) {
			if (empty($post['payment_monokulo_' . $key . '_status_id'])) {
				$json['error'][$key . '-status'] = $this->language->get('error_status');
			}
		}

		if (!$json) {
			$settings = [
				'payment_monokulo_address'       => $address,
				'payment_monokulo_geo_zone_id'   => (int)($post['payment_monokulo_geo_zone_id'] ?? 0),
				'payment_monokulo_forward_errors' => !empty($post['payment_monokulo_forward_errors']) ? '1' : '0',
				'payment_monokulo_status'        => !empty($post['payment_monokulo_status']) ? '1' : '0',
				'payment_monokulo_sort_order'    => (int)($post['payment_monokulo_sort_order'] ?? 0)
			];

			foreach (array_keys(self::STATUSES) as $key) {
				$settings['payment_monokulo_' . $key . '_status_id'] = (int)$post['payment_monokulo_' . $key . '_status_id'];
			}

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_monokulo', $settings);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Starts connecting one store: remembers a single-use nonce for it, then
	 * sends the browser to Monokulo to sign in and pick the store. Monokulo
	 * comes back to the store's own address (it only returns to the shop's
	 * host), whose catalog side finishes connecting and sends the browser
	 * back here.
	 */
	public function connect(): void {
		$this->load->language('extension/monokulo/payment/monokulo');

		$token = 'user_token=' . $this->session->data['user_token'];
		$back = $this->url->link('extension/monokulo/payment/monokulo', $token, true);

		if (!$this->user->hasPermission('modify', 'extension/monokulo/payment/monokulo')) {
			$this->redirect($back . '&monokulo_connect_error=permission');

			return;
		}

		$address = (string)$this->config->get('payment_monokulo_address');
		$store_id = (int)($this->request->get['store_id'] ?? -1);
		$store = null;

		foreach ($this->stores() as $candidate) {
			if ((int)$candidate['store_id'] === $store_id) {
				$store = $candidate;
			}
		}

		if ($address === '' || !$store) {
			$this->redirect($back . '&monokulo_connect_error=address');

			return;
		}

		$nonce = (new Repository($this->db, DB_PREFIX))->createNonce($store_id, ConnectLink::host($store['url']), $back, time());
		$return_url = $store['url'] . 'index.php?route=extension/monokulo/payment/monokulo.connect';

		$this->redirect(ConnectLink::start($address, $store['url'], $return_url, $nonce));
	}

	/**
	 * Creates the extension's tables and the default settings. There is no
	 * uninstall step: OpenCart removes the settings itself, and the tables
	 * stay, since orders refer to them and installing again picks the
	 * connections back up.
	 */
	public function install(): void {
		(new Repository($this->db, DB_PREFIX))->install();

		$settings = [
			'payment_monokulo_address'        => 'https://monokulo.com',
			'payment_monokulo_geo_zone_id'    => 0,
			'payment_monokulo_forward_errors' => '0',
			'payment_monokulo_status'         => '0',
			'payment_monokulo_sort_order'     => 0
		];

		foreach (self::STATUSES as $key => $default) {
			$settings['payment_monokulo_' . $key . '_status_id'] = $default;
		}

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('payment_monokulo', $settings);
	}

	/**
	 * Every store with its address: the default store, then the others.
	 *
	 * @return list<array{store_id: int, name: string, url: string}>
	 */
	private function stores(): array {
		$stores = [[
			'store_id' => 0,
			'name'     => (string)$this->config->get('config_name'),
			'url'      => HTTP_CATALOG
		]];

		$this->load->model('setting/store');

		foreach ($this->model_setting_store->getStores() as $store) {
			$stores[] = ['store_id' => (int)$store['store_id'], 'name' => $store['name'], 'url' => $store['url']];
		}

		return $stores;
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
}
