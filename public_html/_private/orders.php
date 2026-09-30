<?php
// Orders table access.
declare(strict_types=1);

/** Record that a buyer was sent to Stripe Checkout (for funnel numbers). */
function fb_order_mark_started(string $sessionId, bool $livemode): void
{
    $stmt = fb_db()->prepare(
        "INSERT IGNORE INTO orders (stripe_session_id, status, livemode) VALUES (?, 'started', ?)"
    );
    $stmt->execute([$sessionId, $livemode ? 1 : 0]);
}

/** Upsert a paid Checkout Session. Safe to call repeatedly (tak.php + webhook). */
function fb_order_record_paid(array $session): void
{
    $paymentIntent = $session['payment_intent'] ?? null;
    if (is_array($paymentIntent)) {
        $paymentIntent = $paymentIntent['id'] ?? null;
    }
    $customer = $session['customer'] ?? null;
    if (is_array($customer)) {
        $customer = $customer['id'] ?? null;
    }
    $details = $session['customer_details'] ?? [];

    $stmt = fb_db()->prepare(
        "INSERT INTO orders
            (stripe_session_id, status, livemode, amount_total, currency,
             stripe_payment_intent, stripe_customer_id, buyer_name, buyer_email, paid_at)
         VALUES (?, 'paid', ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = 'paid',
            livemode = VALUES(livemode),
            amount_total = VALUES(amount_total),
            currency = VALUES(currency),
            stripe_payment_intent = COALESCE(VALUES(stripe_payment_intent), stripe_payment_intent),
            stripe_customer_id = COALESCE(VALUES(stripe_customer_id), stripe_customer_id),
            buyer_name = COALESCE(VALUES(buyer_name), buyer_name),
            buyer_email = COALESCE(VALUES(buyer_email), buyer_email),
            paid_at = COALESCE(paid_at, VALUES(paid_at))"
    );
    $stmt->execute([
        $session['id'],
        !empty($session['livemode']) ? 1 : 0,
        isset($session['amount_total']) ? (int) $session['amount_total'] : null,
        isset($session['currency']) ? strtoupper((string) $session['currency']) : null,
        $paymentIntent,
        $customer,
        $details['name'] ?? null,
        $details['email'] ?? null,
        fb_utc_now(),
    ]);
}

function fb_order_find(string $sessionId): ?array
{
    $stmt = fb_db()->prepare('SELECT * FROM orders WHERE stripe_session_id = ?');
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fb_order_save_details(int $orderId, array $d): void
{
    $stmt = fb_db()->prepare(
        "UPDATE orders SET
            start_date = ?, recipient_name = ?, recipient_relation = ?, recipient_channel = ?,
            recipient_email = ?, recipient_phone = ?, notes = ?, details_completed_at = ?
         WHERE id = ? AND status = 'paid'"
    );
    $stmt->execute([
        $d['start_date'],
        $d['recipient_name'],
        $d['recipient_relation'],
        $d['recipient_channel'],
        $d['recipient_email'] !== '' ? $d['recipient_email'] : null,
        $d['recipient_phone'] !== '' ? $d['recipient_phone'] : null,
        $d['notes'] !== '' ? $d['notes'] : null,
        fb_utc_now(),
        $orderId,
    ]);
}

/** Time of the first-ever paid order in this Stripe mode (test sales never move the live anchor). */
function fb_first_sale_at(bool $livemode): ?DateTimeImmutable
{
    $stmt = fb_db()->prepare("SELECT MIN(paid_at) FROM orders WHERE status = 'paid' AND livemode = ?");
    $stmt->execute([$livemode ? 1 : 0]);
    $value = $stmt->fetchColumn();
    return $value ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
}
