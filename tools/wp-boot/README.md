# wp-boot: judge a WordPress release candidate side by side

Two local sites on SQLite, the version live runs and the candidate, with the
same theme (cloned from GitHub main, never the local checkout, which can be
stale) and this plugin checkout (symlinked). Outbound HTTP is blocked and cron
is off. Only the differences between the two count.

    W=/some/scratch/dir
    bash tools/wp-boot/setup.sh   $W 7.1.4 7.2-RC1    # downloads, installs, seeds, activates
    bash tools/wp-boot/serve.sh   $W 7.1.4 7.2-RC1    # prints the two php -S commands (8071, 8072)
    bash tools/wp-boot/compare.sh $W 7.1.4 7.2-RC1    # statuses, theme/plugin proof, differing lines, logs

Read `compare.sh` before trusting a quiet result: a page that fell back to a
default theme differs from nothing. Its `theme` and `plugin` columns prove the
real ones rendered (the plugin column counts its twitter:card tag, printed on
singular pages only).

The editor: open `http://127.0.0.1:<port>/?sn_boot_login=1` (the plugin hides
wp-login.php; `boot-login.php` signs in as user 1, only from 127.0.0.1 and only
under the rig's `SN_WP_BOOT`), then edit the seeded note and open the
Signal & Noise inspector panel on both.

Known on both versions, so not a finding: SQLite rejects MySQL-only SQL
(TRIM(TRAILING ...)); the single-note template's group logs a block validation
error in the editor (comments inside the group); `HTTP_HOST` warnings from the
CLI install. PHP is 8.3 by default (live runs 8.4); set `PHP=` to change it.
