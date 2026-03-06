<!DOCTYPE html>
<html lang="bn" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle ?? 'ISP Defaulter System') ?> — <?= getSetting('site_name') ?></title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts - Hind Siliguri for Bangla -->
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@300;400;500;600;700&family=Tiro+Bangla:ital@0;1&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary:       #1a3a5c;
            --primary-light: #2563a8;
            --primary-dark:  #0f2238;
            --accent:        #e8392d;
            --accent-light:  #f5736b;
            --success:       #198754;
            --warning:       #fd7e14;
            --sidebar-width: 260px;
            --sidebar-bg:    #0f2238;
            --topbar-height: 60px;
            --font-main:     'Hind Siliguri', sans-serif;
        }

        * { font-family: var(--font-main); }
        body { background: #f0f4f8; min-height: 100vh; }

        /* ---- Sidebar ---- */
        #sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: var(--sidebar-bg);
            overflow-y: auto; z-index: 1040;
            transition: transform .3s ease;
            display: flex; flex-direction: column;
        }

        .sidebar-brand {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255,255,255,.1);
            display: flex; align-items: center; gap: 10px;
            text-decoration: none;
        }
        .sidebar-brand img { width: 36px; height: 36px; border-radius: 8px; }
        .sidebar-brand-icon {
            width: 36px; height: 36px; border-radius: 8px;
            background: var(--accent); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700;
        }
        .sidebar-brand-text { color: #fff; }
        .sidebar-brand-text .name { font-size: 14px; font-weight: 700; line-height: 1.2; }
        .sidebar-brand-text .sub  { font-size: 11px; color: rgba(255,255,255,.5); }

        .sidebar-nav { padding: 12px 0; flex: 1; }
        .nav-section { padding: 16px 20px 6px; font-size: 10px; font-weight: 600;
                        color: rgba(255,255,255,.3); letter-spacing: 1.5px; text-transform: uppercase; }

        .sidebar-nav .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; color: rgba(255,255,255,.7);
            font-size: 14px; border-radius: 0; transition: all .2s;
            text-decoration: none; border-left: 3px solid transparent;
        }
        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            background: rgba(255,255,255,.08);
            color: #fff; border-left-color: var(--accent);
        }
        .sidebar-nav .nav-link i { font-size: 16px; width: 20px; text-align: center; }
        .sidebar-nav .nav-link .badge-count {
            margin-left: auto; background: var(--accent);
            color: #fff; font-size: 10px;
            padding: 2px 7px; border-radius: 10px;
        }

        .sidebar-footer {
            padding: 14px 20px;
            border-top: 1px solid rgba(255,255,255,.1);
        }
        .sidebar-user { display: flex; align-items: center; gap: 10px; }
        .sidebar-user-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--primary-light); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 700; flex-shrink: 0;
            overflow: hidden;
        }
        .sidebar-user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-user-info { overflow: hidden; }
        .sidebar-user-info .name { color: #fff; font-size: 13px; font-weight: 600;
                                    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-info .role { color: rgba(255,255,255,.45); font-size: 11px; }

        /* ---- Main Layout ---- */
        #main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            display: flex; flex-direction: column;
            transition: margin .3s ease;
        }

        /* ---- Topbar ---- */
        #topbar {
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center;
            padding: 0 20px; gap: 12px;
            position: sticky; top: 0; z-index: 1030;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        #sidebar-toggle { background: none; border: none; font-size: 20px;
                           color: #64748b; cursor: pointer; padding: 4px; }
        .topbar-title { font-size: 18px; font-weight: 600; color: var(--primary); flex: 1; }

        .topbar-search {
            background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 7px 14px;
            display: flex; align-items: center; gap: 8px;
            width: 240px;
        }
        .topbar-search input {
            background: none; border: none; outline: none;
            font-size: 13px; color: #334155; flex: 1;
        }
        .topbar-search i { color: #94a3b8; }

        .topbar-btn {
            background: none; border: none; width: 38px; height: 38px;
            border-radius: 8px; color: #64748b; font-size: 18px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative;
            transition: background .2s, color .2s;
        }
        .topbar-btn:hover { background: #f1f5f9; color: var(--primary); }
        .topbar-btn .badge-dot {
            position: absolute; top: 6px; right: 6px;
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--accent); border: 2px solid #fff;
        }

        /* ---- Page Content ---- */
        .page-content { padding: 24px; flex: 1; }

        /* ---- Cards ---- */
        .card { border: none; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
        .card-header {
            background: #fff; border-bottom: 1px solid #f1f5f9;
            border-radius: 12px 12px 0 0 !important;
            padding: 14px 20px;
            display: flex; align-items: center;
        }
        .card-header .card-title { margin: 0; font-size: 16px; font-weight: 600; color: var(--primary); }

        /* ---- Stats Cards ---- */
        .stat-card {
            border-radius: 12px; padding: 20px; color: #fff;
            display: flex; align-items: center; gap: 16px;
        }
        .stat-card .stat-icon {
            width: 54px; height: 54px; border-radius: 12px;
            background: rgba(255,255,255,.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
        }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
        .stat-card .stat-label { font-size: 13px; opacity: .85; margin-top: 4px; }

        /* ---- Tables ---- */
        .table-card .table { margin: 0; }
        .table thead th {
            background: #f8fafc; color: #475569;
            font-size: 12px; font-weight: 600; letter-spacing: .5px;
            text-transform: uppercase; border-bottom: 2px solid #e2e8f0;
            padding: 12px 16px; white-space: nowrap;
        }
        .table tbody td { padding: 12px 16px; vertical-align: middle; font-size: 14px; }
        .table-hover tbody tr:hover { background: #f8fafc; }

        /* ---- Risk badges ---- */
        .risk-critical { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .risk-high     { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
        .risk-medium   { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .risk-low      { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .risk-badge    { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }

        /* ---- Alert / Flash ---- */
        .flash-message { border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; gap: 10px; }

        /* ---- Forms ---- */
        .form-control, .form-select {
            border-radius: 8px; border-color: #e2e8f0;
            font-size: 14px; padding: 8px 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(37, 99, 168, .15);
        }
        .form-label { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 5px; }
        .required-star { color: var(--accent); }

        /* ---- Buttons ---- */
        .btn { border-radius: 8px; font-size: 14px; font-weight: 500; }
        .btn-primary { background: var(--primary-light); border-color: var(--primary-light); }
        .btn-primary:hover { background: var(--primary); border-color: var(--primary); }
        .btn-danger { background: var(--accent); border-color: var(--accent); }

        /* ---- Customer photo ---- */
        .customer-photo {
            width: 44px; height: 44px; border-radius: 8px;
            object-fit: cover; border: 2px solid #e2e8f0;
        }
        .customer-photo-placeholder {
            width: 44px; height: 44px; border-radius: 8px;
            background: #e2e8f0; color: #94a3b8;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }

        /* ---- Mobile ---- */
        @media (max-width: 991.98px) {
            #sidebar { transform: translateX(-100%); }
            #sidebar.show { transform: translateX(0); }
            #main-content { margin-left: 0; }
            .topbar-search { display: none; }
            .sidebar-overlay {
                display: none; position: fixed; inset: 0;
                background: rgba(0,0,0,.5); z-index: 1039;
            }
            .sidebar-overlay.show { display: block; }
        }

        /* ---- Scrollbar ---- */
        #sidebar::-webkit-scrollbar { width: 4px; }
        #sidebar::-webkit-scrollbar-track { background: transparent; }
        #sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 2px; }

        /* ---- Misc ---- */
        .page-header { margin-bottom: 24px; }
        .page-header h1 { font-size: 22px; font-weight: 700; color: var(--primary); margin: 0; }
        .page-header .breadcrumb { font-size: 13px; }
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 12px; }
        .empty-state p { font-size: 15px; }
    </style>

    <?= $extraHead ?? '' ?>
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ============ SIDEBAR ============ -->
<?php
$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav id="sidebar">
    <!-- Brand -->
    <a href="<?= SITE_URL ?>/dashboard.php" class="sidebar-brand">
        <?php if (getSetting('site_logo')): ?>
            <img src="<?= UPLOAD_URL . getSetting('site_logo') ?>" alt="Logo">
        <?php else: ?>
            <div class="sidebar-brand-icon">ISP</div>
        <?php endif; ?>
        <div class="sidebar-brand-text">
            <div class="name">ISP Defaulter</div>
            <div class="sub">Management System</div>
        </div>
    </a>

    <!-- Navigation -->
    <div class="sidebar-nav">

        <!-- Main -->
        <div class="nav-section">মূল মেনু</div>

        <a href="<?= SITE_URL ?>/dashboard.php"
           class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> ড্যাশবোর্ড
        </a>

        <!-- ===== বকেয়া ব্যবস্থাপনা ===== -->
        <div class="nav-section">বকেয়া ব্যবস্থাপনা</div>

        <?php if (hasPermission('defaulters', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/defaulter/list.php"
           class="nav-link <?= $currentPage === 'list.php' ? 'active' : '' ?>">
            <i class="bi bi-exclamation-triangle"></i> বকেয়া তালিকা
            <?php $activeCount = Database::count('defaulters', 'status = ?', ['active']);
            if ($activeCount > 0): ?><span class="badge-count"><?= $activeCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('defaulters', 'create')): ?>
        <a href="<?= SITE_URL ?>/modules/defaulter/add.php"
           class="nav-link <?= $currentPage === 'add.php' ? 'active' : '' ?>">
            <i class="bi bi-person-plus"></i> নতুন এন্ট্রি
        </a>
        <a href="<?= SITE_URL ?>/modules/defaulter/bulk_import.php"
           class="nav-link <?= $currentPage === 'bulk_import.php' ? 'active' : '' ?>">
            <i class="bi bi-cloud-upload"></i> বাল্ক ইমপোর্ট
        </a>
        <a href="<?= SITE_URL ?>/modules/defaulter/map.php"
           class="nav-link <?= $currentPage === 'map.php' ? 'active' : '' ?>">
            <i class="bi bi-map"></i> অ্যাডভান্সড ম্যাপ
        </a>
        <?php endif; ?>

        <?php if (hasPermission('disputes', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/defaulter/disputes.php"
           class="nav-link <?= $currentPage === 'disputes.php' ? 'active' : '' ?>">
            <i class="bi bi-flag"></i> বিরোধ
            <?php $disputeCount = Database::count('disputes', 'status = ?', ['open']);
            if ($disputeCount > 0): ?><span class="badge-count"><?= $disputeCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <!-- ===== গ্রাহক বিশ্লেষণ ===== -->
        <?php if (hasPermission('defaulters', 'view')): ?>
        <div class="nav-section">গ্রাহক বিশ্লেষণ</div>

        <a href="<?= SITE_URL ?>/modules/customer/profile.php"
           class="nav-link <?= $currentPage === 'profile.php' && str_contains($_SERVER['PHP_SELF'], '/customer/') ? 'active' : '' ?>">
            <i class="bi bi-person-circle"></i> গ্রাহক প্রোফাইল
        </a>

        <a href="<?= SITE_URL ?>/modules/customer/duplicates.php"
           class="nav-link <?= $currentPage === 'duplicates.php' ? 'active' : '' ?>">
            <i class="bi bi-copy"></i> ডুপ্লিকেট শনাক্ত
            <?php $dupCount = Database::fetchOne(
                "SELECT COUNT(DISTINCT customer_phone) as cnt FROM defaulters
                 GROUP BY customer_phone HAVING COUNT(DISTINCT company_id) >= 2"
            )['cnt'] ?? 0;
            // Quick duplicate count
            $dupPhoneCount = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM (
                    SELECT customer_phone FROM defaulters
                    GROUP BY customer_phone HAVING COUNT(DISTINCT company_id) >= 2
                ) t"
            )['cnt'] ?? 0;
            if ($dupPhoneCount > 0): ?><span class="badge-count"><?= $dupPhoneCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <!-- ===== কোম্পানি নেটওয়ার্ক (B-3) ===== -->
        <?php if (hasPermission('defaulters', 'view')): ?>
        <div class="nav-section">কোম্পানি নেটওয়ার্ক</div>

        <a href="<?= SITE_URL ?>/modules/company/ratings.php"
           class="nav-link <?= $currentPage === 'ratings.php' ? 'active' : '' ?>">
            <i class="bi bi-trophy"></i> লিডারবোর্ড ও রেটিং
        </a>

        <a href="<?= SITE_URL ?>/modules/company/messages.php"
           class="nav-link <?= $currentPage === 'messages.php' ? 'active' : '' ?>">
            <i class="bi bi-chat-dots"></i> কোম্পানি মেসেজ
            <?php try {
                $unreadMsg = Database::fetchOne(
                    "SELECT COUNT(*) as cnt FROM company_messages WHERE to_company = ? AND is_read = 0",
                    [$user['company_id'] ?? 0]
                )['cnt'] ?? 0;
                if ($unreadMsg > 0): ?><span class="badge-count"><?= $unreadMsg ?></span><?php endif;
            } catch (Exception $e) {} ?>
        </a>
        <?php if (in_array($user['role_name'], ['company_admin','super_admin','admin'])): ?>
        <a href="<?= SITE_URL ?>/modules/company/settings.php"
           class="nav-link <?= $currentPage === 'settings.php' && str_contains($_SERVER['PHP_SELF'],'/company/') ? 'active' : '' ?>">
            <i class="bi bi-building-gear"></i> কোম্পানি সেটিংস
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ===== আইনি সরঞ্জাম (C-1) ===== -->
        <?php if (hasPermission('defaulters', 'view')): ?>
        <div class="nav-section">আইনি সরঞ্জাম</div>
        <a href="<?= SITE_URL ?>/modules/defaulter/notice_templates.php"
           class="nav-link <?= $currentPage === 'notice_templates.php' ? 'active' : '' ?>">
            <i class="bi bi-file-text"></i> নোটিশ টেমপ্লেট
        </a>
        <a href="<?= SITE_URL ?>/modules/defaulter/court_cases.php"
           class="nav-link <?= $currentPage === 'court_cases.php' ? 'active' : '' ?>">
            <i class="bi bi-bank"></i> মামলা ট্র্যাকার
        </a>
        <?php endif; ?>

        <!-- ===== রিপোর্ট ===== -->
        <?php if (hasPermission('reports', 'view')): ?>
        <div class="nav-section">রিপোর্ট</div>
        <a href="<?= SITE_URL ?>/modules/reports/index.php"
           class="nav-link <?= $currentPage === 'index.php' && str_contains($_SERVER['PHP_SELF'], '/reports/') ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i> রিপোর্ট ও পরিসংখ্যান
        </a>
        <?php if (hasPermission('reports', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/reports/export.php"
           class="nav-link <?= $currentPage === 'export.php' ? 'active' : '' ?>">
            <i class="bi bi-download"></i> এক্সপোর্ট
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ===== অ্যাডমিন ===== -->
        <?php if (hasPermission('companies', 'view') || hasPermission('users', 'view') || $user['role_name'] === 'super_admin'): ?>
        <div class="nav-section">অ্যাডমিন</div>

        <?php if (hasPermission('companies', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/admin/companies.php"
           class="nav-link <?= $currentPage === 'companies.php' ? 'active' : '' ?>">
            <i class="bi bi-building"></i> কোম্পানি ব্যবস্থাপনা
            <?php $pendingCount = Database::count('companies', 'status = ?', ['pending']);
            if ($pendingCount > 0): ?><span class="badge-count"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('users', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/admin/users.php"
           class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> ব্যবহারকারী
        </a>
        <?php endif; ?>

        <?php if (hasPermission('activity_log', 'view')): ?>
        <a href="<?= SITE_URL ?>/modules/admin/logs.php"
           class="nav-link <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i> অ্যাক্টিভিটি লগ
        </a>
        <?php endif; ?>

        <?php if ($user && $user['role_name'] === 'super_admin'): ?>
        <a href="<?= SITE_URL ?>/modules/admin/settings.php"
           class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <i class="bi bi-gear"></i> সিস্টেম সেটিংস
        </a>
        <a href="<?= SITE_URL ?>/modules/admin/notification_settings.php"
           class="nav-link <?= $currentPage === 'notification_settings.php' ? 'active' : '' ?>">
            <i class="bi bi-bell-fill"></i> নোটিফিকেশন সেটিংস
        </a>
        <a href="<?= SITE_URL ?>/modules/admin/notices.php"
           class="nav-link <?= $currentPage === 'notices.php' ? 'active' : '' ?>">
            <i class="bi bi-megaphone"></i> নোটিশ ব্যবস্থাপনা
        </a>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /sidebar-nav -->

    <!-- User Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?php if ($user && $user['avatar']): ?>
                    <img src="<?= UPLOAD_URL . $user['avatar'] ?>" alt="">
                <?php else: ?>
                    <?= mb_substr($user['full_name'] ?? 'U', 0, 1, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div class="sidebar-user-info" style="flex:1;min-width:0;">
                <div class="name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
                <div class="role"><?= htmlspecialchars($user['role_label'] ?? '') ?></div>
            </div>
            <a href="<?= SITE_URL ?>/logout.php" class="text-white opacity-50 fs-5" title="লগআউট">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>

<!-- ============ MAIN CONTENT ============ -->
<div id="main-content">

    <!-- Topbar -->
    <div id="topbar">
        <button id="sidebar-toggle"><i class="bi bi-list"></i></button>
        <div class="topbar-title"><?= htmlspecialchars($pageTitle ?? '') ?></div>

        <div class="topbar-search d-none d-md-flex">
            <i class="bi bi-search"></i>
            <input type="text" id="globalSearch" placeholder="গ্রাহক খুঁজুন...">
        </div>

        <?php
        $notifs = $user ? getUnreadNotifications($user['id'], $user['company_id'] ?? 0) : [];
        ?>
        <button class="topbar-btn position-relative" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell"></i>
            <?php if (count($notifs) > 0): ?>
                <span class="badge-dot"></span>
            <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 p-0" style="width:320px; margin-top:10px;">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <strong class="fs-6">নোটিফিকেশন</strong>
                <?php if (count($notifs) > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= count($notifs) ?></span>
                <?php endif; ?>
            </div>
            <div style="max-height:300px;overflow-y:auto;">
                <?php if (empty($notifs)): ?>
                    <div class="text-center text-muted py-4 small">কোনো নোটিফিকেশন নেই</div>
                <?php else: foreach ($notifs as $n): ?>
                    <a href="<?= SITE_URL ?>/modules/notifications/read.php?id=<?= $n['id'] ?><?= $n['link'] ? '&redirect='.urlencode($n['link']) : '' ?>"
                       class="d-flex gap-2 px-3 py-2 border-bottom text-decoration-none text-dark hover-bg">
                        <i class="bi bi-<?= $n['icon'] ?: 'bell' ?> text-<?= $n['type'] ?> mt-1 fs-6"></i>
                        <div>
                            <div class="fw-semibold small"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="text-muted" style="font-size:11px;"><?= timeAgo($n['created_at']) ?></div>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <a href="<?= SITE_URL ?>/modules/auth/profile.php" class="topbar-btn">
            <div style="width:34px;height:34px;border-radius:50%;background:var(--primary);color:#fff;
                        display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;">
                <?= mb_substr($user['full_name'] ?? 'U', 0, 1, 'UTF-8') ?>
            </div>
        </a>
    </div>

    <!-- Page Content Start -->
    <div class="page-content">

    <!-- Flash Message -->
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible flash-message mb-4" role="alert">
        <i class="bi bi-<?= match($flash['type']) {
            'success' => 'check-circle',
            'error', 'danger' => 'x-circle',
            'warning' => 'exclamation-triangle',
            default => 'info-circle'
        } ?>"></i>
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ===== B-4.2: NOTICE BAR ===== -->
    <?php
    $activeNotices = [];
    try {
        $now = date('Y-m-d H:i:s');
        $allNotices = Database::fetchAll(
            "SELECT * FROM notices
             WHERE is_active = 1
               AND starts_at <= ?
               AND (expires_at IS NULL OR expires_at > ?)
             ORDER BY FIELD(priority,'urgent','important','normal'), created_at DESC",
            [$now, $now]
        );
        $dismissedIds = [];
        if ($user) {
            $dismissed = Database::fetchAll(
                "SELECT notice_id FROM notice_dismissals WHERE user_id = ?",
                [$user['id']]
            );
            $dismissedIds = array_column($dismissed, 'notice_id');
        }
        foreach ($allNotices as $n) {
            if (in_array($n['id'], $dismissedIds)) continue;
            if ($n['target'] === 'all') {
                $activeNotices[] = $n;
            } elseif ($n['target'] === 'company' && $user && $n['target_id'] == $user['company_id']) {
                $activeNotices[] = $n;
            } elseif ($n['target'] === 'role' && $user && $n['target_id'] == $user['role_id']) {
                $activeNotices[] = $n;
            }
        }
    } catch (Exception $e) {}
    ?>
    <?php if (!empty($activeNotices)): ?>

    <style>
    .nb-wrap { margin-bottom: 10px; }
    .nb-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        border-radius: 10px;
        border-left: 5px solid #ccc;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,.08);
        margin-bottom: 8px;
        overflow: hidden;
    }
    .nb-icon {
        flex-shrink: 0;
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        margin: 0 0 0 12px;
        border-radius: 8px;
    }
    .nb-content {
        flex: 1;
        min-width: 0;
        padding: 10px 0;
        cursor: pointer;
    }
    .nb-title {
        font-size: 15px;
        font-weight: 800;
        color: #1a202c;
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .nb-marquee-wrap {
        overflow: hidden;
        white-space: nowrap;
        margin-top: 2px;
    }
    .nb-marquee {
        display: inline-block;
        font-size: 13px;
        color: #4a5568;
        animation: nb-scroll 70s linear infinite;
        padding-left: 100%;
    }
    .nb-marquee:hover { animation-play-state: paused; }
    @keyframes nb-scroll {
        0%   { transform: translateX(0); }
        100% { transform: translateX(-100%); }
    }
    .nb-meta {
        font-size: 11px;
        color: #718096;
        margin-top: 2px;
    }

    .nb-actions {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 4px;
        padding-right: 10px;
    }
    .nb-chevron {
        transition: transform .25s;
        color: #a0aec0;
        font-size: 12px;
        cursor: pointer;
        padding: 4px;
    }
    .nb-dismiss {
        background: none;
        border: none;
        color: #a0aec0;
        font-size: 20px;
        line-height: 1;
        cursor: pointer;
        padding: 2px 4px;
        border-radius: 5px;
        transition: background .15s, color .15s;
    }
    .nb-dismiss:hover { background: rgba(0,0,0,.07); color: #e53e3e; }
    </style>

    <div class="nb-wrap">
    <?php
    $nbColors = [
        'general'     => ['border'=>'#3182ce', 'icon_bg'=>'#ebf8ff', 'icon_color'=>'#2b6cb0', 'icon'=>'megaphone'],
        'meeting'     => ['border'=>'#805ad5', 'icon_bg'=>'#faf5ff', 'icon_color'=>'#6b46c1', 'icon'=>'camera-video'],
        'urgent'      => ['border'=>'#e53e3e', 'icon_bg'=>'#fff5f5', 'icon_color'=>'#c53030', 'icon'=>'exclamation-triangle-fill'],
        'deadline'    => ['border'=>'#d69e2e', 'icon_bg'=>'#fffff0', 'icon_color'=>'#b7791f', 'icon'=>'calendar-event'],
        'maintenance' => ['border'=>'#718096', 'icon_bg'=>'#f7fafc', 'icon_color'=>'#4a5568', 'icon'=>'tools'],
    ];
    $nbPrioLabel = ['normal'=>'', 'important'=>' · গুরুত্বপূর্ণ', 'urgent'=>' · ⚠️ জরুরি'];
    $nbTypeLabel = ['general'=>'ঘোষণা','meeting'=>'মিটিং','urgent'=>'জরুরি','deadline'=>'ডেডলাইন','maintenance'=>'রক্ষণাবেক্ষণ'];
    foreach ($activeNotices as $_notice):
        $nc = $nbColors[$_notice['type']] ?? $nbColors['general'];
    ?>
    <div class="nb-bar" style="border-left-color:<?= $nc['border'] ?>;">

        <!-- Icon -->
        <div class="nb-icon" style="background:<?= $nc['icon_bg'] ?>;color:<?= $nc['icon_color'] ?>;">
            <i class="bi bi-<?= $nc['icon'] ?>"></i>
        </div>

        <!-- Content -->
        <div class="nb-content">
            <div class="nb-title"><?= htmlspecialchars($_notice['title']) ?></div>
            <div class="nb-marquee-wrap">
                <span class="nb-marquee"><?= htmlspecialchars($_notice['message']) ?></span>
            </div>
            <div class="nb-meta">
                <?= $nbTypeLabel[$_notice['type']] ?? '' ?><?= $nbPrioLabel[$_notice['priority']] ?? '' ?>
                <?php if ($_notice['expires_at']): ?>
                · <?= date('d M Y', strtotime($_notice['expires_at'])) ?> পর্যন্ত
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div class="nb-actions">
            <i class="bi bi-chevron-down nb-chevron" id="nb-chev-<?= $_notice['id'] ?>"></i>
            <button class="nb-dismiss" title="বন্ধ করুন"
                    onclick="nbDismiss(<?= $_notice['id'] ?>, this)">×</button>
        </div>
    </div>


    <?php endforeach; ?>
    </div>

    <script>
    function nbDismiss(id, btn) {
        const bar = btn.closest('.nb-bar');
        if (bar) { bar.style.opacity='0'; bar.style.transition='opacity .3s';
                   setTimeout(()=>{ bar.remove(); }, 300); }
        fetch('<?= SITE_URL ?>/modules/notices/dismiss.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'notice_id=' + id + '&<?= CSRF_TOKEN_NAME ?>=<?= generateCsrfToken() ?>'
        });
    }

    </script>
    <?php endif; ?>