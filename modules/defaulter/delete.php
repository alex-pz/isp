<?php
// ============================================================
// DELETE DEFAULTER
// File: modules/defaulter/delete.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$user = getCurrentUser();
$id   = (int)($_GET['id'] ?? 0);

$defaulter = Database::fetchOne("SELECT * FROM defaulters WHERE id = ?", [$id]);
if (!$defaulter) { setFlash('error', 'এন্ট্রি পাওয়া যায়নি।'); redirect('list.php'); }

$canDelete = hasPermission('defaulters','delete_any') ||
             (hasPermission('defaulters','delete_own') && $defaulter['company_id'] == $user['company_id']);
if (!$canDelete) { setFlash('error', 'এই এন্ট্রি মুছে ফেলার অনুমতি নেই।'); redirect('view.php?id='.$id); }

$pageTitle = 'এন্ট্রি মুছুন';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif ($_POST['confirm'] ?? '' !== 'yes') {
        $errors[] = 'নিশ্চিত করুন।';
    } else {
        // Delete photos from disk
        $photos = Database::fetchAll("SELECT photo_path FROM defaulter_photos WHERE defaulter_id = ?", [$id]);
        foreach ($photos as $photo) deleteUploadedFile($photo['photo_path']);

        // Soft-delete: mark as removed
        Database::update('defaulters', ['status' => 'removed'], 'id = ?', [$id]);

        logActivity('defaulter.delete', 'defaulters', [
            'target_id' => $id, 'target_type' => 'defaulters',
            'description' => '"' . $defaulter['customer_name'] . '" মুছে ফেলা হয়েছে',
        ]);
        setFlash('success', '"' . $defaulter['customer_name'] . '" মুছে ফেলা হয়েছে।');
        redirect('list.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center mt-4">
<div class="col-md-5">
<div class="card border-danger">
    <div class="card-header" style="background:#fef2f2;border-bottom:1px solid #fecaca;">
        <h6 class="card-title text-danger mb-0"><i class="bi bi-trash me-2"></i>এন্ট্রি মুছে ফেলুন</h6>
    </div>
    <div class="card-body">
        <div class="p-3 rounded-3 mb-4" style="background:#fef2f2;border:1px solid #fecaca;">
            <div class="fw-bold text-danger"><?= htmlspecialchars($defaulter['customer_name']) ?></div>
            <div class="text-muted small"><?= htmlspecialchars($defaulter['customer_phone']) ?></div>
            <div class="mt-1 fw-bold"><?= formatMoney($defaulter['due_amount']) ?></div>
        </div>
        <p class="text-danger fw-semibold">⚠️ এই এন্ট্রিটি মুছে ফেলতে চান?</p>
        <p class="text-muted small">মুছে ফেলার পরে পুনরুদ্ধার করা যাবে না।</p>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-3 mb-3" style="font-size:13px;"><?= htmlspecialchars($errors[0]) ?></div>
        <?php endif; ?>

        <form method="POST" class="d-flex gap-2">
            <?= csrfField() ?>
            <input type="hidden" name="confirm" value="yes">
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary flex-fill">বাতিল</a>
            <button type="submit" class="btn btn-danger flex-fill fw-semibold">
                <i class="bi bi-trash me-1"></i>মুছে ফেলুন
            </button>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
