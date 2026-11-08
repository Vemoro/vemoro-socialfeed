<?php
use Vemoro\SocialFeed\Diagnostics\FailureTracker;

final class FailureTrackerTest extends WP_UnitTestCase {
	public function test_transient_error_is_logged_but_not_exposed_as_persistent_warning(): void {
		$status = FailureTracker::record( array(), 'Temporary API failure', 100 );

		$this->assertSame( 1, $status['consecutive_failures'] );
		$this->assertArrayNotHasKey( 'last_error', $status );
	}

	public function test_repeated_error_becomes_persistent_and_success_clears_it(): void {
		$status = FailureTracker::record( array(), 'Repeated API failure', 100 );
		$status = FailureTracker::record( $status, 'Repeated API failure', 200 );

		$this->assertSame( 2, $status['consecutive_failures'] );
		$this->assertSame( 'Repeated API failure', $status['last_error'] );
		$this->assertArrayNotHasKey( 'last_error', FailureTracker::clear( $status ) );
	}

	public function test_a_different_error_starts_a_new_failure_sequence(): void {
		$status = FailureTracker::record( array(), 'First API failure', 100 );
		$status = FailureTracker::record( $status, 'Second API failure', 200 );

		$this->assertSame( 1, $status['consecutive_failures'] );
		$this->assertArrayNotHasKey( 'last_error', $status );
	}
}
