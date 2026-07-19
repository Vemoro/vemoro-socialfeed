<?php
namespace LocalInstagramFeed\Admin;

final class Request {
	public static function query(string $key): string {
		$value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);
		if (null === $value && isset($_GET[$key])) { $value = wp_unslash($_GET[$key]); }
		return is_scalar($value) ? (string) $value : '';
	}

	public static function post(string $key): string {
		$value = filter_input(INPUT_POST, $key, FILTER_UNSAFE_RAW);
		if (null === $value && isset($_POST[$key])) { $value = wp_unslash($_POST[$key]); }
		return is_scalar($value) ? (string) $value : '';
	}
}
