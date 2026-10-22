<?php
namespace Monokulo\OpenCart\Tests\Catalog;

use Monokulo\OpenCart\Plugin;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\Tests\Support\OpenCart;
use Monokulo\OpenCart\Tests\Support\ShopTestCase;

/**
 * The end of the connect flow, on the store's own host: Monokulo sends the
 * admin's browser back with a single-use token and the nonce the admin page
 * made, the shop redeems the token for the store's credentials, and the
 * browser goes back to the admin page saying how it went.
 */
final class ConnectReturnTest extends ShopTestCase {
	private const ROUTE = 'extension/monokulo/payment/monokulo.connect';
	private const ADMIN = 'http://localhost:8090/admin/index.php?route=extension/monokulo/payment/monokulo&user_token=abc';

	/** @return array{output: string, headers: list<string>, status: int, returned: mixed} */
	private function comeBack(array $query, string $host = self::HOST): array {
		$registry = OpenCart::boot('catalog', ['route' => self::ROUTE] + $query, [], ['HTTP_HOST' => $host]);

		return OpenCart::call($registry, self::ROUTE);
	}

	private function nonce(string $host = self::HOST, int $storeId = 0, int $now = 0): string {
		return $this->repository()->createNonce($storeId, $host, self::ADMIN, $now ?: time());
	}

	private function monokuloFinishes(int $status, mixed $body): void {
		self::$monokulo->respond('POST', '/plugins/connect/opencart/finish', $status, $body);
	}

	private const CREDENTIALS = ['public_key' => 'pk_new', 'secret_token' => 'sk_new', 'endpoint' => 'https://pay.example.com', 'webhook_signing_secret' => 'whsec_new'];

	public function test_a_good_return_connects_the_store_and_turns_monero_on(): void {
		$this->setting('payment_monokulo_status', '0');
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '&monokulo_connected=1', OpenCart::location($answer));
		$request = self::$monokulo->requests()[0];
		$this->assertSame([
			'token'       => 'tok_1',
			'webhook_url' => 'http://localhost:8090/index.php?route=extension/monokulo/payment/monokulo.callback',
			'name'        => 'OpenCart',
		], json_decode($request['body'], true));
		$this->assertSame('opencart/' . Plugin::VERSION, $request['headers']['monokulo-client']);

		$connection = $this->repository()->connection(self::HOST);
		$this->assertSame(['https://pay.example.com', 'pk_new', 'sk_new', 'whsec_new', Plugin::CONNECTION_VERSION], [$connection->endpoint, $connection->publicKey, $connection->secretToken, $connection->webhookSecret, $connection->version]);
		$this->assertSame('1', $this->rows("SELECT `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0 AND `key` = 'payment_monokulo_status'")[0]['value']);
	}

	/** Connecting again replaces the store's credentials (Monokulo replaced its webhook too). */
	public function test_connecting_again_replaces_the_credentials(): void {
		$this->connect();
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame('pk_new', $this->repository()->connection(self::HOST)->publicKey);
		$this->assertCount(1, $this->rows("SELECT * FROM `" . DB_PREFIX . "monokulo_connection`"));
	}

	/** The second store's return lands on its own host and connects only it, with its own webhook address. */
	public function test_a_second_store_connects_on_its_own_host(): void {
		$storeId = $this->addSecondStore();
		$this->monokuloFinishes(200, self::CREDENTIALS);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce(self::SECOND_HOST, $storeId)], self::SECOND_HOST);

		$this->assertStringEndsWith('&monokulo_connected=1', OpenCart::location($answer));
		$this->assertNotNull($this->repository()->connection(self::SECOND_HOST));
		$this->assertNull($this->repository()->connection(self::HOST));
		$this->assertSame('http://127.0.0.1:8090/index.php?route=extension/monokulo/payment/monokulo.callback', json_decode(self::$monokulo->requests()[0]['body'], true)['webhook_url']);
	}

	public function test_a_nonce_works_once(): void {
		$this->monokuloFinishes(200, self::CREDENTIALS);
		$nonce = $this->nonce();
		$this->comeBack(['token' => 'tok_1', 'nonce' => $nonce]);
		$this->db()->query("DELETE FROM `" . DB_PREFIX . "monokulo_connection`");

		$again = $this->comeBack(['token' => 'tok_1', 'nonce' => $nonce]);

		$this->assertSame(400, $again['status']);
		$this->assertNull(OpenCart::location($again));
		$this->assertStringContainsString('already been used or has expired', $again['output']);
		$this->assertNull($this->repository()->connection(self::HOST));
		$this->assertCount(1, self::$monokulo->requests(), 'the token was not sent twice');
	}

	public static function badReturns(): array {
		return [
			'no nonce'       => [['token' => 'tok_1']],
			'unknown nonce'  => [['token' => 'tok_1', 'nonce' => str_repeat('a', 32)]],
			'not a nonce'    => [['token' => 'tok_1', 'nonce' => "' OR 1=1 --"]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('badReturns')]
	public function test_a_return_this_shop_did_not_start_is_refused(array $query): void {
		$answer = $this->comeBack($query);

		$this->assertSame(400, $answer['status']);
		$this->assertSame([], self::$monokulo->requests());
	}

	/** Without a token the nonce is still used up: the attempt must start again. */
	public function test_no_token_is_refused_and_spends_the_nonce(): void {
		$nonce = $this->nonce();

		$this->assertSame(400, $this->comeBack(['nonce' => $nonce])['status']);
		$this->assertNull($this->repository()->consumeNonce($nonce, time()));
	}

	public function test_a_nonce_older_than_ten_minutes_is_refused(): void {
		$nonce = $this->nonce(self::HOST, 0, time() - 601);

		$this->assertSame(400, $this->comeBack(['token' => 'tok_1', 'nonce' => $nonce])['status']);
	}

	/** Started for one store, came back to another: refused, nothing connected. */
	public function test_coming_back_to_another_store_is_refused(): void {
		$this->addSecondStore();

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce(self::HOST)], self::SECOND_HOST);

		$this->assertSame(self::ADMIN . '&monokulo_connect_error=host', OpenCart::location($answer));
		$this->assertSame([], self::$monokulo->requests());
	}

	public static function finishFailures(): array {
		return [
			'no public address yet' => [503, ['error' => 'no public address'], 'unavailable'],
			'token refused'         => [401, '', 'finish'],
			'missing secret'        => [200, ['public_key' => 'pk', 'secret_token' => 'sk', 'endpoint' => 'https://x'], 'finish'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('finishFailures')]
	public function test_when_monokulo_does_not_hand_over_the_keys_the_admin_is_told(int $status, mixed $body, string $error): void {
		$this->monokuloFinishes($status, $body);

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '&monokulo_connect_error=' . $error, OpenCart::location($answer));
		$this->assertNull($this->repository()->connection(self::HOST));
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		$this->setting('payment_monokulo_address', 'http://127.0.0.1:' . FakeMonokulo::freePort());

		$answer = $this->comeBack(['token' => 'tok_1', 'nonce' => $this->nonce()]);

		$this->assertSame(self::ADMIN . '&monokulo_connect_error=unreachable', OpenCart::location($answer));
	}
}
