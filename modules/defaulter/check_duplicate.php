<?php
// ============================================================
// AJAX DUPLICATE CHECK — A-2.1 + A-2.4
// File: modules/defaulter/check_duplicate.php
// Checks: phone, NID, fuzzy name — returns JSON
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credit_score.php';
requireLogin();

$type   = $_GET['type']  ?? 'phone';  // phone | nid | name
$value  = trim($_GET['value'] ?? '');
$editId = (int)($_GET['edit_id'] ?? 0); // এডিটের সময় নিজেকে বাদ দেবে

if (empty($value)) {
    jsonResponse(['found' => false, 'count' => 0]);
}

$user      = getCurrentUser();
$excludeSql = $editId ? ' AND d.id != ' . $editId : '';

// ============================================================
// PHONE CHECK
// ============================================================
if ($type === 'phone') {
    $rows = Database::fetchAll(
        "SELECT d.id, d.customer_name, d.customer_phone, d.due_amount,
                d.risk_level, d.status, d.created_at,
                c.company_name,
                (SELECT photo_path FROM defaulter_photos
                 WHERE defaulter_id = d.id AND is_primary=1 LIMIT 1) as photo
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         WHERE d.customer_phone = ? AND d.status != 'removed' $excludeSql
         ORDER BY FIELD(d.status,'active','disputed','resolved') LIMIT 10",
        [$value]
    );

    $score = !empty($rows) ? CreditScore::calculate($value) : null;

    jsonResponse([
        'found'   => count($rows) > 0,
        'count'   => count($rows),
        'type'    => 'phone',
        'entries' => array_map(fn($r) => [
            'id'           => $r['id'],
            'name'         => $r['customer_name'],
            'phone'        => $r['customer_phone'],
            'due'          => $r['due_amount'],
            'due_fmt'      => '৳' . number_format($r['due_amount']),
            'risk'         => $r['risk_level'],
            'status'       => $r['status'],
            'status_label' => getStatusLabel($r['status']),
            'company'      => $r['company_name'],
            'time_ago'     => timeAgo($r['created_at']),
            'photo'        => $r['photo'] ? UPLOAD_URL . $r['photo'] : null,
            'view_url'     => SITE_URL . '/modules/defaulter/view.php?id=' . $r['id'],
        ], $rows),
        'score' => $score ? [
            'value'  => $score['score'],
            'label'  => $score['label']['text'],
            'color'  => $score['label']['color'],
            'bl'     => $score['blacklist']['label'],
            'repeat' => $score['is_repeat'],
        ] : null,
    ]);
}

// ============================================================
// NID CHECK — A-2.1
// ============================================================
if ($type === 'nid') {
    $rows = Database::fetchAll(
        "SELECT d.id, d.customer_name, d.customer_phone, d.nid_number,
                d.due_amount, d.risk_level, d.status, d.created_at,
                c.company_name,
                (SELECT photo_path FROM defaulter_photos
                 WHERE defaulter_id = d.id AND is_primary=1 LIMIT 1) as photo
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         WHERE d.nid_number = ? AND d.nid_number != '' AND d.status != 'removed' $excludeSql
         ORDER BY FIELD(d.status,'active','disputed','resolved') LIMIT 10",
        [$value]
    );

    // ভিন্ন নামে একই NID — fraud indicator
    $uniqueNames  = array_unique(array_column($rows, 'customer_name'));
    $uniquePhones = array_unique(array_column($rows, 'customer_phone'));
    $isFraud      = count($uniqueNames) > 1 || count($uniquePhones) > 1;

    jsonResponse([
        'found'        => count($rows) > 0,
        'count'        => count($rows),
        'type'         => 'nid',
        'is_fraud'     => $isFraud,
        'unique_names' => count($uniqueNames),
        'unique_phones'=> count($uniquePhones),
        'entries'      => array_map(fn($r) => [
            'id'           => $r['id'],
            'name'         => $r['customer_name'],
            'phone'        => $r['customer_phone'],
            'nid'          => $r['nid_number'],
            'due'          => $r['due_amount'],
            'due_fmt'      => '৳' . number_format($r['due_amount']),
            'risk'         => $r['risk_level'],
            'status'       => $r['status'],
            'status_label' => getStatusLabel($r['status']),
            'company'      => $r['company_name'],
            'time_ago'     => timeAgo($r['created_at']),
            'photo'        => $r['photo'] ? UPLOAD_URL . $r['photo'] : null,
            'view_url'     => SITE_URL . '/modules/defaulter/view.php?id=' . $r['id'],
        ], $rows),
    ]);
}

