<?php
namespace Monokulo\OpenCart\Tests\Http;

use Monokulo\OpenCart\Tests\Support\Shop;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * dist/monokulo.ocmod.zip through OpenCart's own Extension Installer, as a
 * merchant installs it: upload, install, then turn it on under
 * Extensions > Payments. Any earlier install is uninstalled first the same
 * way, which also shows uninstalling keeps the extension's tables.
 *
 * scripts/test install builds the zip and runs this before every other
 * suite, which then test the extension as installed here.
 */
#[Group('installer')]
final class InstallerTest extends TestCase {
	private const ZIP = __DIR__ . '/../../dist/monokulo.ocmod.zip';
	private const TABLES = ['oc_monokulo_connection', 'oc_monokulo_order', 'oc_monokulo_event', 'oc_monokulo_connect_nonce'];

	public function test_over_http_the_zip_installs_through_the_extension_installer(): void {
		$this->assertFileExists(self::ZIP, 'run scripts/build-zip first');
		[$browser, $token] = Shop::admin();

		$existing = Shop::rows("SELECT extension_install_id FROM oc_extension_install WHERE code = 'monokulo'");
		if ($existing) {
			$id = $existing[0]['extension_install_id'];
			$tables = array_filter(self::TABLES, static fn(string $table): bool => (bool)Shop::rows("SHOW TABLES LIKE '$table'"));
			$this->assertArrayHasKey('success', $browser->get(Shop::adminUrl('extension/payment.uninstall', $token, 'extension=monokulo&code=monokulo'))->json());
			$this->assertArrayHasKey('success', $browser->get(Shop::adminUrl('marketplace/installer.uninstall', $token, 'extension_install_id=' . $id))->json());
			$this->assertArrayHasKey('success', $browser->get(Shop::adminUrl('marketplace/installer.delete', $token, 'extension_install_id=' . $id))->json());
			$this->assertDirectoryDoesNotExist('/var/www/html/extension/monokulo');
			foreach ($tables as $table) {
				$this->assertNotEmpty(Shop::rows("SHOW TABLES LIKE '$table'"), "$table stays after uninstalling");
			}
			$this->assertEmpty(Shop::rows("SELECT * FROM oc_setting WHERE code = 'payment_monokulo'"), 'OpenCart removed the settings');
		}

		$upload = $browser->upload(Shop::adminUrl('marketplace/installer.upload', $token), 'file', self::ZIP, 'monokulo.ocmod.zip')->json();
		$this->assertArrayHasKey('success', $upload, json_encode($upload));

		$row = Shop::rows("SELECT * FROM oc_extension_install WHERE code = 'monokulo'")[0];
		$this->assertSame('0.1.0', $row['version']);
		$this->assertSame('Monokulo (Monero payments)', $row['name']);

		// The installer works in steps (files, then OCMOD XML, then clean-up),
		// each answer naming the next, as the admin page's script follows them.
		$install = $browser->get(Shop::adminUrl('marketplace/installer.install', $token, 'extension_install_id=' . $row['extension_install_id']))->json();
		for ($steps = 0; isset($install['next']) && $steps < 10; $steps++) {
			$install = $browser->get(html_entity_decode($install['next']))->json();
		}
		$this->assertArrayHasKey('success', $install, json_encode($install));

		$zip = new \ZipArchive();
		$zip->open(self::ZIP);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$this->assertSame($zip->getFromIndex($i), file_get_contents('/var/www/html/extension/monokulo/' . $name), $name);
		}

		$payments = $browser->get(Shop::adminUrl('extension/payment', $token));
		$this->assertStringContainsString('Monokulo (Monero)', $payments->text());

		$enable = $browser->get(Shop::adminUrl('extension/payment.install', $token, 'extension=monokulo&code=monokulo'))->json();
		$this->assertArrayHasKey('success', $enable, json_encode($enable));

		$this->assertNotEmpty(Shop::rows("SELECT * FROM oc_extension WHERE type = 'payment' AND extension = 'monokulo' AND code = 'monokulo'"));
		foreach (self::TABLES as $table) {
			$this->assertNotEmpty(Shop::rows("SHOW TABLES LIKE '$table'"), $table);
		}
		$settings = array_column(Shop::rows("SELECT `key`, `value` FROM oc_setting WHERE store_id = 0 AND code = 'payment_monokulo'"), 'value', 'key');
		$this->assertSame('1', $settings['payment_monokulo_pending_status_id']);
		$this->assertSame('2', $settings['payment_monokulo_paid_status_id']);
		$this->assertSame('14', $settings['payment_monokulo_expired_status_id']);
		$this->assertSame('0', $settings['payment_monokulo_status'], 'off until a store connects');

		$settingsPage = $browser->get(Shop::adminUrl('extension/monokulo/payment/monokulo', $token));
		$this->assertSame(200, $settingsPage->status);
		$this->assertStringContainsString('Monokulo address', $settingsPage->text());
	}
}
