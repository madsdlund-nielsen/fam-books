<?php
// Minimal Stripe API client (no Composer needed on shared hosting).
declare(strict_types=1);

final class FbStripeError extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Call the Stripe REST API. $params are form-encoded the way Stripe expects
 * (nested arrays become line_items[0][price]=...). Use strings, not booleans.
 */
function fb_stripe_request(string $method, string $path, array $params = []): array
{
    $cfg = fb_config()['stripe'];
    $url = rtrim($cfg['api_base'] ?? 'https://api.stripe.com', '/') . $path;
    $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    if ($method === 'GET' && $body !== '') {
        $url .= '?' . $body;
        $body = '';
    }
    $headers = [
        'Authorization: Bearer ' . $cfg['secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Familieboger/1.0',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new FbStripeError('Stripe request failed: ' . $err, 0);
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($raw === false) {
            throw new FbStripeError('Stripe request failed', 0);
        }
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new FbStripeError('Unexpected response from Stripe (HTTP ' . $status . ')', $status);
    }
    if ($status >= 400) {
        throw new FbStripeError($data['error']['message'] ?? ('Stripe error HTTP ' . $status), $status);
    }
    return $data;
}

/**
 * Verify a webhook's Stripe-Signature header and return the decoded event.
 * https://docs.stripe.com/webhooks#verify-manually
 */
function fb_stripe_verify_webhook(string $payload, string $header, string $secret, int $tolerance = 300): array
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $timestamp = (int) $kv[1];
        } elseif ($kv[0] === 'v1') {
            $signatures[] = $kv[1];
        }
    }
    if ($timestamp === null || $signatures === []) {
        throw new FbStripeError('Missing webhook signature', 400);
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $valid = false;
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            $valid = true;
        }
    }
    if (!$valid) {
        throw new FbStripeError('Invalid webhook signature', 400);
    }
    if (abs(time() - $timestamp) > $tolerance) {
        throw new FbStripeError('Webhook timestamp outside tolerance', 400);
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        throw new FbStripeError('Invalid webhook payload', 400);
    }
    return $event;
}

/** True when a Checkout Session is one of ours and the money is captured. */
function fb_session_is_paid(array $session): bool
{
    return ($session['object'] ?? '') === 'checkout.session'
        && ($session['status'] ?? '') === 'complete'
        && ($session['payment_status'] ?? '') === 'paid'
        && ($session['metadata']['product'] ?? '') === 'familieboger';
}
