<?php
// Stripe sends the buyer here after payment. We verify the payment, record the
// order, and ask for the start date and the family member who will answer.
declare(strict_types=1);
require __DIR__ . '/_private/bootstrap.php';

const FB_RELATIONS = [
    'mor' => 'Mor', 'far' => 'Far',
    'mormor' => 'Mormor', 'morfar' => 'Morfar',
    'farmor' => 'Farmor', 'farfar' => 'Farfar',
    'andet' => 'Andet',
];
const FB_CHANNELS = ['email' => 'Mail', 'sms' => 'Sms'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sessionId = (string) ($method === 'POST' ? ($_POST['session_id'] ?? '') : ($_GET['session_id'] ?? ''));

if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,250}$/', $sessionId)) {
    fb_error_page(404, 'Vi kan ikke finde din bestilling', 'Linket ser ikke helt rigtigt ud. Brug linket fra betalingssiden, eller skriv til os.');
}

// 1. Make sure the order is paid (the webhook may already have recorded it).
$order = fb_order_find($sessionId);
if (!$order || $order['status'] !== 'paid') {
    try {
        $session = fb_stripe_request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));
    } catch (FbStripeError $e) {
        if ($e->httpStatus === 404) {
            fb_error_page(404, 'Vi kan ikke finde din bestilling', 'Linket ser ikke helt rigtigt ud. Brug linket fra betalingssiden, eller skriv til os.');
        }
        error_log('Familiebøger: could not retrieve Checkout Session: ' . $e->getMessage());
        fb_error_page(502, 'Vi kunne ikke tjekke din betaling', 'Prøv at genindlæse siden om et øjeblik.', 'tak.php?session_id=' . rawurlencode($sessionId), 'Prøv igen');
    }
    if (!fb_session_is_paid($session)) {
        fb_error_page(200, 'Betalingen er ikke gennemført', 'Vi har ikke modtaget betalingen. Hvis du lige har betalt, så genindlæs siden om et øjeblik.', './#pris', 'Tilbage til prisen');
    }
    fb_order_record_paid($session);
    $order = fb_order_find($sessionId);
}

// 2. Which start dates can be chosen right now?
$zone = fb_timezone();
$today = (new DateTimeImmutable('now', $zone))->setTime(0, 0);
$options = [];
foreach (fb_current_start_options((bool) $order['livemode']) as $monday) {
    $options[$monday->format('Y-m-d')] = $monday;
}
$saved = $order['start_date'] ? new DateTimeImmutable($order['start_date'], $zone) : null;
$started = $saved !== null && $saved <= $today && $order['details_completed_at'] !== null;
if ($saved !== null && $saved > $today && !isset($options[$order['start_date']])) {
    // Keep a previously chosen future date selectable when editing.
    $options = [$order['start_date'] => $saved] + $options;
    ksort($options);
}

$values = [
    'start_date' => $order['start_date'] ?? array_key_first($options),
    'recipient_name' => $order['recipient_name'] ?? '',
    'recipient_relation' => $order['recipient_relation'] ?? '',
    'recipient_channel' => $order['recipient_channel'] ?? 'email',
    'recipient_email' => $order['recipient_email'] ?? '',
    'recipient_phone' => $order['recipient_phone'] ?? '',
    'notes' => $order['notes'] ?? '',
];
$errors = [];

// 3. Save the form.
if ($method === 'POST' && !$started) {
    $values = [
        'start_date' => trim((string) ($_POST['start_date'] ?? '')),
        'recipient_name' => trim((string) ($_POST['recipient_name'] ?? '')),
        'recipient_relation' => trim((string) ($_POST['recipient_relation'] ?? '')),
        'recipient_channel' => trim((string) ($_POST['recipient_channel'] ?? '')),
        'recipient_email' => trim((string) ($_POST['recipient_email'] ?? '')),
        'recipient_phone' => trim((string) ($_POST['recipient_phone'] ?? '')),
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ];

    if (!isset($options[$values['start_date']])) {
        $errors['start_date'] = 'Vælg en af startdatoerne.';
    }
    if ($values['recipient_name'] === '' || mb_strlen($values['recipient_name']) > 190) {
        $errors['recipient_name'] = 'Skriv navnet på den, der skal fortælle.';
    }
    if (!isset(FB_RELATIONS[$values['recipient_relation']])) {
        $errors['recipient_relation'] = 'Vælg hvem det er.';
    }
    if (!isset(FB_CHANNELS[$values['recipient_channel']])) {
        $errors['recipient_channel'] = 'Vælg mail eller sms.';
    }

    if ($values['recipient_email'] !== '') {
        if (mb_strlen($values['recipient_email']) > 190 || !filter_var($values['recipient_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['recipient_email'] = 'Mailadressen ser ikke rigtig ud.';
        }
    } elseif ($values['recipient_channel'] === 'email') {
        $errors['recipient_email'] = 'Skriv mailadressen, spørgsmålene skal sendes til.';
    }

    if ($values['recipient_phone'] !== '') {
        $phone = preg_replace('/[\s\-().]/', '', $values['recipient_phone']);
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        } elseif (preg_match('/^\d{8}$/', $phone)) {
            $phone = '+45' . $phone;
        }
        if (!preg_match('/^\+\d{8,15}$/', $phone)) {
            $errors['recipient_phone'] = 'Skriv et gyldigt mobilnummer, fx 12 34 56 78.';
        } else {
            $values['recipient_phone'] = $phone;
        }
    } elseif ($values['recipient_channel'] === 'sms') {
        $errors['recipient_phone'] = 'Skriv mobilnummeret, spørgsmålene skal sendes til.';
    }

    if (mb_strlen($values['notes']) > 2000) {
        $errors['notes'] = 'Hold venligst bemærkningen under 2000 tegn.';
    }

    if ($errors === []) {
        fb_order_save_details((int) $order['id'], $values);
        fb_redirect('tak.php?session_id=' . rawurlencode($sessionId) . '&gemt=1');
    }
}

$showForm = !$started && ($errors !== [] || $order['details_completed_at'] === null || isset($_GET['ret']));

function fb_field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="field-error" id="err-' . h($key) . '">' . h($errors[$key]) . '</span>' : '';
}

