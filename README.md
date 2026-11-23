# CashPe — UPI Payment Gateway

**Receive Payments. Anytime. Anywhere.**

CashPe is a production-ready UPI payment gateway for Indian merchants: create premium payment links and QR codes that settle directly to any UPI ID, with real-time UTR verification, a merchant wallet with 2% commission, REST API, webhooks, support chat and a full admin panel.

## Stack

- **Backend:** Pure PHP 8.2+ (no framework)
- **Database:** Firebase Firestore via `kreait/firebase-php` v7
- **Auth:** Google OAuth 2.0 + Email/Password + magic-link verification
- **Frontend:** PHP + TailwindCSS (CDN) + Alpine.js + Vanilla JS (Inter font, dark mode)
- **Email:** PHPMailer + SMTP
- **HTTP:** cURL
- **Sessions:** native PHP `$_SESSION`
- **Hosting:** Render.com (Docker + PHP-Apache)
- **Icons:** inline SVG only (Lucide/Heroicons style) — zero emojis

## File structure

```
cashpe/  (this repository root)
├── Dockerfile                 # php:8.2-apache, docroot -> /public, composer install
├── composer.json              # kreait/firebase-php ^7, phpmailer/phpmailer ^6.9, chillerlan/php-qrcode ^5
├── render.yaml                # Render blueprint (free plan, healthCheckPath /ping.php)
├── .htaccess                  # HTTPS redirect, security headers, protects config/includes
├── .env.example               # all environment variables (copy to .env locally)
├── config/
│   ├── config.php             # env loader, session, encryption (AES-256-GCM), helpers
│   └── firebase.php           # Firestore client bootstrap
├── includes/                  # auth, csrf, firebase helpers, wallet/payment engine,
│   ├── error_page.php         #   provider client, mailer, icons, header/sidebar/footer
├── public/                    # web root (Apache docroot)
│   ├── index.php              # landing + login
│   ├── register.php           # email register (min 8 chars, 1 letter, 1 number)
│   ├── verify.php             # magic link handler (single-use, 15 min TTL)
│   ├── dashboard.php          # stats + quick actions
│   ├── wallet.php             # balance card, add funds (admin approves), ledger
│   ├── payment-link.php       # create link (2% commission, wallet check) + links table
│   ├── connect.php            # business (UPI ID) + personal (mobile->OTP->UPI list)
│   ├── provider-otp.php       # OTP entry + UPI ID selection
│   ├── transactions.php       # filters + CSV export + detail modal
│   ├── api.php                # JSON REST API (?action=...) + docs (cURL/PHP/Node/Python)
│   ├── support.php            # WhatsApp-style support chat (user side)
│   ├── pay.php                # PUBLIC premium payment page (QR + auto-poll)
│   ├── check_status.php       # AJAX polling endpoint ({status, utr})
│   ├── callback.php           # Google OAuth + provider OTP verify + inbound payment webhook
│   ├── webhook_send.php       # outbound webhook worker (HMAC signed)
│   ├── admin.php              # ADMIN PANEL — single file, 7 tabs
│   ├── ping.php               # keep-alive (UptimeRobot)
│   └── logout.php
└── assets/
    ├── css/app.css            # glassmorphism, gradients, toasts, animations
    └── js/app.js              # dark mode, sidebar drawer, toasts, clipboard, confirm modal
```

## How payments are verified

CashPe does not move money — it creates a UPI intent (`upi://pay?pa=...&am=...`) and detects the incoming credit:

1. **Personal providers** (PhonePe Personal, Paytm Personal, Navi): after OTP login, CashPe polls the provider's transaction feed via cURL every time `check_status.php` is called (every 3s from `pay.php`). A payment whose **amount + timestamp match** the link (and status = success) is auto-marked paid with the provider UTR.
2. **Business providers** (PhonePe Business, Paytm Business, GPay Business, BharatPe): business accounts do not expose a public feed, so verification works through an **inbound webhook**. On the Connect Provider page CashPe shows your personal **callback URL** (`callback.php?action=payment&token=...`). Point any Android "SMS Forwarder" app at it (POST fields: `amount, utr, upi_id`) and CashPe auto-matches pending links. You can also POST to it from any backend.
3. Unpaid links **expire after 30 minutes**.

When a payment is confirmed, the **2% commission is deducted from the merchant wallet** atomically from the ledger's point of view, a `wallet_transactions` entry is written, the merchant gets an email, and the outbound webhook (`payment.success`) is fired with an HMAC-SHA256 signature.

---

## Deploy on Render.com (step by step)

