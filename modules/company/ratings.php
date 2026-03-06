<?php
// ============================================================
// COMPANY RATINGS & LEADERBOARD — B-3.1 + B-3.2
// File: modules/company/ratings.php
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user      = getCurrentUser();
$pageTitle = 'কোম্পানি রেটিং ও লিডারবোর্ড';
$errors    = [];

// ── রেটিং দেওয়া (POST) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'নিরাপত্তা যাচাই ব্যর্থ।';
    } elseif (empty($user['company_id'])) {
        $errors[] = 'রেটিং দিতে হলে আপনাকে একটি কোম্পানির সদস্য হতে হবে।';
    } else {
        $toCompany = (int)($_POST['to_company'] ?? 0);
        $rating    = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $review    = trim($_POST['review'] ?? '');

        if ($toCompany === (int)$user['company_id']) {
            $errors[] = 'নিজের কোম্পানিকে রেটিং দেওয়া যাবে না।';
        } elseif ($toCompany <= 0) {
            $errors[] = 'কোম্পানি নির্বাচন করুন।';
        }

        if (empty($errors)) {
            // UPSERT — আগে দিলে আপডেট, না দিলে ইনসার্ট
            $existing = Database::fetchOne(
                "SELECT id FROM company_ratings WHERE from_company = ? AND to_company = ?",
                [$user['company_id'], $toCompany]
            );
            if ($existing) {
                Database::update('company_ratings',
                    ['rating' => $rating, 'review' => $review ?: null],
                    'id = ?', [$existing['id']]
                );
                setFlash('success', 'রেটিং আপডেট হয়েছে।');
            } else {
                Database::insert('company_ratings', [
                    'from_company' => $user['company_id'],
                    'to_company'   => $toCompany,
                    'rating'       => $rating,
                    'review'       => $review ?: null,
                ]);
                setFlash('success', 'রেটিং সফলভাবে দেওয়া হয়েছে।');
            }
            redirect($_SERVER['PHP_SELF']);
        }
    } // end company_id check
}

// ── Leaderboard Data (B-3.2) ─────────────────────────────────
$leaderboard = Database::fetchAll(
    "SELECT
        c.id, c.company_name, c.area,
        COUNT(DISTINCT d.id) as total_entries,
        SUM(CASE WHEN d.status='active'   THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) as resolved_count,
        COALESCE(SUM(CASE WHEN d.status='resolved' THEN d.payment_amount ELSE 0 END),0) as total_recovered,
        COALESCE(SUM(CASE WHEN d.status='active'   THEN d.due_amount ELSE 0 END),0) as total_due,
        ROUND(
            SUM(CASE WHEN d.status='resolved' THEN 1 ELSE 0 END) /
            NULLIF(COUNT(DISTINCT d.id), 0) * 100, 1
        ) as recovery_rate,
        COALESCE(AVG(r.rating), 0) as avg_rating,
        COUNT(DISTINCT r.id) as rating_count
     FROM companies c
     LEFT JOIN defaulters d ON d.company_id = c.id AND d.status != 'removed'
     LEFT JOIN company_ratings r ON r.to_company = c.id
     WHERE c.status = 'approved'
     GROUP BY c.id
     ORDER BY total_recovered DESC, recovery_rate DESC"
);

// ── সব কোম্পানি (রেটিং দেওয়ার জন্য) ─────────────────────────
$companies = Database::fetchAll(
    "SELECT id, company_name FROM companies WHERE status = 'approved' AND id != ? ORDER BY company_name",
    [$user['company_id']]
);

// ── আমার দেওয়া রেটিং ─────────────────────────────────────────
$myRatings = Database::fetchAll(
    "SELECT r.*, c.company_name as to_name
     FROM company_ratings r
     JOIN companies c ON c.id = r.to_company
     WHERE r.from_company = ?",
    [$user['company_id']]
);
$myRatingMap = array_column($myRatings, null, 'to_company');

