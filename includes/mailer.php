<?php
/**
 * CashPe - Transactional email via PHPMailer + SMTP.
 * SMTP settings can be overridden from Admin -> Settings (Firestore),
 * falling back to .env values.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/firebase_helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function smtp_config(): array
{
    return [
        'host' => (string) setting('smtp_host', env('SMTP_HOST', '')),
        'port' => (int) setting('smtp_port', env('SMTP_PORT', '587')),
        'user' => (string) setting('smtp_user', env('SMTP_USER', '')),
        'pass' => (string) setting('smtp_pass', env('SMTP_PASS', '')),
        'from_email' => (string) setting('smtp_from_email', env('SMTP_FROM_EMAIL', 'no-reply@cashpe.onrender.com')),
        'from_name' => (string) setting('smtp_from_name', env('SMTP_FROM_NAME', 'CashPe')),
    ];
}

function mail_send(string $to, string $subject, string $htmlBody): bool
{
    $cfg = smtp_config();
    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
        error_log('mail_send skipped for ' . $to . ': SMTP is not configured.');
        return false;
    }
    if (!class_exists(PHPMailer::class)) {
        error_log('mail_send skipped: PHPMailer not installed (run composer install).');
        return false;
    }
    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->Port = $cfg['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['user'];
        $mail->Password = $cfg['pass'];
        if ($cfg['port'] === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Timeout = 20;
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));
        $mail->send();
        return true;
    } catch (MailException | Throwable $e) {
        error_log('mail_send failed to ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

/** Inline SVG logo (bolt in gradient rounded square) as a data URI for email. */
function mail_logo_data_uri(): string
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset=".5" stop-color="#8b5cf6"/><stop offset="1" stop-color="#d946ef"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><path d="M36 8 L16 36 H30 L26 56 L46 27 H33 Z" fill="#ffffff"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** Branded HTML layout for all transactional emails. */
function mail_layout(string $heading, string $bodyHtml, ?string $ctaText = null, ?string $ctaUrl = null): string
{
    $logo = mail_logo_data_uri();
    $year = date('Y');
    $cta = '';
    if ($ctaText !== null && $ctaUrl !== null) {
        $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 8px 0;"><tr><td align="center" bgcolor="#7c3aed" style="border-radius:12px;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 50%,#d946ef 100%);">'
            . '<a href="' . e($ctaUrl) . '" target="_blank" style="display:inline-block;padding:14px 34px;font-family:Inter,Arial,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:12px;">' . e($ctaText) . '</a>'
            . '</td></tr></table>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(15,23,42,.08);">'
        // Header
        . '<tr><td style="background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 55%,#c026d3 100%);padding:28px 32px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="padding-right:12px;"><img src="' . $logo . '" width="44" height="44" alt="CashPe" style="display:block;border-radius:12px;"></td>'
        . '<td><div style="font-family:Inter,Arial,sans-serif;font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-.3px;">CashPe</div>'
        . '<div style="font-family:Inter,Arial,sans-serif;font-size:11px;color:#e9d5ff;letter-spacing:.4px;">RECEIVE PAYMENTS. ANYTIME. ANYWHERE.</div></td>'
        . '</tr></table>'
        . '</td></tr>'
        // Body
        . '<tr><td style="padding:32px;font-family:Inter,Arial,sans-serif;color:#0f172a;">'
        . '<h1 style="margin:0 0 14px 0;font-size:21px;font-weight:800;letter-spacing:-.3px;color:#0f172a;">' . e($heading) . '</h1>'
        . '<div style="font-size:14px;line-height:1.75;color:#334155;">' . $bodyHtml . '</div>'
        . $cta
        . '</td></tr>'
        // Footer
        . '<tr><td style="padding:20px 32px;border-top:1px solid #e2e8f0;font-family:Inter,Arial,sans-serif;">'
        . '<div style="font-size:11px;color:#64748b;line-height:1.6;">CashPe - UPI Payment Gateway<br>If you did not expect this email you can ignore it.<br>&copy; ' . $year . ' CashPe. All rights reserved.</div>'
        . '</td></tr>'
        . '</table></td></tr></table>'
        . '</body></html>';
}

/** 1. Magic link verification email. */
function send_magic_link(string $to, string $name, string $link): bool
{
    $body = '<p style="margin:0 0 12px 0;">Hi ' . e($name) . ',</p>'
        . '<p style="margin:0;">Welcome to CashPe. Confirm your email address to activate your account and start receiving UPI payments. This link expires in 15 minutes and can be used only once.</p>'
        . '<p style="margin:18px 0 0 0;font-size:12px;color:#64748b;word-break:break-all;">Or paste this link into your browser:<br><span style="color:#4f46e5;">' . e($link) . '</span></p>';
    return mail_send($to, 'Verify your CashPe email', mail_layout('Verify your email', $body, 'Verify Email Address', $link));
}

/** 2. Welcome email (sent after successful verification). */
function send_welcome(string $to, string $name): bool
{
    $body = '<p style="margin:0 0 12px 0;">Hi ' . e($name) . ',</p>'
        . '<p style="margin:0;">Your CashPe account is ready. Connect your UPI provider, share payment links with your customers and get paid instantly - with only a small commission per successful payment.</p>'
        . '<p style="margin:18px 0 0 0;">Next steps:</p>'
        . '<ul style="margin:8px 0 0 0;padding-left:18px;"><li>Connect your UPI ID under Connect Provider</li><li>Add funds to your wallet</li><li>Create your first payment link</li></ul>';
    return mail_send($to, 'Welcome to CashPe', mail_layout('Welcome to CashPe', $body, 'Go to Dashboard', url('dashboard.php')));
}

/** 3. Payment received notification. */
function send_payment_received(string $to, string $name, string $amount, string $customerName, string $utr, string $linkId): bool
{
    $body = '<p style="margin:0 0 12px 0;">Hi ' . e($name) . ',</p>'
        . '<p style="margin:0;">Great news - you just received a payment.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:16px 0;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;width:100%;"><tr><td style="padding:14px 16px;font-size:13px;color:#334155;line-height:2;">'
        . '<strong>Amount:</strong> ' . e($amount) . '<br>'
        . '<strong>Customer:</strong> ' . e($customerName) . '<br>'
        . '<strong>UTR:</strong> ' . e($utr) . '<br>'
        . '<strong>Payment Link:</strong> ' . e($linkId) . '</td></tr></table>'
        . '<p style="margin:0;">Your 2% commission has been deducted from your wallet automatically.</p>';
    return mail_send($to, 'Payment received - ' . $amount, mail_layout('Payment Received', $body, 'View Transactions', url('transactions.php')));
}

/** 4. Low wallet balance alert. */
function send_low_balance(string $to, string $name, string $balance): bool
{
    $body = '<p style="margin:0 0 12px 0;">Hi ' . e($name) . ',</p>'
        . '<p style="margin:0;">Your CashPe wallet balance is low: <strong>' . e($balance) . '</strong>.</p>'
        . '<p style="margin:12px 0 0 0;">Payment links are created only when your wallet can cover the 2% commission. Add funds to keep generating links.</p>';
    return mail_send($to, 'Low wallet balance - add funds', mail_layout('Low Wallet Balance', $body, 'Add Funds', url('wallet.php')));
}
