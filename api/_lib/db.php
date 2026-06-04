<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Слой работы с БД.
 * SQLite, одна таблица purchases.
 * Файл базы создаётся автоматически при первом обращении.
 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = Config::dbPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // SQLite-специфика: WAL для лучшей конкурентности, foreign keys ON
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::initSchema();

        return $pdo;
    }

    private static function initSchema(): void
    {
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS purchases (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                stripe_session  TEXT UNIQUE NOT NULL,
                tier            TEXT NOT NULL,
                tier_name       TEXT NOT NULL,
                amount_eur      INTEGER NOT NULL,
                customer_email  TEXT,
                customer_name   TEXT,
                status          TEXT NOT NULL DEFAULT 'pending',
                mode            TEXT NOT NULL,
                metadata_json   TEXT,
                created_at      TEXT NOT NULL DEFAULT (datetime('now')),
                paid_at         TEXT,
                notified_at     TEXT
            )
        ");

        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_status ON purchases(status)");
        self::$pdo->exec("CREATE INDEX IF NOT EXISTS idx_purchases_created ON purchases(created_at DESC)");
    }

    /**
     * Создать запись о попытке покупки (статус pending).
     * Возвращает true если новая запись, false если уже была (idempotent).
     */
    public static function createPendingPurchase(
        string $stripeSession,
        string $tier,
        string $tierName,
        int $amountEur,
        string $mode,
        array $metadata = []
    ): bool {
        try {
            $stmt = self::pdo()->prepare("
                INSERT INTO purchases (stripe_session, tier, tier_name, amount_eur, mode, metadata_json)
                VALUES (:s, :t, :tn, :a, :m, :meta)
            ");
            $stmt->execute([
                ':s'    => $stripeSession,
                ':t'    => $tier,
                ':tn'   => $tierName,
                ':a'    => $amountEur,
                ':m'    => $mode,
                ':meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
            return true;
        } catch (PDOException $e) {
            // UNIQUE constraint = идемпотентно
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Пометить покупку как оплаченную. Идемпотентно — повторный вызов не дублирует данные.
     * Возвращает true если статус только что изменился (стоит слать email),
     * false если уже был paid (повторный webhook от Stripe).
     */
    public static function markAsPaid(
        string $stripeSession,
        ?string $customerEmail = null,
        ?string $customerName = null
    ): bool {
        $current = self::pdo()->prepare("SELECT status FROM purchases WHERE stripe_session = :s");
        $current->execute([':s' => $stripeSession]);
        $row = $current->fetch();

        if ($row === false) {
            return false;
        }

        if ($row['status'] === 'paid') {
            return false;
        }

        $stmt = self::pdo()->prepare("
            UPDATE purchases
            SET status = 'paid',
                customer_email = COALESCE(:e, customer_email),
                customer_name = COALESCE(:n, customer_name),
                paid_at = datetime('now')
            WHERE stripe_session = :s
        ");
        $stmt->execute([
            ':e' => $customerEmail,
            ':n' => $customerName,
            ':s' => $stripeSession,
        ]);

        return true;
    }

    public static function markAsNotified(string $stripeSession): void
    {
        $stmt = self::pdo()->prepare("
            UPDATE purchases SET notified_at = datetime('now') WHERE stripe_session = :s
        ");
        $stmt->execute([':s' => $stripeSession]);
    }

    public static function findBySession(string $stripeSession): ?array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM purchases WHERE stripe_session = :s");
        $stmt->execute([':s' => $stripeSession]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function listRecent(int $limit = 100): array
    {
        $stmt = self::pdo()->prepare("
            SELECT * FROM purchases ORDER BY created_at DESC LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function stats(): array
    {
        $pdo = self::pdo();
        return [
            'total_paid'   => (int) $pdo->query("SELECT COUNT(*) FROM purchases WHERE status = 'paid'")->fetchColumn(),
            'total_pending' => (int) $pdo->query("SELECT COUNT(*) FROM purchases WHERE status = 'pending'")->fetchColumn(),
            'revenue_eur'   => (int) $pdo->query("SELECT COALESCE(SUM(amount_eur), 0) FROM purchases WHERE status = 'paid'")->fetchColumn(),
            'by_tier'       => $pdo->query("
                SELECT tier_name, COUNT(*) as cnt, SUM(amount_eur) as sum_eur
                FROM purchases WHERE status = 'paid'
                GROUP BY tier
            ")->fetchAll(),
        ];
    }
}
