<?php
/**
 * CashPe - Provider catalogue + HTTP client for provider APIs.
 *
 * Business providers (PhonePe Business, Paytm Business, Google Pay Business,
 * BharatPe) only need a UPI ID. Personal providers (PhonePe Personal,
 * Paytm Personal, Navi) use a mobile + OTP login and a transaction feed.
 *
 * Personal provider HTTP endpoints are configurable via .env / Admin Settings
 * because they are not publicly documented. When unconfigured the helpers
 * return a clear error instead of failing silently or pretending success.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/firebase_helper.php';

/** Provider catalogue. */
function provider_catalogue(): array
{
    return [
        'business' => [
            ['id' => 'phonepe_business', 'name' => 'PhonePe Business', 'type' => 'business', 'tag' => 'Business', 'desc' => 'Receive into your PhonePe Business UPI ID', 'icon' => 'provider-phonepe'],
            ['id' => 'paytm_business', 'name' => 'Paytm Business', 'type' => 'business', 'tag' => 'Business', 'desc' => 'Receive into your Paytm Business UPI ID', 'icon' => 'provider-paytm'],
            ['id' => 'gpay_business', 'name' => 'Google Pay Business', 'type' => 'business', 'tag' => 'Business', 'desc' => 'Receive into your Google Pay Business UPI ID', 'icon' => 'provider-gpay'],
            ['id' => 'bharatpe_business', 'name' => 'BharatPe', 'type' => 'business', 'tag' => 'Business', 'desc' => 'Receive into your BharatPe merchant UPI ID', 'icon' => 'provider-bharatpe'],
        ],
        'personal' => [
            ['id' => 'phonepe_personal', 'name' => 'PhonePe Personal', 'type' => 'personal', 'tag' => null, 'desc' => 'Link your personal PhonePe account via OTP', 'icon' => 'provider-phonepe'],
            ['id' => 'paytm_personal', 'name' => 'Paytm Personal', 'type' => 'personal', 'tag' => null, 'desc' => 'Link your personal Paytm account via OTP', 'icon' => 'provider-paytm'],
            ['id' => 'navi', 'name' => 'Navi', 'type' => 'personal', 'tag' => null, 'desc' => 'Link your Navi account via OTP', 'icon' => 'provider-navi'],
        ],
    ];
}

function provider_by_id(string $id): ?array
{
    foreach (provider_catalogue() as $group) {
        foreach ($group as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
    }
    return null;
}

/** Configured base URL for a personal provider, or '' when not configured. */
function provider_base_url(string $providerId): string
{
    $map = [
        'phonepe_personal' => ['provider_phonepe_personal_base', 'PHONEPE_PERSONAL_BASE_URL'],
        'paytm_personal' => ['provider_paytm_personal_base', 'PAYTM_PERSONAL_BASE_URL'],
        'navi' => ['provider_navi_personal_base', 'NAVI_PERSONAL_BASE_URL'],
    ];
    [$settingKey, $envKey] = $map[$providerId] ?? [null, null];
    if ($settingKey === null) {
        return '';
    }
    $value = (string) setting($settingKey, env($envKey, ''));
    return rtrim(trim($value), '/');
}

/** Low-level HTTP request. cURL, 30s timeout, SSL verification on. */
function provider_http(string $method, string $url, array $headers = [], ?array $body = null, int $timeout = 30): array
{
    $ch = curl_init();
    $headerLines = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headerLines,
    ];
    if ($body !== null) {
        $json = json_encode($body);
        $options[CURLOPT_POSTFIELDS] = $json;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER][] = 'Content-Length: ' . strlen($json);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        return ['http' => 0, 'body' => '', 'json' => null, 'error' => 'Network error: ' . ($error !== '' ? $error : 'request failed')];
    }
    $json = json_decode((string) $raw, true);
    return ['http' => $httpCode, 'body' => (string) $raw, 'json' => is_array($json) ? $json : null, 'error' => ''];
}

/** Extract the first present key from a (possibly nested) array. */
function provider_pick(mixed $data, array $paths): mixed
{
    foreach ($paths as $path) {
        $current = $data;
        $found = true;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                $found = false;
                break;
            }
        }
        if ($found && $current !== null && $current !== '') {
            return $current;
        }
    }
    return null;
}

/** Step 1 (personal providers): send OTP to the mobile number. */
function provider_send_otp(string $providerId, string $mobile): array
{
    $mobile = preg_replace('/\D/', '', $mobile);
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        return ['ok' => false, 'message' => 'Enter a valid 10-digit Indian mobile number.'];
    }
    $base = provider_base_url($providerId);
    if ($base === '') {
        return ['ok' => false, 'message' => 'This provider is not configured on this server yet. Set its base URL in Admin Settings (or .env).'];
    }
    $res = provider_http('POST', $base . '/v1/login/otp', [], ['mobile' => $mobile]);
    if ($res['error'] !== '') {
        return ['ok' => false, 'message' => $res['error']];
    }
    $status = $res['http'];
    $json = $res['json'] ?? [];
    if ($status >= 200 && $status < 300) {
        $msg = (string) (provider_pick($json, ['message', 'data.message']) ?? 'OTP sent successfully.');
        return ['ok' => true, 'message' => $msg];
    }
    $msg = (string) (provider_pick($json, ['message', 'error', 'data.message']) ?? ('Provider rejected the request (HTTP ' . $status . ').'));
    return ['ok' => false, 'message' => $msg];
}

