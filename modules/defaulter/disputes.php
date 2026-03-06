<?php
// ============================================================
// DISPUTES
// File: modules/defaulter/disputes.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('disputes', 'view');

$user      = getCurrentUser();
$pageTitle = 'বিরোধ ব্যবস্থাপনা';

// ---- Handle Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।'); redirect($_SERVER['PHP_SELF']);
    }

    $action    = $_POST['action'] ?? '';
    $disputeId = (int)($_POST['dispute_id'] ?? 0);
    $defId     = (int)($_POST['defaulter_id'] ?? 0);
    $note      = trim($_POST['admin_note'] ?? '');
    $reason    = trim($_POST['reason'] ?? '');

    if ($action === 'raise') {
        if (empty($reason)) { setFlash('error', 'কারণ লিখুন।'); redirect('view.php?id='.$defId); }
        Database::insert('disputes', [
            'defaulter_id' => $defId,
            'raised_by'    => $user['id'],
            'reason'       => $reason,
            'status'       => 'open',
        ]);
        Database::update('defaulters', ['status' => 'disputed'], 'id = ?', [$defId]);
        logActivity('dispute.raise', 'disputes', ['target_id'=>$defId,'description'=>'বিরোধ উত্থাপিত হয়েছে']);
        setFlash('success', 'বিরোধ উত্থাপিত হয়েছে।');
        redirect('view.php?id='.$defId);
    }

    if ($action === 'resolve' && hasPermission('disputes','manage')) {
        Database::update('disputes', [
            'status'      => 'resolved',
            'admin_note'  => $note,
            'resolved_by' => $user['id'],
        ], 'id = ?', [$disputeId]);
        // If all disputes resolved, set defaulter back to active
        $dispute = Database::fetchOne("SELECT defaulter_id FROM disputes WHERE id = ?", [$disputeId]);
        if ($dispute) {
            $openCount = Database::count('disputes', "defaulter_id = ? AND status = 'open'", [$dispute['defaulter_id']]);
            if ($openCount === 0) {
                Database::update('defaulters', ['status' => 'active'], 'id = ? AND status = ?', [$dispute['defaulter_id'], 'disputed']);
            }
        }
        logActivity('dispute.resolve', 'disputes', ['target_id'=>$disputeId,'description'=>'বিরোধ সমাধান করা হয়েছে']);
        setFlash('success', 'বিরোধ সমাধান করা হয়েছে।');
    }

    if ($action === 'reject' && hasPermission('disputes','manage')) {
        Database::update('disputes', [
            'status'      => 'rejected',
            'admin_note'  => $note,
            'resolved_by' => $user['id'],
        ], 'id = ?', [$disputeId]);
        logActivity('dispute.reject', 'disputes', ['target_id'=>$disputeId]);
        setFlash('warning', 'বিরোধ বাতিল করা হয়েছে।');
    }

    redirect($_SERVER['PHP_SELF']);
}

// ---- List ----
$statusFilter = $_GET['status'] ?? 'open';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

$where  = '1=1';
$params = [];
if ($statusFilter) { $where .= ' AND dis.status = ?'; $params[] = $statusFilter; }
if (!in_array($user['role_name'], ['super_admin','admin'])) {
    $where .= ' AND (u.company_id = ? OR d.company_id = ?)';
    $params[] = $user['company_id']; $params[] = $user['company_id'];
}

$total   = Database::fetchOne("SELECT COUNT(*) as cnt FROM disputes dis JOIN users u ON u.id = dis.raised_by JOIN defaulters d ON d.id = dis.defaulter_id WHERE $where", $params)['cnt'];
$paging  = paginate($total, $perPage, $page, '?status=' . urlencode($statusFilter) . '&page=');

$disputes = Database::fetchAll(
    "SELECT dis.*, d.customer_name, d.customer_phone, d.due_amount,
            u.full_name as raised_by_name, c.company_name as raised_by_company,
            dc.company_name as defaulter_company, ru.full_name as resolved_by_name
     FROM disputes dis
     JOIN defaulters d ON d.id = dis.defaulter_id
     JOIN users u ON u.id = dis.raised_by
     JOIN companies c ON c.id = u.company_id
     JOIN companies dc ON dc.id = d.company_id
     LEFT JOIN users ru ON ru.id = dis.resolved_by
     WHERE $where
     ORDER BY dis.created_at DESC
     LIMIT $perPage OFFSET {$paging['offset']}",
    $params
);

