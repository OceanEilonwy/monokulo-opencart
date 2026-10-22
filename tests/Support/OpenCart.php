<?php
namespace Monokulo\OpenCart\Tests\Support;

/**
 * Boots the installed OpenCart in this PHP process, the way its index.php
 * does, up to the point where it would dispatch the request: the same
 * config, start-up actions (store by host, session, language, currency,
 * extensions, events), loader and database. A test then calls a controller
 * through the loader and reads the response.
 *
 * Every boot shares one database connection, so a test can run in a
 * transaction and roll it back. One process boots only one side (catalog or
 * admin): OpenCart defines its constants per side, so the two run as
 * separate PHPUnit suites.
 */
final class OpenCart {
	private const ROOT = '/var/www/html/';

	private static ?string $side = null;
	private static ?\Opencart\System\Engine\Autoloader $autoloader = null;
	private static ?\Opencart\System\Library\DB $db = null;

	/**
	 * A fresh request to the shop, ready to dispatch.
	 *
	 * @param 'catalog'|'admin' $side
	 * @param array<string, mixed> $get
	 * @param array<string, mixed> $post
	 * @param array<string, string> $server extra $_SERVER entries (HTTP_HOST defaults to the shop's)
	 * @param array<string, mixed> $session data to start the session with
	 */
	public static function boot(string $side, array $get = [], array $post = [], array $server = [], array $session = []): \Opencart\System\Engine\Registry {
		self::define($side);

		$saved = [$_GET, $_POST, $_SERVER, $_COOKIE, $_FILES];
		$_GET = $get;
		$_POST = $post;
		$_COOKIE = $session ? ['OCSESSID' => self::session($session)] : [];
		$_FILES = [];
		$_SERVER = $server + [
			'HTTP_HOST'       => 'localhost:8090',
			'PHP_SELF'        => $side === 'admin' ? '/admin/index.php' : '/index.php',
			'REQUEST_METHOD'  => $post ? 'POST' : 'GET',
			'REMOTE_ADDR'     => '127.0.0.1',
			'HTTP_USER_AGENT' => 'monokulo-opencart-tests',
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'HTTPS'           => false,
		] + $saved[2];

		try {
			return self::framework();
		} finally {
			[$_GET, $_POST, $_SERVER, $_COOKIE, $_FILES] = $saved;
		}
	}

	/** The shared database connection. */
	public static function db(): \Opencart\System\Library\DB {
		return self::$db ??= new \Opencart\System\Library\DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
	}

	/** Stores a session holding `data`, as a browser's earlier requests would have; returns its id. */
	private static function session(array $data): string {
		$id = bin2hex(random_bytes(13));
		self::db()->query("INSERT INTO `" . DB_PREFIX . "session` SET `session_id` = '" . $id . "', `data` = '" . self::db()->escape(json_encode($data)) . "', `expire` = '" . gmdate('Y-m-d H:i:s', time() + 3600) . "'");

		return $id;
	}

	/**
	 * Calls `route` (`extension/monokulo/payment/monokulo.confirm`) on a
	 * booted registry and returns what it answered: its output, headers and
	 * HTTP status (as http_response_code saw it).
	 *
	 * @return array{output: string, headers: list<string>, status: int, returned: mixed}
	 */
	public static function call(\Opencart\System\Engine\Registry $registry, string $route): array {
		http_response_code(200);
		$returned = $registry->get('load')->controller($route);
		if ($returned instanceof \Exception) {
			throw $returned;
		}
		$response = $registry->get('response');
		$headers = (fn() => $this->headers)->call($response);

		return [
			'output'   => $response->getOutput(),
			'headers'  => $headers,
			'status'   => (int)http_response_code(),
			'returned' => $returned,
		];
	}

	/** The Location header a call answered with, or null. */
	public static function location(array $answer): ?string {
		foreach ($answer['headers'] as $header) {
			if (stripos($header, 'Location: ') === 0) {
				return substr($header, 10);
			}
		}

		return null;
	}

	public static function define(string $side): void {
		if (self::$side === null) {
			self::$side = $side;
			define('VERSION', '4.1.0.4');
			require_once self::ROOT . ($side === 'admin' ? 'admin/' : '') . 'config.php';
			require_once DIR_SYSTEM . 'startup.php';
			self::$autoloader = new \Opencart\System\Engine\Autoloader();
			self::$autoloader->register('Opencart\\' . APPLICATION, DIR_APPLICATION);
			self::$autoloader->register('Opencart\Extension', DIR_EXTENSION);
			self::$autoloader->register('Opencart\System', DIR_SYSTEM);
			require_once DIR_STORAGE . 'vendor/autoload.php';
		} elseif (self::$side !== $side) {
			throw new \LogicException('this process already booted the ' . self::$side . ' side');
		}
	}

	/** system/framework.php, without its error handlers, its headers and the dispatch. */
	private static function framework(): \Opencart\System\Engine\Registry {
		$registry = new \Opencart\System\Engine\Registry();
		$registry->set('autoloader', self::$autoloader);

		$config = new \Opencart\System\Engine\Config();
		$config->addPath(DIR_CONFIG);
		$config->load('default');
		$config->load(strtolower(APPLICATION));
		$config->set('application', APPLICATION);
		$registry->set('config', $config);
		date_default_timezone_set($config->get('date_timezone'));

		$registry->set('log', new \Opencart\System\Library\Log($config->get('error_filename')));

		$event = new \Opencart\System\Engine\Event($registry);
		$registry->set('event', $event);
		foreach ($config->get('action_event') ?? [] as $key => $value) {
			foreach ($value as $priority => $action) {
				$event->register($key, new \Opencart\System\Engine\Action($action), $priority);
			}
		}

		$registry->set('factory', new \Opencart\System\Engine\Factory($registry));
		$registry->set('load', new \Opencart\System\Engine\Loader($registry));
		$registry->set('request', new \Opencart\System\Library\Request());
		$registry->set('response', new \Opencart\System\Library\Response());

		$registry->set('db', self::db());

		$registry->set('cache', new \Opencart\System\Library\Cache($config->get('cache_engine'), $config->get('cache_expire')));

		$template = new \Opencart\System\Library\Template($config->get('template_engine'));
		$template->addPath(DIR_TEMPLATE);
		$registry->set('template', $template);

		$language = new \Opencart\System\Library\Language($config->get('language_code'));
		$language->addPath(DIR_LANGUAGE);
		$language->load('default');
		$registry->set('language', $language);

		$registry->set('url', new \Opencart\System\Library\Url($config->get('site_url')));
		$registry->set('document', new \Opencart\System\Library\Document());

		foreach ($config->get('action_pre_action') as $pre_action) {
			$args = [];
			$result = (new \Opencart\System\Engine\Action($pre_action))->execute($registry, $args);
			if ($result instanceof \Exception) {
				throw $result;
			}
			if ($result instanceof \Opencart\System\Engine\Action) {
				throw new \RuntimeException($pre_action . ' sent the request to ' . $result->getId() . ' instead');
			}
		}

		return $registry;
	}
}
