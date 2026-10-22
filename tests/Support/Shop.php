<?php
namespace Monokulo\OpenCart\Tests\Support;

/**
 * The OpenCart under test (docker/compose.yaml): its address, its admin
 * login, and its database, for setting up and checking what a test needs.
 */
final class Shop {
	public const URL = 'http://localhost:8090/';
	public const ADMIN_USER = 'admin';
	public const ADMIN_PASSWORD = 'admin-password-1';

	private static ?\mysqli $db = null;

	public static function db(): \mysqli {
		if (!self::$db) {
			mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
			self::$db = new \mysqli('127.0.0.1', 'opencart', 'opencart', 'opencart', (int)(getenv('DB_PORT') ?: 33061));
			self::$db->set_charset('utf8mb4');
		}

		return self::$db;
	}

	/** @return list<array<string, mixed>> */
	public static function rows(string $sql): array {
		return self::db()->query($sql)->fetch_all(MYSQLI_ASSOC);
	}

	public static function exec(string $sql): void {
		self::db()->query($sql);
	}

	public static function quote(string $value): string {
		return "'" . self::db()->real_escape_string($value) . "'";
	}

	/**
	 * Signs in to the admin as a merchant does, through its login form.
	 *
	 * @return array{0: Browser, 1: string} the signed-in browser and its user_token
	 */
	public static function admin(): array {
		$browser = new Browser();
		$page = $browser->get(self::URL . 'admin/index.php?route=common/login');
		$login = $page->form('common/login.login');
		$answer = $browser->post($login['action'], ['username' => self::ADMIN_USER, 'password' => self::ADMIN_PASSWORD])->json();
		if (!isset($answer['redirect']) || !preg_match('/user_token=([0-9a-f]{32})/', $answer['redirect'], $m)) {
			throw new \RuntimeException('admin login failed: ' . json_encode($answer));
		}

		return [$browser, $m[1]];
	}

	public static function adminUrl(string $route, string $token, string $query = ''): string {
		return self::URL . 'admin/index.php?route=' . $route . '&user_token=' . $token . ($query !== '' ? '&' . $query : '');
	}
}
