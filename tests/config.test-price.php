<?php
// Same as config.test.php, but with a fixed Stripe Price configured.
$config = require __DIR__ . '/config.test.php';
$config['stripe']['price_id'] = 'price_mock';
return $config;