/** Step 2 (personal providers): verify OTP, returns session token + UPI ids. */
function provider_verify_otp(string $providerId, string $mobile, string $otp): array
{
    $mobile = preg_replace('/\D/', '', $mobile);
    $otp = trim($otp);
    if (!preg_match('/^\d{4,8}$/', $otp)) {
        return ['ok' => false, 'message' => 'Enter the OTP you received on SMS.'];
    }
    $base = provider_base_url($providerId);
    if ($base === '') {
        return ['ok' => false, 'message' => 'This provider is not configured on this server yet. Set its base URL in Admin Settings (or .env).'];
    }
    $res = provider_http('POST', $base . '/v1/login/verify', [], ['mobile' => $mobile, 'otp' => $otp]);
    if ($res['error'] !== '') {
        return ['ok' => false, 'message' => $res['error']];
    }
    $status = $res['http'];
    $json = $res['json'] ?? [];
    if ($status < 200 || $status >= 300) {
        $msg = (string) (provider_pick($json, ['message', 'error', 'data.message']) ?? ('OTP verification failed (HTTP ' . $status . ').'));
        return ['ok' => false, 'message' => $msg];
    }
    $token = (string) (provider_pick($json, ['session_token', 'token', 'data.session_token', 'data.token', 'access_token', 'data.access_token']) ?? '');
    if ($token === '') {
        return ['ok' => false, 'message' => 'Provider response did not include a session token.'];
    }
    $upiIds = provider_pick($json, ['upi_ids', 'data.upi_ids', 'upiIds', 'data.upiIds', 'vpaList', 'data.vpaList']);
    if (is_string($upiIds)) {
        $upiIds = array_filter(array_map('trim', explode(',', $upiIds)));
    }
    if (!is_array($upiIds)) {
        $upiIds = [];
    }
    // Normalise: accept "id" strings or {upi_id|vpa|id} objects
    $normalized = [];
    foreach ($upiIds as $item) {
        if (is_string($item) && $item !== '') {
            $normalized[] = $item;
        } elseif (is_array($item)) {
            $id = $item['upi_id'] ?? $item['vpa'] ?? $item['id'] ?? $item['address'] ?? '';
            if (is_string($id) && $id !== '') {
                $normalized[] = $id;
            }
        }
    }
    return ['ok' => true, 'message' => 'Verified successfully.', 'session_token' => $token, 'upi_ids' => array_values(array_unique($normalized))];
}

/** Fetch the provider's recent transactions (last N). Returns a normalised list. */
function provider_recent_transactions(string $providerId, string $sessionToken, int $limit = 10): array
{
    $base = provider_base_url($providerId);
    if ($base === '') {
        return [];
    }
    $res = provider_http('GET', $base . '/v1/transactions?limit=' . $limit, ['Authorization' => 'Bearer ' . $sessionToken]);
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300 || !is_array($res['json'])) {
        return [];
    }
    $list = provider_pick($res['json'], ['transactions', 'data.transactions', 'data', 'items', 'history']);
    if (!is_array($list)) {
        return [];
    }
    $normalized = [];
    foreach ($list as $tx) {
        if (!is_array($tx)) {
            continue;
        }
        $amount = provider_pick($tx, ['amount', 'txnAmount', 'amountInRs', 'data.amount']);
        $status = strtoupper((string) (provider_pick($tx, ['status', 'txnStatus', 'state']) ?? 'SUCCESS'));
        $utr = (string) (provider_pick($tx, ['utr', 'utrNumber', 'bankReference', 'rrn', 'txnId', 'id']) ?? '');
        $timeRaw = provider_pick($tx, ['timestamp', 'time', 'created_at', 'date', 'txnTime']);
        if (is_numeric($timeRaw)) {
            $ts = (int) $timeRaw;
            if ($ts > 9999999999) {
                $ts = (int) ($ts / 1000); // milliseconds
            }
        } else {
            $ts = strtotime((string) $timeRaw) ?: time();
        }
        $normalized[] = [
            'utr' => $utr,
            'amount' => (float) str_replace([',', '₹', 'Rs.', 'INR', ' '], '', (string) $amount),
            'status' => (stripos($status, 'SUCCESS') !== false || $status === 'COMPLETED') ? 'success' : ((stripos($status, 'FAIL') !== false || stripos($status, 'REJECT') !== false) ? 'failed' : 'pending'),
            'payer' => (string) (provider_pick($tx, ['payer', 'payerVpa', 'from', 'sender', 'payerName']) ?? ''),
            'time' => $ts,
        ];
    }
    usort($normalized, fn($a, $b) => $b['time'] <=> $a['time']);
    return array_slice($normalized, 0, $limit);
}

/** Find a matching incoming payment in the provider feed. */
function provider_find_payment(string $providerId, string $sessionToken, float $amount, string $upiId, int $sinceTs): ?array
{
    $txs = provider_recent_transactions($providerId, $sessionToken, 25);
    foreach ($txs as $tx) {
        if (($tx['status'] ?? '') !== 'success') {
            continue;
        }
        if (abs((float) $tx['amount'] - $amount) > 0.01) {
            continue;
        }
        if ((int) $tx['time'] < $sinceTs) {
            continue;
        }
        return $tx;
    }
    return null;
}