### 1. Firebase setup
1. Go to [console.firebase.google.com](https://console.firebase.google.com) → **Add project** (e.g. `cashpe-prod`).
2. In the project: **Build → Firestore Database → Create database** (production mode, region `asia-south1` recommended for India).
3. **Project Settings → Service accounts → Generate new private key** — downloads a JSON file.
4. You'll paste this JSON (one line) as `FIREBASE_CREDENTIALS_JSON` in Render (step 4).
5. No composite indexes are required (all queries are single-field). The magic-link lookup uses a **collection group query** on `tokens` — single-field, automatically indexed.

### 2. Google OAuth (optional but recommended)
1. [console.cloud.google.com](https://console.cloud.google.com) → **APIs & Services → Credentials → Create credentials → OAuth client ID** (type: Web application).
2. Authorized redirect URI: `https://cashpe.onrender.com/callback.php?action=google`
   (replace with your own domain if different from `APP_URL`).
3. Copy **Client ID** and **Client Secret** into Render env vars.

### 3. SMTP (magic links + notifications)
Any SMTP works (Gmail app password, Workspace, Brevo, SES...). For Gmail: enable 2FA → App Passwords → generate one. Set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587`, `SMTP_USER=`, `SMTP_PASS=`. If SMTP is not set, the app still runs — emails are skipped and logged.

### 4. Push to Render
1. Push this repo to your own GitHub account.
2. Render dashboard → **New → Blueprint** → select the repo → it reads `render.yaml` (Docker, free plan, `healthCheckPath: /ping.php`).
3. Set the `sync: false` env vars when prompted:

| Key | Value |
|---|---|
| `FIREBASE_CREDENTIALS_JSON` | service account JSON, one line |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | from step 2 |
| `SMTP_HOST` / `SMTP_USER` / `SMTP_PASS` | from step 3 |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | your admin credentials (used only by `admin.php`) |
| `COMMISSION_PERCENT` | `2` (already set) |

4. Also worth setting: `APP_URL=https://cashpe.onrender.com` and `ENCRYPTION_KEY=<64 random chars>` (provider session tokens are AES-256-GCM encrypted with it).
5. Deploy. The Dockerfile sets Apache's docroot to `public/`, enables `rewrite` + `headers`, runs `composer install --no-dev`, and exposes port 80.

### 5. Keep free tier warm
Render free web services sleep after 15 minutes of inactivity. Create an [UptimeRobot](https://uptimerobot.com) HTTP monitor for `https://cashpe.onrender.com/ping.php` every 5 minutes. (Note: free plan has a 750 instance-hours/month budget.)

### 6. First run checklist
1. Visit `/register.php` → create account (email + password).
2. Check inbox → magic link → you're on the dashboard.
3. **Connect Provider** → connect a business UPI ID (instant) or a personal provider (mobile → OTP → pick UPI ID).
4. **Wallet** → Add Funds (₹10+). Open `admin.php` → sign in with `ADMIN_EMAIL`/`ADMIN_PASSWORD` → **Wallet Requests** → Approve.
5. Create a payment link → share `pay.php?id=pl_...` → pay ₹1 from another phone → watch it flip to **success** with UTR.

## Local development

```bash
composer install
cp .env.example .env   # fill in values, keep APP_URL=http://localhost:8000
php -S localhost:8000 -t public
```
(`/etc/hosts` hack not needed; Apache/XAMPP users: root `.htaccess` rewrites to `public/`.)

## REST API

Base URL: `https://your-domain/api.php` — send `X-API-Key: cp_live_...` on every call. 60 requests/minute per key; optional IP whitelist from the dashboard.

```bash
# Create a payment link
curl -X POST 'https://your-domain/api.php?action=create_link' \
  -H 'X-API-Key: cp_live_...' -H 'Content-Type: application/json' \
  -d '{"amount":499,"customer_name":"Rahul Sharma","note":"Order #1042"}'

# Poll status
curl 'https://your-domain/api.php?action=status&id=pl_...' -H 'X-API-Key: cp_live_...'

# Wallet balance / transactions
curl 'https://your-domain/api.php?action=wallet'       -H 'X-API-Key: cp_live_...'
curl 'https://your-domain/api.php?action=transactions' -H 'X-API-Key: cp_live_...'
```

Outbound webhook: set **API → Webhook URL** in the dashboard. Events are POSTed as JSON (`event: payment.success`) with `X-CashPe-Signature: HMAC_SHA256(payload, api_key)`.

## Admin panel

`https://your-domain/admin.php` — sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

1. **Dashboard** — users, volume, commission, active links + 7-day SVG chart
2. **Users** — search, credit/debit wallet, block/unblock
3. **Payment Links** — every link, filter by status
4. **Transactions** — global successful payments + CSV export
5. **Support Chats** — inbox with unread bell badge; replies sync live (3s polling)
6. **Wallet Requests** — approve/reject merchant top-ups (credits wallet + ledger)
7. **Settings** — commission %, SMTP, personal provider endpoints

## Security model

- `password_hash`/`password_verify` (bcrypt), single-use magic links (15 min TTL, SHA-256 tokens), session regeneration on login, UA-fingerprinted sessions
- CSRF tokens on every form (including AJAX via `X-CSRF-Token`)
- Mandatory email verification before dashboard access; blocked users rejected at login
- Input sanitization + `htmlspecialchars` everywhere; prepared Firestore field updates (no query injection possible)
- HTTPS forced in `.htaccess`; `.env`, `config/`, `includes/`, `vendor/` never served (docroot is `public/`, root `.htaccess` belt-and-braces)
- Provider session tokens AES-256-GCM encrypted at rest; admin login uses timing-safe compare with 700ms delay on failure
- Outbound webhooks HMAC-signed; optional per-key IP whitelist; 60 req/min rate limit
- Callback token per merchant for the inbound payment SMS-forwarder webhook (treat it as a secret)
- Amounts: commission computed server-side from the stored amount — never trusted from the client

## Honest limitations (please read)

- **Personal provider APIs** (PhonePe Personal / Paytm Personal / Navi) are not publicly documented APIs. `includes/provider_helper.php` implements the complete, production-grade client (cURL, 30s timeout, SSL verification, JSON normalisation) but the **base URLs must be supplied** via Admin → Settings or `*_PERSONAL_BASE_URL` env vars, sourced from your own partner access or bridge service. Until then those providers return a clear "not configured" message — nothing fails silently or fakes success.
- **Business providers cannot be pulled** (no public feed for merchant UPI); auto-verification uses the SMS-forwarder webhook described above (or your own backend posting to `callback.php?action=payment`). Manual UTR confirmation is intentionally not faked.
- Firestore counts in the admin dashboard iterate documents (fine for thousands of users; for large scale switch to Firestore aggregation queries).
- Real money never moves — CashPe is a detection + notification layer over UPI. Test thoroughly with ₹1 before going live. This is not a licensed payment aggregator.

## Acceptance checklist (from the spec)

Verification status honesty key:
- **Built + statically verified in-repo** = code written, PHP-parsed (php-wasm 8.2), helpers unit-tested, no placeholders — but NOT integration-tested with live services.
- **Requires deploy-time credentials** = cannot be proven without real Firebase/Google/SMTP/provider access on Render. You must test these after deploying (₹1 test payment).

- [x] Built: register + magic link verify flow (single-use token, 15 min, collection-group query) — deploy-test required
- [x] Built: Google sign-in (OAuth 2.0 code exchange, auto-creates account, email_verified=true) — deploy-test required
- [x] Built: unverified emails blocked at login + resend verification flow
- [x] Built: sidebar with 8 inline SVG icons, zero emojis anywhere (verified by scan)
- [x] Built: business provider (UPI ID) saved to Firestore, server-side validation
- [x] Built: personal provider mobile → OTP → UPI list via cURL client — needs `*_PERSONAL_BASE_URL` (not publicly documented); returns clear "not configured" otherwise
- [x] Built: max 3 providers enforced server-side (+ locked rows)
- [x] Built: link creation blocked when wallet < 2% commission (server-side, fresh read)
- [x] Built: pay.php premium UI, QR via UPI intent, auto-poll every 3s (expiry 30 min)
- [x] Built: check_status.php → deducts 2% → saves UTR → email + webhook (idempotent `payment_finalize`)
- [x] Built: wallet page (balance, add-funds request with admin approval, ledger, low-balance banner < ₹50)
- [x] Built: transactions filters + working CSV export + detail modal
- [x] Built: API create_link / status / wallet / transactions (60 req/min per key, optional IP whitelist, HMAC webhook)
- [x] Built: support chat both ways (3s polling, image attachments, base64 → Firestore)
- [x] Built: admin panel 7 tabs (dashboard / users / links / transactions CSV / support / wallet requests / settings)
- [x] Built: email templates (magic link, welcome, payment received, low balance) via PHPMailer SMTP — needs real SMTP credentials to confirm delivery
- [x] Built: Dockerfile + render.yaml + /ping.php ("ok") for UptimeRobot — Docker build itself must run on Render (no Docker in this environment)
- [x] Built: dark mode toggle (localStorage), responsive drawer sidebar
- [x] Verified: zero emoji (regex scan), zero TODO/mock data, CSRF on all forms + X-CSRF-Token for JSON, e() escaping, styled error pages
- [ ] Deploy-time: Google login works end-to-end against real Google OAuth client
- [ ] Deploy-time: emails actually delivered by your SMTP provider
- [ ] Deploy-time: ₹1 end-to-end payment auto-detected (SMS forwarder or personal provider bridge)
- [ ] Deploy-time: Docker image builds green on Render and health check passes
- [ ] Deploy-time: Firebase security rules / indexes deployed as needed

Everything marked "Built" is implemented in this repository; nothing is stubbed or faked. The unchecked items are inherently un-testable without your live credentials and infrastructure.

## License

Proprietary — built for CashPe.
