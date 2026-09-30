# Familiebøger

Validation landing page for Familiebøger: a static HTML page with a small PHP + MySQL
backend for a yearly Stripe subscription (899 kr./år) and a waitlist. Built for plain shared hosting (simply.com).
No frameworks, no build step, no Composer.

## How it works

```
index.html ──"Køb Familiebøger"──▶ kob.php ──303──▶ Stripe Checkout (subscription, 899 kr./år)
                                                         │ paid
                                                         ▼
                           tak.php?session_id=…  ◀── redirect
                           · verifies the payment with Stripe
                           · records the order in MySQL
                           · buyer picks a start date + the family member (name,
                             relation, mail/sms, email/phone, notes)
                           · "Administrér abonnement" → abonnement.php → Stripe
                             customer portal (cancel, change card)

stripe-webhook.php  ◀── Stripe (records the order even if the buyer closes the tab)
venteliste.php      ◀── waitlist form on index.html (fetch, or plain POST without JS)
startdato.php       ◀── index.html shows "Første mulige start: …" under the price
```

**Subscription**: 899 kr. per year, renewing automatically. At renewal the buyer can
continue with the same family member or pick another. Cancelling stops the next renewal;
paid periods are not refunded. Buyers can opt in to marketing emails on `tak.php`
(unticked by default); only email offers to buyers where `marketing_consent_at` is set.

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
| `public_html/*.php` | Checkout, thank-you/onboarding, customer portal, waitlist, webhook, next start date |
| `public_html/_private/` | Config and shared PHP code. Blocked from the web by `.htaccess` |
| `database/schema.sql` | MySQL tables: `orders`, `waitlist`. Do not upload |
| `tests/` | Local end-to-end tests with a mock Stripe. Do not upload |

## Deploying to simply.com

1. **Database**: in the simply.com control panel, create a MySQL database. Open phpMyAdmin
   and import `database/schema.sql`.
2. **Config**: copy `public_html/_private/config.sample.php` to
   `public_html/_private/config.php` and fill in the database details, `site_url`, the
   Stripe secret key and webhook secret. `config.php` is git-ignored, so never commit it.
3. **Upload** everything inside `public_html/` (including `_private/` with its `.htaccess`)
   to the site's webroot over SFTP/FTP.
4. **HTTPS**: make sure the site runs on https (simply.com provides free certificates).
5. **Check**: open `https://<site>/_private/config.php` in a browser. It must be blank or
   return 403. Then open the landing page: "Første mulige start: …" should appear under the price.

### Stripe setup

1. Product `prod_VM9Qxqvr80ceiw` with the **recurring** price
   `price_1ULR7bECicFapRox9jg8nyAh` (899 DKK / year). Checkout runs in subscription mode
   and uses `stripe.price_id`. If you empty it, Checkout creates a yearly price of
   `stripe.amount` (89900 øre = 899 kr., VAT included) on `stripe.product_id` instead.
   Product and price IDs differ between test and live mode, so the IDs must match the
   mode of `secret_key`.
2. API key: Developers → API keys. Use `sk_test_…` while testing. For live, a restricted
   key (`rk_live_…`) with **Checkout Sessions: Write** and **Customer portal: Write** is
   enough.
3. Customer portal: Settings → Billing → Customer portal. Allow customers to **cancel
   subscriptions** with cancellation **at the end of the billing period**, turn prorations
   off (no refunds for the period already paid), and allow updating payment methods. Save
   in both test and live mode, or "Administrér abonnement" on `tak.php` fails.
4. Webhook: Developers → Webhooks → add endpoint `https://<site>/stripe-webhook.php` with
   events `checkout.session.completed` and `checkout.session.async_payment_succeeded`.
   Put the signing secret (`whsec_…`) in `config.php`.
5. Settings → Emails / Billing: turn on **successful payment receipts**, and **renewal
   reminders** for the yearly renewal, with a link to the customer portal.
6. Payment methods: in subscription mode Checkout only offers methods that support
   recurring payments, which in practice means cards.
7. Test the whole flow with test card `4242 4242 4242 4242`, then switch to live keys.

## Search engines and AI assistants

- `index.html` has a canonical URL, Open Graph/Twitter tags with `assets/img/og-image.png`
  (1200×630), and JSON-LD structured data: Organization, WebSite, WebPage, Product with
  the yearly 899 DKK offer, and FAQPage. The FAQPage text must match the visible FAQ; the
  e2e test fails if they drift apart, so update both together.
- `robots.txt` allows all crawlers (including AI crawlers) and keeps them out of the
  checkout, thank-you and API endpoints. `sitemap.xml` lists the landing page.
- `llms.txt` is a plain-language summary of the product for AI assistants. Keep it in line
  with the page.
- After launch: add the site in Google Search Console and submit
  `https://xn--familiebger-ngb.dk/sitemap.xml`, then check the page with Google's Rich
  Results Test.

## Looking at the data (phpMyAdmin)

```sql
-- Funnel: how many clicked "Køb" vs. paid
SELECT status, COUNT(*) FROM orders WHERE livemode = 1 GROUP BY status;

-- Paid orders with onboarding details
SELECT paid_at, buyer_name, buyer_email, start_date, recipient_name, recipient_relation,
       recipient_channel, recipient_email, recipient_phone, notes
FROM orders WHERE status = 'paid' AND livemode = 1 ORDER BY start_date, paid_at;

-- Look up a buyer's subscription in Stripe: search the dashboard for stripe_subscription_id
SELECT buyer_email, stripe_subscription_id FROM orders WHERE status = 'paid' AND livemode = 1;

-- Buyers who opted in to marketing emails (discounts on extra books etc.)
SELECT buyer_name, buyer_email, marketing_consent_at FROM orders
WHERE status = 'paid' AND livemode = 1 AND marketing_consent_at IS NOT NULL;

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
- Terms of sale must also explain the yearly auto-renewal and how to cancel.
- Renewals and cancellations are not written to MySQL; the Stripe dashboard is the
  source of truth for subscription status.
- Do a real live-mode purchase, then cancel and refund it.

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
