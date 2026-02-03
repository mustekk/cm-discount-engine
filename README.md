# CM Discount Engine

Плагин автоматической системы скидок для WooCommerce. Разработан для магазина кофе **Coffee Madman**.

**Версия:** 1.0.5 (Фаза 1)

---

## Что делает плагин

Автоматически рассчитывает и применяет лучшую скидку в корзине WooCommerce. Скидки **не суммируются** — система всегда выбирает одну, наиболее выгодную для клиента.

### Типы скидок (Фаза 1)

| Тип | Триггер | Ставка по умолчанию | Приоритет (при ничьей) |
|-----|---------|---------------------|----------------------|
| **Первый заказ** | Автоматически — у клиента нет завершённых заказов | −10% | 4 (низший) |
| **За количество** | Автоматически — по числу пачек в корзине | 3+ → −5%, 4+ → −10%, 8+ → −15% | 2 (высший) |
| **Промокод** | Вручную — клиент вводит код в поле купона | Настраивается (% или фикс. €) | 3 (средний) |

Все ставки и ступени полностью настраиваются из админки WooCommerce.

---

## Требования

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

**Платные или сторонние плагины НЕ требуются.** Единственная зависимость — бесплатный WooCommerce.

---

## Установка

### Способ 1: Через админку WordPress

1. Скачать ZIP-архив репозитория (Code → Download ZIP).
2. WP Admin → Plugins → Add New → Upload Plugin → выбрать ZIP.
3. Нажать Install Now → Activate.

### Способ 2: Через FTP

1. Загрузить папку `cm-discount-engine/` в `wp-content/plugins/`.
2. WP Admin → Plugins → активировать **CM Discount Engine**.

---

## Настройка после установки

### 1. Общие настройки

**WP Admin → WooCommerce → Settings → CM Discounts**

| Настройка | Описание | По умолчанию |
|-----------|----------|-------------|
| First Order Discount | Вкл/выкл скидку на первый заказ | Включено |
| First Order Rate (%) | Процент скидки для первого заказа | 10% |
| Quantity Discount | Вкл/выкл ступенчатую скидку за количество | Включено |
| Promo Codes | Вкл/выкл промокоды | Включено |
| Free Shipping Threshold (€) | Порог бесплатной доставки | €30 |

### 2. Ступени скидок за количество

**CM Discounts → Quantity Tiers**

Таблица для добавления/редактирования/удаления ступеней:
- Каждая строка: **Min Packs** (минимум пачек) и **Discount %** (процент скидки).
- Кнопка "+ Add Tier" — добавить новую ступень.
- Ступени по умолчанию: 3+ → 5%, 4+ → 10%, 8+ → 15%.

### 3. Категории для скидок

**CM Discounts → Category Eligibility**

- Отметить категории товаров, которые участвуют в системе скидок.
- Товары из неотмеченных категорий не получают скидку и не учитываются при подсчёте пачек.

### 4. Промокоды

**WP Admin → WooCommerce → Promo Codes → Add Promo Code**

Для каждого промокода:
- **Заголовок** — текст кода (сохраняется в нижнем регистре).
- **Discount Type** — Percentage (%) или Fixed amount (€).
- **Value** — значение скидки.
- **Expiry Date** — дата окончания (пустое = бессрочно).
- **Usage Limit** — максимум использований (0 = без лимита).

---

## Как работает Resolver (выбор лучшей скидки)

Resolver запускается при каждом пересчёте корзины и работает в 3 слоя:

```
Eligibility → Calculation → Selection
(на что право)   (сколько €)   (максимум побеждает)
```

1. **Eligibility** — проверяет, на какие скидки клиент имеет право.
2. **Calculation** — рассчитывает экономию в € для каждой подходящей скидки.
3. **Selection** — выбирает скидку с максимальной экономией. При равенстве побеждает скидка с меньшим номером приоритета.

### Пример

Корзина: 4 пачки на €96, первый заказ, промокод "test15" (15%):

| Скидка | Ставка | Экономия | Результат |
|--------|--------|----------|-----------|
| Первый заказ | 10% | €9.60 | |
| За количество | 10% | €9.60 | |
| **Промокод "test15"** | **15%** | **€14.40** | **Побеждает** |

---

## Что отображается на сайте

### Страница товара
- Зелёный блок **"Volume discount"** под кнопкой "Add to cart" с карточками ступеней.
- Показывается только на товарах из участвующих категорий.

### Корзина
- **Синий блок** — какая скидка применена (например: "First order discount (−10%)").
- **Жёлтый блок** — мотивация к увеличению заказа ("Add 1 more pack for 5% discount — save €X extra!").
- **Подсказка доставки** — "Add €X more for free shipping!".
- **Строка купона** — скидка в итогах корзины с человекопонятным названием.

---

## Что сохраняется в заказе

| Поле | Описание | Пример |
|------|----------|--------|
| `cm_discount_type` | Тип скидки | quantity, first_order, promo |
| `cm_discount_rate` | Ставка (%) | 10 |
| `cm_discount_amount` | Сумма в € | 9.60 |
| `cm_promo_id` | ID промокода | 3096 |

После завершения заказа:
- Клиент помечается как "использовал первый заказ".
- Счётчик использований промокода увеличивается на 1.

---

## Структура файлов

```
cm-discount-engine/
├── cm-discount-engine.php                # Bootstrap, HPOS compat, activation defaults
├── includes/
│   ├── class-cm-promo-codes.php          # CPT cm_promo_code + validate_code()
│   ├── class-cm-discount-types.php       # Eligibility + calculation per type
│   ├── class-cm-discount-resolver.php    # 3-layer orchestrator
│   ├── class-cm-virtual-coupon.php       # WC hooks, virtual coupon, order meta
│   └── class-cm-upsell-engine.php        # Product/cart/shipping hints
├── admin/
│   ├── class-cm-admin-settings.php       # WC Settings → CM Discounts tab
│   └── class-cm-admin-promo-codes.php    # CPT columns, meta boxes, save handlers
├── templates/
│   ├── product-discount-hint.php         # Tier breakdown on product page
│   ├── upsell-message.php                # "Add N more packs" in cart
│   └── cart-discount-notice.php          # Applied discount explanation
└── assets/css/
    └── cm-discount-frontend.css          # Frontend styles
```

---

## Совместимость

- **WooCommerce HPOS** (High-Performance Order Storage) — совместим.
- **Существующие купоны WooCommerce** — `individual_use = false`, не конфликтует с другими купонами.
- **Кеш-плагины** (WP-Optimize и др.) — после обновления CSS нужно очистить кеш минификации.

---

## Лицензия

Проприетарный плагин. Разработан для Coffee Madman.
