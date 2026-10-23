<?php
declare(strict_types=1);

namespace Monokulo\OpenCart;

/**
 * Where the client keeps each connection's API entry between requests (the
 * Repository, in the connection's row).
 */
interface EntryStore {
	/** The entry kept for `connection`'s key, or null. */
	public function entry(Connection $connection): ?Entry;

	public function keepEntry(Connection $connection, Entry $entry): void;
}
