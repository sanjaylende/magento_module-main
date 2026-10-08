<?php
/**
 * Tests the log rotator with no WordPress or Magento loaded.
 *   php tests/log-rotator-test.php [path/to/LogRotator.php] [ClassName]
 * Default: this plugin's class. The Magento extension's copy is tested with:
 *   php tests/log-rotator-test.php /path/Logger/LogRotator.php 'Flipick\VideoGenerator\Logger\LogRotator'
 * Needs the PHP zip extension (the same one the rotator uses).
 */
define('ABSPATH', __DIR__);
$file = $argv[1] ?? __DIR__ . '/../Logger/LogRotator.php';
$class = $argv[2] ?? 'Flipick\VideoGenerator\Logger\LogRotator';
require $file;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' && !$ok ? "  -- $detail" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}
function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/logrot-' . bin2hex(random_bytes(4));
    mkdir($d, 0775, true);
    return $d;
}
function zips(string $d): array
{
    $z = array_map('basename', glob($d . '/*.zip') ?: []);
    sort($z);
    return $z;
}
function readZip(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['name' => null, 'text' => null, 'ok' => false];
    }
    $name = $zip->getNameIndex(0);
    $text = $zip->getFromIndex(0);
    $count = $zip->numFiles;
    $zip->close();
    return ['name' => $name, 'text' => $text, 'ok' => $count === 1];
}
$UTC = new DateTimeZone('UTC');
$at = function (string $s) use ($UTC): int { return (new DateTimeImmutable($s, $UTC))->getTimestamp(); };

// A rotator whose clock the test controls; writes also move the file's mtime to the fake "now" (the real filesystem would).
function make(string $class, string $dir, int &$now, int $max = 10485760, int $retention = 0, ?DateTimeZone $tz = null)
{
    return new $class($dir, 'app', $max, '00:05', $tz ?? new DateTimeZone('UTC'), $retention, function () use (&$now) { return $now; });
}
function put($r, string $text, int $now): void
{
    $r->append($text);
    @touch($r->getLogFile(), $now);
}