// ── আমার প্রাপ্ত রেটিং ───────────────────────────────────────
$receivedRatings = Database::fetchAll(
    "SELECT r.*, c.company_name as from_name
     FROM company_ratings r
     JOIN companies c ON c.id = r.from_company
     WHERE r.to_company = ?
     ORDER BY r.created_at DESC",
    [$user['company_id']]
);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1><i class="bi bi-trophy me-2"></i>কোম্পানি রেটিং ও লিডারবোর্ড</h1>
    <nav><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
        <li class="breadcrumb-item active">লিডারবোর্ড</li>
    </ol></nav>
</div>

<!-- ===== LEADERBOARD (B-3.2) ===== -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title"><i class="bi bi-bar-chart-fill me-2 text-warning"></i>কোম্পানি লিডারবোর্ড</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th style="width:50px;">র‍্যাংক</th>
                    <th>কোম্পানি</th>
                    <th class="text-center">মোট এন্ট্রি</th>
                    <th class="text-center">সমাধান</th>
                    <th class="text-center">রিকভারি %</th>
                    <th class="text-end">মোট আদায়</th>
                    <th class="text-center">রেটিং</th>
                    <th class="text-center">একশন</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leaderboard as $i => $co):
                $rank = $i + 1;
                $rankIcon = match($rank) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#$rank" };
                $rateColor = $co['recovery_rate'] >= 70 ? '#16a34a' : ($co['recovery_rate'] >= 40 ? '#d97706' : '#dc2626');
                $isMyCompany = $co['id'] == $user['company_id'];
                $myRating = $myRatingMap[$co['id']] ?? null;
            ?>
            <tr <?= $isMyCompany ? 'style="background:#eff6ff;"' : '' ?>>
                <td class="text-center fw-bold" style="font-size:16px;"><?= $rankIcon ?></td>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars($co['company_name']) ?>
                        <?php if ($isMyCompany): ?>
                        <span class="badge bg-primary rounded-pill ms-1" style="font-size:10px;">আমার</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($co['area']): ?>
                    <div class="text-muted" style="font-size:11px;"><?= htmlspecialchars($co['area']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-center"><?= $co['total_entries'] ?></td>
                <td class="text-center">
                    <span class="badge bg-success rounded-pill"><?= $co['resolved_count'] ?></span>
                </td>
                <td class="text-center">
                    <div class="fw-bold" style="color:<?= $rateColor ?>;"><?= $co['recovery_rate'] ?>%</div>
                    <div style="height:4px;background:#f1f5f9;border-radius:2px;margin-top:2px;">
                        <div style="height:100%;width:<?= min(100,$co['recovery_rate']) ?>%;background:<?= $rateColor ?>;border-radius:2px;"></div>
                    </div>
                </td>
                <td class="text-end fw-semibold text-success"><?= formatMoney($co['total_recovered']) ?></td>
                <td class="text-center">
                    <?php if ($co['rating_count'] > 0): ?>
                    <div><?php for($s=1;$s<=5;$s++) echo $s<=$co['avg_rating'] ? '⭐' : '☆'; ?></div>
                    <div style="font-size:11px;color:#64748b;"><?= number_format($co['avg_rating'],1) ?> (<?= $co['rating_count'] ?>)</div>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:11px;">রেটিং নেই</span>
                    <?php endif; ?>
                    <?php if ($myRating): ?>
                    <div style="font-size:10px;color:#2563a8;">আপনার: <?= $myRating['rating'] ?>⭐</div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if (!$isMyCompany): ?>
                    <button class="btn btn-sm btn-outline-warning"
                            style="border-radius:20px;font-size:11px;"
                            onclick="openRatingModal(<?= $co['id'] ?>,'<?= addslashes($co['company_name']) ?>',<?= $myRating ? $myRating['rating'] : 0 ?>,'<?= addslashes($myRating['review'] ?? '') ?>')">
                        <i class="bi bi-star me-1"></i><?= $myRating ? 'আপডেট' : 'রেটিং দিন' ?>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===== আমার প্রাপ্ত রেটিং ===== -->
<?php if (!empty($receivedRatings)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h6 class="card-title"><i class="bi bi-star-fill text-warning me-2"></i>আমার কোম্পানির প্রাপ্ত রেটিং</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
        <?php foreach ($receivedRatings as $rv): ?>
        <div class="col-md-4">
            <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                <div class="fw-semibold"><?= htmlspecialchars($rv['from_name']) ?></div>
                <div class="my-1"><?php for($s=1;$s<=5;$s++) echo $s<=$rv['rating']?'⭐':'☆'; ?></div>
                <?php if ($rv['review']): ?>
                <div style="font-size:12px;color:#64748b;">"<?= htmlspecialchars($rv['review']) ?>"</div>
                <?php endif; ?>
                <div style="font-size:11px;color:#94a3b8;margin-top:4px;"><?= timeAgo($rv['created_at']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== RATING MODAL (B-3.1) ===== -->
<div class="modal fade" id="ratingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius:16px;">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-star-fill text-warning me-2"></i>রেটিং দিন</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="submit_rating" value="1">
                <input type="hidden" name="to_company" id="ratingToCompany">
                <div class="modal-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" style="font-size:13px;">
                        <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="text-center mb-3">
                        <div id="ratingCompanyName" class="fw-bold fs-6 mb-3"></div>
                        <!-- Star Rating UI -->
                        <div id="starContainer" style="font-size:36px;cursor:pointer;letter-spacing:4px;">
                            <?php for($s=1;$s<=5;$s++): ?>
                            <span class="star" data-val="<?= $s ?>" onclick="setRating(<?= $s ?>)">☆</span>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="ratingValue" value="5">
                        <div id="ratingLabel" style="font-size:13px;color:#64748b;margin-top:6px;">৫ স্টার — চমৎকার</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:13px;">রিভিউ (ঐচ্ছিক)</label>
                        <textarea name="review" id="ratingReview" class="form-control" rows="3"
                                  placeholder="এই কোম্পানির সাথে কাজের অভিজ্ঞতা লিখুন..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">বাতিল</button>
                    <button type="submit" class="btn btn-warning fw-semibold">
                        <i class="bi bi-star-fill me-1"></i>রেটিং দিন
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let ratingModal;
document.addEventListener('DOMContentLoaded', () => {
    ratingModal = new bootstrap.Modal(document.getElementById('ratingModal'));
});

const ratingLabels = {1:'১ স্টার — খুব খারাপ',2:'২ স্টার — খারাপ',3:'৩ স্টার — ঠিকঠাক',4:'৪ স্টার — ভালো',5:'৫ স্টার — চমৎকার'};

function openRatingModal(id, name, existing, review) {
    document.getElementById('ratingToCompany').value = id;
    document.getElementById('ratingCompanyName').textContent = name;
    document.getElementById('ratingReview').value = review || '';
    setRating(existing || 5);
    ratingModal.show();
}

function setRating(val) {
    document.getElementById('ratingValue').value = val;
    document.getElementById('ratingLabel').textContent = ratingLabels[val] || '';
    document.querySelectorAll('.star').forEach(s => {
        s.textContent = parseInt(s.dataset.val) <= val ? '⭐' : '☆';
    });
}

// Hover effect
document.querySelectorAll('.star').forEach(s => {
    s.addEventListener('mouseover', () => {
        const v = parseInt(s.dataset.val);
        document.querySelectorAll('.star').forEach(ss =>
            ss.textContent = parseInt(ss.dataset.val) <= v ? '⭐' : '☆'
        );
    });
    s.addEventListener('mouseleave', () => {
        setRating(parseInt(document.getElementById('ratingValue').value));
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>