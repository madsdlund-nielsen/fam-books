<?php
// Waitlist signup (POST from the landing page; JSON for fetch, HTML otherwise).
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

const FB_WAITLIST_CONSENT = 'Vi bruger kun din e-mail til at give dig besked om Familiebøger. Du kan altid blive slettet igen ved at svare på en af vores mails.';

function fb_waitlist_respond(int $status, bool $ok, string $message): void
{
    if (fb_wants_json()) {
        fb_json($status, ['ok' => $ok, 'message' => $message]);
    }
    if ($ok) {
        fb_error_page($status, 'Tak!', $message, './', 'Tilbage til forsiden');
    }
    fb_error_page($status, 'Hov', $message, './#venteliste', 'Prøv igen');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fb_redirect('./#venteliste');
}

$okMessage = 'Tak! Du er nu på ventelisten, og vi giver dig besked, når der er nyt.';

// Bots fill in the hidden field; pretend it worked.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    fb_waitlist_respond(200, true, $okMessage);
}

$email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
$name = trim((string) ($_POST['name'] ?? ''));
$source = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_POST['source'] ?? 'landing')));

if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fb_waitlist_respond(422, false, 'Skriv en gyldig e-mailadresse.');
}
$name = mb_substr($name, 0, 120);
$source = substr($source ?: 'landing', 0, 60);

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$ipHash = $ip !== '' ? hash('sha256', $ip . (fb_config()['ip_salt'] ?? '')) : null;

$stmt = fb_db()->prepare(
    'INSERT INTO waitlist (email, name, source, consent, ip_hash) VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = COALESCE(VALUES(name), name), updated_at = CURRENT_TIMESTAMP'
);
$stmt->execute([$email, $name !== '' ? $name : null, $source, FB_WAITLIST_CONSENT, $ipHash]);

fb_waitlist_respond(200, true, $okMessage);
