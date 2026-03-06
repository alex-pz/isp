<?php
// ============================================================
// NOTIFICATION SERVICE — Email + SMS
// File: includes/notification_service.php
// ============================================================

class NotificationService
{
    // ============================================================
    // EMAIL via SMTP (PHPMailer-compatible raw SMTP)
    // ============================================================
    public static function sendEmail(string $to, string $toName, string $subject, string $body): array
    {
        $host    = getSetting('smtp_host', '');
        $port    = (int)getSetting('smtp_port', '587');
        $user    = getSetting('smtp_user', '');
        $pass    = getSetting('smtp_pass', '');
        $from    = getSetting('smtp_from', $user);
        $fromName= getSetting('site_name', 'ISP Defaulter System');

        if (empty($host) || empty($user)) {
            return ['success' => false, 'error' => 'SMTP সেটিংস কনফিগার করা নেই।'];
        }

        $htmlBody = self::wrapEmailTemplate($subject, $body);

        // Use PHP mail() as fallback if SMTP not configured
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "X-Mailer: ISP-Defaulter-System\r\n";

        // Try SMTP socket connection
        try {
            $smtp = fsockopen(
                ($port === 465 ? 'ssl://' : 'tls://') . $host,
                $port, $errno, $errstr, 10
            );
            if (!$smtp) throw new Exception("SMTP Connection failed: $errstr");

            $read = fgets($smtp, 515);
            if (substr($read, 0, 3) !== '220') throw new Exception("SMTP greeting failed");

            $cmds = [
                "EHLO " . gethostname() . "\r\n"            => '250',
                "AUTH LOGIN\r\n"                             => '334',
                base64_encode($user) . "\r\n"                => '334',
                base64_encode($pass) . "\r\n"                => '235',
                "MAIL FROM: <$from>\r\n"                     => '250',
                "RCPT TO: <$to>\r\n"                         => '250',
                "DATA\r\n"                                    => '354',
                "To: $toName <$to>\r\nFrom: $fromName <$from>\r\n" .
                "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" .
                $htmlBody . "\r\n.\r\n"                      => '250',
                "QUIT\r\n"                                   => '221',
            ];

            foreach ($cmds as $cmd => $expectedCode) {
                fputs($smtp, $cmd);
                $response = fgets($smtp, 515);
                if (substr($response, 0, 3) !== $expectedCode) {
                    fclose($smtp);
                    throw new Exception("SMTP error (expected $expectedCode): $response");
                }
            }
            fclose($smtp);
            return ['success' => true];

        } catch (Exception $e) {
            // Fallback to PHP mail()
            $sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
            return $sent
                ? ['success' => true]
                : ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================================
    // SMS via BD SMS Gateways
    // Supports: SSL Wireless, Infobip, Twilio, BulkSMSBD
    // ============================================================
    public static function sendSMS(string $phone, string $message): array
    {
        $provider = getSetting('sms_provider', ''); // ssl | infobip | twilio | bulksmsbd
        if (empty($provider)) {
            return ['success' => false, 'error' => 'SMS প্রোভাইডার কনফিগার করা নেই।'];
        }

        // Normalize BD phone: 01XXXXXXXXX → 8801XXXXXXXXX
        $phone = preg_replace('/^0/', '88', $phone);

        return match($provider) {
            'ssl'       => self::sendSSLWireless($phone, $message),
            'infobip'   => self::sendInfobip($phone, $message),
            'twilio'    => self::sendTwilio($phone, $message),
            'bulksmsbd' => self::sendBulkSMSBD($phone, $message),
            default     => ['success' => false, 'error' => 'অজানা SMS প্রোভাইডার।'],
        };
    }

    // ---- SSL Wireless (sslwireless.com) ----
    private static function sendSSLWireless(string $phone, string $message): array
    {
        $apiToken = getSetting('sms_api_token', '');
        $sid      = getSetting('sms_sid', '');
        $url = 'https://core.sslwireless.com/api/v3/send-sms?' . http_build_query([
            'api_token' => $apiToken,
            'sid'       => $sid,
            'msisdn'    => $phone,
            'sms'       => $message,
            'csms_id'   => uniqid('isp_'),
        ]);
        return self::httpGet($url);
    }

    // ---- Infobip ----
    private static function sendInfobip(string $phone, string $message): array
    {
        $apiKey   = getSetting('sms_api_token', '');
        $baseUrl  = getSetting('sms_base_url', '');
        $from     = getSetting('sms_sender_id', 'ISPDefaulter');
        $response = self::httpPost("$baseUrl/sms/2/text/advanced", [
            'messages' => [[
                'from' => $from,
                'destinations' => [['to' => $phone]],
                'text' => $message,
            ]]
        ], ['Authorization: App ' . $apiKey, 'Content-Type: application/json']);
        return $response;
    }

    // ---- Twilio ----
    private static function sendTwilio(string $phone, string $message): array
    {
        $sid   = getSetting('sms_sid', '');
        $token = getSetting('sms_api_token', '');
        $from  = getSetting('sms_sender_id', '');
        return self::httpPost(
            "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json",
            ['From' => $from, 'To' => '+' . $phone, 'Body' => $message],
            ['Authorization: Basic ' . base64_encode("$sid:$token")]
        );
    }

    // ---- BulkSMSBD ----
    private static function sendBulkSMSBD(string $phone, string $message): array
    {
        $user  = getSetting('sms_username', '');
        $pass  = getSetting('sms_password', '');
        $sid   = getSetting('sms_sid', '');
        $url   = 'http://bulksmsbd.net/api/smsapi?' . http_build_query([
            'api_key'  => getSetting('sms_api_token', ''),
            'type'     => 'text',
            'number'   => $phone,
            'senderid' => $sid,
            'message'  => $message,
        ]);
        return self::httpGet($url);
    }

    // ============================================================
    // NOTIFICATION TRIGGERS — called from various modules
    // ============================================================

    // New defaulter entry — notify all company admins
    public static function notifyNewDefaulter(array $defaulter, int $enteredByCompanyId): void
    {
        $siteName = getSetting('site_name', 'ISP Defaulter System');
        $link     = SITE_URL . '/modules/defaulter/view.php?id=' . $defaulter['id'];
        $msg      = '"' . $defaulter['customer_name'] . '" (' . $defaulter['customer_phone'] . ')' .
                    ' — বকেয়া: ৳' . number_format($defaulter['due_amount']);

        // In-app notification (already created in add.php)
        // Email to company admins
        if (getSetting('email_notify_new_defaulter', '0') === '1') {
            $admins = Database::fetchAll(
                "SELECT u.email, u.full_name, c.company_name
                 FROM users u JOIN companies c ON c.id = u.company_id
                 WHERE c.status='approved' AND u.company_id != ? AND u.role_id IN (3) AND u.status='active'",
                [$enteredByCompanyId]
            );
            foreach ($admins as $admin) {
                $body = "
                    <p>নতুন বকেয়া এন্ট্রি আপনার এলাকায় যোগ হয়েছে।</p>
                    <table style='width:100%;border-collapse:collapse;'>
                        <tr><td style='padding:6px;border:1px solid #e2e8f0;color:#64748b;'>গ্রাহকের নাম</td><td style='padding:6px;border:1px solid #e2e8f0;font-weight:600;'>" . htmlspecialchars($defaulter['customer_name']) . "</td></tr>
                        <tr><td style='padding:6px;border:1px solid #e2e8f0;color:#64748b;'>মোবাইল</td><td style='padding:6px;border:1px solid #e2e8f0;'>" . htmlspecialchars($defaulter['customer_phone']) . "</td></tr>
                        <tr><td style='padding:6px;border:1px solid #e2e8f0;color:#64748b;'>বকেয়া</td><td style='padding:6px;border:1px solid #e2e8f0;color:#dc2626;font-weight:700;'>৳" . number_format($defaulter['due_amount']) . "</td></tr>
                        <tr><td style='padding:6px;border:1px solid #e2e8f0;color:#64748b;'>ঝুঁকি</td><td style='padding:6px;border:1px solid #e2e8f0;'>" . getStatusLabel($defaulter['risk_level']) . "</td></tr>
                    </table>
                    <p style='margin-top:16px;'><a href='$link' style='background:#1a3a5c;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;'>বিস্তারিত দেখুন →</a></p>";
                self::sendEmail($admin['email'], $admin['full_name'], "[$siteName] নতুন বকেয়া এন্ট্রি", $body);
            }
        }

        // SMS to company admins
        if (getSetting('sms_notify_new_defaulter', '0') === '1') {
            $admins = Database::fetchAll(
                "SELECT u.phone FROM users u JOIN companies c ON c.id = u.company_id
                 WHERE c.status='approved' AND u.company_id != ? AND u.role_id = 3 AND u.status='active'",
                [$enteredByCompanyId]
            );
            $smsText = "[$siteName] নতুন বকেয়া: {$defaulter['customer_name']} ({$defaulter['customer_phone']}) ৳" . number_format($defaulter['due_amount']);
            foreach ($admins as $admin) {
                if ($admin['phone']) self::sendSMS($admin['phone'], $smsText);
            }
        }
    }

    // Company approved — notify company admin
    public static function notifyCompanyApproved(int $companyId): void
    {
        $company = Database::fetchOne("SELECT * FROM companies WHERE id = ?", [$companyId]);
        $user    = Database::fetchOne("SELECT * FROM users WHERE company_id = ? AND role_id = 3 LIMIT 1", [$companyId]);
        if (!$company || !$user) return;

        $siteName = getSetting('site_name', 'ISP Defaulter System');
        $body = "
            <p>অভিনন্দন! আপনার কোম্পানি <strong>" . htmlspecialchars($company['company_name']) . "</strong> অনুমোদিত হয়েছে।</p>
            <p>এখন আপনি সিস্টেমে লগইন করে বকেয়া তালিকা পরিচালনা করতে পারবেন।</p>
            <p><a href='" . SITE_URL . "/login.php' style='background:#1a3a5c;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;'>এখনই লগইন করুন →</a></p>";

        if (getSetting('email_notify_approval', '1') === '1' && $user['email']) {
            self::sendEmail($user['email'], $user['full_name'], "[$siteName] আপনার কোম্পানি অনুমোদিত হয়েছে!", $body);
        }
        if (getSetting('sms_notify_approval', '1') === '1' && $user['phone']) {
            self::sendSMS($user['phone'], "[$siteName] আপনার কোম্পানি অনুমোদিত! এখন লগইন করুন: " . SITE_URL);
        }
    }

    // Unresolved due reminder — cron job call
    public static function sendDueReminders(): void
    {
        $days = (int)getSetting('due_reminder_days', '30');
        $defaulters = Database::fetchAll(
            "SELECT d.*, c.company_name, u.email, u.full_name, u.phone
             FROM defaulters d
             JOIN companies c ON c.id = d.company_id
             JOIN users u ON u.id = d.entered_by
             WHERE d.status = 'active'
             AND d.created_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
             AND (d.last_reminder_at IS NULL OR d.last_reminder_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))",
            [$days]
        );

        $siteName = getSetting('site_name', 'ISP Defaulter System');
        foreach ($defaulters as $d) {
            $body = "
                <p><strong>" . htmlspecialchars($d['customer_name']) . "</strong> ({$d['customer_phone']}) এর বকেয়া এখনো সমাধান হয়নি।</p>
                <p style='color:#dc2626;font-size:20px;font-weight:700;'>৳" . number_format($d['due_amount']) . "</p>
                <p><a href='" . SITE_URL . "/modules/defaulter/view.php?id={$d['id']}' style='background:#1a3a5c;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;'>এন্ট্রি দেখুন →</a></p>";

            self::sendEmail($d['email'], $d['full_name'], "[$siteName] বকেয়া রিমাইন্ডার", $body);
            Database::update('defaulters', ['last_reminder_at' => date('Y-m-d H:i:s')], 'id = ?', [$d['id']]);
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================
    private static function wrapEmailTemplate(string $subject, string $body): string
    {
        $siteName = getSetting('site_name', 'ISP Defaulter System');
        $year     = date('Y');
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f4f8; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,.1); }
            .header { background: linear-gradient(135deg, #0f2238, #2563a8); padding: 24px 32px; color: #fff; }
            .header h1 { margin: 0; font-size: 18px; }
            .header p  { margin: 4px 0 0; opacity: .75; font-size: 13px; }
            .body { padding: 28px 32px; font-size: 14px; line-height: 1.6; color: #374151; }
            .footer { padding: 16px 32px; background: #f8fafc; text-align: center; font-size: 11px; color: #94a3b8; }
        </style></head><body>
        <div class='container'>
            <div class='header'><h1>$siteName</h1><p>$subject</p></div>
            <div class='body'>$body</div>
            <div class='footer'>© $year $siteName — এই ইমেইল স্বয়ংক্রিয়ভাবে পাঠানো হয়েছে।</div>
        </div></body></html>";
    }

    private static function httpGet(string $url): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10,
            'header' => "User-Agent: ISP-Defaulter/1.0\r\n"]]);
        $response = @file_get_contents($url, false, $ctx);
        return $response !== false
            ? ['success' => true, 'response' => $response]
            : ['success' => false, 'error' => 'HTTP request failed'];
    }

    private static function httpPost(string $url, array $data, array $headers = []): array
    {
        $isJson   = in_array('Content-Type: application/json', $headers);
        $body     = $isJson ? json_encode($data) : http_build_query($data);
        $headers  = array_merge(['Content-Type: ' . ($isJson ? 'application/json' : 'application/x-www-form-urlencoded'),
                                 'Content-Length: ' . strlen($body)], $headers);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 10,
        ]]);
        $response = @file_get_contents($url, false, $ctx);
        return $response !== false
            ? ['success' => true, 'response' => $response]
            : ['success' => false, 'error' => 'HTTP request failed'];
    }
}
