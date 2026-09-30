<?php
// Tiny stand-in for the Stripe API, for local end-to-end tests only.
// Run: php -S 127.0.0.1:12111 tests/mock_stripe.php
declare(strict_types=1);

$store = sys_get_temp_dir() . '/fb-mock-stripe.json';
$db = is_file($store) ? json_decode((string) file_get_contents($store), true) : [];
$save = function () use (&$db, $store) { file_put_contents($store, json_encode($db)); };
$json = function (int $status, array $data) { http_response_code($status); header('Content-Type: application/json'); echo json_encode($data); exit; };

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($path, '/v1/') && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk_test_mock') {
    $json(401, ['error' => ['message' => 'Invalid API Key provided']]);
}

if ($method === 'POST' && $path === '/v1/checkout/sessions') {
    parse_str((string) file_get_contents('php://input'), $p);
    // Mirror the Stripe validations that matter for a yearly subscription.
    if (($p['mode'] ?? '') === 'subscription') {
        foreach (['customer_creation', 'payment_intent_data', 'submit_type'] as $paymentOnly) {
            if (isset($p[$paymentOnly])) {
                $json(400, ['error' => ['message' => "`$paymentOnly` can only be used in payment mode"]]);
            }
        }
    }
    $item = $p['line_items'][0] ?? [];
    if (isset($item['price'])) {
        if ($item['price'] !== 'price_mock') {
            $json(400, ['error' => ['message' => 'No such price']]);
        }
        $recurring = true; // price_mock is 899 DKK / year, like the real price
        $amount = 89900;
    } elseif (($item['price_data']['product'] ?? '') === 'prod_mock') {
        $recurring = isset($item['price_data']['recurring']['interval']);
        $amount = (int) $item['price_data']['unit_amount'];
    } else {
        $json(400, ['error' => ['message' => 'No such product']]);
    }
    if ($recurring && ($p['mode'] ?? '') !== 'subscription') {
        $json(400, ['error' => ['message' => 'You specified `payment` mode but passed a recurring price.']]);
    }
    if (!$recurring && ($p['mode'] ?? '') === 'subscription') {
        $json(400, ['error' => ['message' => 'You must provide at least one recurring price in `subscription` mode.']]);
    }
    $id = 'cs_test_' . bin2hex(random_bytes(20));
    $db[$id] = [
        'id' => $id, 'object' => 'checkout.session', 'livemode' => false, 'mode' => $p['mode'],
        'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => $amount, 'currency' => 'dkk',
        'metadata' => $p['metadata'] ?? [], 'success_url' => $p['success_url'], 'cancel_url' => $p['cancel_url'],
        'url' => 'http://127.0.0.1:12111/pay/' . $id, 'customer' => null, 'payment_intent' => null, 'subscription' => null,
        'customer_details' => null, 'params' => $p,
    ];
    $save();
    $json(200, $db[$id]);
}

if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $path, $m)) {
    if (!isset($db[$m[1]])) {
        $json(404, ['error' => ['message' => 'No such checkout.session']]);
    }
    $json(200, $db[$m[1]]);
}

if ($method === 'POST' && $path === '/v1/billing_portal/sessions') {
    parse_str((string) file_get_contents('php://input'), $p);
    if (($p['customer'] ?? '') !== 'cus_mock123' || empty($p['return_url'])) {
        $json(400, ['error' => ['message' => 'No such customer']]);
    }
    $db['_last_portal'] = $p;
    $save();
    $json(200, ['id' => 'bps_mock', 'object' => 'billing_portal.session', 'url' => 'http://127.0.0.1:12111/portal']);
}

if ($path === '/portal') {
    header('Content-Type: text/html');
    echo '<h1>Mock customer portal</h1>';
    exit;
}

// Fake hosted checkout page: "pay" and bounce to success_url.
if (preg_match('#^/pay/(cs_[A-Za-z0-9_]+)$#', $path, $m) && isset($db[$m[1]])) {
    $s = &$db[$m[1]];
    $s['status'] = 'complete';
    $s['payment_status'] = 'paid';
    $s['customer'] = 'cus_mock123';
    if ($s['mode'] === 'subscription') {
        $s['subscription'] = 'sub_mock' . substr($m[1], -6);
    } else {
        $s['payment_intent'] = 'pi_mock' . substr($m[1], -6);
    }
    $s['customer_details'] = ['name' => 'Karen Testesen', 'email' => 'karen@example.com'];
    $save();
    header('Location: ' . str_replace('{CHECKOUT_SESSION_ID}', $m[1], $s['success_url']), true, 303);
    exit;
}

$json(404, ['error' => ['message' => 'Unknown mock route ' . $method . ' ' . $path]]);
