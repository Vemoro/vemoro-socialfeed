<?php
namespace Vemoro\SocialFeed\Domain;

final class SyncResult {
	public int $created = 0;
	public int $updated = 0;
	public int $skipped = 0;
	public int $deleted = 0;
	public int $pruned  = 0;
	public int $failed  = 0;
	/** @var array<int,string> */
	public array $errors     = array();
	public int $fetched      = 0;
	public bool $complete    = false;
	public bool $fullRefresh = false;

	/** @return array<string,mixed> */
	public function toArray(): array {
		return get_object_vars( $this );
	}
}
