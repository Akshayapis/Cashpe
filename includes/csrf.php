<?php
/**
 * CashPe - CSRF protection (synchronizer token pattern).
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(32);
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate the CSRF token of the current request; aborts with a styled 403 on mismatch. */
function csrf_validate(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
        http_response_code(403);
        $title = 'Invalid security token';
        $message = 'Your session expired or the form was tampered with. Please go back and try again.';
        require CASHPE_ROOT . '/includes/error_page.php';
        exit;
    }
}
