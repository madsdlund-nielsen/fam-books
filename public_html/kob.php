<?php
// "Køb Familiebøger": create a Stripe Checkout Session and send the buyer to it.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$cfg = fb_config();
$site = rtrim($cfg['site_url'], '/');

try {
    $session = fb_stripe_request('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'line_items' => [['price' => $cfg['stripe']['price_id'], 'quantity' => 1]],
        'success_url' => $site . '/tak.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $site . '/#pris',
        'locale' => 'da',
        'customer_creation' => 'always',
        'billing_address_collection' => 'auto',
        'metadata' => ['product' => 'familieboger'],
        'payment_intent_data' => [
            'description' => 'Familiebøger – 12 måneders forløb',
            'metadata' => ['product' => 'familieboger'],
        ],
        'custom_text' => [
            'submit' => ['message' => 'Efter betalingen vælger du startdato og fortæller os, hvem der skal have spørgsmålene.'],
        ],
    ]);
} catch (FbStripeError $e) {
    error_log('Familiebøger: could not create Checkout Session: ' . $e->getMessage());
    fb_error_page(502, 'Betalingen kunne ikke startes', 'Der skete en fejl hos vores betalingsudbyder. Prøv igen om lidt.');
}

// Funnel tracking must never block a sale.
try {
    fb_order_mark_started($session['id'], !empty($session['livemode']));
} catch (Throwable $e) {
    error_log('Familiebøger: could not record checkout start: ' . $e->getMessage());
}

fb_redirect($session['url']);
