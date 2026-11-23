<?php
/**
 * CashPe - Unified callback handler.
 *
 * action=google        GET  - Google OAuth 2.0 (start + completion)
 * action=provider_otp  POST - Verify OTP for personal providers (session guarded)
 * action=payment       POST - Inbound payment notification (callback_token guarded)
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/icons.php';
require_once dirname(__DIR__) . '/includes/provider_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';

/** Styled error page for hard failures. */
function callback_error(string $title, string $message): never
{
    http_response_code(400);
    $GLOBALS['title'] = $title;
    $GLOBALS['message'] = $message;
    require CASHPE_ROOT . '/includes/error_page.php';
    exit;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// ---------------------------------------------------------------------------
// action=google
// ---------------------------------------------------------------------------
if ($action === 'google') {
    $code = (string) ($_GET['code'] ?? '');

    // Step 1: no code -> redirect to Google's consent screen
    if ($code === '') {
        if (GOOGLE_CLIENT_ID === '') {
            callback_error('Google Sign-In not configured', 'The server owner has not set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET. Use email + password, or contact support.');
        }
        $state = generate_token(16);
        $_SESSION['oauth_state'] = $state;
        $params = http_build_query([
            'client_id' => GOOGLE_CLIENT_ID,
            'redirect_uri' => url('callback.php?action=google'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
        exit;
    }

    // Step 2: exchange the code for tokens
    $state = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['oauth_state'] ?? '');
    if ($expectedState === '' || !hash_equals($expectedState, $state)) {
        callback_error('Sign-in expired', 'The sign-in session expired. Please try again.');
    }
    unset($_SESSION['oauth_state']);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => url('callback.php?action=google'),
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $tokenRaw = curl_exec($ch);
    $tokenErr = curl_error($ch);
    $tokenHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tokenErr !== '' || $tokenHttp < 200 || $tokenHttp >= 300) {
        error_log('Google token exchange failed: HTTP ' . $tokenHttp . ' ' . $tokenErr);
        callback_error('Google sign-in failed', 'Could not exchange the authorization code. Please try again.');
    }
    $tokenData = json_decode((string) $tokenRaw, true);
    $accessToken = is_array($tokenData) ? (string) ($tokenData['access_token'] ?? '') : '';
    if ($accessToken === '') {
        callback_error('Google sign-in failed', 'Google did not return an access token. Please try again.');
    }

    // Step 3: fetch the profile
    $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    ]);
    $profileRaw = curl_exec($ch);
    $profileHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $profile = json_decode((string) $profileRaw, true);

    if ($profileHttp !== 200 || !is_array($profile) || empty($profile['sub']) || empty($profile['email'])) {
        callback_error('Google sign-in failed', 'Could not read your Google profile. Please try again.');
    }
    $googleId = (string) $profile['sub'];
    $email = strtolower((string) $profile['email']);
    $name = trim((string) ($profile['name'] ?? explode('@', $email)[0]));

    try {
        $user = user_find_by_google_id($googleId);
        if (!$user) {
            $user = user_find_by_email($email);
            if ($user) {
                // Link Google to the existing account
                $linkUid = (string) ($user['uid'] ?? $user['id']);
                user_update($linkUid, ['google_id' => $googleId, 'email_verified' => true]);
                $user = user_get($linkUid) ?? $user;
                $user['google_id'] = $googleId;
                $user['email_verified'] = true;
            } else {
                // First sign-in ever: create the account
                $uid = uuid4();
                $newUser = [
                    'uid' => $uid,
                    'name' => $name !== '' ? $name : 'CashPe Merchant',
                    'email' => $email,
                    'password_hash' => '',
                    'google_id' => $googleId,
                    'email_verified' => true,
                    'wallet_balance' => 0,
                    'api_key' => generate_api_key(),
                    'callback_token' => generate_token(16),
                    'role' => 'user',
                    'status' => 'active',
                    'webhook_url' => '',
                    'ip_whitelist' => [],
                    'created_at' => time(),
                ];
                fs_set('users', $uid, $newUser, false);
                $user = $newUser;
                try {
                    send_welcome($email, $newUser['name']);
                } catch (Throwable $e) {
                    error_log('Welcome email failed: ' . $e->getMessage());
                }
            }
        }
        if (($user['status'] ?? 'active') === 'blocked') {
            callback_error('Account suspended', 'Your account has been suspended. Contact support@cashpe.onrender.com');
        }
        auth_login_user($user);
        redirect('dashboard.php');
    } catch (Throwable $e) {
        callback_error('Sign-in failed', $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// action=provider_otp (personal providers - step 2)
// ---------------------------------------------------------------------------
if ($action === 'provider_otp') {
    $user = auth_require_verified();
    csrf_validate();

    $pending = $_SESSION['pending_personal'] ?? null;
    if (!is_array($pending) || empty($pending['provider']) || empty($pending['mobile'])) {
        redirect('connect.php?error=' . urlencode('Start the provider setup again - your session expired.'));
    }
    $otp = trim((string) ($_POST['otp'] ?? ''));
    $result = provider_verify_otp((string) $pending['provider'], (string) $pending['mobile'], $otp);

    if (!$result['ok']) {
        redirect('provider-otp.php?step=otp&error=' . urlencode($result['message']));
    }

    // Regenerate session state and hold the verified material until the user picks a UPI ID
    session_regenerate_id(true);
    $_SESSION['personal_verified'] = [
        'provider' => $pending['provider'],
        'mobile' => $pending['mobile'],
        'session_token' => $result['session_token'],
        'upi_ids' => $result['upi_ids'],
        'verified_at' => time(),
    ];
    unset($_SESSION['pending_personal']);
    redirect('provider-otp.php?step=select');
}

// ---------------------------------------------------------------------------
// action=payment (inbound payment notification, token guarded)
// ---------------------------------------------------------------------------
if ($action === 'payment') {
    // Accept JSON or form posts
    $rawInput = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $token = trim((string) ($_GET['token'] ?? $payload['token'] ?? ($_SERVER['HTTP_X_CASHPE_TOKEN'] ?? '')));
    $amount = (float) ($payload['amount'] ?? 0);
    $utr = trim((string) ($payload['utr'] ?? ($payload['txn_ref'] ?? '')));
    $upiId = trim((string) ($payload['upi_id'] ?? ($payload['vpa'] ?? '')));
    $linkId = trim((string) ($payload['link_id'] ?? ''));

    try {
        $owner = user_find_by_callback_token($token);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    if (!$owner) {
        json_response(['ok' => false, 'error' => 'Invalid token'], 401);
    }
    $uid = (string) ($owner['uid'] ?? $owner['id']);

    // Try to match a pending link immediately
    $matched = null;
    try {
        if ($linkId !== '') {
            $links = fs_query('payment_links', [['link_id', '==', $linkId]], 1);
            $candidate = $links[0] ?? null;
            if ($candidate && ($candidate['uid'] ?? '') === $uid && ($candidate['status'] ?? '') === 'pending') {
                $matched = $candidate;
            }
        } elseif ($amount > 0) {
            $where = [['uid', '==', $uid], ['status', '==', 'pending']];
            if ($upiId !== '') {
                $where[] = ['upi_id', '==', $upiId];
            }
            $candidates = fs_query('payment_links', $where, 20, 'created_at', 'desc');
            foreach ($candidates as $candidate) {
                if (abs((float) ($candidate['amount'] ?? 0) - $amount) <= 0.01) {
                    $matched = $candidate;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('callback.php link match failed: ' . $e->getMessage());
    }

    if ($matched) {
        payment_finalize($matched, $utr !== '' ? $utr : ('CB' . time()));
        json_response(['ok' => true, 'matched' => true, 'link_id' => $matched['link_id'] ?? null]);
    }

    // Otherwise record the callback so a later poll can match it
    try {
        fs_add('payment_callbacks', [
            'uid' => $uid,
            'link_id' => $linkId,
            'upi_id' => $upiId,
            'amount' => $amount,
            'utr' => $utr,
            'source' => 'callback',
            'consumed' => false,
            'created_at' => time(),
        ]);
        json_response(['ok' => true, 'matched' => false]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

// Unknown action
callback_error('Unknown action', 'The callback action you requested does not exist.');
