<?php
declare(strict_types=1);

/**
 * GET /api/admin.php
 *
 * Простая админка для Марии: список покупок + статистика.
 * Доступ через HTTP Basic Auth (логин/пароль в .env).
 *
 * Это намеренно простая страница без фреймворков — мобильная,
 * читаемая, без js-логики. Если потребуется больше функций
 * (фильтры, экспорт, поиск), вынесем на отдельный слой.
 */

require_once __DIR__ . '/_lib/config.php';
require_once __DIR__ . '/_lib/db.php';

// ---- Basic Auth
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW']   ?? '';

if ($user !== Config::adminUser() || !hash_equals(Config::adminPassword(), $pass) || Config::adminPassword() === '') {
    header('WWW-Authenticate: Basic realm="Tonko-Krepko Admin"');
    http_response_code(401);
    echo 'Authentication required';
    exit;
}


$stats     = DB::stats();
$purchases = DB::listRecent(200);

function esc(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Тонко-Крепко · Админка</title>
<style>
:root {
    --c-bg: #F5EFE7;
    --c-bg-soft: #FAF6F0;
    --c-ink: #1F1814;
    --c-ink-soft: #6B5D52;
    --c-ink-muted: #9B8B7F;
    --c-accent: #A51D8B;
    --c-line: #E5D9CC;
    --c-success: #4F7A4F;
    --c-pending: #A51D8B;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Helvetica Neue', sans-serif;
    background: var(--c-bg);
    color: var(--c-ink);
    line-height: 1.5;
    padding: 2rem 1.5rem 5rem;
}
.wrap { max-width: 1200px; margin: 0 auto; }
header { margin-bottom: 2.5rem; }
h1 {
    font-family: Georgia, 'Times New Roman', serif;
    font-weight: 400;
    font-size: 2rem;
    letter-spacing: -0.02em;
    color: var(--c-ink);
}
h1 em {
    font-style: italic;
    color: var(--c-accent);
    font-weight: 300;
}
.mode-tag {
    display: inline-block;
    margin-top: 0.5rem;
    padding: 0.25rem 0.7rem;
    background: var(--c-bg-soft);
    border: 1px solid var(--c-line);
    border-radius: 999px;
    font-size: 0.78rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--c-ink-muted);
    font-weight: 600;
}
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 2.5rem;
}
.stat {
    padding: 1.5rem;
    background: var(--c-bg-soft);
    border: 1px solid var(--c-line);
    border-radius: 8px;
}
.stat-label {
    font-size: 0.75rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--c-ink-muted);
    font-weight: 600;
}
.stat-value {
    font-family: Georgia, serif;
    font-size: 2rem;
    margin-top: 0.4rem;
    color: var(--c-ink);
    letter-spacing: -0.01em;
}
.stat-value small { font-size: 0.85rem; color: var(--c-ink-muted); }

.by-tier {
    margin-bottom: 2.5rem;
    padding: 1.25rem 1.5rem;
    background: var(--c-bg-soft);
    border: 1px solid var(--c-line);
    border-radius: 8px;
}
.by-tier-title {
    font-size: 0.78rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--c-ink-muted);
    font-weight: 600;
    margin-bottom: 0.85rem;
}
.by-tier-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-block: 0.4rem;
}
.by-tier-row strong { font-weight: 600; min-width: 120px; }
.by-tier-row .cnt { color: var(--c-ink-soft); }
.by-tier-row .sum { color: var(--c-accent); margin-left: auto; font-variant-numeric: tabular-nums; }

h2 {
    font-family: Georgia, serif;
    font-weight: 400;
    font-size: 1.3rem;
    margin-bottom: 1rem;
    color: var(--c-ink);
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    background: var(--c-bg-soft);
    border: 1px solid var(--c-line);
    border-radius: 8px;
    overflow: hidden;
}
th, td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--c-line);
    vertical-align: top;
}
th {
    font-size: 0.72rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--c-ink-muted);
    font-weight: 600;
    background: var(--c-bg);
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--c-bg); }

