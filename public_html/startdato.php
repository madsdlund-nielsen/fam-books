<?php
// Next possible start date, shown under the price on the landing page.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

$first = fb_current_start_options(fb_stripe_livemode())[0];

header('Cache-Control: public, max-age=300');
fb_json(200, [
    'date' => $first->format('Y-m-d'),
    'week' => (int) $first->format('W'),
    'label' => fb_format_date_da($first, false),
]);
