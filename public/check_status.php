<?php
/**
 * CashPe - AJAX polling endpoint for the public payment page.
 * GET ?id=pl_xxx -> JSON { status, utr }
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/firebase_helper.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';

header('Cache-Control: no-store');

$id = trim((string) ($_GET['id'] ?? ''));

if ($id === '' || !preg_match('/^pl_[a-f0-9]{20}$/', $id)) {
    json_response(['status' => 'error', 'message' => 'Invalid link id'], 400);
}

try {
    $rows = fs_query('payment_links', [['link_id', '==', $id]], 1);
    $link = $rows[0] ?? null;
} catch (Throwable $e) {
    error_log('check_status load failed: ' . $e->getMessage());
    json_response(['status' => 'error', 'message' => 'Database unavailable'], 503);
}

if (!$link) {
    json_response(['status' => 'error', 'message' => 'Link not found'], 404);
}

try {
    $link = link_refresh_status($link);
} catch (Throwable $e) {
    error_log('check_status refresh failed: ' . $e->getMessage());
    // Fall back to the stored status
}

json_response([
    'status' => $link['status'] ?? 'pending',
    'utr' => $link['utr'] ?? null,
]);
