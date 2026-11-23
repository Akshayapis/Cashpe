<?php
/**
 * CashPe - Wallet ledger + payment-link status engine.
 */

require_once __DIR__ . '/firebase_helper.php';
require_once __DIR__ . '/provider_helper.php';
require_once __DIR__ . '/mailer.php';

function wallet_balance_fresh(string $uid): float
{
    $user = user_get($uid);
    return $user ? (float) ($user['wallet_balance'] ?? 0) : 0.0;
}

/** Credit the wallet and write a ledger entry. Returns the new balance. */
function wallet_credit(string $uid, float $amount, string $type, string $description, string $ref = ''): float
{
    $amount = round($amount, 2);
    $user = user_get($uid);
    if (!$user) {
        throw new RuntimeException('User not found');
    }
    $newBalance = round((float) ($user['wallet_balance'] ?? 0) + $amount, 2);
    user_update($uid, ['wallet_balance' => $newBalance]);
    fs_add('wallet_transactions', [
        'uid' => $uid,
        'type' => 'credit',
        'amount' => $amount,
        'description' => $description,
        'balance_after' => $newBalance,
        'ref' => $ref,
        'created_at' => time(),
    ]);
    return $newBalance;
}

/** Debit the wallet. Throws when funds are insufficient (unless negative allowed). */
function wallet_debit(string $uid, float $amount, string $type, string $description, string $ref = '', bool $allowNegative = false): float
{
    $amount = round($amount, 2);
    $user = user_get($uid);
    if (!$user) {
        throw new RuntimeException('User not found');
    }
    $oldBalance = round((float) ($user['wallet_balance'] ?? 0), 2);
    $newBalance = round($oldBalance - $amount, 2);
    if (!$allowNegative && $newBalance < 0) {
        throw new RuntimeException('Insufficient wallet balance');
    }
    user_update($uid, ['wallet_balance' => $newBalance]);
    fs_add('wallet_transactions', [
        'uid' => $uid,
        'type' => 'debit',
        'amount' => $amount,
        'description' => $description,
        'balance_after' => $newBalance,
        'ref' => $ref,
        'created_at' => time(),
    ]);
    // Low balance alert (only when crossing the threshold downwards)
    $threshold = 50.0;
    if ($oldBalance >= $threshold && $newBalance < $threshold && !empty($user['email'])) {
        try {
            send_low_balance($user['email'], $user['name'] ?? 'Merchant', format_money($newBalance));
        } catch (Throwable $e) {
            error_log('Low balance email failed: ' . $e->getMessage());
        }
    }
    return $newBalance;
}

/**
 * Finalize a pending link as paid: mark success, stamp UTR, deduct the
 * commission, log the ledger entry, email the merchant and fire the
 * outbound webhook. Idempotent - safe to call multiple times.
 */
function payment_finalize(array $link, string $utr): array
{
    if (($link['status'] ?? 'pending') === 'success') {
        return $link;
    }
    $linkId = $link['id'] ?? '';
    if ($linkId === '') {
        return $link;
    }
    $utr = trim($utr);
    if ($utr === '') {
        $utr = 'UTR' . time();
    }

    $paidAt = time();
    fs_update('payment_links', $linkId, [
        'status' => 'success',
        'utr' => $utr,
        'paid_at' => $paidAt,
    ]);
    $link['status'] = 'success';
    $link['utr'] = $utr;
    $link['paid_at'] = $paidAt;

    $uid = (string) ($link['uid'] ?? '');
    $commission = (float) ($link['commission'] ?? commission_for((float) ($link['amount'] ?? 0)));

    if ($uid !== '' && $commission > 0) {
        try {
            wallet_debit($uid, $commission, 'commission', 'Commission for payment link ' . ($link['link_id'] ?? $linkId), (string) ($link['link_id'] ?? $linkId), true);
        } catch (Throwable $e) {
            // Never leave a paid link unmarked because of a ledger failure
            error_log('Commission debit failed for ' . $linkId . ': ' . $e->getMessage());
        }
    }

    // Merchant notification + outbound webhook (after the response is sent)
    try {
        $user = $uid !== '' ? user_get($uid) : null;
        if ($user && !empty($user['email'])) {
            send_payment_received(
                $user['email'],
                $user['name'] ?? 'Merchant',
                format_money($link['amount'] ?? 0),
                (string) ($link['customer_name'] ?? 'Customer'),
                $utr,
                (string) ($link['link_id'] ?? $linkId)
            );
        }
        if ($user && !empty($user['webhook_url'])) {
            $snapshot = ['user' => $user, 'link' => $link, 'utr' => $utr];
            register_shutdown_function(function () use ($snapshot) {
                try {
                    if (!defined('CASHPE_INTERNAL')) {
                        define('CASHPE_INTERNAL', true);
                    }
                    require_once __DIR__ . '/../public/webhook_send.php';
                    if (function_exists('webhook_send')) {
                        webhook_send($snapshot['user'], $snapshot['link'], $snapshot['utr']);
                    }
                } catch (Throwable $e) {
                    error_log('Outbound webhook failed: ' . $e->getMessage());
                }
            });
        }
    } catch (Throwable $e) {
        error_log('payment_finalize notify failed: ' . $e->getMessage());
    }

    return $link;
}

