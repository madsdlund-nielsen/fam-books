-- Familiebøger — MySQL/MariaDB schema.
-- Import once via phpMyAdmin on simply.com (Import → vælg denne fil), or:
--   mysql -h <host> -u <user> -p <database> < schema.sql

SET NAMES utf8mb4;

-- One row per Stripe Checkout Session.
--   status 'started'  → buyer clicked "Køb" and was sent to Stripe (funnel tracking)
--   status 'paid'     → Stripe confirmed payment (via tak.php and/or the webhook)
-- The earliest start date is derived from MIN(paid_at), i.e. the first-ever sale.
CREATE TABLE IF NOT EXISTS orders (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_session_id     VARCHAR(255) NOT NULL,
  status                ENUM('started','paid') NOT NULL DEFAULT 'started',
  livemode              TINYINT(1) NOT NULL DEFAULT 0,
  amount_total          INT UNSIGNED NULL COMMENT 'Smallest currency unit (øre)',
  currency              CHAR(3) NULL,
  stripe_payment_intent VARCHAR(255) NULL,
  stripe_customer_id    VARCHAR(255) NULL,
  buyer_name            VARCHAR(190) NULL,
  buyer_email           VARCHAR(190) NULL,
  paid_at               DATETIME NULL COMMENT 'UTC',

  -- Filled in by the buyer on tak.php after payment
  start_date            DATE NULL COMMENT 'Always a Monday',
  recipient_name        VARCHAR(190) NULL,
  recipient_relation    VARCHAR(60) NULL,
  recipient_channel     ENUM('email','sms') NULL,
  recipient_email       VARCHAR(190) NULL,
  recipient_phone       VARCHAR(40) NULL,
  notes                 TEXT NULL,
  details_completed_at  DATETIME NULL COMMENT 'UTC',

  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_session (stripe_session_id),
  KEY idx_orders_status_paid (status, paid_at),
  KEY idx_orders_start (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waitlist (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190) NOT NULL,
  name        VARCHAR(120) NULL,
  source      VARCHAR(60) NULL,
  consent     VARCHAR(255) NOT NULL COMMENT 'The consent text shown at signup',
  ip_hash     CHAR(64) NULL COMMENT 'SHA-256 of IP + secret salt, for abuse checks only',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_waitlist_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Handy queries for phpMyAdmin:
--   Funnel:      SELECT status, livemode, COUNT(*) FROM orders GROUP BY status, livemode;
--   Paid orders: SELECT paid_at, buyer_name, buyer_email, start_date, recipient_name, recipient_channel
--                FROM orders WHERE status = 'paid' ORDER BY paid_at;
--   Missing details (paid but not onboarded):
--                SELECT buyer_email, paid_at FROM orders WHERE status = 'paid' AND details_completed_at IS NULL;
--   Waitlist:    SELECT created_at, name, email, source FROM waitlist ORDER BY created_at;
