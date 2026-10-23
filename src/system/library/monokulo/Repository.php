<?php
namespace Monokulo\OpenCart;

/**
 * The extension's own tables, through OpenCart's database object (anything
 * with OpenCart's `query`, `escape` and `countAffected`).
 *
 * - `monokulo_connection`: one row per shop host that has connected.
 * - `monokulo_order`: each Monokulo order the shop created, and which
 *   OpenCart order it pays. An OpenCart order can have more than one when
 *   its total changed after a first attempt; the newest is the live one.
 * - `monokulo_event`: webhook events already applied, so a delivery that is
 *   sent again (its answer was lost) changes nothing the second time.
 * - `monokulo_connect_nonce`: connect attempts the admin started, each
 *   good once and for ten minutes.
 *
 * Uninstalling the extension keeps these tables: orders refer to them, and
 * installing again picks the connections back up.
 */
final class Repository {
	public const NONCE_TTL_SECONDS = 600;

	public function __construct(
		private readonly object $db,
		private readonly string $prefix,
	) {
	}

	/** @return list<string> CREATE TABLE statements, safe to run again. */
	private function schema(): array {
		$p = $this->prefix;

		return [
			"CREATE TABLE IF NOT EXISTS `{$p}monokulo_connection` (
				`host` VARCHAR(255) NOT NULL,
				`endpoint` VARCHAR(255) NOT NULL,
				`public_key` VARCHAR(128) NOT NULL,
				`secret_token` VARCHAR(255) NOT NULL,
				`webhook_secret` VARCHAR(255) NOT NULL,
				`connected_at` INT(11) NOT NULL,
				PRIMARY KEY (`host`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
			"CREATE TABLE IF NOT EXISTS `{$p}monokulo_order` (
				`seq` INT(11) NOT NULL AUTO_INCREMENT,
				`monokulo_order_id` VARCHAR(64) NOT NULL,
				`order_id` INT(11) NOT NULL,
				`store_id` INT(11) NOT NULL,
				`host` VARCHAR(255) NOT NULL,
				`amount` VARCHAR(40) NOT NULL,
				`currency` VARCHAR(8) NOT NULL,
				`created_at` INT(11) NOT NULL,
				PRIMARY KEY (`monokulo_order_id`),
				UNIQUE KEY `seq` (`seq`),
				KEY `order_id` (`order_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
			"CREATE TABLE IF NOT EXISTS `{$p}monokulo_event` (
				`event_id` VARCHAR(64) NOT NULL,
				`monokulo_order_id` VARCHAR(64) NOT NULL,
				`applied_at` INT(11) NOT NULL,
				PRIMARY KEY (`event_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
			"CREATE TABLE IF NOT EXISTS `{$p}monokulo_connect_nonce` (
				`nonce` CHAR(32) NOT NULL,
				`store_id` INT(11) NOT NULL,
				`host` VARCHAR(255) NOT NULL,
				`admin_return` TEXT NOT NULL,
				`expires_at` INT(11) NOT NULL,
				PRIMARY KEY (`nonce`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
		];
	}

	public function install(): void {
		foreach ($this->schema() as $statement) {
			$this->db->query($statement);
		}
	}

	public function connection(string $host): ?Connection {
		$row = $this->db->query("SELECT * FROM `{$this->prefix}monokulo_connection` WHERE `host` = '" . $this->db->escape($host) . "'")->row;
		if (!$row) {
			return null;
		}

		return new Connection(
			$row['host'],
			$row['endpoint'],
			$row['public_key'],
			$row['secret_token'],
			$row['webhook_secret'],
			(int)$row['connected_at'],
		);
	}

	public function saveConnection(Connection $c): void {
		$e = fn(string $v): string => "'" . $this->db->escape($v) . "'";
		$this->db->query(
			"REPLACE INTO `{$this->prefix}monokulo_connection` (`host`, `endpoint`, `public_key`, `secret_token`, `webhook_secret`, `connected_at`) VALUES ("
			. implode(', ', [$e($c->host), $e($c->endpoint), $e($c->publicKey), $e($c->secretToken), $e($c->webhookSecret), (int)$c->connectedAt])
			. ')'
		);
	}

	/** Remembers a connect attempt the admin started; returns its nonce. */
	public function createNonce(int $storeId, string $host, string $adminReturn, int $now): string {
		$nonce = bin2hex(random_bytes(16));
		$this->db->query("DELETE FROM `{$this->prefix}monokulo_connect_nonce` WHERE `expires_at` < " . $now);
		$this->db->query(
			"INSERT INTO `{$this->prefix}monokulo_connect_nonce` (`nonce`, `store_id`, `host`, `admin_return`, `expires_at`) VALUES ('"
			. $nonce . "', " . $storeId . ", '" . $this->db->escape($host) . "', '" . $this->db->escape($adminReturn) . "', " . ($now + self::NONCE_TTL_SECONDS) . ')'
		);

		return $nonce;
	}

	/**
	 * Uses up a nonce: its attempt (`store_id`, `host`, `admin_return`) the
	 * first time it is presented within ten minutes, null ever after.
	 *
	 * @return array{store_id: int, host: string, admin_return: string}|null
	 */
	public function consumeNonce(string $nonce, int $now): ?array {
		if (!preg_match('/\A[0-9a-f]{32}\z/', $nonce)) {
			return null;
		}
		$row = $this->db->query("SELECT * FROM `{$this->prefix}monokulo_connect_nonce` WHERE `nonce` = '" . $nonce . "'")->row;
		$this->db->query("DELETE FROM `{$this->prefix}monokulo_connect_nonce` WHERE `nonce` = '" . $nonce . "'");
		// Only the request whose DELETE removed the row may use it.
		if (!$row || $this->db->countAffected() !== 1 || (int)$row['expires_at'] < $now) {
			return null;
		}

		return ['store_id' => (int)$row['store_id'], 'host' => $row['host'], 'admin_return' => $row['admin_return']];
	}

	public function recordOrder(string $monokuloOrderId, int $orderId, int $storeId, string $host, string $amount, string $currency, int $now): void {
		$this->db->query(
			"INSERT IGNORE INTO `{$this->prefix}monokulo_order` (`monokulo_order_id`, `order_id`, `store_id`, `host`, `amount`, `currency`, `created_at`) VALUES ('"
			. $this->db->escape($monokuloOrderId) . "', " . $orderId . ', ' . $storeId . ", '" . $this->db->escape($host) . "', '"
			. $this->db->escape($amount) . "', '" . $this->db->escape($currency) . "', " . $now . ')'
		);
	}

	/**
	 * The OpenCart order a Monokulo order pays, and whether it is that
	 * order's newest Monokulo order.
	 *
	 * @return array{order_id: int, store_id: int, host: string, latest: bool}|null
	 */
	public function findOrder(string $monokuloOrderId): ?array {
		$row = $this->db->query("SELECT * FROM `{$this->prefix}monokulo_order` WHERE `monokulo_order_id` = '" . $this->db->escape($monokuloOrderId) . "'")->row;
		if (!$row) {
			return null;
		}
		$newest = $this->db->query(
			"SELECT `monokulo_order_id` FROM `{$this->prefix}monokulo_order` WHERE `order_id` = " . (int)$row['order_id']
			. ' ORDER BY `seq` DESC LIMIT 1'
		)->row;

		return [
			'order_id' => (int)$row['order_id'],
			'store_id' => (int)$row['store_id'],
			'host'     => $row['host'],
			'latest'   => $newest && $newest['monokulo_order_id'] === $row['monokulo_order_id'],
		];
	}

	/**
	 * Claims a webhook event: true the first time `eventId` is seen, false for
	 * a repeat, so each event changes the order once.
	 */
	public function claimEvent(string $eventId, string $monokuloOrderId, int $now): bool {
		$this->db->query(
			"INSERT IGNORE INTO `{$this->prefix}monokulo_event` (`event_id`, `monokulo_order_id`, `applied_at`) VALUES ('"
			. $this->db->escape($eventId) . "', '" . $this->db->escape($monokuloOrderId) . "', " . $now . ')'
		);

		return $this->db->countAffected() === 1;
	}

	/** Gives a claimed event back, after applying it failed, so a retry applies it. */
	public function releaseEvent(string $eventId): void {
		$this->db->query("DELETE FROM `{$this->prefix}monokulo_event` WHERE `event_id` = '" . $this->db->escape($eventId) . "'");
	}
}
