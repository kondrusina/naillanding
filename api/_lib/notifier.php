<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Отправка уведомлений Марии о новой покупке.
 * Использует встроенный mail() PHP — для серьёзного объёма позже
 * можно подключить SMTP-сервис (Postmark, Mailgun, SendGrid),
 * но для старта лендинга mail() более чем достаточно.
 */
final class Notifier
{
    public static function notifyMariaOfNewPurchase(array $purchase): bool
    {
        $to = Config::notifyEmail();
        if ($to === '') {
            error_log('[notifier] NOTIFY_EMAIL is empty, skipping notification');
            return false;
        }

        $fromEmail = Config::notifyFromEmail();
        $fromName  = Config::notifyFromName();

        $tier       = (string) ($purchase['tier_name'] ?? '?');
        $amountEur  = (int)    ($purchase['amount_eur'] ?? 0);
        $email      = (string) ($purchase['customer_email'] ?? '—');
        $name       = (string) ($purchase['customer_name'] ?? '—');
        $session    = (string) ($purchase['stripe_session'] ?? '—');
        $paidAt     = (string) ($purchase['paid_at'] ?? '—');
        $mode       = (string) ($purchase['mode'] ?? '?');

        $modeNote = $mode === 'live'
            ? ''
            : "\n⚠️ Режим: {$mode} (это не реальная оплата)\n";

        $subject = "💅 Новая покупка: {$tier} — {$amountEur}€";

        $body = <<<TEXT
Мария, новая покупка курса «Тонко-Крепко».
{$modeNote}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Тариф:           {$tier}
Сумма:           {$amountEur} €

Покупатель:      {$name}
Email:           {$email}

Stripe session:  {$session}
Дата оплаты:     {$paidAt}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━

Что делать:
1. Добавить покупательницу в Telegram-канал
2. Отправить приветственное сообщение

Админка покупок: подключай в браузере /api/admin.php

— автоматически, бот «Тонко-Крепко»
TEXT;

        $headers = [
            'From'         => sprintf('%s <%s>', self::encodeName($fromName), $fromEmail),
            'Reply-To'     => $email !== '—' ? $email : $fromEmail,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer'     => 'TonkoKrepko/1.0',
        ];

        $headerString = '';
        foreach ($headers as $k => $v) {
            $headerString .= "$k: $v\r\n";
        }

        // В mock-режиме просто логируем — реально не отправляем
        if (Config::isMock()) {
            error_log("[notifier:mock] would send to {$to}: {$subject}");
            return true;
        }

        return mail(
            $to,
            self::encodeSubject($subject),
            $body,
            $headerString
        );
    }

    /**
     * Корректное кодирование UTF-8 в Subject для совместимости с почтовыми клиентами.
     */
    private static function encodeSubject(string $subject): string
    {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private static function encodeName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        return $name;
    }
}