$counts = [];
foreach (['open','under_review','resolved','rejected'] as $s) {
    $counts[$s] = Database::count('disputes', 'status = ?', [$s]);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1>বিরোধ ব্যবস্থাপনা</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">বিরোধ</li>
    </ol></nav>
</div>

<!-- Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1">
            <?php $tabs = ['open'=>['label'=>'খোলা','class'=>'danger'],'under_review'=>['label'=>'পর্যালোচনায়','class'=>'warning'],'resolved'=>['label'=>'সমাধান','class'=>'success'],'rejected'=>['label'=>'বাতিল','class'=>'secondary']];
            foreach ($tabs as $sv => $info): ?>
            <a href="?status=<?= $sv ?>" class="btn btn-sm <?= $statusFilter===$sv ? 'btn-'.$info['class'] : 'btn-outline-'.$info['class'] ?>" style="border-radius:20px;font-size:13px;">
                <?= $info['label'] ?> <span class="badge bg-white text-dark ms-1"><?= $counts[$sv] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>গ্রাহক</th>
                    <th>বিরোধকারী কোম্পানি</th>
                    <th>কারণ</th>
                    <th>এন্ট্রি কোম্পানি</th>
                    <th>তারিখ</th>
                    <th>স্ট্যাটাস</th>
                    <?php if (hasPermission('disputes','manage')): ?><th>অ্যাকশন</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($disputes)): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">কোনো বিরোধ নেই</td></tr>
            <?php else: foreach ($disputes as $dis): ?>
            <tr>
                <td>
                    <a href="view.php?id=<?= $dis['defaulter_id'] ?>" class="fw-semibold text-decoration-none text-dark">
                        <?= htmlspecialchars($dis['customer_name']) ?>
                    </a>
                    <div class="text-muted small"><?= htmlspecialchars($dis['customer_phone']) ?> — <?= formatMoney($dis['due_amount']) ?></div>
                </td>
                <td><small><?= htmlspecialchars($dis['raised_by_name']) ?><br><?= htmlspecialchars($dis['raised_by_company']) ?></small></td>
                <td style="max-width:200px;">
                    <div class="text-truncate" style="font-size:13px;" title="<?= htmlspecialchars($dis['reason']) ?>">
                        <?= htmlspecialchars($dis['reason']) ?>
                    </div>
                    <?php if ($dis['admin_note']): ?>
                    <div class="text-success small mt-1"><i class="bi bi-reply me-1"></i><?= htmlspecialchars($dis['admin_note']) ?></div>
                    <?php endif; ?>
                </td>
                <td><small><?= htmlspecialchars($dis['defaulter_company']) ?></small></td>
                <td><small class="text-muted"><?= timeAgo($dis['created_at']) ?></small></td>
                <td><span class="badge bg-<?= getBadgeClass($dis['status']) ?> rounded-pill"><?= $dis['status'] ?></span></td>
                <?php if (hasPermission('disputes','manage')): ?>
                <td>
                    <?php if ($dis['status'] === 'open'): ?>
                    <button class="btn btn-sm btn-success" style="border-radius:6px;padding:3px 8px;"
                            onclick="resolveDispute(<?= $dis['id'] ?>, 'resolve')">
                        <i class="bi bi-check me-1"></i>সমাধান
                    </button>
                    <button class="btn btn-sm btn-outline-danger" style="border-radius:6px;padding:3px 8px;"
                            onclick="resolveDispute(<?= $dis['id'] ?>, 'reject')">
                        <i class="bi bi-x me-1"></i>বাতিল
                    </button>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Resolve Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius:14px;">
            <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="resolveModalTitle"></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="resolveAction">
                <input type="hidden" name="dispute_id" id="resolveDisputeId">
                <div class="modal-body pt-0">
                    <label class="form-label fw-semibold">নোট / মন্তব্য</label>
                    <textarea name="admin_note" class="form-control" rows="3" placeholder="সিদ্ধান্তের কারণ লিখুন..."></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">বাতিল</button>
                    <button type="submit" class="btn" id="resolveSubmitBtn"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const resolveModal = new bootstrap.Modal(document.getElementById('resolveModal'));
function resolveDispute(id, action) {
    document.getElementById('resolveDisputeId').value = id;
    document.getElementById('resolveAction').value = action;
    if (action === 'resolve') {
        document.getElementById('resolveModalTitle').textContent = 'বিরোধ সমাধান';
        document.getElementById('resolveSubmitBtn').textContent = 'সমাধান করুন';
        document.getElementById('resolveSubmitBtn').className = 'btn btn-success';
    } else {
        document.getElementById('resolveModalTitle').textContent = 'বিরোধ বাতিল';
        document.getElementById('resolveSubmitBtn').textContent = 'বাতিল করুন';
        document.getElementById('resolveSubmitBtn').className = 'btn btn-danger';
    }
    resolveModal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