/**
 * Refresh a payment link's status. Handles:
 *  - expiry after 30 minutes
 *  - business providers: consume a matching row from payment_callbacks
 *    (written by callback.php?action=payment, e.g. from an SMS forwarder)
 *  - personal providers: poll the provider's transaction feed via cURL
 */
function link_refresh_status(array $link): array
{
    if (($link['status'] ?? 'pending') !== 'pending') {
        return $link;
    }
    $linkId = (string) ($link['id'] ?? '');
    if ($linkId === '') {
        return $link;
    }

    // Expiry (30 minutes)
    $createdAt = (int) ($link['created_at'] ?? 0);
    if ($createdAt > 0 && (time() - $createdAt) > 1800) {
        fs_update('payment_links', $linkId, ['status' => 'expired']);
        $link['status'] = 'expired';
        return $link;
    }

    $providerId = (string) ($link['provider_id'] ?? '');
    $uid = (string) ($link['uid'] ?? '');
    $provider = null;
    if ($providerId !== '' && $uid !== '') {
        try {
            $provider = fs_get('users/' . $uid . '/providers', $providerId);
        } catch (Throwable $e) {
            error_log('link_refresh_status provider load failed: ' . $e->getMessage());
        }
    }

    $amount = (float) ($link['amount'] ?? 0);
    $since = $createdAt > 0 ? $createdAt - 300 : time() - 3600;

    // Personal provider: poll provider transaction feed
    if ($provider && (($provider['type'] ?? '') === 'personal') && !empty($provider['session_token'])) {
        $token = decrypt_secret((string) $provider['session_token']);
        if ($token !== false) {
            $match = provider_find_payment((string) $provider['provider'], $token, $amount, (string) ($link['upi_id'] ?? ''), $since);
            if ($match !== null) {
                return payment_finalize($link, (string) ($match['utr'] ?? ''));
            }
        }
        return $link;
    }

    // Business provider: look for an inbound callback recorded by callback.php
    try {
        $callbacks = fs_query('payment_callbacks', [['link_id', '==', $link['link_id'] ?? $linkId]], 5);
        foreach ($callbacks as $cb) {
            if (!empty($cb['consumed'])) {
                continue;
            }
            $cbAmount = (float) ($cb['amount'] ?? 0);
            if (abs($cbAmount - $amount) > 0.01 && $amount > 0) {
                continue;
            }
            fs_update('payment_callbacks', (string) $cb['id'], ['consumed' => true, 'consumed_at' => time()]);
            return payment_finalize($link, (string) ($cb['utr'] ?? ''));
        }
        // Fallback: match by upi_id + amount for callbacks that did not carry a link_id
        $upiId = (string) ($link['upi_id'] ?? '');
        if ($upiId !== '' && $uid !== '') {
            $callbacks = fs_query('payment_callbacks', [['upi_id', '==', $upiId]], 20);
            foreach ($callbacks as $cb) {
                if (!empty($cb['consumed'])) {
                    continue;
                }
                if ((int) ($cb['created_at'] ?? 0) < $since) {
                    continue;
                }
                $cbAmount = (float) ($cb['amount'] ?? 0);
                if (abs($cbAmount - $amount) > 0.01 && $amount > 0) {
                    continue;
                }
                if (!empty($cb['uid']) && $cb['uid'] !== $uid) {
                    continue;
                }
                fs_update('payment_callbacks', (string) $cb['id'], ['consumed' => true, 'consumed_at' => time()]);
                return payment_finalize($link, (string) ($cb['utr'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        error_log('link_refresh_status callback lookup failed: ' . $e->getMessage());
    }

    return $link;
}
