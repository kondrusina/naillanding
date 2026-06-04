<?php
declare(strict_types=1);

/**
 * Загрузчик конфигурации.
 * Читает .env (если есть) и предоставляет доступ к настройкам.
 * Все PHP-файлы бэка должны начинаться с require этого файла.
 */

// Корень проекта (на уровень выше /api)
define('PROJECT_ROOT', dirname(__DIR__, 2));


/**
 * Простой .env парсер без зависимостей.
 * (vlucas/phpdotenv будет добавлен через composer, но пока для mock-режима
 * хватит этого — чтобы можно было запускать без composer install.)
 */
function load_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Снимаем кавычки, если они есть
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}


/**
 * Достать значение из ENV с фоллбэком.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
}


// Загружаем .env
load_env(PROJECT_ROOT . '/.env');


/**
 * Конфигурация приложения — структурированный доступ.
 */
final class Config
{
    public static function mode(): string
    {
        return env('STRIPE_MODE', 'mock');
    }

    public static function isMock(): bool
    {
        return self::mode() === 'mock';
    }

    public static function stripeSecretKey(): string
    {
        return (string) env('STRIPE_SECRET_KEY', '');
    }

    public static function stripeWebhookSecret(): string
    {
        return (string) env('STRIPE_WEBHOOK_SECRET', '');
    }

    /**
     * Маппинг tier → Stripe Price ID + локальная цена/название для логов.
     */
    public static function tiers(): array
    {
        return [
            'basic' => [
                'name'     => 'Базовый',
                'price_eur' => 49,
                'stripe_price' => env('STRIPE_PRICE_BASIC', 'price_mock_basic'),
            ],
            'standard' => [
                'name'     => 'Стандарт',
                'price_eur' => 79,
                'stripe_price' => env('STRIPE_PRICE_STANDARD', 'price_mock_standard'),
            ],
            'vip' => [
                'name'     => 'VIP',
                'price_eur' => 119,
                'stripe_price' => env('STRIPE_PRICE_VIP', 'price_mock_vip'),
            ],
        ];
    }

    public static function tier(string $key): ?array
    {
        return self::tiers()[$key] ?? null;
    }

    public static function siteUrl(): string
    {
        return rtrim((string) env('SITE_URL', ''), '/');
    }

    public static function notifyEmail(): string
    {
        return (string) env('NOTIFY_EMAIL', '');
    }

    public static function notifyFromEmail(): string
    {
        return (string) env('NOTIFY_FROM_EMAIL', 'noreply@localhost');
    }

    public static function notifyFromName(): string
    {
        return (string) env('NOTIFY_FROM_NAME', 'Тонко-Крепко');
    }

    public static function telegramInviteUrl(): string
    {
        return (string) env('TELEGRAM_INVITE_URL', '');
    }

    public static function dbPath(): string
    {
        $path = (string) env('DB_PATH', 'data/purchases.sqlite');
        return PROJECT_ROOT . '/' . $path;
    }

    public static function adminUser(): string
    {
        return (string) env('ADMIN_USER', 'admin');
    }

    public static function adminPassword(): string
    {
        return (string) env('ADMIN_PASSWORD', '');
    }
}
