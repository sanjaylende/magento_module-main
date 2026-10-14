# Flipick Video Generator: Magento extension

Magento 2 extension (`Flipick_VideoGenerator`) that connects a store to the Flipick video adapter. The module sits at the root of this repository (`Block/`, `Controller/`, `Logger/` …); install it as `app/code/Flipick/VideoGenerator`.

## Logging and rotation

Every class of the extension logs through `Flipick\VideoGenerator\Logger\FlipickLogger` (wired in `etc/di.xml`):

| | |
|---|---|
| Live file | `var/log/flipick_videogenerator.log` |
| Archives | `var/log/flipick_videogenerator-2026-10-08.zip` (daily) and `…-2026-10-08-153012.zip` (size) |
| Daily rotation | **every day at 00:05:00** in the store's configured timezone: cron job `flipick_videogenerator_rotate_logs` (`etc/crontab.xml`, `5 0 * * *`) |
| Size rotation | **at once when the file would pass 10 MB**, whatever the time |
| Also | the first line written after 00:05 rotates a file whose day has ended, so a missed cron run never skips a day |
| Warnings and errors | also passed to Magento's `var/log/system.log` |
| Debug lines | only in developer mode |
| Secrets | keys such as `secret`, `token`, `password`, `authorization`, `signature` are written as `[redacted]` |

A daily archive is named after the *log day* (00:05 to 00:05): a line written at 00:03 belongs to the day before. A rotation first renames
the live file (atomic, under a lock), so no line is lost or split, and then zips it; a crash in between leaves a `.rotating-*` file that the
next call zips. The PHP `zip` extension is used (it is required by Magento); without it the file is gzipped (`.log.gz`) and the problem is reported.
Logging never throws: if the folder is not writable the line goes to PHP's error log.

After installing or updating the extension: `bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush`, and make
sure Magento's cron is running (`bin/magento cron:run` from the system cron every minute).

### Tests
`tools/log-rotator-test.php` (rotation rules, timezones, size, crash recovery, retention) runs with any PHP that has the zip extension:

```
php tools/log-rotator-test.php Logger/LogRotator.php 'Flipick\VideoGenerator\Logger\LogRotator'
```
