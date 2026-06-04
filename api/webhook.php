<?php
declare(strict_types=1);

/**
 * POST /api/webhook.php
 *
 * Принимает события от Stripe (checkout.session.completed) и обновляет БД.
 * Stripe вызывает этот endpoint POST-ом с подписью в заголовке Stripe-Signature.
 *
 * Настройка в Stripe Dashboard:
 *   Developers → Webhooks → Add endpoint
 *   URL: https://домен/api/webhook.php
 *   Events: checkout.session.completed
 *
 * Логика:
 * 1. Проверяем подпись Stripe (защита от подделки)
 * 2. Разбираем событие
 * 3. Если checkout.session.completed → пишем в БД paid + шлём email Марии
 *
 * Идемпотентность: Stripe может прислать одно и то же событие несколько раз
 * (при сбоях). DB::markAsPaid возвращает false если уже paid → email не шлём дважды.
 */

require_once __DIR__ . '/_lib/config.php';
require_once __DIR__ . '/_lib/db.php';
require_once __DIR__ . '/_lib/notifier.php';

// Webhook не для пользователей, всегда возвращаем text/plain
header('Content-Type: text/plain; charset=utf-8');


// ---- В mock-режиме можно вручную дёрнуть webhook GET-запросом
// чтобы протестировать цепочку без реального Stripe.
// /api/webhook.php?mock_session=cs_mock_xxx
if (Config::isMock() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sessionId = $_GET['mock_session'] ?? '';
    if ($sessionId === '') {
        http_response_code(400);
        echo "mock mode: pass ?mock_session=cs_mock_xxx";
        exit;
    }

    $changed = DB::markAsPaid(
        stripeSession: $sessionId,
        customerEmail: $_GET['email']  ?? 'test@example.com',
        customerName:  $_GET['name']   ?? 'Тестовая Покупательница'
    );

    if ($changed) {
        $purchase = DB::findBySession($sessionId);
        if ($purchase) {
            Notifier::notifyMariaOfNewPurchase($purchase);
            DB::markAsNotified($sessionId);
        }
        echo "OK: marked paid and notified (mock)";
    } else {
        echo "OK: was already paid or not found";
    }
    exit;
}


// ---- Реальный webhook от Stripe
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "method not allowed";
    exit;
}

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';


// ---- Проверка подписи (защита от подделки)
if (!Config::isMock()) {
    if (!class_exists(\Stripe\Webhook::class)) {
        error_log('[webhook] Stripe SDK not installed');
        http_response_code(500);
        echo "stripe sdk missing";
        exit;
    }

    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            Config::stripeWebhookSecret()
        );
    } catch (\UnexpectedValueException $e) {
        error_log('[webhook] invalid payload: ' . $e->getMessage());
        http_response_code(400);
        echo "invalid payload";
        exit;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        error_log('[webhook] invalid signature: ' . $e->getMessage());
        http_response_code(400);
        echo "invalid signature";
        exit;
    }
} else {
    // В mock-режиме декодируем JSON напрямую (для локальных тестов)
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        http_response_code(400);
        echo "invalid mock event";
        exit;
    }
    $event = (object) $event;
}


// ---- Обработка события
try {
    $type = is_object($event) && property_exists($event, 'type') ? $event->type : ($event->type ?? '');

    if ($type === 'checkout.session.completed') {
        $session = $event->data->object;

        $sessionId    = $session->id ?? '';
        $customerEmail = $session->customer_details->email ?? $session->customer_email ?? null;
        $customerName  = $session->customer_details->name  ?? null;

        if ($sessionId === '') {
            error_log('[webhook] no session id in event');
            http_response_code(400);
            echo "missing session id";
            exit;
        }

        $changed = DB::markAsPaid(
            stripeSession: $sessionId,
            customerEmail: $customerEmail,
            customerName:  $customerName,
        );

        if ($changed) {
            // Только при первом получении события — шлём email Марии
            $purchase = DB::findBySession($sessionId);
            if ($purchase !== null) {
                $sent = Notifier::notifyMariaOfNewPurchase($purchase);
                if ($sent) {
                    DB::markAsNotified($sessionId);
                }
            }
        }
    }

    // Stripe ждёт 2xx ответ, иначе считает webhook failed и повторит
    http_response_code(200);
    echo "ok";

} catch (\Throwable $e) {
    error_log('[webhook] processing error: ' . $e->getMessage());
    http_response_code(500);
    echo "internal error";
}
