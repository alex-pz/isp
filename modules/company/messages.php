<?php
// ============================================================
// INTER-COMPANY MESSAGES — B-3.4
// File: modules/company/messages.php
// একই গ্রাহক নিয়ে দুই কোম্পানির মেসেজ
// ============================================================
require_once __DIR__ . '/../../includes/functions.php';
requirePermission('defaulters', 'view');

$user       = getCurrentUser();
$defaulterId = (int)($_GET['defaulter_id'] ?? 0);
$toCompanyId = (int)($_GET['to'] ?? 0);

// defaulter যাচাই
$defaulter = $defaulterId ? Database::fetchOne(
    "SELECT d.*, c.company_name FROM defaulters d
     JOIN companies c ON c.id = d.company_id WHERE d.id = ?", [$defaulterId]
) : null;

// conversation partner
$partner = $toCompanyId ? Database::fetchOne(
    "SELECT id, company_name FROM companies WHERE id = ? AND status = 'approved'", [$toCompanyId]
) : null;

// ── মেসেজ পাঠানো ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (!validateCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'নিরাপত্তা যাচাই ব্যর্থ।');
    } else {
        $msg = trim($_POST['message'] ?? '');
        $to  = (int)($_POST['to_company'] ?? 0);
        $did = (int)($_POST['defaulter_id'] ?? 0);

        if ($msg && $to && $did) {
            try {
                Database::insert('company_messages', [
                    'defaulter_id' => $did,
                    'from_company' => $user['company_id'],
                    'to_company'   => $to,
                    'from_user'    => $user['id'],
                    'message'      => $msg,
                ]);
            } catch (Exception $e) {
                setFlash('error', 'মেসেজ পাঠানো যায়নি।');
            }
        }
        redirect($_SERVER['PHP_SELF'] . "?defaulter_id=$did&to=$to");
    }
}

// ── মেসেজ পড়া (read করা) ─────────────────────────────────────
if ($defaulter && $partner) {
    try {
        Database::query(
            "UPDATE company_messages SET is_read = 1
             WHERE defaulter_id = ? AND to_company = ? AND from_company = ? AND is_read = 0",
            [$defaulterId, $user['company_id'], $toCompanyId]
        );
    } catch (Exception $e) {}
}

// ── কথোপকথন লোড ──────────────────────────────────────────────
$messages = [];
if ($defaulter && $partner) {
    try {
        $messages = Database::fetchAll(
            "SELECT m.*, u.full_name as sender_name, c.company_name as from_name
             FROM company_messages m
             JOIN users u ON u.id = m.from_user
             JOIN companies c ON c.id = m.from_company
             WHERE m.defaulter_id = ?
               AND (
                   (m.from_company = ? AND m.to_company = ?) OR
                   (m.from_company = ? AND m.to_company = ?)
               )
             ORDER BY m.created_at ASC",
            [$defaulterId,
             $user['company_id'], $toCompanyId,
             $toCompanyId, $user['company_id']]
        );
    } catch (Exception $e) {}
}

// ── সব conversation list (inbox) ─────────────────────────────
$inbox = [];
try {
    $inbox = Database::fetchAll(
        "SELECT
            m.defaulter_id,
            d.customer_name, d.customer_phone,
            CASE WHEN m.from_company = ? THEN m.to_company ELSE m.from_company END as partner_id,
            CASE WHEN m.from_company = ? THEN tc.company_name ELSE fc.company_name END as partner_name,
            MAX(m.created_at) as last_message_at,
            SUM(CASE WHEN m.to_company = ? AND m.is_read = 0 THEN 1 ELSE 0 END) as unread
         FROM company_messages m
         JOIN defaulters d ON d.id = m.defaulter_id
         JOIN companies fc ON fc.id = m.from_company
         JOIN companies tc ON tc.id = m.to_company
         WHERE m.from_company = ? OR m.to_company = ?
         GROUP BY m.defaulter_id, partner_id
         ORDER BY last_message_at DESC",
        [$user['company_id'], $user['company_id'], $user['company_id'],
         $user['company_id'], $user['company_id']]
    );
} catch (Exception $e) {}

$pageTitle = 'কোম্পানি মেসেজ';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <h1><i class="bi bi-chat-dots me-2"></i>কোম্পানি মেসেজ</h1>
        <nav><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= SITE_URL ?>/dashboard.php">হোম</a></li>
            <li class="breadcrumb-item active">মেসেজ</li>
        </ol></nav>
    </div>
</div>

<div class="row g-3" style="height:calc(100vh - 220px);min-height:500px;">

