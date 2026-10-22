<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\Plugin;
use PHPUnit\Framework\TestCase;

/** The version is written in three places; they must agree. */
final class PackageTest extends TestCase {
	public function test_the_version_is_the_same_everywhere(): void {
		$install = json_decode((string)file_get_contents(__DIR__ . '/../../src/install.json'), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(trim((string)file_get_contents(__DIR__ . '/../../VERSION')), Plugin::VERSION);
		$this->assertSame(Plugin::VERSION, $install['version']);
		$this->assertSame('opencart/' . Plugin::VERSION, Plugin::client());
	}

	/** Monokulo takes a slug of a lowercase letter then 1 to 31 lowercase letters, digits or hyphens, and a name up to 40. */
	public function test_the_slug_and_name_are_ones_monokulo_takes(): void {
		$this->assertMatchesRegularExpression('/\A[a-z][a-z0-9-]{1,31}\z/', Plugin::SLUG);
		$this->assertLessThanOrEqual(40, mb_strlen(Plugin::NAME));
	}

	/** OpenCart's Extension Installer lists the extension from these. */
	public function test_the_install_json_has_what_the_installer_reads(): void {
		$install = json_decode((string)file_get_contents(__DIR__ . '/../../src/install.json'), true, 512, JSON_THROW_ON_ERROR);

		foreach (['name', 'description', 'version', 'author', 'link'] as $key) {
			$this->assertNotEmpty($install[$key] ?? null, $key);
		}
	}
}