.status-badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.status-paid { background: rgba(79, 122, 79, 0.12); color: var(--c-success); }
.status-pending { background: rgba(165, 29, 139, 0.12); color: var(--c-pending); }

.mode-cell {
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 0.7rem;
    color: var(--c-ink-muted);
    text-transform: uppercase;
}
.session-id {
    font-family: ui-monospace, 'SF Mono', Menlo, monospace;
    font-size: 0.7rem;
    color: var(--c-ink-muted);
}
.email-cell { color: var(--c-accent); word-break: break-all; }
.amount-cell { font-variant-numeric: tabular-nums; font-weight: 600; }

.empty {
    padding: 3rem 1.5rem;
    text-align: center;
    color: var(--c-ink-muted);
    background: var(--c-bg-soft);
    border: 1px dashed var(--c-line);
    border-radius: 8px;
}

@media (max-width: 720px) {
    body { padding: 1.5rem 1rem 4rem; }
    h1 { font-size: 1.5rem; }
    .stat-value { font-size: 1.5rem; }
    table { font-size: 0.8rem; display: block; overflow-x: auto; }
    th, td { padding: 0.5rem 0.6rem; }
}
</style>
</head>
<body>
<div class="wrap">

<header>
    <h1>Тонко·<em>Крепко</em> · Админка</h1>
    <span class="mode-tag">Режим: <?= esc(Config::mode()) ?></span>
</header>

<section class="stats">
    <div class="stat">
        <div class="stat-label">Оплачено</div>
        <div class="stat-value"><?= (int)$stats['total_paid'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Ожидают</div>
        <div class="stat-value"><?= (int)$stats['total_pending'] ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Выручка</div>
        <div class="stat-value"><?= (int)$stats['revenue_eur'] ?><small> €</small></div>
    </div>
</section>

<?php if (!empty($stats['by_tier'])): ?>
<section class="by-tier">
    <div class="by-tier-title">По тарифам</div>
    <?php foreach ($stats['by_tier'] as $row): ?>
        <div class="by-tier-row">
            <strong><?= esc($row['tier_name']) ?></strong>
            <span class="cnt"><?= (int)$row['cnt'] ?> покуп.</span>
            <span class="sum"><?= (int)$row['sum_eur'] ?> €</span>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<h2>Покупки <span style="font-size: 0.85rem; color: var(--c-ink-muted);">(последние 200)</span></h2>

<?php if (empty($purchases)): ?>
    <div class="empty">Покупок пока нет.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Дата</th>
            <th>Тариф</th>
            <th>Сумма</th>
            <th>Покупатель</th>
            <th>Статус</th>
            <th>Уведомление</th>
            <th>Режим</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($purchases as $p): ?>
        <tr>
            <td>
                <?= esc(substr($p['created_at'], 0, 16)) ?>
                <?php if ($p['paid_at']): ?>
                    <br><small style="color: var(--c-ink-muted)">оплата: <?= esc(substr($p['paid_at'], 11, 5)) ?></small>
                <?php endif; ?>
            </td>
            <td><?= esc($p['tier_name']) ?></td>
            <td class="amount-cell"><?= (int)$p['amount_eur'] ?> €</td>
            <td>
                <?php if ($p['customer_email']): ?>
                    <div><?= esc($p['customer_name'] ?? '—') ?></div>
                    <div class="email-cell"><?= esc($p['customer_email']) ?></div>
                <?php else: ?>
                    <span style="color: var(--c-ink-muted)">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="status-badge status-<?= esc($p['status']) ?>">
                    <?= $p['status'] === 'paid' ? 'оплачено' : 'ожидает' ?>
                </span>
            </td>
            <td>
                <?php if ($p['notified_at']): ?>
                    <span style="color: var(--c-success)">✓ отправлено</span>
                <?php else: ?>
                    <span style="color: var(--c-ink-muted)">—</span>
                <?php endif; ?>
            </td>
            <td class="mode-cell"><?= esc($p['mode']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</div>
</body>
</html>
