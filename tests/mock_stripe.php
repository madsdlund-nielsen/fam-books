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
    if (($p['line_items'][0]['price'] ?? '') !== 'price_mock') {
        $json(400, ['error' => ['message' => 'No such price']]);
    }
    $id = 'cs_test_' . bin2hex(random_bytes(20));
    $db[$id] = [
        'id' => $id, 'object' => 'checkout.session', 'livemode' => false, 'mode' => $p['mode'],
        'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => 89900, 'currency' => 'dkk',
        'metadata' => $p['metadata'] ?? [], 'success_url' => $p['success_url'], 'cancel_url' => $p['cancel_url'],
        'url' => 'http://127.0.0.1:12111/pay/' . $id, 'customer' => null, 'payment_intent' => null,
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

// Fake hosted checkout page: "pay" and bounce to success_url.
if (preg_match('#^/pay/(cs_[A-Za-z0-9_]+)$#', $path, $m) && isset($db[$m[1]])) {
    $s = &$db[$m[1]];
    $s['status'] = 'complete';
    $s['payment_status'] = 'paid';
    $s['customer'] = 'cus_mock123';
    $s['payment_intent'] = 'pi_mock' . substr($m[1], -6);
    $s['customer_details'] = ['name' => 'Karen Testesen', 'email' => 'karen@example.com'];
    $save();
    header('Location: ' . str_replace('{CHECKOUT_SESSION_ID}', $m[1], $s['success_url']), true, 303);
    exit;
}

$json(404, ['error' => ['message' => 'Unknown mock route ' . $method . ' ' . $path]]);
