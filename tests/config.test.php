<?php
// Config used by tests/run.sh (points at local MariaDB + tests/mock_stripe.php).
return [
    'site_url' => 'http://127.0.0.1:8080',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'familieboger_test', 'user' => 'fb', 'pass' => 'fbpass'],
    'stripe' => [
        'secret_key' => 'sk_test_mock',
        'product_id' => 'prod_mock',
        'amount' => 89900,
        'currency' => 'dkk',
        'price_id' => '',
        'webhook_secret' => 'whsec_test_secret',
        'api_base' => 'http://127.0.0.1:12111',
    ],
    'start_dates' => ['weeks_after_first_sale' => 3, 'options' => 8],
    'timezone' => 'Europe/Copenhagen',
    'ip_salt' => 'test-salt',
    'debug' => true,
];