<!-- ===== LEFT: INBOX ===== -->
<div class="col-lg-4">
    <div class="card h-100 d-flex flex-column">
        <div class="card-header">
            <h6 class="card-title mb-0"><i class="bi bi-inbox me-2"></i>ইনবক্স</h6>
        </div>
        <div style="overflow-y:auto;flex:1;">
            <?php if (empty($inbox)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-chat-square-dots fs-1 d-block mb-2"></i>
                কোনো কথোপকথন নেই
            </div>
            <?php endif; ?>
            <?php foreach ($inbox as $conv):
                $isActive = $conv['defaulter_id'] == $defaulterId && $conv['partner_id'] == $toCompanyId;
            ?>
            <a href="?defaulter_id=<?= $conv['defaulter_id'] ?>&to=<?= $conv['partner_id'] ?>"
               class="d-flex align-items-start gap-2 px-3 py-2 border-bottom text-decoration-none text-dark <?= $isActive ? 'bg-light' : '' ?>"
               style="transition:background .15s;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:38px;height:38px;background:#eff6ff;color:#2563a8;font-size:14px;font-weight:700;">
                    <?= mb_substr($conv['partner_name'], 0, 1, 'UTF-8') ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold" style="font-size:13px;"><?= htmlspecialchars($conv['partner_name']) ?></span>
                        <?php if ($conv['unread'] > 0): ?>
                        <span class="badge bg-danger rounded-pill" style="font-size:10px;"><?= $conv['unread'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted text-truncate" style="font-size:11px;">
                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($conv['customer_name']) ?>
                    </div>
                    <div class="text-muted" style="font-size:10px;"><?= timeAgo($conv['last_message_at']) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ===== RIGHT: CHAT ===== -->
<div class="col-lg-8">
    <div class="card h-100 d-flex flex-column">

        <?php if ($defaulter && $partner): ?>
        <!-- Chat Header -->
        <div class="card-header d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center"
                 style="width:40px;height:40px;background:#eff6ff;color:#2563a8;font-size:16px;font-weight:700;flex-shrink:0;">
                <?= mb_substr($partner['company_name'], 0, 1, 'UTF-8') ?>
            </div>
            <div>
                <div class="fw-bold"><?= htmlspecialchars($partner['company_name']) ?></div>
                <div style="font-size:11px;color:#64748b;">
                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($defaulter['customer_name']) ?>
                    (<?= htmlspecialchars($defaulter['customer_phone']) ?>)
                    — <a href="<?= SITE_URL ?>/modules/defaulter/view.php?id=<?= $defaulterId ?>" style="font-size:11px;">বিস্তারিত দেখুন →</a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <div id="chatBox" style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;">
            <?php if (empty($messages)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-chat-square fs-1 d-block mb-2"></i>
                এখনো কোনো মেসেজ নেই। প্রথম মেসেজ পাঠান!
            </div>
            <?php endif; ?>
            <?php foreach ($messages as $msg):
                $isMe = $msg['from_company'] == $user['company_id'];
            ?>
            <div class="d-flex <?= $isMe ? 'justify-content-end' : 'justify-content-start' ?>">
                <div style="max-width:75%;">
                    <div style="background:<?= $isMe ? '#1a3a5c' : '#f1f5f9' ?>;
                                color:<?= $isMe ? '#fff' : '#0f2238' ?>;
                                padding:10px 14px;border-radius:<?= $isMe ? '16px 16px 4px 16px' : '16px 16px 16px 4px' ?>;
                                font-size:13px;line-height:1.5;">
                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                    </div>
                    <div class="text-muted <?= $isMe ? 'text-end' : '' ?>" style="font-size:10px;margin-top:3px;">
                        <?= htmlspecialchars($msg['sender_name']) ?> · <?= timeAgo($msg['created_at']) ?>
                        <?php if ($isMe && $msg['is_read']): ?> · ✓✓<?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Message Input -->
        <div class="card-footer border-top p-2">
            <form method="POST" class="d-flex gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="send_message" value="1">
                <input type="hidden" name="defaulter_id" value="<?= $defaulterId ?>">
                <input type="hidden" name="to_company" value="<?= $toCompanyId ?>">
                <textarea name="message" class="form-control" rows="1" id="msgInput"
                          placeholder="মেসেজ লিখুন..."
                          style="border-radius:20px;resize:none;font-size:13px;"
                          onkeydown="if(event.ctrlKey&&event.key==='Enter')this.form.submit()"
                          required></textarea>
                <button type="submit" class="btn btn-primary"
                        style="border-radius:20px;padding:6px 16px;flex-shrink:0;">
                    <i class="bi bi-send-fill"></i>
                </button>
            </form>
            <div class="text-muted text-center mt-1" style="font-size:10px;">Ctrl+Enter দিয়ে পাঠান</div>
        </div>

        <?php else: ?>
        <div class="card-body d-flex align-items-center justify-content-center text-muted">
            <div class="text-center">
                <i class="bi bi-chat-square-dots fs-1 d-block mb-2"></i>
                বাম পাশ থেকে কথোপকথন নির্বাচন করুন<br>
                <span style="font-size:12px;">অথবা কোনো এন্ট্রির বিবরণ থেকে মেসেজ করুন</span>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
</div>

<script>
// Auto scroll to bottom
const chatBox = document.getElementById('chatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

// Auto resize textarea
const msgInput = document.getElementById('msgInput');
if (msgInput) {
    msgInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
