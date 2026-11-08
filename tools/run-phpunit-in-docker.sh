#!/bin/sh
set -eu

apk add --no-cache subversion curl >/dev/null
curl -fsSL https://wordpress.org/latest.tar.gz -o /tmp/wordpress.tar.gz
mkdir -p /tmp/wordpress
tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C /tmp/wordpress

wp_version="$(grep -m1 'wp_version =' /tmp/wordpress/wp-includes/version.php | cut -d"'" -f2)"
svn export --quiet \
  "https://develop.svn.wordpress.org/tags/${wp_version}/tests/phpunit" \
  /tmp/wordpress-tests-lib
svn cat \
  "https://develop.svn.wordpress.org/tags/${wp_version}/wp-tests-config-sample.php" \
  > /tmp/wordpress-tests-lib/wp-tests-config.php
sed -i 's/youremptytestdbnamehere/wordpress_test/' /tmp/wordpress-tests-lib/wp-tests-config.php
sed -i 's/yourusernamehere/root/' /tmp/wordpress-tests-lib/wp-tests-config.php
sed -i 's/yourpasswordhere/root/' /tmp/wordpress-tests-lib/wp-tests-config.php
sed -i 's|localhost|vemoro-wp-ci-mysql|' /tmp/wordpress-tests-lib/wp-tests-config.php
sed -i \
  "s|^define( 'ABSPATH'.*|define( 'ABSPATH', '/tmp/wordpress/' );|" \
  /tmp/wordpress-tests-lib/wp-tests-config.php

WP_TESTS_DIR=/tmp/wordpress-tests-lib \
  php vendor/bin/phpunit --configuration phpunit.xml.dist "$@"
