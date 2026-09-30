// End-to-end flow test. Run via tests/run.sh.
const { execSync } = require('child_process');
const crypto = require('crypto');
const path = require('path');
const { chromium } = require(execSync('npm root -g').toString().trim() + '/playwright');

const SITE = 'http://127.0.0.1:8080';
const SHOTS = process.env.SHOTS || path.join(__dirname, 'screenshots');
const sql = (q) => execSync(`mysql -N familieboger_test -e ${JSON.stringify(q)}`).toString().trim();
let failures = 0;
const check = (label, ok, extra = '') => { console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${ok ? '' : ' ' + extra}`); if (!ok) failures++; };

(async () => {
  execSync(`mkdir -p ${SHOTS}`);
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  // Landing page
  await page.goto(SITE + '/');
  await page.waitForSelector('[data-next-start]:not([hidden])', { timeout: 5000 });
  const nextStart = await page.textContent('[data-next-start]');
  check('landing shows next start date', /mandag d\. \d+\. \w+/.test(nextStart), nextStart);
  check('page title', (await page.title()).startsWith('Familiebøger'));
  await page.screenshot({ path: `${SHOTS}/landing-desktop.png`, fullPage: true });

  // Search engines / LLMs: structured data, meta tags, robots, sitemap, llms.txt
  const seo = await page.evaluate(() => {
    const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => JSON.parse(s.textContent));
    const meta = (sel) => (document.querySelector(sel) || {}).content || (document.querySelector(sel) || {}).href || '';
    return {
      ld,
      faqVisible: [...document.querySelectorAll('#faq summary')].map((s) => s.textContent.trim()),
      canonical: meta('link[rel=canonical]'),
      ogImage: meta('meta[property="og:image"]'),
      ogUrl: meta('meta[property="og:url"]'),
      twitter: meta('meta[name="twitter:card"]'),
      description: meta('meta[name=description]'),
    };
  });
  const graph = (seo.ld[0] || {})['@graph'] || [];
  const byType = Object.fromEntries(graph.map((n) => [n['@type'], n]));
  check('JSON-LD has Organization, WebSite, WebPage, Product, FAQPage', ['Organization', 'WebSite', 'WebPage', 'Product', 'FAQPage'].every((t) => byType[t]), Object.keys(byType).join(','));
  check('JSON-LD FAQ matches the visible FAQ', JSON.stringify(byType.FAQPage.mainEntity.map((q) => q.name)) === JSON.stringify(seo.faqVisible));
  const offer = byType.Product.offers;
  check('JSON-LD offer: 899 DKK per year incl. VAT', offer.price === '899' && offer.priceCurrency === 'DKK' && offer.priceSpecification.billingDuration === 'P1Y' && offer.priceSpecification.valueAddedTaxIncluded === true);
  check('canonical and og:url are the punycode domain', seo.canonical === 'https://xn--familiebger-ngb.dk/' && seo.ogUrl === seo.canonical);
  check('og:image is absolute and the file exists', seo.ogImage === 'https://xn--familiebger-ngb.dk/assets/img/og-image.png' && execSync(`curl -s -o /dev/null -w '%{http_code} %{content_type}' ${SITE}/assets/img/og-image.png`).toString() === '200 image/png');
  check('twitter card + description present', seo.twitter === 'summary_large_image' && seo.description.length > 50 && seo.description.length <= 160);
  const robots = execSync(`curl -s ${SITE}/robots.txt`).toString();
  check('robots.txt allows the site, blocks checkout/API, lists the sitemap', /User-agent: \*\nAllow: \//.test(robots) && robots.includes('Disallow: /kob.php') && robots.includes('Disallow: /tak.php') && robots.includes('Sitemap: https://xn--familiebger-ngb.dk/sitemap.xml'));
  check('sitemap.xml lists the home page', execSync(`curl -s ${SITE}/sitemap.xml`).toString().includes('<loc>https://xn--familiebger-ngb.dk/</loc>'));
  const llms = execSync(`curl -s ${SITE}/llms.txt`).toString();
  check('llms.txt describes the product and price', llms.startsWith('# Familiebøger') && llms.includes('899 kr. om året') && llms.includes('12 måneder'));
  const tak = execSync(`curl -s -D - -o /dev/null '${SITE}/tak.php?session_id=hack'`).toString();
  check('PHP pages are noindex', /X-Robots-Tag: noindex/i.test(tak));

  // Waitlist: invalid then valid
  await page.fill('#wl-email', 'not-an-email');
  await page.click('[data-waitlist-form] button[type=submit]');
  check('waitlist rejects invalid email', (await page.textContent('[data-form-status]')).includes('gyldig'));
  await page.fill('#wl-name', 'Mette');
  await page.fill('#wl-email', 'Mette@Example.com');
  await page.click('[data-waitlist-form] button[type=submit]');
  await page.waitForFunction(() => document.querySelector('[data-form-status]').textContent.includes('Tak'));
  check('waitlist accepts valid email', sql("SELECT CONCAT(email,'|',name) FROM waitlist") === 'mette@example.com|Mette');
  // Duplicate signup is fine and keeps one row
  execSync(`curl -s -o /dev/null -w '%{http_code}' -X POST -d 'email=mette@example.com' ${SITE}/venteliste.php`);
  check('duplicate waitlist signup keeps one row', sql('SELECT COUNT(*) FROM waitlist') === '1');
  const noJs = execSync(`curl -s -X POST -d 'email=anders@example.com' ${SITE}/venteliste.php`).toString();
  check('waitlist works without JS (HTML response)', noJs.includes('Du er nu på ventelisten') && sql('SELECT COUNT(*) FROM waitlist') === '2');
  const bot = execSync(`curl -s -X POST -H 'Accept: application/json' -d 'email=bot@example.com&website=spam' ${SITE}/venteliste.php`).toString();
  check('honeypot fakes success but stores nothing', JSON.parse(bot).ok === true && sql('SELECT COUNT(*) FROM waitlist') === '2');

  // Buy → mock Stripe → tak.php
  await page.goto(SITE + '/#pris');
  await Promise.all([page.waitForURL(/tak\.php\?session_id=cs_test_/), page.click('[data-checkout-form] button')]);
  const sessionId = new URL(page.url()).searchParams.get('session_id');
  check('redirected back with session id', !!sessionId);
  const anchor = sql("SELECT MIN(paid_at) FROM orders WHERE status='paid' AND livemode=0");
  check('order recorded as paid', sql(`SELECT CONCAT(status,'|',amount_total,'|',currency,'|',buyer_email) FROM orders WHERE stripe_session_id='${sessionId}'`) === 'paid|89900|DKK|karen@example.com');
  const params = JSON.parse(execSync(`cat $(php -r 'echo sys_get_temp_dir();')/fb-mock-stripe.json`).toString())[sessionId].params;
  check('checkout: subscription mode, Danish, metadata', params.mode === 'subscription' && params.locale === 'da' && params.metadata.product === 'familieboger' && params.subscription_data.metadata.product === 'familieboger' && params.success_url.endsWith('{CHECKOUT_SESSION_ID}'));
  check('checkout: renewal is stated at the pay button', /fornyes automatisk hvert år/.test(params.custom_text.submit.message));
  const pd = params.line_items[0].price_data || {};
  check('checkout: 899 DKK/year incl. VAT on the configured product', pd.product === 'prod_mock' && pd.unit_amount === '89900' && pd.currency === 'dkk' && pd.recurring.interval === 'year' && pd.tax_behavior === 'inclusive', JSON.stringify(params.line_items));
  check('order stores the subscription id', /^sub_mock/.test(sql(`SELECT stripe_subscription_id FROM orders WHERE stripe_session_id='${sessionId}'`)));
  const options = await page.$$eval('input[name=start_date]', (els) => els.map((e) => e.value));
  check('8 start date options, first = Mon 19 Oct 2026 (week 40 + 3)', options.length === 8 && options[0] === '2026-10-19', options.join(','));
  await page.screenshot({ path: `${SHOTS}/tak-form-desktop.png`, fullPage: true });

  // Validation errors
  await page.fill('#recipient_name', 'Inger Hansen');
  await page.selectOption('#recipient_relation', 'mormor');
  await page.check('input[name=recipient_channel][value=sms]');
  await page.click('button[type=submit]');
  check('sms without phone shows error', (await page.textContent('body')).includes('Skriv mobilnummeret'));
  check('entered values are kept after error', (await page.inputValue('#recipient_name')) === 'Inger Hansen');
  await page.screenshot({ path: `${SHOTS}/tak-form-errors.png`, fullPage: true });

  // Valid submit
  await page.fill('#recipient_phone', '12 34 56 78');
  await page.check('input[name=start_date][value="2026-10-26"]');
  await page.fill('#notes', 'Det er en overraskelse til hendes 80-års fødselsdag.');
  check('marketing opt-in is unticked by default', !(await page.isChecked('input[name=marketing_consent]')));
  await page.check('input[name=marketing_consent]');
  await Promise.all([page.waitForURL(/gemt=1/), page.click('button.btn[type=submit]')]);
  const body = await page.textContent('body');
  check('confirmation shows start date and name', body.includes('Inger') && body.includes('mandag d. 26. oktober 2026'));
  check('marketing opt-in stored with its text', sql(`SELECT marketing_consent_at IS NOT NULL AND marketing_consent_text LIKE 'Ja tak%' FROM orders WHERE stripe_session_id='${sessionId}'`) === '1');
  check('details saved', sql(`SELECT CONCAT(start_date,'|',recipient_name,'|',recipient_relation,'|',recipient_channel,'|',recipient_phone) FROM orders WHERE stripe_session_id='${sessionId}'`) === '2026-10-26|Inger Hansen|mormor|sms|+4512345678');
  await page.screenshot({ path: `${SHOTS}/tak-confirmation-desktop.png`, fullPage: true });

  check('landing-price renewal wording is shown in the summary', body.includes('om året · fornyes automatisk'));

  // Manage subscription → Stripe customer portal
  await Promise.all([page.waitForURL(/127\.0\.0\.1:12111\/portal/), page.click('text=Administrér abonnement')]);
  const portalReq = JSON.parse(execSync(`cat $(php -r 'echo sys_get_temp_dir();')/fb-mock-stripe.json`).toString())._last_portal;
  check('portal opens for the buyer with return to tak.php', portalReq.customer === 'cus_mock123' && portalReq.return_url === `${SITE}/tak.php?session_id=${sessionId}`, JSON.stringify(portalReq));
  check('portal refuses unknown sessions', execSync(`curl -s -o /dev/null -w '%{http_code}' -X POST -d 'session_id=cs_test_doesnotexist123' ${SITE}/abonnement.php`).toString() === '404');
  await page.goto(`${SITE}/tak.php?session_id=${sessionId}`);

  // Edit link
  await page.click('text=Ret oplysninger');
  check('edit form is prefilled', (await page.inputValue('#recipient_phone')) === '+4512345678' && await page.isChecked('input[name=start_date][value="2026-10-26"]') && await page.isChecked('input[name=marketing_consent]'));
  const consentAt = sql(`SELECT marketing_consent_at FROM orders WHERE stripe_session_id='${sessionId}'`);
  await Promise.all([page.waitForURL(/gemt=1/), page.click('button.btn[type=submit]')]);
  check('re-saving keeps the original consent time', sql(`SELECT marketing_consent_at FROM orders WHERE stripe_session_id='${sessionId}'`) === consentAt);
  await page.click('text=Ret oplysninger');
  await page.uncheck('input[name=marketing_consent]');
  await Promise.all([page.waitForURL(/gemt=1/), page.click('button.btn[type=submit]')]);
  check('unticking withdraws consent', sql(`SELECT marketing_consent_at IS NULL AND marketing_consent_text IS NULL FROM orders WHERE stripe_session_id='${sessionId}'`) === '1');

  // Tampered start date is rejected
  const tampered = execSync(`curl -s -X POST --data-urlencode 'session_id=${sessionId}' -d 'start_date=2026-10-05&recipient_name=X&recipient_relation=mor&recipient_channel=email&recipient_email=x@example.com' ${SITE}/tak.php`).toString();
  check('start date outside allowed options is rejected', tampered.includes('Vælg en af startdatoerne') && sql(`SELECT start_date FROM orders WHERE stripe_session_id='${sessionId}'`) === '2026-10-26');

  // Bad / unknown / unpaid sessions
  check('invalid session id → 404', execSync(`curl -s -o /dev/null -w '%{http_code}' '${SITE}/tak.php?session_id=hack'`).toString() === '404');
  check('unknown session id → 404', execSync(`curl -s -o /dev/null -w '%{http_code}' '${SITE}/tak.php?session_id=cs_test_doesnotexist123'`).toString() === '404');
  const unpaid = JSON.parse(execSync(`curl -s -u sk_test_mock: -H 'Authorization: Bearer sk_test_mock' -d 'mode=subscription&line_items[0][price]=price_mock&line_items[0][quantity]=1&success_url=x&cancel_url=y&metadata[product]=familieboger' http://127.0.0.1:12111/v1/checkout/sessions`).toString());
  const unpaidPage = execSync(`curl -s '${SITE}/tak.php?session_id=${unpaid.id}'`).toString();
  check('unpaid session is not accepted', unpaidPage.includes('Betalingen er ikke gennemført') && sql(`SELECT COUNT(*) FROM orders WHERE stripe_session_id='${unpaid.id}' AND status='paid'`) === '0');

  // Webhook
  const event = JSON.stringify({ id: 'evt_1', type: 'checkout.session.completed', data: { object: {
    id: 'cs_test_webhookonly1234567890', object: 'checkout.session', livemode: false, status: 'complete', payment_status: 'paid',
    amount_total: 89900, currency: 'dkk', metadata: { product: 'familieboger' }, customer: 'cus_w', payment_intent: 'pi_w',
    customer_details: { name: 'Webhook Buyer', email: 'wb@example.com' } } } });
  const t = Math.floor(Date.now() / 1000);
  const sig = crypto.createHmac('sha256', 'whsec_test_secret').update(`${t}.${event}`).digest('hex');
  const post = (s) => execSync(`curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Stripe-Signature: ${s}' --data-binary @- ${SITE}/stripe-webhook.php`, { input: event }).toString();
  check('webhook with bad signature → 400', post(`t=${t},v1=deadbeef`) === '400');
  check('webhook with valid signature → 200', post(`t=${t},v1=${sig}`) === '200');
  check('webhook recorded the order', sql("SELECT CONCAT(status,'|',buyer_email) FROM orders WHERE stripe_session_id='cs_test_webhookonly1234567890'") === 'paid|wb@example.com');
  check('webhook replay is idempotent', post(`t=${t},v1=${sig}`) === '200' && sql("SELECT COUNT(*) FROM orders WHERE stripe_session_id='cs_test_webhookonly1234567890'") === '1');
  check('first sale anchor unchanged by later sales', anchor !== '' && sql("SELECT MIN(paid_at) FROM orders WHERE status='paid' AND livemode=0") === anchor);
  check('live-mode anchor is untouched by test sales', sql("SELECT COUNT(*) FROM orders WHERE livemode=1") === '0');

  // Funnel: clicking buy without paying leaves a 'started' row
  const loc = execSync(`curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST ${SITE}/kob.php`).toString();
  const startedId = (loc.match(/pay\/(cs_test_\w+)/) || [])[1];
  check('kob.php 303-redirects to Stripe', loc.startsWith('303 http://127.0.0.1:12111/pay/cs_test_'), loc);
  check('abandoned checkout recorded as started', sql(`SELECT status FROM orders WHERE stripe_session_id='${startedId}'`) === 'started');

  // Price-ID configuration (second site instance with price_id set)
  const loc2 = execSync(`curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST http://127.0.0.1:8081/kob.php`).toString();
  const priceSession = (loc2.match(/pay\/(cs_test_\w+)/) || [])[1];
  const store = JSON.parse(execSync(`cat $(php -r 'echo sys_get_temp_dir();')/fb-mock-stripe.json`).toString());
  check('price_id config uses the Stripe Price', loc2.startsWith('303 ') && store[priceSession] && store[priceSession].params.line_items[0].price === 'price_mock', loc2);

  // Mobile screenshots
  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
  await mobile.goto(SITE + '/');
  await mobile.waitForTimeout(500);
  check('mobile: no horizontal scroll', await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await mobile.screenshot({ path: `${SHOTS}/landing-mobile.png`, fullPage: true });
  await mobile.goto(`${SITE}/tak.php?session_id=${sessionId}&ret=1`);
  check('mobile tak: no horizontal scroll', await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  await mobile.screenshot({ path: `${SHOTS}/tak-form-mobile.png`, fullPage: true });

  check('no console errors', consoleErrors.length === 0, consoleErrors.join(' | '));
  await browser.close();
  console.log(failures ? `\n${failures} FAILED` : '\nAll e2e checks passed');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
