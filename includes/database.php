<?php
// ============================================================
// DATABASE CONNECTION (PDO) - Optimized & Hardened
// File: includes/database.php
// ============================================================

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    /**
     * Singleton instance তৈরি করা
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=%s',
                    DB_HOST, DB_NAME, DB_CHARSET
                );
                
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false, // SQL Injection প্রোটেকশনের জন্য জরুরি
                    PDO::ATTR_PERSISTENT         => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    PDO::ATTR_TIMEOUT            => 5, // কানেকশন টাইমআউট ৫ সেকেন্ড
                ]);
            } catch (PDOException $e) {
                // এরর লগিং (প্রোডাকশনে ইউজারকে এরর না দেখিয়ে লগে সেভ করা)
                error_log("DB Connection Error: " . $e->getMessage());
                
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    die('<div style="background:#f44;color:#fff;padding:20px;font-family:monospace;">
                        <strong>Database Connection Error:</strong><br>' . 
                        htmlspecialchars($e->getMessage()) . '</div>');
                } else {
                    die('সার্ভার সমস্যা হচ্ছে। একটু পরে আবার চেষ্টা করুন।');
                }
            }
        }
        return self::$instance;
    }

    /**
     * টেবিল বা কলামের নামকে ব্যাকটিক (`) দিয়ে সুরক্ষিত করা
     */
    private static function escapeIdentifier(string $name): string {
        return "`" . str_replace("`", "``", $name) . "`";
    }

    /**
     * জেনারেল কুয়েরি এক্সেকিউটর
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("SQL Error: " . $e->getMessage() . " | Query: " . $sql);
            throw $e; // ট্রানজেকশন হ্যান্ডেল করার জন্য এক্সেপশন থ্রো করা হয়েছে
        }
    }

    /**
     * একটি মাত্র রো (Row) রিটার্ন করা
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $result = self::query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * সব রো (All Rows) রিটার্ন করা
     */
    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * ডাইনামিক ইনসার্ট (Insert)
     */
    public static function insert(string $table, array $data): int {
        $cols = implode(', ', array_map([self::class, 'escapeIdentifier'], array_keys($data)));
        $holders = implode(', ', array_fill(0, count($data), '?'));
        
        $tableEscaped = self::escapeIdentifier($table);
        self::query("INSERT INTO $tableEscaped ($cols) VALUES ($holders)", array_values($data));
        
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * ডাইনামিক আপডেট (Update)
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $sets = implode(', ', array_map(fn($k) => self::escapeIdentifier($k) . " = ?", array_keys($data)));
        $tableEscaped = self::escapeIdentifier($table);
        
        $stmt = self::query(
            "UPDATE $tableEscaped SET $sets WHERE $where",
            [...array_values($data), ...$whereParams]
        );
        
        return $stmt->rowCount();
    }

    /**
     * ডাইনামিক ডিলিট (Delete)
     */
    public static function delete(string $table, string $where, array $params = []): int {
        $tableEscaped = self::escapeIdentifier($table);
        return self::query("DELETE FROM $tableEscaped WHERE $where", $params)->rowCount();
    }

    /**
     * ডাটা কাউন্ট করা (Count)
     */
    public static function count(string $table, string $where = '1', array $params = []): int {
        $tableEscaped = self::escapeIdentifier($table);
        $row = self::fetchOne("SELECT COUNT(*) as cnt FROM $tableEscaped WHERE $where", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    // ============================================================
    // TRANSACTION MANAGEMENT
    // ============================================================

    public static function beginTransaction(): void {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void {
        self::getInstance()->commit();
    }

    public static function rollback(): void {
        self::getInstance()->rollBack();
    }
}

/**
 * গ্লোবাল শর্টকাট ফাংশন
 */
function db(): PDO { 
    return Database::getInstance(); 
}
