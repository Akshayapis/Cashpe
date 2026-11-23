<?php
/**
 * CashPe - Firebase / Firestore bootstrap (kreait/firebase-php v7).
 */

require_once __DIR__ . '/config.php';

use Kreait\Firebase\Factory;

/**
 * Returns a shared Firestore database client.
 * Throws RuntimeException with a safe message when credentials are missing
 * or Firebase is unreachable.
 */
function firestore()
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $json = (string) env('FIREBASE_CREDENTIALS_JSON', '');
    $path = (string) env('FIREBASE_CREDENTIALS_PATH', '');

    try {
        $factory = new Factory();

        if ($json !== '') {
            $factory = $factory->withServiceAccountString($json);
        } elseif ($path !== '' && is_file($path)) {
            $factory = $factory->withServiceAccount($path);
        } elseif (is_file(CASHPE_ROOT . '/firebase-credentials.json')) {
            $factory = $factory->withServiceAccount(CASHPE_ROOT . '/firebase-credentials.json');
        } else {
            throw new RuntimeException('Firebase credentials not configured. Set FIREBASE_CREDENTIALS_JSON or FIREBASE_CREDENTIALS_PATH.');
        }

        $firestore = $factory->createFirestore();
        $db = $firestore->database();
        return $db;
    } catch (Throwable $e) {
        error_log('Firebase init failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Please try again later.');
    }
}
