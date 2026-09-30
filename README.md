# Familiebøger

Validation landing page for Familiebøger: a static HTML page with a small PHP + MySQL
backend for Stripe payments and a waitlist. Built for plain shared hosting (simply.com).
No frameworks, no build step, no Composer.

## How it works

```
index.html ──"Køb Familiebøger"──▶ kob.php ──303──▶ Stripe Checkout (card, 899 kr.)
                                                         │ paid
                                                         ▼
                           tak.php?session_id=…  ◀── redirect
                           · verifies the payment with Stripe
                           · records the order in MySQL
                           · buyer picks a start date + the family member (name,
                             relation, mail/sms, email/phone, notes)

stripe-webhook.php  ◀── Stripe (records the order even if the buyer closes the tab)
venteliste.php      ◀── waitlist form on index.html (fetch, or plain POST without JS)
startdato.php       ◀── index.html shows "Første mulige start: …" under the price
```

**Start dates**: the earliest start is the Monday 3 weeks after the week of the
**first-ever sale**. Before any sale it's 3 weeks after the current week, so it rolls
forward each week. Once that Monday has passed, the earliest start is the next upcoming
Monday. Buyers choose between 8 Mondays from the earliest start onward. Test-mode sales
never affect the live anchor. Change the numbers in `config.php` → `start_dates`.

## Files

| Path | What |
|---|---|
| `public_html/` | **Upload the contents of this folder** to the webroot on simply.com |
| `public_html/index.html` | The landing page (static) |
| `public_html/assets/` | CSS, JS (optional enhancements), self-hosted fonts, images |
| `public_html/*.php` | Checkout, thank-you/onboarding, waitlist, webhook, next start date |
| `public_html/_private/` | Config and shared PHP code. Blocked from the web by `.htaccess` |
| `database/schema.sql` | MySQL tables: `orders`, `waitlist`. Do not upload |
| `tests/` | Local end-to-end tests with a mock Stripe. Do not upload |

## Deploying to simply.com

1. **Database**: in the simply.com control panel, create a MySQL database. Open phpMyAdmin
   and import `database/schema.sql`.
2. **Config**: copy `public_html/_private/config.sample.php` to
   `public_html/_private/config.php` and fill in the database details, `site_url`, the
   Stripe secret key, product ID and webhook secret. `config.php` is git-ignored, so never commit it.
3. **Upload** everything inside `public_html/` (including `_private/` with its `.htaccess`)
   to the site's webroot over SFTP/FTP.
4. **HTTPS**: make sure the site runs on https (simply.com provides free certificates).
5. **Check**: open `https://<site>/_private/config.php` in a browser. It must be blank or
   return 403. Then open the landing page: "Første mulige start: …" should appear under the price.

### Stripe setup

1. Product: **Familiebøger – 12 måneders forløb** (`prod_VM9Qxqvr80ceiw`), price
   `price_1ULR7bECicFapRox9jg8nyAh`. Checkout uses `stripe.price_id` when it is set.
   If you empty it, Checkout charges `stripe.amount` (89900 øre = 899 kr., VAT included)
   on `stripe.product_id` instead. Product and price IDs differ between test and live
   mode, so the IDs must match the mode of `secret_key`.
2. API key: Developers → API keys. Use `sk_test_…` while testing. For live, a restricted
   key (`rk_live_…`) with **Checkout Sessions: Write** is enough.
3. Webhook: Developers → Webhooks → add endpoint `https://<site>/stripe-webhook.php` with
   events `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
   Put the signing secret (`whsec_…`) in `config.php`.
4. Settings → Emails: turn on **successful payment receipts** so buyers get a receipt.
5. Settings → Payment methods: cards are on by default. MobilePay can be enabled there
   too; Checkout picks it up automatically.
6. Test the whole flow with test card `4242 4242 4242 4242`, then switch to live keys.

## Looking at the data (phpMyAdmin)

```sql
-- Funnel: how many clicked "Køb" vs. paid
SELECT status, COUNT(*) FROM orders WHERE livemode = 1 GROUP BY status;

-- Paid orders with onboarding details
SELECT paid_at, buyer_name, buyer_email, start_date, recipient_name, recipient_relation,
       recipient_channel, recipient_email, recipient_phone, notes
FROM orders WHERE status = 'paid' AND livemode = 1 ORDER BY start_date, paid_at;

-- Paid but never filled in the details (follow up by mail)
SELECT buyer_email, paid_at FROM orders
WHERE status = 'paid' AND livemode = 1 AND details_completed_at IS NULL;

-- Waitlist
SELECT created_at, name, email FROM waitlist ORDER BY created_at;
```

## Photos

The two image spots show illustrations (a closed book and an open book spread) until
you add photos. Put `hero.jpg` (4:5) and/or `bog.jpg` (1:1) in `public_html/assets/img/`
and uncomment the `<img>` tag next to each illustration in `index.html`.

## Before launch

- Footer links (Kontakt, Handelsbetingelser, Privatliv) still point to `#`. Danish law
  requires terms of sale, including the 14-day right of withdrawal, and a privacy policy
  when you sell online and collect personal data.
- Do a real live-mode purchase and refund it.

## Local testing

Requires PHP 8, MariaDB/MySQL and Playwright (`npm i -g playwright`).

```sh
mysql -e "CREATE DATABASE familieboger_test; CREATE USER 'fb'@'127.0.0.1' IDENTIFIED BY 'fbpass';
          GRANT ALL ON familieboger_test.* TO 'fb'@'127.0.0.1';"
tests/run.sh
```

`run.sh` starts the site on `http://127.0.0.1:8080` with `tests/config.test.php` and a mock
Stripe API on port 12111. It then runs the start-date unit tests and a browser test of the
waitlist, checkout, onboarding form and webhook.
