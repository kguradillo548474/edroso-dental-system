<?php
/**
 * Patient portal booking form options (no staff auth; read-only GET).
 */
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$db = getDB();
$db->query(
    'CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(191) NOT NULL PRIMARY KEY,
        `value` TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$defaultPayments  = ['Cash', 'GCash', 'Bank Transfer', 'PhilHealth', 'HMO'];
$defaultReferrals = ['Facebook', 'Instagram', 'TikTok', 'Referral', 'Walk-in', 'Other'];

$payment_methods = $defaultPayments;
$stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
if ($stmt) {
    $kBp = 'billing_preferences';
    $stmt->bind_param('s', $kBp);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && isset($row['value']) && trim((string) $row['value']) !== '') {
        $decoded = json_decode((string) $row['value'], true);
        if (is_array($decoded) && !empty($decoded['payment_types']) && is_array($decoded['payment_types'])) {
            $payment_methods = array_values(array_filter(array_map(function ($x) {
                return trim((string) $x);
            }, $decoded['payment_types']), function ($x) {
                return $x !== '';
            }));
        }
    }
}
if (!$payment_methods) {
    $payment_methods = $defaultPayments;
}

$referral_sources = $defaultReferrals;
$stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
if ($stmt) {
    $kRef = 'portal_referral_sources';
    $stmt->bind_param('s', $kRef);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && isset($row['value']) && trim((string) $row['value']) !== '') {
        $decoded = json_decode((string) $row['value'], true);
        if (is_array($decoded)) {
            $referral_sources = array_values(array_filter(array_map(function ($x) {
                return trim((string) $x);
            }, $decoded), function ($x) {
                return $x !== '';
            }));
        }
    }
}
if (!$referral_sources) {
    $referral_sources = $defaultReferrals;
}

respond([
    'payment_methods'  => $payment_methods,
    'referral_sources' => $referral_sources,
]);
