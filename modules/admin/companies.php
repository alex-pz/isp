<?php
// ============================================================
// ADMIN — COMPANY MANAGEMENT
// File: modules/admin/companies.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('companies', 'view');

$user      = getCurrentUser();
$pageTitle = 'কোম্পানি ব্যবস্থাপনা';

// ---- Handle Actions (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।');
        redirect($_SERVER['PHP_SELF']);
    }

    $action    = $_POST['action']     ?? '';
    $companyId = (int)($_POST['company_id'] ?? 0);
    $note      = trim($_POST['note'] ?? '');

    $company = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$companyId]);
    if (!$company) { setFlash('error', 'কোম্পানি পাওয়া যায়নি।'); redirect($_SERVER['PHP_SELF']); }

    if ($action === 'approve' && hasPermission('companies', 'approve')) {
        Database::update('companies',
            ['status' => 'approved', 'approved_by' => $user['id'],
             'approved_at' => date('Y-m-d H:i:s'), 'rejection_note' => null],
            'id = ?', [$companyId]
        );
        // Notify company admin
        $cu = Database::fetchOne("SELECT id FROM users WHERE company_id = ? AND role_id = 3 LIMIT 1", [$companyId]);
        if ($cu) createNotification('রেজিস্ট্রেশন অনুমোদিত!',
            '"' . $company['company_name'] . '" সিস্টেমে অ্যাক্সেস পেয়েছে।',
            ['user_id' => $cu['id'], 'type' => 'success', 'icon' => 'check-circle',
             'link' => SITE_URL . '/dashboard.php']);
        logActivity('company.approve', 'companies', ['target_id' => $companyId, 'target_type' => 'companies',
            'description' => '"' . $company['company_name'] . '" অনুমোদন করা হয়েছে']);
        setFlash('success', '"' . $company['company_name'] . '" অনুমোদন করা হয়েছে।');

    } elseif ($action === 'reject' && hasPermission('companies', 'approve')) {
        Database::update('companies',
            ['status' => 'rejected', 'rejection_note' => $note ?: 'কারণ উল্লেখ করা হয়নি।'],
            'id = ?', [$companyId]
        );
        logActivity('company.reject', 'companies', ['target_id' => $companyId, 'target_type' => 'companies',
            'description' => '"' . $company['company_name'] . '" বাতিল: ' . $note]);
        setFlash('warning', '"' . $company['company_name'] . '" বাতিল করা হয়েছে।');

    } elseif ($action === 'suspend' && hasPermission('companies', 'suspend')) {
        Database::update('companies', ['status' => 'suspended', 'rejection_note' => $note ?: null], 'id = ?', [$companyId]);
        logActivity('company.suspend', 'companies', ['target_id' => $companyId, 'target_type' => 'companies',
            'description' => '"' . $company['company_name'] . '" স্থগিত করা হয়েছে']);
        setFlash('warning', '"' . $company['company_name'] . '" স্থগিত করা হয়েছে।');

    } elseif ($action === 'reactivate' && hasPermission('companies', 'approve')) {
        Database::update('companies',
            ['status' => 'approved', 'rejection_note' => null,
             'approved_by' => $user['id'], 'approved_at' => date('Y-m-d H:i:s')],
            'id = ?', [$companyId]
        );
        logActivity('company.reactivate', 'companies', ['target_id' => $companyId]);
        setFlash('success', '"' . $company['company_name'] . '" পুনরায় সক্রিয় করা হয়েছে।');
    }

    redirect($_SERVER['PHP_SELF'] . '?status=' . ($_GET['status'] ?? ''));
}

// ---- Filters ----
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 15;

$where  = '1=1';
$params = [];

if ($statusFilter) { $where .= ' AND c.status = ?'; $params[] = $statusFilter; }
if ($search) {
    $where .= ' AND (c.company_name LIKE ? OR c.owner_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.area LIKE ?)';
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

$total   = Database::fetchOne("SELECT COUNT(*) as cnt FROM companies c WHERE $where", $params)['cnt'];
$paging  = paginate($total, $perPage, $page, $_SERVER['PHP_SELF'] . '?status=' . urlencode($statusFilter) . '&q=' . urlencode($search) . '&page=');
$companies = Database::fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM defaulters WHERE company_id = c.id AND status = 'active') as active_defaulters,
            (SELECT COUNT(*) FROM users WHERE company_id = c.id AND status = 'active') as user_count,
            u.full_name as approved_by_name
     FROM companies c
     LEFT JOIN users u ON u.id = c.approved_by
     WHERE $where
     ORDER BY FIELD(c.status,'pending','approved','suspended','rejected'), c.created_at DESC
     LIMIT {$perPage} OFFSET {$paging['offset']}",
    $params
);