function fb_invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="err-' . h($key) . '"' : '';
}

function fb_money(?int $amount, ?string $currency): string
{
    if ($amount === null) {
        return '';
    }
    $text = number_format($amount / 100, $amount % 100 ? 2 : 0, ',', '.');
    return $currency === 'DKK' || $currency === null ? $text . ' kr.' : $text . ' ' . $currency;
}

$recipientFirstName = $values['recipient_name'] !== '' ? explode(' ', $values['recipient_name'])[0] : '';
$link = 'tak.php?session_id=' . rawurlencode($sessionId);

fb_page_start($showForm ? 'Tak for dit køb' : 'Alt er på plads');
?>
<section class="wrap flow">
  <div class="flow__inner">
<?php if ($showForm): ?>
    <span class="eyebrow">Betalingen er gennemført</span>
    <h1 class="flow__title">Tak for dit køb!</h1>
    <p class="lead">Nu mangler vi bare to ting: hvornår I vil starte, og hvem der skal fortælle.</p>

    <?php if ($errors): ?>
      <div class="notice notice--error" role="alert">Tjek venligst de markerede felter nedenfor.</div>
    <?php endif; ?>

    <form method="post" action="tak.php" class="panel form" novalidate>
      <input type="hidden" name="session_id" value="<?= h($sessionId) ?>">

      <fieldset>
        <legend><h2>1. Hvornår skal I starte?</h2></legend>
        <p class="hint">Det første spørgsmål sendes mandag i den uge, I vælger. Derefter kommer der et nyt hver uge i 12 måneder.</p>
        <div class="radio-cards" role="radiogroup">
          <?php foreach ($options as $value => $monday): ?>
            <label class="radio-card">
              <input type="radio" name="start_date" value="<?= h($value) ?>" <?= $values['start_date'] === $value ? 'checked' : '' ?> required>
              <span><?= h(ucfirst(fb_format_date_da($monday, false, true))) ?><small>Uge <?= (int) $monday->format('W') ?> · <?= h($monday->format('Y')) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>
        <?= fb_field_error($errors, 'start_date') ?>
      </fieldset>

      <hr class="divider">

      <fieldset>
        <legend><h2>2. Hvem skal fortælle?</h2></legend>
        <div class="form-row">
          <div class="field">
            <label for="recipient_name">Navn</label>
            <input class="input" id="recipient_name" name="recipient_name" type="text" maxlength="190" autocomplete="off" required value="<?= h($values['recipient_name']) ?>"<?= fb_invalid($errors, 'recipient_name') ?>>
            <?= fb_field_error($errors, 'recipient_name') ?>
          </div>
          <div class="field">
            <label for="recipient_relation">Hvem er det for jer?</label>
            <select class="select" id="recipient_relation" name="recipient_relation" required<?= fb_invalid($errors, 'recipient_relation') ?>>
              <option value="">Vælg …</option>
              <?php foreach (FB_RELATIONS as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $values['recipient_relation'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= fb_field_error($errors, 'recipient_relation') ?>
          </div>
        </div>

        <div class="field">
          <span class="field-label" id="channel-label"><b>Hvordan skal spørgsmålene sendes?</b></span>
          <div class="radio-cards" role="radiogroup" aria-labelledby="channel-label">
            <label class="radio-card">
              <input type="radio" name="recipient_channel" value="email" <?= $values['recipient_channel'] === 'email' ? 'checked' : '' ?>>
              <span>På mail<small>Svar ved at trykke "svar"</small></span>
            </label>
            <label class="radio-card">
              <input type="radio" name="recipient_channel" value="sms" <?= $values['recipient_channel'] === 'sms' ? 'checked' : '' ?>>
              <span>På sms<small>Svar direkte fra telefonen</small></span>
            </label>
          </div>
          <?= fb_field_error($errors, 'recipient_channel') ?>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="recipient_email">E-mail <span class="hint">(skal udfyldes ved mail)</span></label>
            <input class="input" id="recipient_email" name="recipient_email" type="email" maxlength="190" autocomplete="off" value="<?= h($values['recipient_email']) ?>"<?= fb_invalid($errors, 'recipient_email') ?>>
            <?= fb_field_error($errors, 'recipient_email') ?>
          </div>
          <div class="field">
            <label for="recipient_phone">Mobilnummer <span class="hint">(skal udfyldes ved sms)</span></label>
            <input class="input" id="recipient_phone" name="recipient_phone" type="tel" maxlength="40" autocomplete="off" value="<?= h($values['recipient_phone']) ?>"<?= fb_invalid($errors, 'recipient_phone') ?>>
            <?= fb_field_error($errors, 'recipient_phone') ?>
          </div>
        </div>
        <p class="form-small">Vi skriver først til fortælleren på startdatoen, så I kan nå at overrække gaven.</p>
      </fieldset>

      <hr class="divider">

      <div class="field">
        <label for="notes">Er der noget, vi skal vide? <span class="hint">(valgfrit)</span></label>
        <textarea class="textarea" id="notes" name="notes" maxlength="2000" placeholder="Fx om det er en overraskelse, eller emner vi skal springe over."<?= fb_invalid($errors, 'notes') ?>><?= h($values['notes']) ?></textarea>
        <?= fb_field_error($errors, 'notes') ?>
      </div>

      <div><button type="submit" class="btn btn--primary">Gem og afslut</button></div>
      <p class="form-small">Gem gerne linket til denne side. Her kan du rette oplysningerne frem til startdatoen.</p>
    </form>

<?php else:
    $start = new DateTimeImmutable($order['start_date'], $zone);
    $channelText = $order['recipient_channel'] === 'sms'
        ? 'sms (' . $order['recipient_phone'] . ')'
        : 'mail (' . $order['recipient_email'] . ')';
?>
    <?php if (isset($_GET['gemt'])): ?>
      <div class="notice notice--ok" role="status">Oplysningerne er gemt.</div>
    <?php endif; ?>
    <span class="eyebrow">Alt er på plads</span>
    <h1 class="flow__title">Nu glæder vi os til at høre historierne.</h1>
    <p class="lead">
      <?php if ($started): ?>
        Forløbet startede <?= h(fb_format_date_da($start)) ?>.
      <?php else: ?>
        Vi sender det første spørgsmål til <?= h($recipientFirstName) ?> <?= h(fb_format_date_da($start)) ?> på <?= $order['recipient_channel'] === 'sms' ? 'sms' : 'mail' ?>.
      <?php endif; ?>
    </p>

    <div class="panel">
      <h2>Din bestilling</h2>
      <dl class="summary-list">
        <dt>Startdato</dt><dd><?= h(ucfirst(fb_format_date_da($start))) ?> (uge <?= (int) $start->format('W') ?>)</dd>
        <dt>Fortæller</dt><dd><?= h($order['recipient_name']) ?> · <?= h(FB_RELATIONS[$order['recipient_relation']] ?? $order['recipient_relation']) ?></dd>
        <dt>Spørgsmål på</dt><dd><?= h($channelText) ?></dd>
        <?php if ($order['buyer_email']): ?>
          <dt>Købt af</dt><dd><?= h(trim(($order['buyer_name'] ?? '') . ' · ' . $order['buyer_email'], ' ·')) ?></dd>
        <?php endif; ?>
        <?php if ($order['amount_total'] !== null): ?>
          <dt>Abonnement</dt><dd><?= h(fb_money((int) $order['amount_total'], $order['currency'])) ?> om året · fornyes automatisk, kan opsiges når som helst</dd>
        <?php endif; ?>
        <?php if ($order['notes']): ?>
          <dt>Bemærkninger</dt><dd><?= nl2br(h($order['notes'])) ?></dd>
        <?php endif; ?>
      </dl>
      <div class="panel__actions">
        <?php if (!$started): ?>
          <a class="link-underline" href="<?= h($link) ?>&amp;ret=1">Ret oplysninger</a>
        <?php endif; ?>
        <?php if ($order['stripe_customer_id']): ?>
          <form method="post" action="abonnement.php">
            <input type="hidden" name="session_id" value="<?= h($sessionId) ?>">
            <button type="submit" class="link-underline link-button">Administrér abonnement</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <p><a class="btn btn--primary" href="./">Til forsiden</a></p>
<?php endif; ?>
  </div>
</section>
<?php
fb_page_end();
