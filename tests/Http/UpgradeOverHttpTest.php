<?php
namespace Monokulo\OpenCart\Tests\Http;

use Monokulo\OpenCart\Tests\Support\Shop;
use PHPUnit\Framework\TestCase;

/**
 * A shop that connected with version 0.1 saved Monokulo's address
 * (`endpoint`) and no API entry. With the new files in place it keeps its
 * connection: the entry is `{endpoint}/api/v1`, and the old column goes.
 */
final class UpgradeOverHttpTest extends TestCase {
	protected function setUp(): void {
		Shop::exec("DROP TABLE oc_monokulo_connection");
		// The table as version 0.1 made it.
		Shop::exec("CREATE TABLE oc_monokulo_connection (
			`host` VARCHAR(255) NOT NULL,
			`endpoint` VARCHAR(255) NOT NULL,
			`public_key` VARCHAR(128) NOT NULL,
			`secret_token` VARCHAR(255) NOT NULL,
			`webhook_secret` VARCHAR(255) NOT NULL,
			`connected_at` INT(11) NOT NULL,
			PRIMARY KEY (`host`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
		Shop::exec("INSERT INTO oc_monokulo_connection VALUES ('localhost:8090', 'https://pay.example.com/', 'pk_old', 'sk_old', 'whsec_old', 1)");
	}

	protected function tearDown(): void {
		Shop::exec("DELETE FROM oc_monokulo_connection");
	}

	/** @return list<string> */
	private function columns(): array {
		return array_column(Shop::rows("SHOW COLUMNS FROM oc_monokulo_connection"), 'Field');
	}

	public function test_over_http_an_old_connection_keeps_working_on_the_api_entry(): void {
		[$browser, $token] = Shop::admin();

		$settings = $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token));

		$this->assertSame(200, $settings->status);
		$this->assertStringContainsString('pk_old', $settings->text(), 'still connected');
		$this->assertSame(['host', 'api', 'public_key', 'secret_token', 'webhook_secret', 'connected_at'], $this->columns());
		$row = Shop::rows("SELECT * FROM oc_monokulo_connection")[0];
		$this->assertSame(['https://pay.example.com/api/v1', 'pk_old', 'sk_old', 'whsec_old'], [$row['api'], $row['public_key'], $row['secret_token'], $row['webhook_secret']]);

		// Once moved, nothing more to do.
		$this->assertStringContainsString('pk_old', $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token))->text());
		$this->assertSame('https://pay.example.com/api/v1', Shop::rows("SELECT api FROM oc_monokulo_connection")[0]['api']);
	}
}
