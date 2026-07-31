<?php
namespace LocalInstagramFeed\Api;

final class ApiException extends \RuntimeException {
	public function __construct( string $message, public readonly int $httpStatus = 0, public readonly int $apiCode = 0, public readonly bool $retryable = false ) {
		parent::__construct( $message, $apiCode );
	}
}
