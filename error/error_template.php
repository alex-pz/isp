<?php
// Direct access বা Apache এ সরাসরি call হলে default দাও
$code  = $code  ?? http_response_code() ?: 500;
$title = $title ?? 'সার্ভার ত্রুটি';
$msg   = $msg   ?? 'একটি সমস্যা হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।';
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $code ?> — <?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Hind Siliguri', sans-serif; }
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f0f4f8; }
    </style>
</head>
<body>
<div class="text-center p-4">
    <div style="font-size:72px;font-weight:700;color:#e2e8f0;line-height:1;"><?= $code ?></div>
    <h2 style="color:#1a3a5c;font-weight:700;"><?= htmlspecialchars($title) ?></h2>
    <p class="text-muted"><?= htmlspecialchars($msg) ?></p>
    <a href="javascript:history.back()" class="btn btn-primary rounded-3 mt-2">← ফিরে যান</a>
</div>
</body>
</html>