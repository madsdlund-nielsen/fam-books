<?php
// Unit tests for the start-date rule. Run: php tests/start_dates_test.php
declare(strict_types=1);
require __DIR__ . '/../public_html/_private/start_dates.php';

$tz = new DateTimeZone('Europe/Copenhagen');
$d = fn(string $s) => new DateTimeImmutable($s, $tz);
$fails = 0;
$check = function (string $label, array $options, array $expected) use (&$fails) {
    $got = array_map(fn($x) => $x->format('Y-m-d'), array_slice($options, 0, count($expected)));
    $ok = $got === $expected;
    $fails += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok ? '' : ' — got ' . implode(',', $got) . ' expected ' . implode(',', $expected)) . "\n";
};

// No sales yet: this week's Monday + 3 weeks, rolling with "now".
$check('no sale, Wed 30 Sep 2026', fb_start_date_options(null, $d('2026-09-30 15:00'), $tz), ['2026-10-19', '2026-10-26', '2026-11-02']);
$check('no sale, Sunday 4 Oct (same ISO week)', fb_start_date_options(null, $d('2026-10-04 23:30'), $tz), ['2026-10-19']);
$check('no sale, Monday 5 Oct (new week)', fb_start_date_options(null, $d('2026-10-05 00:10'), $tz), ['2026-10-26']);

// First sale Wed 1 Oct (week 40) → earliest Mon 19 Oct (week 43) for everyone.
$first = $d('2026-10-01 10:00');
$check('anchored, buyer 5 Oct', fb_start_date_options($first, $d('2026-10-05 12:00'), $tz), ['2026-10-19', '2026-10-26']);
$check('anchored, buyer 18 Oct', fb_start_date_options($first, $d('2026-10-18 20:00'), $tz), ['2026-10-19']);
$check('anchored, buyer on the anchor Monday', fb_start_date_options($first, $d('2026-10-19 09:00'), $tz), ['2026-10-19']);
$check('anchor passed, buyer Tue 20 Oct → next Monday', fb_start_date_options($first, $d('2026-10-20 09:00'), $tz), ['2026-10-26', '2026-11-02']);
$check('anchor passed, buyer on Monday 26 Oct → next Monday', fb_start_date_options($first, $d('2026-10-26 09:00'), $tz), ['2026-11-02']);

// Stored UTC first-sale time late Sunday evening (still Sunday locally → that ISO week).
$check('UTC anchor Sun 4 Oct 21:30Z = Sun 23:30 local', fb_start_date_options(new DateTimeImmutable('2026-10-04 21:30', new DateTimeZone('UTC')), $d('2026-10-05 08:00'), $tz), ['2026-10-19']);
// UTC Sunday 22:30Z is already Monday 00:30 local (CEST) → next ISO week.
$check('UTC anchor Sun 4 Oct 22:30Z = Mon 00:30 local', fb_start_date_options(new DateTimeImmutable('2026-10-04 22:30', new DateTimeZone('UTC')), $d('2026-10-05 08:00'), $tz), ['2026-10-26']);

// DST change (25 Oct 2026) keeps local midnight Mondays.
$opts = fb_start_date_options(null, $d('2026-10-07 12:00'), $tz, 3, 8);
$allMidnightMondays = array_reduce($opts, fn($c, $x) => $c && $x->format('N H:i') === '1 00:00', true);
$fails += $allMidnightMondays ? 0 : 1;
echo ($allMidnightMondays ? 'PASS' : 'FAIL') . " 8 options are all local-midnight Mondays across DST\n";
$count = count($opts) === 8;
$fails += $count ? 0 : 1;
echo ($count ? 'PASS' : 'FAIL') . " option count\n";

$label = fb_format_date_da($d('2026-10-19'));
$fails += $label === 'mandag d. 19. oktober 2026' ? 0 : 1;
echo ($label === 'mandag d. 19. oktober 2026' ? 'PASS' : 'FAIL') . " Danish label: $label\n";

exit($fails ? 1 : 0);
