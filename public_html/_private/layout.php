<?php
// Shared page chrome for the PHP pages (same look as index.html).
declare(strict_types=1);

function fb_page_start(string $title): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('Referrer-Policy: same-origin');
    }
    $t = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$t} – Familiebøger</title>
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="site-header">
  <div class="wrap site-header__inner">
    <a href="./" class="brand" aria-label="Familiebøger – forside">
      <span class="brand__mark" aria-hidden="true"></span>
      <span class="brand__name">Familiebøger</span>
    </a>
  </div>
</header>
<main>

HTML;
}

function fb_page_end(): void
{
    echo <<<HTML

</main>
<footer class="site-footer">
  <div class="wrap site-footer__inner">
    <span class="site-footer__brand">Familiebøger</span>
    <small>familiebøger.dk</small>
  </div>
</footer>
</body>
</html>

HTML;
}