// ============================================================
// FUZZY NAME CHECK — A-2.2
// ============================================================
if ($type === 'name') {
    if (mb_strlen($value, 'UTF-8') < 3) {
        jsonResponse(['found' => false, 'count' => 0, 'entries' => []]);
    }

    // ১. Exact match
    // ২. First 4 chars LIKE match (Bangla fuzzy)
    // ৩. Common name variations
    $prefix  = mb_substr($value, 0, 4, 'UTF-8');

    // নাম variations তৈরি করা
    $variations = self_nameVariations($value);
    $likeParams = array_map(fn($v) => "%$v%", $variations);

    $orClauses = implode(' OR ', array_fill(0, count($likeParams), 'd.customer_name LIKE ?'));

    $rows = Database::fetchAll(
        "SELECT DISTINCT d.id, d.customer_name, d.customer_phone, d.nid_number,
                d.due_amount, d.risk_level, d.status, d.created_at, c.company_name
         FROM defaulters d
         JOIN companies c ON c.id = d.company_id
         WHERE ($orClauses) AND d.status != 'removed' $excludeSql
         ORDER BY FIELD(d.status,'active','disputed','resolved'),
                  CASE WHEN d.customer_name = ? THEN 0
                       WHEN d.customer_name LIKE ? THEN 1
                       ELSE 2 END
         LIMIT 8",
        [...$likeParams, $value, "%$value%"]
    );

    jsonResponse([
        'found'   => count($rows) > 0,
        'count'   => count($rows),
        'type'    => 'name',
        'entries' => array_map(fn($r) => [
            'id'           => $r['id'],
            'name'         => $r['customer_name'],
            'phone'        => $r['customer_phone'],
            'nid'          => $r['nid_number'] ?? '',
            'due_fmt'      => '৳' . number_format($r['due_amount']),
            'risk'         => $r['risk_level'],
            'status'       => $r['status'],
            'status_label' => getStatusLabel($r['status']),
            'company'      => $r['company_name'],
            'time_ago'     => timeAgo($r['created_at']),
            'view_url'     => SITE_URL . '/modules/defaulter/view.php?id=' . $r['id'],
        ], $rows),
    ]);
}

jsonResponse(['found' => false, 'count' => 0]);

// ── A-2.2: নাম variation তৈরি ─────────────────────────────
function self_nameVariations(string $name): array {
    $vars = [$name];

    // বাংলা সাধারণ বিকল্প বানান
    $replacements = [
        'আব্দুল'  => ['আবদুল',  'আব্দুল'],
        'আবদুল'   => ['আব্দুল', 'আবদুল'],
        'মোহাম্মদ'=> ['মোহাম্মাদ', 'মুহাম্মদ', 'মুহাম্মাদ', 'মো.', 'মোঃ', 'মো'],
        'মুহাম্মদ'=> ['মোহাম্মদ', 'মুহম্মদ', 'মো.', 'মোঃ'],
        'মো.'     => ['মোঃ', 'মো', 'মোহাম্মদ'],
        'মোঃ'     => ['মো.', 'মো', 'মোহাম্মদ'],
        'মো'      => ['মো.', 'মোঃ'],
        'রহমান'   => ['রহমাণ'],
        'হোসেন'   => ['হোসেইন', 'হুসেন', 'হুসেইন'],
        'হুসেন'   => ['হোসেন', 'হোসেইন'],
        'বেগম'    => ['বেগম'],
        'খানম'    => ['খানোম', 'খানুম'],
        'আলী'     => ['আলি'],
        'আলি'     => ['আলী'],
        'ইসলাম'   => ['ইছলাম'],
        'শেখ'     => ['শেইখ', 'শেখ্'],
        'সিকদার'  => ['সিকদার', 'সিকদার'],
    ];

    foreach ($replacements as $from => $tos) {
        if (mb_strpos($name, $from, 0, 'UTF-8') !== false) {
            foreach ($tos as $to) {
                $vars[] = str_replace($from, $to, $name);
            }
        }
    }

    // প্রথম ৪ অক্ষর দিয়ে LIKE
    $prefix = mb_substr($name, 0, 4, 'UTF-8');
    if (!in_array($prefix, $vars)) $vars[] = $prefix;

    return array_unique($vars);
}
