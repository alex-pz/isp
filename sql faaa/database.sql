-- ============================================================
-- ISP DEFAULTER MANAGEMENT SYSTEM
-- Database Schema v1.0
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+06:00";

CREATE DATABASE IF NOT EXISTS `isp_defaulter` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `isp_defaulter`;

-- ============================================================
-- TABLE: companies
-- ISP কোম্পানির তথ্য
-- ============================================================
CREATE TABLE `companies` (
    `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_name`    VARCHAR(150) NOT NULL,
    `owner_name`      VARCHAR(100) NOT NULL,
    `email`           VARCHAR(150) NOT NULL UNIQUE,
    `phone`           VARCHAR(20) NOT NULL,
    `alt_phone`       VARCHAR(20) DEFAULT NULL,
    `address`         TEXT NOT NULL,
    `area`            VARCHAR(100) NOT NULL COMMENT 'Service area name',
    `trade_license`   VARCHAR(100) DEFAULT NULL,
    `nid_number`      VARCHAR(30) DEFAULT NULL,
    `logo`            VARCHAR(255) DEFAULT NULL,
    `description`     TEXT DEFAULT NULL,
    `status`          ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
    `rejection_note`  TEXT DEFAULT NULL,
    `approved_by`     INT(11) DEFAULT NULL,
    `approved_at`     DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_area` (`area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: roles
-- সিস্টেমের বিভিন্ন রোল
-- ============================================================
CREATE TABLE `roles` (
    `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(50) NOT NULL UNIQUE,
    `label`       VARCHAR(100) NOT NULL COMMENT 'Display name in Bangla/English',
    `is_system`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=system role, cannot delete',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default roles
INSERT INTO `roles` (`name`, `label`, `is_system`) VALUES
('super_admin',    'সুপার অ্যাডমিন',     1),
('admin',          'অ্যাডমিন',           1),
('company_admin',  'কোম্পানি অ্যাডমিন',  1),
('company_staff',  'কোম্পানি স্টাফ',     0),
('viewer',         'শুধু দেখতে পারবে',   0);


-- ============================================================
-- TABLE: permissions
-- মডিউল ভিত্তিক পারমিশন
-- ============================================================
CREATE TABLE `permissions` (
    `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `module`      VARCHAR(50) NOT NULL COMMENT 'Module name e.g. defaulters, reports',
    `action`      VARCHAR(50) NOT NULL COMMENT 'Action: view, create, edit, delete, export',
    `label`       VARCHAR(100) NOT NULL COMMENT 'Human readable label',
    `description` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_perm` (`module`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System permissions
INSERT INTO `permissions` (`module`, `action`, `label`, `description`) VALUES
-- Defaulter module
('defaulters', 'view',        'বকেয়া তালিকা দেখা',        'সব কোম্পানির বকেয়া তালিকা দেখতে পারবে'),
('defaulters', 'create',      'নতুন এন্ট্রি যোগ করা',     'নতুন বকেয়া গ্রাহক যোগ করতে পারবে'),
('defaulters', 'edit_own',    'নিজের এন্ট্রি সম্পাদনা',   'শুধু নিজের কোম্পানির এন্ট্রি এডিট করতে পারবে'),
('defaulters', 'delete_own',  'নিজের এন্ট্রি মুছে ফেলা',  'শুধু নিজের কোম্পানির এন্ট্রি ডিলিট করতে পারবে'),
('defaulters', 'delete_any',  'যেকোনো এন্ট্রি মুছে ফেলা', 'যেকোনো কোম্পানির এন্ট্রি ডিলিট করতে পারবে'),
('defaulters', 'mark_done',   'সমাধান চিহ্নিত করা',       'বকেয়া পরিশোধ হলে done করতে পারবে'),
('defaulters', 'export',      'ডেটা রপ্তানি করা',          'PDF/Excel এ ডেটা ডাউনলোড করতে পারবে'),
-- Companies module
('companies', 'view',         'কোম্পানি তালিকা দেখা',     'সব কোম্পানির তালিকা দেখতে পারবে'),
('companies', 'approve',      'কোম্পানি অনুমোদন দেওয়া',  'নতুন কোম্পানি অনুমোদন/বাতিল করতে পারবে'),
('companies', 'suspend',      'কোম্পানি স্থগিত করা',      'কোম্পানির অ্যাক্সেস স্থগিত করতে পারবে'),
-- Users module
('users', 'view',             'ব্যবহারকারী দেখা',          'ব্যবহারকারীদের তালিকা দেখতে পারবে'),
('users', 'create',           'ব্যবহারকারী তৈরি করা',      'নতুন ব্যবহারকারী তৈরি করতে পারবে'),
('users', 'edit',             'ব্যবহারকারী সম্পাদনা',      'ব্যবহারকারীর তথ্য সম্পাদনা করতে পারবে'),
('users', 'delete',           'ব্যবহারকারী মুছে ফেলা',    'ব্যবহারকারী মুছে ফেলতে পারবে'),
-- Reports module
('reports', 'view',           'রিপোর্ট দেখা',              'সিস্টেম রিপোর্ট দেখতে পারবে'),
('reports', 'export',         'রিপোর্ট রপ্তানি করা',       'রিপোর্ট ডাউনলোড করতে পারবে'),
-- Activity Log module
('activity_log', 'view',      'লগ দেখা',                   'অ্যাক্টিভিটি লগ দেখতে পারবে'),
-- Disputes module
('disputes', 'view',          'বিরোধ দেখা',                'বিরোধ তালিকা দেখতে পারবে'),
('disputes', 'manage',        'বিরোধ পরিচালনা',            'বিরোধ সমাধান করতে পারবে');


-- ============================================================
-- TABLE: role_permissions
-- রোল এবং পারমিশনের সম্পর্ক
-- ============================================================
CREATE TABLE `role_permissions` (
    `role_id`       INT(11) UNSIGNED NOT NULL,
    `permission_id` INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Super Admin gets ALL permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, id FROM `permissions`;

-- Admin gets most permissions (not delete_any for others)
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `module` != 'activity_log' OR `action` = 'view';

-- Company Admin permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, id FROM `permissions` 
WHERE (`module` = 'defaulters' AND `action` IN ('view','create','edit_own','delete_own','mark_done','export'))
   OR (`module` = 'companies' AND `action` = 'view')
   OR (`module` = 'users' AND `action` IN ('view','create','edit'))
   OR (`module` = 'reports' AND `action` = 'view')
   OR (`module` = 'disputes' AND `action` IN ('view'));

-- Company Staff permissions  
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, id FROM `permissions` 
WHERE (`module` = 'defaulters' AND `action` IN ('view','create','edit_own','mark_done'))
   OR (`module` = 'disputes' AND `action` = 'view');

-- Viewer only permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, id FROM `permissions` 
WHERE `action` = 'view' AND `module` IN ('defaulters','companies');


-- ============================================================
-- TABLE: users
-- সিস্টেমের সব ব্যবহারকারী
-- ============================================================
CREATE TABLE `users` (
    `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`      INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL for super admin/system admin',
    `role_id`         INT(11) UNSIGNED NOT NULL,
    `full_name`       VARCHAR(100) NOT NULL,
    `username`        VARCHAR(50) NOT NULL UNIQUE,
    `email`           VARCHAR(150) NOT NULL UNIQUE,
    `phone`           VARCHAR(20) NOT NULL,
    `password`        VARCHAR(255) NOT NULL COMMENT 'bcrypt hashed',
    `avatar`          VARCHAR(255) DEFAULT NULL,
    `nid_number`      VARCHAR(30) DEFAULT NULL,
    `status`          ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `last_login`      DATETIME DEFAULT NULL,
    `last_login_ip`   VARCHAR(45) DEFAULT NULL,
    `login_attempts`  INT(3) NOT NULL DEFAULT 0,
    `locked_until`    DATETIME DEFAULT NULL,
    `reset_token`     VARCHAR(100) DEFAULT NULL,
    `reset_expires`   DATETIME DEFAULT NULL,
    `custom_perms`    JSON DEFAULT NULL COMMENT 'Extra or revoked permissions in JSON',
    `created_by`      INT(11) UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_role` (`role_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Super Admin (password: Admin@12345)
INSERT INTO `users` (`company_id`, `role_id`, `full_name`, `username`, `email`, `phone`, `password`, `status`) VALUES
(NULL, 1, 'Super Administrator', 'superadmin', 'admin@ispsystem.local', '01700000000',
 '$2y$12$LQv3c1yqBWVHxkd0LQ1Tc.hqLuvADdKp6P9HzVOLJbgBBLDgJMJCa', 'active');
-- NOTE: Change this password immediately after first login!


-- ============================================================
-- TABLE: defaulters
-- বকেয়া/ফ্রড গ্রাহকের তথ্য
-- ============================================================
CREATE TABLE `defaulters` (
    `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`      INT(11) UNSIGNED NOT NULL COMMENT 'যে কোম্পানি এন্ট্রি দিয়েছে',
    `entered_by`      INT(11) UNSIGNED NOT NULL COMMENT 'যে ইউজার এন্ট্রি দিয়েছে',
    -- Customer Info
    `customer_name`   VARCHAR(150) NOT NULL,
    `customer_phone`  VARCHAR(20) NOT NULL,
    `alt_phone`       VARCHAR(20) DEFAULT NULL,
    `nid_number`      VARCHAR(30) DEFAULT NULL,
    `email`           VARCHAR(150) DEFAULT NULL,
    -- Address
    `address_text`    TEXT NOT NULL COMMENT 'পূর্ণ ঠিকানা',
    `area`            VARCHAR(100) DEFAULT NULL,
    `thana`           VARCHAR(100) DEFAULT NULL,
    `district`        VARCHAR(100) DEFAULT NULL,
    `lat`             DECIMAL(10, 8) DEFAULT NULL COMMENT 'Google Maps latitude',
    `lng`             DECIMAL(11, 8) DEFAULT NULL COMMENT 'Google Maps longitude',
    `map_address`     TEXT DEFAULT NULL COMMENT 'Google Maps formatted address',
    -- Financial
    `due_amount`      DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'মোট বকেয়া টাকা',
    `connection_type` ENUM('home','office','corporate','other') NOT NULL DEFAULT 'home',
    `service_period`  VARCHAR(50) DEFAULT NULL COMMENT 'কতদিনের বকেয়া e.g. Jan-Jun 2024',
    -- Fraud Classification
    `type`            ENUM('due_payment','fraud','equipment_theft','contract_breach','other') NOT NULL DEFAULT 'due_payment',
    `risk_level`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `description`     TEXT DEFAULT NULL COMMENT 'বিস্তারিত বিবরণ',
    -- Status
    `status`          ENUM('active','resolved','disputed','removed') NOT NULL DEFAULT 'active',
    `resolution_type` ENUM('full_payment','partial_payment','waived','other') DEFAULT NULL COMMENT 'কিভাবে সমাধান হলো',
    `payment_amount`  DECIMAL(10,2) DEFAULT NULL COMMENT 'আসলে কত টাকা নেওয়া হয়েছে',
    `waiver_amount`   DECIMAL(10,2) DEFAULT NULL COMMENT 'কত টাকা মাফ/ছাড় দেওয়া হয়েছে',
    `resolved_at`     DATETIME DEFAULT NULL,
    `resolved_by`     INT(11) UNSIGNED DEFAULT NULL,
    `resolution_note` TEXT DEFAULT NULL,
    -- Metadata
    `view_count`      INT(11) NOT NULL DEFAULT 0 COMMENT 'কতবার দেখা হয়েছে',
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_phone` (`customer_phone`),
    KEY `idx_nid` (`nid_number`),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`type`),
    KEY `idx_risk` (`risk_level`),
    KEY `idx_area` (`area`),
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`),
    FOREIGN KEY (`entered_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: defaulter_photos
-- বকেয়া গ্রাহকের ছবি (একাধিক)
-- ============================================================
CREATE TABLE `defaulter_photos` (
    `id`             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `defaulter_id`   INT(11) UNSIGNED NOT NULL,
    `photo_path`     VARCHAR(255) NOT NULL,
    `photo_type`     ENUM('customer_face','nid_front','nid_back','equipment','other') NOT NULL DEFAULT 'customer_face',
    `caption`        VARCHAR(255) DEFAULT NULL,
    `is_primary`     TINYINT(1) NOT NULL DEFAULT 0,
    `uploaded_by`    INT(11) UNSIGNED NOT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_defaulter` (`defaulter_id`),
    FOREIGN KEY (`defaulter_id`) REFERENCES `defaulters`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: disputes
-- কোনো এন্ট্রি নিয়ে বিরোধ
-- ============================================================
CREATE TABLE `disputes` (
    `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `defaulter_id`    INT(11) UNSIGNED NOT NULL,
    `raised_by`       INT(11) UNSIGNED NOT NULL COMMENT 'যে কোম্পানি আপত্তি তুলেছে',
    `reason`          TEXT NOT NULL,
    `evidence`        VARCHAR(255) DEFAULT NULL COMMENT 'প্রমাণের ফাইল',
    `status`          ENUM('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
    `admin_note`      TEXT DEFAULT NULL,
    `resolved_by`     INT(11) UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_defaulter` (`defaulter_id`),
    FOREIGN KEY (`defaulter_id`) REFERENCES `defaulters`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`raised_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: activity_logs
-- সব কার্যকলাপের লগ
-- ============================================================
CREATE TABLE `activity_logs` (
    `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT(11) UNSIGNED DEFAULT NULL,
    `company_id`  INT(11) UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(100) NOT NULL COMMENT 'e.g. defaulter.create, company.approve',
    `module`      VARCHAR(50) NOT NULL,
    `target_id`   INT(11) UNSIGNED DEFAULT NULL COMMENT 'Affected record ID',
    `target_type` VARCHAR(50) DEFAULT NULL COMMENT 'Table name',
    `description` TEXT DEFAULT NULL,
    `old_data`    JSON DEFAULT NULL,
    `new_data`    JSON DEFAULT NULL,
    `ip_address`  VARCHAR(45) DEFAULT NULL,
    `user_agent`  TEXT DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_action` (`action`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: notifications
-- সিস্টেম নোটিফিকেশন
-- ============================================================
CREATE TABLE `notifications` (
    `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = broadcast to all companies',
    `company_id`  INT(11) UNSIGNED DEFAULT NULL COMMENT 'NULL = all companies',
    `title`       VARCHAR(255) NOT NULL,
    `message`     TEXT NOT NULL,
    `type`        ENUM('info','warning','success','danger') NOT NULL DEFAULT 'info',
    `icon`        VARCHAR(50) DEFAULT NULL,
    `link`        VARCHAR(255) DEFAULT NULL,
    `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
    `read_at`     DATETIME DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE: settings
-- সিস্টেম সেটিংস
-- ============================================================
CREATE TABLE `settings` (
    `key`         VARCHAR(100) NOT NULL,
    `value`       TEXT DEFAULT NULL,
    `label`       VARCHAR(255) DEFAULT NULL,
    `group`       VARCHAR(50) NOT NULL DEFAULT 'general',
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `label`, `group`) VALUES
('site_name',           'ISP Defaulter Management System', 'সাইটের নাম',          'general'),
('site_tagline',        'এলাকার ISP গুলোর যৌথ বকেয়া ব্যবস্থাপনা', 'ট্যাগলাইন', 'general'),
('site_email',          'admin@ispsystem.local',           'যোগাযোগের ইমেইল',    'general'),
('site_phone',          '',                                'যোগাযোগের ফোন',      'general'),
('site_logo',           '',                                'লোগো',               'general'),
('max_login_attempts',  '5',                               'সর্বোচ্চ লগইন চেষ্টা', 'security'),
('lockout_duration',    '30',                              'লকআউট সময় (মিনিট)',  'security'),
('require_approval',    '1',                               'কোম্পানি অনুমোদন আবশ্যক', 'registration'),
('allow_registration',  '1',                               'নতুন রেজিস্ট্রেশন চালু', 'registration'),
('google_maps_key',     '',                                'Google Maps API Key', 'integration'),
('smtp_host',           '',                                'SMTP Host',           'email'),
('smtp_port',           '587',                             'SMTP Port',           'email'),
('smtp_user',           '',                                'SMTP Username',       'email'),
('smtp_pass',           '',                                'SMTP Password',       'email'),
('smtp_from',           '',                                'From Email',          'email'),
('items_per_page',      '20',                              'প্রতি পেজে আইটেম',   'display'),
('timezone',            'Asia/Dhaka',                      'টাইমজোন',            'general');

-- ============================================================
-- TABLE: import_logs (A-3.3)
-- বাল্ক ইমপোর্ট ইতিহাস
-- ============================================================
CREATE TABLE `import_logs` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id`    INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `filename`      VARCHAR(255) NOT NULL,
    `total_rows`    SMALLINT NOT NULL DEFAULT 0,
    `success_count` SMALLINT NOT NULL DEFAULT 0,
    `skip_count`    SMALLINT NOT NULL DEFAULT 0,
    `error_count`   SMALLINT NOT NULL DEFAULT 0,
    `notes`         TEXT DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
