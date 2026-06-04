<?php
declare(strict_types=1);

/**
 * POST /api/checkout.php
 *
 * Принимает: { "tier": "basic" | "standard" | "vip" }
 * Возвращает: { "url": "https://checkout.stripe.com/..." }
 *
 * Логика:
 * 1. Валидируем tier
 * 2. Создаём Stripe Checkout Session (или mock-session)
 * 3. Пишем pending-purchase в БД
 * 4. Отдаём URL клиенту → JS делает window.location = url
 */

require_once __DIR__ . '/_lib/config.php';
require_once __DIR__ . '/_lib/db.php';

header('Content-Type: application/json; charset=utf-8');

// CORS — на случай если фронт и бэк на разных поддоменах
header('Access-Control-Allow-Origin: ' . (Config::siteUrl() ?: '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// ---- Парсим тело
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || !isset($body['tier'])) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_tier']);
    exit;
}

$tierKey = (string) $body['tier'];
$tier = Config::tier($tierKey);

if ($tier === null) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_tier']);
    exit;
}


// ---- Создаём Checkout Session
// Если SITE_URL в .env не задан — определяем из текущего запроса.
// Это важно для локальной разработки на разных портах (php -S localhost:8000 и т.д.).
$siteUrl = Config::siteUrl();
if ($siteUrl === '') {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $siteUrl = $proto . '://' . $host;
}

$successUrl = $siteUrl . '/thank-you.html?session_id={CHECKOUT_SESSION_ID}&tier=' . $tierKey;
$cancelUrl  = $siteUrl . '/#pricing';

try {
    if (Config::isMock()) {
        // MOCK-режим: имитируем поведение Stripe без реальных запросов.
        // Это даёт возможность тестировать всю цепочку (фронт → checkout → thank-you → webhook → email)
        // без реальных Stripe-ключей.
        $sessionId = 'cs_mock_' . bin2hex(random_bytes(12));

        DB::createPendingPurchase(
            stripeSession: $sessionId,
            tier:          $tierKey,
            tierName:      $tier['name'],
            amountEur:     $tier['price_eur'],
            mode:          'mock',
            metadata:      ['source' => 'web']
        );

        // В mock-режиме редиректим прямо на thank-you (минуя Stripe-страницу).
        // Также сразу вызываем mock-webhook чтобы записать оплату как успешную
        // — иначе на thank-you не будет данных.
        $mockSuccessUrl = $siteUrl . '/thank-you.html?session_id=' . $sessionId . '&tier=' . $tierKey . '&mock=1';

        echo json_encode([
            'url' => $mockSuccessUrl,
            'mode' => 'mock',
            'session_id' => $sessionId,
        ]);
        exit;
    }

    // ---- Реальный Stripe (test или live)
    if (!class_exists(\Stripe\StripeClient::class)) {
        // Stripe SDK не установлен — это значит не сделан composer install
        http_response_code(500);
        echo json_encode([
            'error' => 'stripe_sdk_missing',
            'hint'  => 'Run: composer install',
        ]);
        exit;
    }

    $stripe = new \Stripe\StripeClient(Config::stripeSecretKey());

    $session = $stripe->checkout->sessions->create([
        'mode' => 'payment',
        'line_items' => [[
            'price'    => $tier['stripe_price'],
            'quantity' => 1,
        ]],
        'success_url' => $successUrl,
        'cancel_url'  => $cancelUrl,
        'metadata' => [
            'tier'      => $tierKey,
            'tier_name' => $tier['name'],
        ],
        'locale' => 'auto',
        'allow_promotion_codes' => false,
        // Email обязателен — нужен для отправки приветствия и для аналитики
        'customer_creation' => 'always',
    ]);

    // Записываем pending — webhook потом обновит на paid
    DB::createPendingPurchase(
        stripeSession: $session->id,
        tier:          $tierKey,
        tierName:      $tier['name'],
        amountEur:     $tier['price_eur'],
        mode:          Config::mode(),
        metadata:      ['source' => 'web']
    );

    echo json_encode([
        'url'        => $session->url,
        'mode'       => Config::mode(),
        'session_id' => $session->id,
    ]);

} catch (\Throwable $e) {
    error_log('[checkout] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'checkout_failed',
        'message' => Config::isMock() ? $e->getMessage() : 'Could not create checkout session',
    ]);
}
