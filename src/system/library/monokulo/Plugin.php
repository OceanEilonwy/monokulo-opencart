<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * Who this plugin is, as monokulo sees it.
 */
final class Plugin {
	/** Kept equal to VERSION and install.json by a test. */
	public const VERSION = '0.1.0';

	/** The connect path's platform slug: `/plugins/connect/opencart`. */
	public const SLUG = 'opencart';

	/** What monokulo shows the merchant ("Connect your OpenCart shop"). */
	public const NAME = 'OpenCart';

	/** The `Monokulo-Client` header value, `opencart/<version>`. */
	public static function client(): string {
		return self::SLUG . '/' . self::VERSION;
	}
}
