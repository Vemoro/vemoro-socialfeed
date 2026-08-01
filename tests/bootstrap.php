<?php
$testsDir = getenv('WP_TESTS_DIR') ?: dirname(__DIR__, 5) . '/wordpress-tests-lib';
if (! file_exists($testsDir . '/includes/functions.php')) { fwrite(STDERR, "Set WP_TESTS_DIR to the WordPress test library.\n"); exit(1); }
require_once $testsDir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function(): void { require dirname(__DIR__) . '/vemoro-socialfeed.php'; });
require $testsDir . '/includes/bootstrap.php';
