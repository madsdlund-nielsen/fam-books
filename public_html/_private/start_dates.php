<?php
// Start-date rule for the validation phase.
//
// The earliest start is the Monday 3 weeks after the week of the first-ever
// sale. Before the first sale the anchor is "now", so the earliest start rolls
// forward each week. Once that Monday has passed, the earliest start is the
// next upcoming Monday. Buyers can pick from the earliest start and the
// following Mondays.
declare(strict_types=1);

function fb_monday_of(DateTimeImmutable $day): DateTimeImmutable
{
    $dow = (int) $day->format('N'); // 1 = Monday … 7 = Sunday
    return $day->setTime(0, 0)->modify('-' . ($dow - 1) . ' days');
}

/**
 * @return DateTimeImmutable[] Mondays (local midnight) the buyer may choose.
 */
function fb_start_date_options(
    ?DateTimeImmutable $firstSaleAt,
    DateTimeImmutable $now,
    DateTimeZone $zone,
    int $weeksAfterFirstSale = 3,
    int $count = 8
): array {
    $now = $now->setTimezone($zone);
    $today = $now->setTime(0, 0);
    $anchor = ($firstSaleAt ?? $now)->setTimezone($zone);

    $earliest = fb_monday_of($anchor)->modify('+' . $weeksAfterFirstSale . ' weeks');
    if ($earliest < $today) {
        $earliest = fb_monday_of($today)->modify('+1 week');
    }

    $options = [];
    for ($i = 0; $i < $count; $i++) {
        $options[] = $earliest->modify('+' . $i . ' weeks');
    }
    return $options;
}

/** "mandag d. 20. oktober 2026" */
function fb_format_date_da(DateTimeImmutable $date, bool $withYear = true, bool $withWeekday = true): string
{
    static $days = [1 => 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag'];
    static $months = [1 => 'januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli',
        'august', 'september', 'oktober', 'november', 'december'];
    $text = (int) $date->format('j') . '. ' . $months[(int) $date->format('n')];
    if ($withYear) {
        $text .= ' ' . $date->format('Y');
    }
    if ($withWeekday) {
        $text = $days[(int) $date->format('N')] . ' d. ' . $text;
    }
    return $text;
}

/** Start-date options for the current Stripe mode, using config + database. */
function fb_current_start_options(bool $livemode): array
{
    $cfg = fb_config()['start_dates'] ?? [];
    return fb_start_date_options(
        fb_first_sale_at($livemode),
        new DateTimeImmutable('now'),
        fb_timezone(),
        (int) ($cfg['weeks_after_first_sale'] ?? 3),
        (int) ($cfg['options'] ?? 8)
    );
}

/** Whether the configured Stripe key is a live key. */
function fb_stripe_livemode(): bool
{
    $key = fb_config()['stripe']['secret_key'] ?? '';
    return str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_');
}
