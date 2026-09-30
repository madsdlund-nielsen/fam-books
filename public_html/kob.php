<?php
// "Køb Familiebøger": create a Stripe Checkout Session for the yearly
// subscription (899 kr./år) and send the buyer to it.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$cfg = fb_config();
$site = rtrim($cfg['site_url'], '/');

// Prefer the recurring Stripe Price if configured; otherwise charge the configured
// yearly amount on the product.
$stripeCfg = $cfg['stripe'];
if (!empty($stripeCfg['price_id'])) {
    $lineItem = ['price' => $stripeCfg['price_id'], 'quantity' => 1];
} elseif (!empty($stripeCfg['product_id']) && !empty($stripeCfg['amount'])) {
    $lineItem = [
        'price_data' => [
            'product' => $stripeCfg['product_id'],
            'currency' => strtolower($stripeCfg['currency'] ?? 'dkk'),
            'unit_amount' => (int) $stripeCfg['amount'],
            'recurring' => ['interval' => 'year'],
            'tax_behavior' => 'inclusive',
        ],
        'quantity' => 1,
    ];
} else {
    error_log('Familiebøger: set stripe.price_id or stripe.product_id + stripe.amount in config.php');
    fb_error_page(500, 'Betalingen kunne ikke startes', 'Siden er ikke sat helt op endnu. Prøv igen senere.');
}

try {
    $session = fb_stripe_request('POST', '/v1/checkout/sessions', [
        'mode' => 'subscription',
        'line_items' => [$lineItem],
        'success_url' => $site . '/tak.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $site . '/#pris',
        'locale' => 'da',
        'billing_address_collection' => 'auto',
        'metadata' => ['product' => 'familieboger'],
        'subscription_data' => [
            'description' => 'Familiebøger – årligt abonnement',
            'metadata' => ['product' => 'familieboger'],
        ],
        'custom_text' => [
            'submit' => ['message' => 'Abonnementet fornyes automatisk hvert år til 899 kr., indtil du opsiger det. Du kan opsige når som helst med virkning fra næste periode; betalte perioder refunderes ikke. Efter betalingen vælger du startdato og fortæller os, hvem der skal have spørgsmålene.'],
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
