<?php
// ============================================================
// ADMIN — ACTIVITY LOGS
// File: modules/admin/logs.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('activity_log', 'view');

$pageTitle = 'অ্যাক্টিভিটি লগ';

$search     = trim($_GET['q']      ?? '');
$module     = $_GET['module']      ?? '';
$dateFrom   = $_GET['date_from']   ?? '';
$dateTo     = $_GET['date_to']     ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;

$where  = '1=1';
$params = [];
if ($search) { $where .= ' AND (al.description LIKE ? OR al.action LIKE ? OR u.full_name LIKE ?)'; $s = "%$search%"; array_push($params, $s, $s, $s); }
if ($module) { $where .= ' AND al.module = ?'; $params[] = $module; }
if ($dateFrom) { $where .= ' AND DATE(al.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where .= ' AND DATE(al.created_at) <= ?'; $params[] = $dateTo; }

$total  = Database::fetchOne("SELECT COUNT(*) as cnt FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE $where", $params)['cnt'];
$paging = paginate($total, $perPage, $page, '?' . http_build_query(array_filter(['q'=>$search,'module'=>$module,'date_from'=>$dateFrom,'date_to'=>$dateTo])) . '&page=');

$logs = Database::fetchAll(
    "SELECT al.*, u.full_name, u.username, c.company_name
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     LEFT JOIN companies c ON c.id = al.company_id
     WHERE $where
     ORDER BY al.created_at DESC
     LIMIT $perPage OFFSET {$paging['offset']}",
    $params
);

$modules = Database::fetchAll("SELECT DISTINCT module FROM activity_logs ORDER BY module");

// Action icon mapping
function actionIcon(string $action): string {
    if (str_contains($action, 'login'))    return 'box-arrow-in-right text-success';
    if (str_contains($action, 'logout'))   return 'box-arrow-right text-secondary';
    if (str_contains($action, 'create') || str_contains($action, 'register') || str_contains($action, 'add')) return 'plus-circle text-primary';
    if (str_contains($action, 'edit') || str_contains($action, 'update'))   return 'pencil text-warning';
    if (str_contains($action, 'delete') || str_contains($action, 'remove')) return 'trash text-danger';
    if (str_contains($action, 'approve'))  return 'check-circle text-success';
    if (str_contains($action, 'reject') || str_contains($action, 'suspend')) return 'x-circle text-danger';
    if (str_contains($action, 'resolve'))  return 'check2-circle text-success';
    if (str_contains($action, 'export'))   return 'download text-info';
    return 'activity text-secondary';
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>অ্যাক্টিভিটি লগ</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">লগ</li>
    </ol></nav>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
            <input type="text" name="q" class="form-control form-control-sm" style="width:200px;border-radius:20px;"
                   placeholder="অ্যাকশন / ব্যবহারকারী..." value="<?= htmlspecialchars($search) ?>">
            <select name="module" class="form-select form-select-sm" style="width:140px;border-radius:20px;">
                <option value="">সব মডিউল</option>
                <?php foreach ($modules as $m): ?>
                <option value="<?= $m['module'] ?>" <?= $module === $m['module'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['module']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:150px;border-radius:20px;"
                   value="<?= $dateFrom ?>" placeholder="তারিখ থেকে">
            <input type="date" name="date_to" class="form-control form-control-sm" style="width:150px;border-radius:20px;"
                   value="<?= $dateTo ?>" placeholder="তারিখ পর্যন্ত">
            <button class="btn btn-sm btn-primary" style="border-radius:20px;"><i class="bi bi-search"></i></button>
            <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:20px;"><i class="bi bi-x"></i> রিসেট</a>
            <span class="ms-auto text-muted small">মোট: <?= number_format($total) ?> টি</span>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th>অ্যাকশন</th>
                    <th>ব্যবহারকারী</th>
                    <th>কোম্পানি</th>
                    <th>বিবরণ</th>
                    <th>IP</th>
                    <th>সময়</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">কোনো লগ পাওয়া যায়নি</td></tr>
            <?php else: foreach ($logs as $log): ?>
            <tr>
                <td class="text-center">
                    <i class="bi bi-<?= actionIcon($log['action']) ?>" style="font-size:16px;"></i>
                </td>
                <td>
                    <code style="font-size:12px;background:#f1f5f9;padding:2px 7px;border-radius:4px;color:#1a3a5c;">
                        <?= htmlspecialchars($log['action']) ?>
                    </code>
                    <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($log['module']) ?></div>
                </td>
                <td>
                    <?php if ($log['full_name']): ?>
                    <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($log['full_name']) ?></div>
                    <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($log['username'] ?? '') ?></div>
                    <?php else: ?>
                    <span class="text-muted small">সিস্টেম</span>
                    <?php endif; ?>
                </td>
                <td><small class="text-muted"><?= htmlspecialchars($log['company_name'] ?? '—') ?></small></td>
                <td style="max-width:250px;">
                    <span class="text-truncate d-block" style="font-size:13px;" title="<?= htmlspecialchars($log['description'] ?? '') ?>">
                        <?= htmlspecialchars($log['description'] ?? '—') ?>
                    </span>
                </td>
                <td><small class="text-muted font-monospace"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></small></td>
                <td>
                    <small class="text-muted"><?= timeAgo($log['created_at']) ?></small>
                    <div class="text-muted" style="font-size:11px;"><?= formatDate($log['created_at'], 'd M Y') ?></div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($paging['total_pages'] > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <small class="text-muted"><?= $paging['offset']+1 ?>–<?= min($paging['offset']+$perPage, $paging['total']) ?> / <?= number_format($paging['total']) ?></small>
        <nav><ul class="pagination pagination-sm mb-0">
            <?php if ($paging['has_prev']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].$paging['current']-1 ?>">‹</a></li>
            <?php endif; ?>
            <?php for ($p=max(1,$paging['current']-2); $p<=min($paging['total_pages'],$paging['current']+2); $p++): ?>
            <li class="page-item <?= $p===$paging['current']?'active':'' ?>"><a class="page-link" href="<?= $paging['base_url'].$p ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <?php if ($paging['has_next']): ?>
            <li class="page-item"><a class="page-link" href="<?= $paging['base_url'].$paging['current']+1 ?>">›</a></li>
            <?php endif; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
