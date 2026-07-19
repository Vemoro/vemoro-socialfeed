<?php
namespace LocalInstagramFeed\Security;

final class SecretStore implements SecretStoreInterface {
	private const PREFIX = 'lif_secret_';

	public function store(string $key, string $value): bool {
		$encrypted = $this->encrypt($value);
		if (null === $encrypted) { return false; }
		$option = self::PREFIX . sanitize_key($key);
		if (false === get_option($option, false)) { return add_option($option, $encrypted, '', false); }
		update_option($option, $encrypted, false);
		return is_string(get_option($option));
	}

	public function get(string $key): ?string {
		$payload = get_option(self::PREFIX . sanitize_key($key));
		return is_string($payload) ? $this->decrypt($payload) : null;
	}

	public function delete(string $key): bool {
		return delete_option(self::PREFIX . sanitize_key($key));
	}

	public function available(): bool {
		return function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
	}

	private function key(): string {
		return hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . 'local-instagram-feed', true);
	}

	private function encrypt(string $value): ?string {
		if (function_exists('sodium_crypto_secretbox')) {
			$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			return 'sodium:' . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key()));
		}
		if (function_exists('openssl_encrypt')) {
			$nonce = random_bytes(12); $tag = '';
			$cipher = openssl_encrypt($value, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
			return false === $cipher ? null : 'openssl:' . base64_encode($nonce . $tag . $cipher);
		}
		return null;
	}

	private function decrypt(string $payload): ?string {
		[$method, $encoded] = array_pad(explode(':', $payload, 2), 2, '');
		$raw = base64_decode($encoded, true);
		if (false === $raw) { return null; }
		if ('sodium' === $method && function_exists('sodium_crypto_secretbox_open')) {
			$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$value = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
			return false === $value ? null : $value;
		}
		if ('openssl' === $method && function_exists('openssl_decrypt')) {
			$value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
			return false === $value ? null : $value;
		}
		return null;
	}
}