// 1. boundaries
{
    $d = tmpdir(); $now = 0; $r = make($class, $d, $now);
    $b = $at('2026-10-08 00:05:00');
    check('lastBoundary at 00:04:59 is the day before', $r->lastBoundary($at('2026-10-08 00:04:59')) === $at('2026-10-07 00:05:00'));
    check('lastBoundary at 00:05:00 is that moment', $r->lastBoundary($b) === $b);
    check('nextBoundary at 00:05:00 is tomorrow', $r->nextBoundary($b) === $at('2026-10-09 00:05:00'));
    check('year end', $r->nextBoundary($at('2026-12-31 12:00:00')) === $at('2027-01-01 00:05:00'));
}
// 2. daily
{
    $d = tmpdir(); $now = $at('2026-10-08 09:00:00'); $r = make($class, $d, $now);
    put($r, "line one\n", $now);
    $now = $at('2026-10-08 23:59:59'); put($r, "line two\n", $now);
    $now = $at('2026-10-09 00:04:59'); put($r, "line three\n", $now);
    check('no rotation at 00:04:59', zips($d) === [], implode(',', zips($d)));
    $now = $at('2026-10-09 00:05:00'); put($r, "line four\n", $now);
    check('rotated at 00:05:00 into app-2026-10-08.zip', zips($d) === ['app-2026-10-08.zip'], implode(',', zips($d)));
    $z = readZip($d . '/app-2026-10-08.zip');
    check('zip is valid and holds the whole day', $z['ok'] && $z['name'] === 'app-2026-10-08.log' && $z['text'] === "line one\nline two\nline three\n");
    check('new file holds only the new line', file_get_contents($d . '/app.log') === "line four\n");
    check('no temporary files left', glob($d . '/*.rotating-*') === [] && glob($d . '/*.tmp') === []);
    // the scheduled job on a quiet system
    $d2 = tmpdir(); $now = $at('2026-10-08 22:00:00'); $r2 = make($class, $d2, $now);
    put($r2, "quiet day\n", $now);
    $now = $at('2026-10-09 00:04:59');
    check('rotateIfDue before 00:05 does nothing', $r2->rotateIfDue() === null);
    $now = $at('2026-10-09 00:05:00');
    check('rotateIfDue at 00:05:00 rotates (cron path)', $r2->rotateIfDue() !== null && zips($d2) === ['app-2026-10-08.zip']);
    check('rotateIfDue again does nothing', $r2->rotateIfDue() === null);
}
// 3. size
{
    $d = tmpdir(); $now = $at('2026-10-08 10:00:00'); $r = make($class, $d, $now, 1000);
    $line = str_repeat('x', 99) . "\n";
    for ($i = 0; $i < 10; $i++) { put($r, $line, $now); }
    check('exactly at the limit is one file', zips($d) === []);
    $now = $at('2026-10-08 10:00:30');
    put($r, "the eleventh line\n", $now);
    check('past the limit: zipped at once', zips($d) === ['app-2026-10-08-100030.zip'], implode(',', zips($d)));
    $z = readZip($d . '/app-2026-10-08-100030.zip');
    check('size zip holds the first ten lines', $z['text'] === str_repeat($line, 10));
    check('new file starts with the eleventh line', file_get_contents($d . '/app.log') === "the eleventh line\n");
}
// 4. same-second size rotations get distinct names, nothing lost
{
    $d = tmpdir(); $now = $at('2026-10-08 10:00:00'); $r = make($class, $d, $now, 50);
    for ($i = 0; $i < 6; $i++) { put($r, str_repeat('y', 40) . "\n", $now); }
    $names = zips($d);
    $all = 0;
    foreach ($names as $n) { $all += strlen(readZip($d . '/' . $n)['text']); }
    $all += strlen(file_get_contents($d . '/app.log'));
    check('5 distinct archives', count($names) === 5 && count(array_unique($names)) === 5, implode(',', $names));
    check('no line lost', $all === 6 * 41);
}
// 5. leftovers and a file from before the last 00:05
{
    $d = tmpdir(); file_put_contents($d . '/app.log.rotating-20261007235900-abc', "half rotated\n");
    $now = $at('2026-10-08 08:00:00'); $r = make($class, $d, $now);
    $r->append("");
    check('leftover from a crash is zipped on the next call', count(zips($d)) === 1 && readZip($d . '/' . zips($d)[0])['text'] === "half rotated\n");
    $d = tmpdir(); file_put_contents($d . '/app.log', "old line\n"); touch($d . '/app.log', $at('2026-10-07 22:00:00'));
    $now = $at('2026-10-08 08:00:00'); $r = make($class, $d, $now);
    put($r, "new line\n", $now);
    check('a stale file is rotated by the first write of the new day', zips($d) === ['app-2026-10-07.zip']);
    check('the old zip holds the old line, the new file the new line', readZip($d . '/app-2026-10-07.zip')['text'] === "old line\n" && file_get_contents($d . '/app.log') === "new line\n");
}
// 6. log day: a line written at 00:03 belongs to the day before
{
    $d = tmpdir(); $now = $at('2026-10-09 00:03:00'); $r = make($class, $d, $now);
    put($r, "just after midnight\n", $now);
    $now = $at('2026-10-09 00:05:00'); $r->rotateIfDue();
    check('named after the log day (2026-10-08)', zips($d) === ['app-2026-10-08.zip'], implode(',', zips($d)));
}
// 7. timezone: 00:05 is local time
{
    $d = tmpdir(); $tz = new DateTimeZone('Asia/Kolkata'); $now = $at('2026-10-08 18:00:00'); // = 23:30 IST
    $r = make($class, $d, $now, 10485760, 0, $tz);
    put($r, "ist\n", $now);
    $now = $at('2026-10-08 18:34:59'); // 00:04:59 IST
    check('no rotation at 00:04:59 local', $r->rotateIfDue() === null);
    $now = $at('2026-10-08 18:35:00'); // 00:05:00 IST
    check('rotation at 00:05:00 local', $r->rotateIfDue() !== null && zips($d) === ['app-2026-10-08.zip']);
}
// 8. retention
{
    $d = tmpdir(); $now = $at('2026-10-20 09:00:00');
    file_put_contents($d . '/app-2026-09-01.zip', 'x'); touch($d . '/app-2026-09-01.zip', $now - 40 * 86400);
    file_put_contents($d . '/app-2026-10-15.zip', 'x'); touch($d . '/app-2026-10-15.zip', $now - 5 * 86400);
    $r = make($class, $d, $now, 10, 30);
    put($r, "0123456789\n", $now); put($r, "trigger\n", $now);
    check('old archive pruned, recent one kept', !is_file($d . '/app-2026-09-01.zip') && is_file($d . '/app-2026-10-15.zip'));
}
// 9. never throws
{
    $now = time();
    $r = make($class, '/proc/nope/cannot/write', $now);
    $ok = true;
    try { $res = $r->append("x\n"); $r->rotateIfDue(); $r->rotateNow(); } catch (Throwable $e) { $ok = false; }
    check('an unwritable directory never throws', $ok && $res === false);
}
echo $failures ? "\n$failures FAILED\n" : "\nAll rotation checks passed.\n";
exit($failures ? 1 : 0);
