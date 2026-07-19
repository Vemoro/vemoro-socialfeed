<?php
namespace LocalInstagramFeed\Security;

interface SecretStoreInterface {
	public function store(string $key, string $value): bool;
	public function get(string $key): ?string;
	public function delete(string $key): bool;
}
