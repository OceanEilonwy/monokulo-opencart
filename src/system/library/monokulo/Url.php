<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * Links in Monokulo's answers are paths on its own host (`/api/v1/orders`,
 * `/checkout/order_…`, with the path of a Monokulo served under one), or
 * absolute URLs for its onion address. They are resolved against the URL
 * the shop called, so a shop that reaches Monokulo at one address stays
 * on it.
 */
final class Url {
	/**
	 * `href` resolved against `base`: an absolute URL as it is, a path from
	 * `base`'s scheme, host and port, anything else beside `base`'s last
	 * path segment (RFC 3986's "merge").
	 */
	public static function resolve(string $base, string $href): string {
		if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $href)) {
			return $href;
		}
		$parts = parse_url($base);
		$scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'https';
		if (str_starts_with($href, '//')) {
			return $scheme . ':' . $href;
		}
		$origin = self::origin($base);
		if (str_starts_with($href, '/')) {
			return $origin . $href;
		}
		$path = is_array($parts) && isset($parts['path']) ? $parts['path'] : '/';

		return $origin . substr($path, 0, (int)strrpos($path, '/') + 1) . $href;
	}

	/** `https://pay.example.com:8443/api/v1` → `https://pay.example.com:8443`. */
	public static function origin(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}

		return strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	/** Whether `host` (`abc….onion`, with or without a port) is an onion address. */
	public static function isOnion(string $host): bool {
		return (bool)preg_match('/\.onion\.?(:\d+)?\z/i', trim($host));
	}
}
