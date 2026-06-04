# Тонко-Крепко — лендинг

Продающий лендинг курса Марии Ехаловой для мастеров маникюра.

## Стек

- **Лендинг:** статичный HTML + CSS + JS
- **Бэкенд:** PHP 8.1+ + SQLite
- **Платежи:** Stripe Checkout (карты, Apple Pay, Google Pay, MBWay)
- **Доступ к курсу:** Telegram-канал

## Структура

```
nail-landing/
├── index.html              ← главный лендинг
├── thank-you.html          ← страница после успешной оплаты
├── css/main.css            ← все стили
├── js/main.js              ← таймер, аккордеон, чекаут
├── api/
│   ├── checkout.php        ← создаёт Stripe Checkout Session
│   ├── webhook.php         ← принимает события Stripe
│   ├── admin.php           ← дашборд покупок для Марии
│   └── _lib/               ← config / db / notifier (приватно)
├── data/                   ← SQLite база (приватно)
├── composer.json           ← Stripe SDK
├── .env.example            ← шаблон конфига
├── .htaccess               ← безопасность
└── README.md
```

## Режимы работы (STRIPE_MODE)

В `.env` есть переменная `STRIPE_MODE`, определяющая поведение бэкенда:

- **`mock`** — без реального Stripe. Клик по кнопке тарифа имитирует оплату, ведёт на thank-you, в БД появляется запись. Для разработки и демонстрации Марии до подключения Stripe.
- **`test`** — тестовый режим Stripe (ключи `sk_test_...`). Реальный Stripe Checkout, но платится тестовыми картами (например `4242 4242 4242 4242`).
- **`live`** — продакшен. Реальные деньги.

Переключение режима — только через `.env`, без правок кода.

---

## Локальный запуск

```bash
cd nail-landing/
cp .env.example .env
# .env можно не править — mock-режим работает из коробки

php -S localhost:8000
# открыть http://localhost:8000
```

Для админки: открыть `http://localhost:8000/api/admin.php`, ввести логин `maria` и пароль из `.env`.

---

## Деплой на хостинг (Apache + PHP 8.1+)

1. **Залить файлы** в корень сайта (через FTP/SFTP/git).
2. **Создать `.env`** на основе `.env.example`:
   ```bash
   cp .env.example .env
   # отредактировать .env (ключи Stripe, email, Telegram)
   ```
3. **Установить Stripe SDK** (если есть SSH/Composer):
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   Если Composer недоступен на хостинге — установить локально, потом залить папку `vendor/`.

4. **Права на папку `data/`:**
   ```bash
   chmod 755 data/
   ```
   PHP должен иметь возможность создать SQLite-файл и писать в него.

5. **Проверить, что `.env` и `data/` не отдаются веб-сервером:**
   Открыть в браузере `https://домен/.env` — должно быть 403/404.
   Открыть `https://домен/data/purchases.sqlite` — тоже 403.

   Если что-то открывается — `.htaccess` не подхватился, нужно проверить `AllowOverride` в конфиге Apache.

---

## Подключение Stripe (когда Мария готова)

### 1. Создать Stripe-аккаунт

Зарегистрироваться на stripe.com от имени ENI Марии. Указать португальский ИНН, банковский счёт PT для выплат. Верификация занимает 1-2 дня.

### 2. Создать продукты в Stripe Dashboard

`Products → Add product`. Три продукта:

| Название | Цена (one-time) |
|----------|-----------------|
| Тонко-Крепко · Базовый | 49 EUR |
| Тонко-Крепко · Стандарт | 79 EUR |
| Тонко-Крепко · VIP | 119 EUR |

После создания у каждого продукта будет **Price ID** (формат `price_1OXXX...`). Скопировать в `.env`.

### 3. Получить API-ключи

`Developers → API keys`. Скопировать **Secret key** в `STRIPE_SECRET_KEY`.

Сначала использовать тестовые (`sk_test_...`), убедиться что работает, потом переключиться на live.

### 4. Создать webhook

`Developers → Webhooks → Add endpoint`:

- **URL:** `https://домен/api/webhook.php`
- **Events:** `checkout.session.completed`

После создания скопировать **Signing secret** (формат `whsec_...`) в `STRIPE_WEBHOOK_SECRET`.

### 5. Заполнить `.env`

```
STRIPE_MODE=test
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_BASIC=price_...
STRIPE_PRICE_STANDARD=price_...
STRIPE_PRICE_VIP=price_...
```

### 6. Тестовая покупка

Кликнуть тариф на сайте → попасть на Stripe Checkout → ввести тестовую карту `4242 4242 4242 4242` (любая дата в будущем, любой CVC). После оплаты:

- Редирект на `/thank-you.html`
- В админке (`/api/admin.php`) появилась запись со статусом `paid`
- Марии на email пришло уведомление

### 7. Включить live-режим

Когда тесты прошли — поменять в `.env`:

```
STRIPE_MODE=live
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_... (live webhook)
```

И Price IDs — на live-продукты (Stripe разделяет test и live миры).

---

## Что нужно от Марии

- [x] **ENI открыто** (есть)
- [ ] **Stripe-аккаунт** — открыть от ENI
- [ ] **Stripe ключи + 3 Price ID** — после открытия аккаунта
- [ ] **Email для уведомлений** о покупках (положить в `NOTIFY_EMAIL`)
- [ ] **Telegram invite-ссылка** на закрытый канал курса (`TELEGRAM_INVITE_URL`)
- [ ] **Telegram контакт** Марии для кнопки «остались вопросы»
- [ ] **Домен** — купить, привязать к хостингу, поставить в `SITE_URL`

---

## Админка

`https://домен/api/admin.php` — простой дашборд для Марии:

- Сколько покупок, какая выручка
- Разбивка по тарифам
- Список последних покупок с email и статусом
- Метка «уведомление отправлено» — чтобы видеть, сработал ли email

Защищена HTTP Basic Auth, логин/пароль в `.env`.

---

## Дизайн-система

**Палитра:**

| Токен | Значение | Назначение |
|-------|----------|------------|
| `--c-bg` | `#F5EFE7` | основной фон (молочный кремовый) |
| `--c-ink` | `#1F1814` | основной текст |
| `--c-accent` | `#A51D8B` | акцент (глубокая фуксия) |
| `--c-accent-hot` | `#EF1585` | энергия / hover / пульсы |
| `--c-line` | `#E5D9CC` | разделители |

**Типографика:**

- **Display:** Fraunces (variable serif) — заголовки
- **Body:** Manrope — UI и основной текст

Все цвета и отступы — через CSS-переменные в `:root`.

---

## Что осталось доделать

- [ ] Юридические страницы: оферта, политика конфиденциальности
- [ ] Cookie-баннер для ЕС
- [ ] Реальные данные: домен, ключи, ссылки, фото
- [ ] Финальная проверка Lighthouse + кросс-браузер
- [ ] Деплой
