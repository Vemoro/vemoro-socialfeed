# WordPress.org release checklist

Target slug: `vemoro-socialfeed`  
Contributor account: `vemoro`

The first submission is manual and must not use the SVN workflow until WordPress.org has approved the plugin and created the SVN repository.

1. Secure the `vemoro` account with a strong unique password and two-factor authentication. Stop if that account name is unavailable.
2. Supply and publish final legal texts at the privacy and terms URLs referenced by `readme.txt`.
3. Run Plugin Check, the official readme validator, PHPCS/PHPCompatibility, PHPUnit, JavaScript tests and PHP lint on PHP 8.1 and 8.2.
4. Test a clean installation and an upgrade from the manually installed 2.0.3 plugin without uninstalling its data.
5. Build the review ZIP with the top-level folder `vemoro-socialfeed`; exclude everything in `.distignore`.
6. Submit the ZIP and answer service/privacy questions with the exact OAuth disclosure from `readme.txt`.
7. After approval, configure the protected GitHub environment `wordpress-org` with `WPORG_USERNAME` and an SVN-specific `WPORG_PASSWORD`.
8. Create the signed/reviewed Git tag, then manually run “Publish approved WordPress.org release” and type the required approval phrase.
9. Verify `trunk`, `tags/<version>`, assets, checksums and the public plugin page before changing the website download link.

The immediate, dismissible support notice intentionally remains part of the submission. If the Plugin Review Team requests presentation or frequency changes, implement those changes before publishing.
