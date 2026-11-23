<?php
/**
 * CashPe - Outbound webhook sender.
 * Called internally by wallet_helper::payment_finalize() after a payment is
 * verified. Direct HTTP access is forbidden; it is an internal worker.
 */

if (!defined('CASHPE_INTERNAL')) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    exit;
}

require_once dirname(__DIR__) . '/includes/firebase_helper.php';

/**
 * POST a signed payment.success event to the merchant's webhook URL.
 * Signature: X-CashPe-Signature = HMAC-SHA256(payload, merchant api_key)
 */
function webhook_send(array $user, array $link, string $utr): bool
{
    $url = trim((string) ($user['webhook_url'] ?? ''));
    if ($url === '') {
        return false;
    }
    $isLocalhost = (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?/#i', $url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || (!str_starts_with($url, 'https://') && !$isLocalhost)) {
        webhook_log((string) ($user['uid'] ?? ''), $url, 0, false, 'Rejected: webhook URL must be a valid https URL');
        return false;
    }

    $payload = json_encode([
        'event' => 'payment.success',
        'link_id' => $link['link_id'] ?? null,
        'amount' => $link['amount'] ?? null,
        'commission' => $link['commission'] ?? null,
        'utr' => $utr,
        'customer_name' => $link['customer_name'] ?? null,
        'customer_email' => $link['customer_email'] ?? null,
        'customer_phone' => $link['customer_phone'] ?? null,
        'upi_id' => $link['upi_id'] ?? null,
        'note' => $link['note'] ?? null,
        'paid_at' => $link['paid_at'] ?? time(),
    ], JSON_UNESCAPED_SLASHES);

    $signature = hash_hmac('sha256', (string) $payload, (string) ($user['api_key'] ?? ''));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => !$isLocalhost,
        CURLOPT_SSL_VERIFYHOST => $isLocalhost ? 0 : 2,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-CashPe-Event: payment.success',
            'X-CashPe-Signature: ' . $signature,
            'User-Agent: CashPe-Webhook/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $ok = $error === '' && $httpCode >= 200 && $httpCode < 300;
    $detail = $ok ? 'Delivered' : ($error !== '' ? 'cURL: ' . $error : 'HTTP ' . $httpCode . ': ' . substr((string) $body, 0, 200));
    webhook_log((string) ($user['uid'] ?? ''), $url, $httpCode, $ok, $detail);
    return $ok;
}
