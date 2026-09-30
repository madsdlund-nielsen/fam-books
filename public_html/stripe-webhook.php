<?php
// Stripe webhook: records paid orders even if the buyer never reaches tak.php.
// Stripe → Udviklere → Webhooks → endpoint https://<site>/stripe-webhook.php with events
// checkout.session.completed and checkout.session.async_payment_succeeded.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$secret = (string) (fb_config()['stripe']['webhook_secret'] ?? '');
if ($secret === '') {
    error_log('Familiebøger: webhook called but stripe.webhook_secret is not configured');
    fb_json(503, ['error' => 'Webhook not configured']);
}

$payload = (string) file_get_contents('php://input');
try {
    $event = fb_stripe_verify_webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret);
} catch (FbStripeError $e) {
    fb_json(400, ['error' => $e->getMessage()]);
}

$type = $event['type'] ?? '';
if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
    $session = $event['data']['object'] ?? [];
    if (fb_session_is_paid($session)) {
        // A database error throws → HTTP 500 → Stripe retries later.
        fb_order_record_paid($session);
    }
}

fb_json(200, ['received' => true]);
