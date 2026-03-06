<?php
// ============================================================
// NOTIFICATION CENTER
// File: modules/notifications/index.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user      = getCurrentUser();
$pageTitle = 'নোটিফিকেশন';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;

// ---- Mark all as read ----
if (isset($_GET['mark_all_read'])) {
    Database::query(
        "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
        [$user['id']]
    );
    setFlash('success', 'সব নোটিফিকেশন পড়া হয়েছে।');
    redirect($_SERVER['PHP_SELF']);
}

// ---- Mark single as read (AJAX) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $nid = (int)$_POST['notification_id'];
    Database::update('notifications',
        ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
        'id = ? AND user_id = ?', [$nid, $user['id']]
    );
    jsonResponse(['success' => true]);
}

// ---- Fetch notifications ----
$filter   = $_GET['filter'] ?? 'all'; // all | unread
$where    = 'n.user_id = ?';
$params   = [$user['id']];
if ($filter === 'unread') { $where .= ' AND n.is_read = 0'; }

$total  = Database::fetchOne("SELECT COUNT(*) as cnt FROM notifications n WHERE $where", $params)['cnt'];
$paging = paginate($total, $perPage, $page, '?filter=' . $filter . '&page=');

$notifications = Database::fetchAll(
    "SELECT n.* FROM notifications n
     WHERE $where
     ORDER BY n.created_at DESC
     LIMIT $perPage OFFSET {$paging['offset']}",
    $params
);

$unreadCount = Database::count('notifications', 'user_id = ? AND is_read = 0', [$user['id']]);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>নোটিফিকেশন
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger rounded-pill ms-2" style="font-size:13px;"><?= $unreadCount ?></span>
            <?php endif; ?>
        </h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">নোটিফিকেশন</li>
        </ol></nav>
    </div>
    <?php if ($unreadCount > 0): ?>
    <a href="?mark_all_read=1" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-check-all me-1"></i>সব পড়া হয়েছে চিহ্নিত করুন
    </a>
    <?php endif; ?>
</div>

<!-- Filter Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex gap-2">
            <a href="?filter=all" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" style="border-radius:20px;font-size:13px;">
                সব <span class="badge bg-white text-dark ms-1"><?= $total ?></span>
            </a>
            <a href="?filter=unread" class="btn btn-sm <?= $filter === 'unread' ? 'btn-danger' : 'btn-outline-danger' ?>" style="border-radius:20px;font-size:13px;">
                অপঠিত <span class="badge bg-white text-dark ms-1"><?= $unreadCount ?></span>
            </a>
        </div>
    </div>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
    <div class="card-body">
        <div class="empty-state py-5">
            <i class="bi bi-bell-slash d-block fs-2 text-muted mb-3"></i>
            <p class="text-muted">কোনো নোটিফিকেশন নেই</p>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($notifications as $n):
        $iconMap  = ['success'=>'check-circle-fill text-success','warning'=>'exclamation-triangle-fill text-warning',
                     'danger'=>'x-circle-fill text-danger','info'=>'info-circle-fill text-primary'];
        $iconClass = $iconMap[$n['type']] ?? 'bell-fill text-secondary';
    ?>
    <div class="notification-item d-flex gap-3 px-4 py-3 border-bottom <?= !$n['is_read'] ? 'unread' : '' ?>"
         style="<?= !$n['is_read'] ? 'background:#f8faff;' : '' ?>cursor:pointer;"
         onclick="markRead(<?= $n['id'] ?>, '<?= $n['link'] ?? '' ?>')">
        <!-- Icon -->
        <div style="width:40px;height:40px;border-radius:50%;background:<?= !$n['is_read'] ? '#eff6ff' : '#f8fafc' ?>;
                    display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px;">
            <i class="bi bi-<?= $iconClass ?>"></i>
        </div>
        <!-- Content -->
        <div style="flex:1;min-width:0;">
            <div class="d-flex justify-content-between align-items-start">
                <div class="fw-semibold" style="font-size:14px;color:<?= !$n['is_read'] ? '#0f2238' : '#475569' ?>">
                    <?= htmlspecialchars($n['title']) ?>
                </div>
                <div class="d-flex align-items-center gap-2 ms-2 flex-shrink-0">
                    <?php if (!$n['is_read']): ?>
                    <span style="width:8px;height:8px;border-radius:50%;background:#2563a8;display:inline-block;"></span>
                    <?php endif; ?>
                    <small class="text-muted"><?= timeAgo($n['created_at']) ?></small>
                </div>
            </div>
            <div class="text-muted" style="font-size:13px;margin-top:2px;"><?= htmlspecialchars($n['message']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($paging['total_pages'] > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-center">
        <nav><ul class="pagination pagination-sm mb-0">
            <?php if ($paging['has_prev']): ?>
            <li class="page-item"><a class="page-link" href="?filter=<?= $filter ?>&page=<?= $paging['current']-1 ?>">‹</a></li>
            <?php endif; ?>
            <?php for ($p=max(1,$paging['current']-2); $p<=min($paging['total_pages'],$paging['current']+2); $p++): ?>
            <li class="page-item <?= $p===$paging['current']?'active':'' ?>">
                <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <?php if ($paging['has_next']): ?>
            <li class="page-item"><a class="page-link" href="?filter=<?= $filter ?>&page=<?= $paging['current']+1 ?>">›</a></li>
            <?php endif; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function markRead(id, link) {
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'mark_read=1&notification_id=' + id })
    .then(() => { if (link) window.location.href = link; else location.reload(); });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