// Status counts for tabs
$statusCounts = [];
foreach (['pending','approved','suspended','rejected'] as $s) {
    $statusCounts[$s] = Database::count('companies', 'status = ?', [$s]);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1>কোম্পানি ব্যবস্থাপনা</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">কোম্পানি</li>
        </ol></nav>
    </div>
</div>

<!-- Status Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-1">
            <a href="?status=" class="btn btn-sm <?= !$statusFilter ? 'btn-primary' : 'btn-outline-secondary' ?>" style="border-radius:20px;font-size:13px;">
                সব <span class="badge bg-white text-dark ms-1"><?= array_sum($statusCounts) ?></span>
            </a>
            <a href="?status=pending" class="btn btn-sm <?= $statusFilter === 'pending' ? 'btn-warning' : 'btn-outline-warning' ?>" style="border-radius:20px;font-size:13px;">
                পেন্ডিং <span class="badge bg-white text-dark ms-1"><?= $statusCounts['pending'] ?></span>
            </a>
            <a href="?status=approved" class="btn btn-sm <?= $statusFilter === 'approved' ? 'btn-success' : 'btn-outline-success' ?>" style="border-radius:20px;font-size:13px;">
                অনুমোদিত <span class="badge bg-white text-dark ms-1"><?= $statusCounts['approved'] ?></span>
            </a>
            <a href="?status=suspended" class="btn btn-sm <?= $statusFilter === 'suspended' ? 'btn-secondary' : 'btn-outline-secondary' ?>" style="border-radius:20px;font-size:13px;">
                স্থগিত <span class="badge bg-white text-dark ms-1"><?= $statusCounts['suspended'] ?></span>
            </a>
            <a href="?status=rejected" class="btn btn-sm <?= $statusFilter === 'rejected' ? 'btn-danger' : 'btn-outline-danger' ?>" style="border-radius:20px;font-size:13px;">
                বাতিল <span class="badge bg-white text-dark ms-1"><?= $statusCounts['rejected'] ?></span>
            </a>

            <!-- Search -->
            <form class="ms-auto d-flex gap-2" method="GET">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <input type="text" name="q" class="form-control form-control-sm" style="border-radius:20px;width:200px;"
                       placeholder="কোম্পানি খুঁজুন..." value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-sm btn-primary" style="border-radius:20px;"><i class="bi bi-search"></i></button>
            </form>
        </div>
    </div>
</div>

<!-- Company Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>#</th>
                    <th>কোম্পানি</th>
                    <th>মালিক</th>
                    <th>এলাকা</th>
                    <th>যোগাযোগ</th>
                    <th>বকেয়া এন্ট্রি</th>
                    <th>স্ট্যাটাস</th>
                    <th>নিবন্ধন তারিখ</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($companies)): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">
                    <i class="bi bi-building d-block fs-2 mb-2"></i>কোনো কোম্পানি পাওয়া যায়নি
                </td></tr>
            <?php else: foreach ($companies as $c): ?>
            <tr>
                <td class="text-muted small"><?= $c['id'] ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($c['logo']): ?>
                            <img src="<?= UPLOAD_URL . $c['logo'] ?>" alt="" class="customer-photo">
                        <?php else: ?>
                            <div class="customer-photo-placeholder"
                                 style="background:#eff6ff;color:#2563a8;font-weight:700;font-size:14px;">
                                <?= mb_substr($c['company_name'], 0, 1, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($c['company_name']) ?></div>
                            <div class="text-muted small"><?= $c['user_count'] ?> ব্যবহারকারী</div>
                        </div>
                    </div>
                </td>
                <td><?= htmlspecialchars($c['owner_name']) ?></td>
                <td><?= htmlspecialchars($c['area']) ?></td>
                <td>
                    <div style="font-size:13px;"><?= htmlspecialchars($c['phone']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($c['email']) ?></div>
                </td>
                <td>
                    <span class="fw-bold" style="color:<?= $c['active_defaulters'] > 0 ? '#dc2626' : '#16a34a' ?>">
                        <?= $c['active_defaulters'] ?>
                    </span>
                </td>
                <td>
                    <span class="badge bg-<?= getBadgeClass($c['status']) ?> rounded-pill">
                        <?= getStatusLabel($c['status']) ?>
                    </span>
                    <?php if ($c['status'] === 'pending'): ?>
                    <div class="text-muted" style="font-size:11px;"><?= timeAgo($c['created_at']) ?></div>
                    <?php endif; ?>
                    <?php if ($c['rejection_note']): ?>
                    <div class="text-muted small" style="max-width:120px;" title="<?= htmlspecialchars($c['rejection_note']) ?>">
                        <i class="bi bi-chat-text"></i> <small><?= mb_substr($c['rejection_note'], 0, 30, 'UTF-8') ?>...</small>
                    </div>
                    <?php endif; ?>
                </td>
                <td><small class="text-muted"><?= formatDate($c['created_at'], 'd M Y') ?></small></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <!-- View Details -->
                        <button class="btn btn-sm btn-outline-primary" style="border-radius:6px;padding:3px 8px;"
                                onclick="viewCompany(<?= htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE)) ?>)"
                                title="বিস্তারিত">
                            <i class="bi bi-eye"></i>
                        </button>

                        <?php if ($c['status'] === 'pending'): ?>
                            <?php if (hasPermission('companies', 'approve')): ?>
                            <button class="btn btn-sm btn-success" style="border-radius:6px;padding:3px 10px;"
                                    onclick="doAction('approve', <?= $c['id'] ?>, '<?= addslashes($c['company_name']) ?>')"
                                    title="অনুমোদন দিন">
                                <i class="bi bi-check-lg me-1"></i>অনুমোদন
                            </button>
                            <button class="btn btn-sm btn-danger" style="border-radius:6px;padding:3px 10px;"
                                    onclick="doAction('reject', <?= $c['id'] ?>, '<?= addslashes($c['company_name']) ?>')"
                                    title="বাতিল করুন">
                                <i class="bi bi-x-lg me-1"></i>বাতিল
                            </button>
                            <?php endif; ?>

                        <?php elseif ($c['status'] === 'approved'): ?>
                            <?php if (hasPermission('companies', 'suspend')): ?>
                            <button class="btn btn-sm btn-warning" style="border-radius:6px;padding:3px 10px;"
                                    onclick="doAction('suspend', <?= $c['id'] ?>, '<?= addslashes($c['company_name']) ?>')"
                                    title="স্থগিত করুন">
                                <i class="bi bi-pause-circle me-1"></i>স্থগিত
                            </button>
                            <?php endif; ?>

                        <?php elseif (in_array($c['status'], ['suspended','rejected'])): ?>
                            <?php if (hasPermission('companies', 'approve')): ?>
                            <button class="btn btn-sm btn-success" style="border-radius:6px;padding:3px 10px;"
                                    onclick="doAction('reactivate', <?= $c['id'] ?>, '<?= addslashes($c['company_name']) ?>')"
                                    title="পুনরায় সক্রিয়">
                                <i class="bi bi-arrow-clockwise me-1"></i>সক্রিয়
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($paging['total_pages'] > 1): ?>
    <div class="card-footer bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
        <small class="text-muted">মোট <?= $paging['total'] ?> টি, দেখাচ্ছে <?= $paging['offset']+1 ?>–<?= min($paging['offset']+$perPage, $paging['total']) ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($paging['has_prev']): ?>
                <li class="page-item"><a class="page-link" href="<?= $paging['base_url'] ?><?= $paging['current']-1 ?>">‹</a></li>
                <?php endif; ?>
                <?php for ($p = max(1,$paging['current']-2); $p <= min($paging['total_pages'],$paging['current']+2); $p++): ?>
                <li class="page-item <?= $p === $paging['current'] ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $paging['base_url'] ?><?= $p ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($paging['has_next']): ?>
                <li class="page-item"><a class="page-link" href="<?= $paging['base_url'] ?><?= $paging['current']+1 ?>">›</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>


<!-- ===== ACTION MODAL ===== -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header border-0 pb-0" id="modalHeader">
                <h5 class="modal-title fw-bold" id="modalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction">
                <input type="hidden" name="company_id" id="formCompanyId">
                <div class="modal-body pt-2">
                    <p id="modalMessage" class="text-muted" style="font-size:14px;"></p>
                    <div id="noteField" class="d-none">
                        <label class="form-label fw-semibold">কারণ / নোট</label>
                        <textarea name="note" class="form-control" rows="3"
                                  placeholder="বাতিল/স্থগিতের কারণ লিখুন..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">বাতিল</button>
                    <button type="submit" class="btn" id="modalSubmitBtn"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ===== VIEW DETAILS MODAL ===== -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius:16px;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold">কোম্পানির বিস্তারিত</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
</div>

<script>
let actionModal, viewModal;

document.addEventListener('DOMContentLoaded', function() {
    actionModal = new bootstrap.Modal(document.getElementById('actionModal'));
    viewModal   = new bootstrap.Modal(document.getElementById('viewModal'));
});

function doAction(action, id, name) {
    const cfg = {
        approve:    { title: 'অনুমোদন দিন',    msg: `"${name}" কে অনুমোদন দিতে চান?`, btn: 'অনুমোদন দিন', btnClass: 'btn-success', note: false },
        reject:     { title: 'রেজিস্ট্রেশন বাতিল', msg: `"${name}" কে বাতিল করতে চান?`, btn: 'বাতিল করুন', btnClass: 'btn-danger', note: true },
        suspend:    { title: 'কোম্পানি স্থগিত', msg: `"${name}" কে স্থগিত করতে চান?`, btn: 'স্থগিত করুন', btnClass: 'btn-warning', note: true },
        reactivate: { title: 'পুনরায় সক্রিয়', msg: `"${name}" কে পুনরায় সক্রিয় করতে চান?`, btn: 'সক্রিয় করুন', btnClass: 'btn-success', note: false },
    };
    const c = cfg[action];
    document.getElementById('modalTitle').textContent = c.title;
    document.getElementById('modalMessage').textContent = c.msg;
    document.getElementById('formAction').value = action;
    document.getElementById('formCompanyId').value = id;
    document.getElementById('noteField').classList.toggle('d-none', !c.note);
    const sb = document.getElementById('modalSubmitBtn');
    sb.textContent = c.btn; sb.className = 'btn ' + c.btnClass;
    actionModal.show();
}

function viewCompany(c) {
    const logo = c.logo
        ? `<img src="<?= UPLOAD_URL ?>${c.logo}" style="width:70px;height:70px;border-radius:12px;object-fit:cover;border:2px solid #e2e8f0;">`
        : `<div style="width:70px;height:70px;border-radius:12px;background:#eff6ff;color:#2563a8;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;">${c.company_name.charAt(0)}</div>`;

    const statusBadge = {
        pending:'warning', approved:'success', suspended:'secondary', rejected:'danger'
    };
    const statusLabel = {
        pending:'অনুমোদন বাকি', approved:'অনুমোদিত', suspended:'স্থগিত', rejected:'বাতিল'
    };

    document.getElementById('viewModalBody').innerHTML = `
        <div class="d-flex align-items-start gap-3 mb-4">
            ${logo}
            <div>
                <h5 class="fw-bold mb-1">${c.company_name}</h5>
                <span class="badge bg-${statusBadge[c.status]} rounded-pill">${statusLabel[c.status]}</span>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 rounded-3" style="background:#f8fafc;font-size:14px;">
                    <div class="fw-semibold text-primary mb-2">কোম্পানির তথ্য</div>
                    <div class="mb-1"><span class="text-muted">মালিক:</span> <strong>${c.owner_name}</strong></div>
                    <div class="mb-1"><span class="text-muted">ফোন:</span> ${c.phone} ${c.alt_phone ? '/ '+c.alt_phone : ''}</div>
                    <div class="mb-1"><span class="text-muted">ইমেইল:</span> ${c.email}</div>
                    <div class="mb-1"><span class="text-muted">এলাকা:</span> ${c.area}</div>
                    <div class="mb-1"><span class="text-muted">ঠিকানা:</span> ${c.address}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 rounded-3" style="background:#f8fafc;font-size:14px;">
                    <div class="fw-semibold text-primary mb-2">নিবন্ধন তথ্য</div>
                    <div class="mb-1"><span class="text-muted">ট্রেড লাইসেন্স:</span> ${c.trade_license || 'দেওয়া হয়নি'}</div>
                    <div class="mb-1"><span class="text-muted">NID:</span> ${c.nid_number || 'দেওয়া হয়নি'}</div>
                    <div class="mb-1"><span class="text-muted">নিবন্ধন তারিখ:</span> ${c.created_at}</div>
                    ${c.rejection_note ? `<div class="mt-2 p-2 rounded" style="background:#fef2f2;color:#dc2626;font-size:13px;"><i class="bi bi-chat-text me-1"></i>${c.rejection_note}</div>` : ''}
                    ${c.description ? `<div class="mt-2 text-muted">${c.description}</div>` : ''}
                </div>
            </div>
        </div>`;
    viewModal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>