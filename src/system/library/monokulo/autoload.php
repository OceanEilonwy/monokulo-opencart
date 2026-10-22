<?php
/**
 * Loads the extension's own classes (namespace Monokulo\OpenCart, one class
 * per file named after it). They know nothing about OpenCart, so the unit
 * tests load them the same way without booting a shop.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'Monokulo\\OpenCart\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
