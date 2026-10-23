<?php
namespace Monokulo\OpenCart\Tests\Support;

use Monokulo\OpenCart\Connection;
use Monokulo\OpenCart\Entry;
use Monokulo\OpenCart\EntryStore;

/** Kept API entries in memory, by key, for tests of the client alone. */
final class MemoryEntries implements EntryStore {
	/** @var array<string, Entry> */
	public array $kept = [];

	public function entry(Connection $connection): ?Entry {
		return $this->kept[$connection->secretToken] ?? null;
	}

	public function keepEntry(Connection $connection, Entry $entry): void {
		$this->kept[$connection->secretToken] = $entry;
	}
}
