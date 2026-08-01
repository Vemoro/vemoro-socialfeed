<?php
namespace LocalInstagramFeed\Api;

final class ApiException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $httpStatus = 0,
		public readonly int $apiCode = 0,
		public readonly bool $retryable = false,
		public readonly string $operation = '',
		public readonly int $apiSubcode = 0,
		public readonly string $requestId = '',
		public readonly string $apiType = ''
	) {
		parent::__construct( $message, $apiCode );
	}

	/** @return array<string,mixed> */
	public function diagnosticContext( string $phase = '' ): array {
		return array_filter(
			array(
				'phase'         => $phase,
				'operation'     => $this->operation,
				'http_status'   => $this->httpStatus,
				'meta_code'     => $this->apiCode,
				'meta_subcode'  => $this->apiSubcode,
				'meta_type'     => $this->apiType,
				'request_id'    => $this->requestId,
				'retryable'     => $this->retryable,
				'error'         => $this->getMessage(),
			),
			static fn( mixed $value ): bool => '' !== $value && 0 !== $value
		);
	}
}
