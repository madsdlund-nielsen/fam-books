<?php
// "Administrér abonnement": open the Stripe customer portal for a paid order,
// where the buyer can cancel or update their card.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fb_redirect('./');
}

$sessionId = (string) ($_POST['session_id'] ?? '');
$order = preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,250}$/', $sessionId) ? fb_order_find($sessionId) : null;
if (!$order || $order['status'] !== 'paid' || !$order['stripe_customer_id']) {
    fb_error_page(404, 'Vi kan ikke finde dit abonnement', 'Brug linket fra din bekræftelse, eller skriv til os, så hjælper vi.');
}

$back = 'tak.php?session_id=' . rawurlencode($sessionId);
try {
    $portal = fb_stripe_request('POST', '/v1/billing_portal/sessions', [
        'customer' => $order['stripe_customer_id'],
        'return_url' => rtrim(fb_config()['site_url'], '/') . '/' . $back,
    ]);
} catch (FbStripeError $e) {
    error_log('Familiebøger: could not create billing portal session: ' . $e->getMessage());
    fb_error_page(502, 'Vi kunne ikke åbne dit abonnement', 'Prøv igen om lidt, eller skriv til os, så hjælper vi.', $back, 'Tilbage');
}

fb_redirect($portal['url']);
