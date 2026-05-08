# خطة باك إند Laravel — نظام إدارة محل الصرافة (COL)

> **الإصدار:** 3.0
> **التقنية:** Laravel 12 + MySQL + Redis + Laravel Sanctum + Spatie Permission
> **النمط:** Single App — محل صرافة واحد
> **المطوّر:** Backend API فقط (Frontend منفصل)

---

## الفهرس

1. [نظرة عامة](#1-نظرة-عامة)
2. [هيكل قاعدة البيانات](#2-هيكل-قاعدة-البيانات)
3. [نظام الصلاحيات — Spatie Permission](#3-نظام-الصلاحيات)
4. [الإعدادات العامة](#4-الإعدادات-العامة)
5. [طبقة الـ API](#5-طبقة-الـ-api)
6. [Service Layer](#6-service-layer)
7. [Middleware Stack](#7-middleware-stack)
8. [التقارير والإيصالات](#8-التقارير-والإيصالات)
9. [الإشعارات](#9-الإشعارات-in-app)
10. [الأرشفة و Soft Delete](#10-الأرشفة-و-soft-delete)
11. [Queue Jobs](#11-queue-jobs)
12. [هيكل الملفات](#12-هيكل-الملفات)
13. [خطة التنفيذ](#13-خطة-التنفيذ)

---

## 1. نظرة عامة

### ما هو النظام
نظام إدارة داخلي لمحل صرافة واحد. يعمل كـ API خالص يستهلكه الـ Frontend (React).

### القرارات المعمارية النهائية

| القرار | الخيار |
|--------|--------|
| Laravel Version | **12** |
| Multi-Tenant | ❌ لا — محل واحد فقط |
| Laravel Modules | ❌ لا — `app/` قياسية منظمة بـ namespaces |
| المصادقة | Laravel Sanctum (tokens) |
| الصلاحيات | **spatie/laravel-permission** |
| الأدوار | `owner` (أدمن) + `manager` فقط |
| Response Format | **BaseApiController** موحّد |
| التقارير | PDF + Excel |
| الإشعارات | In-App فقط |
| Soft Delete | ✅ على جميع النماذج الرئيسية |
| Cache | Redis |
| Queue | Laravel Queue + Redis driver |

### المبدأ المحاسبي
```
كل عملية → مبلغ + عملة + سعر صرف → قيمة بالدولار (USD)
استلام  → يزيد الرصيد  → direction = +1
تسليم   → يخفض الرصيد  → direction = -1
usd_value = amount / rate_to_usd
```

### منطق الصندوق والمستخدمين
```
owner (أدمن)
  ├── يضيف مستخدمين (manager / بدون دور محدد)
  ├── يحدد قيمة الصندوق المالي لكل مستخدم
  └── يرى كل شيء في النظام

كل مستخدم
  ├── له صندوق مالي خاص (vault) تحدده الـ owner
  ├── له عملاء خاصون به (customers)
  └── عملاؤه مرتبطون بصندوقه
```

---

## 2. هيكل قاعدة البيانات

> ⚠️ **Soft Delete مفعّل على:** users, customers, vaults, transactions
> السجلات المحذوفة لا تُحذف فعلياً بل تُضاف `deleted_at` — تبقى في التقارير ولا تظهر في القوائم

---

### 2.1 جدول `users` — المستخدمون
```sql
id                BIGINT PK AUTO_INCREMENT
name              VARCHAR(100)
email             VARCHAR(150) UNIQUE
password          VARCHAR(255)
phone             VARCHAR(30)  NULL
initial_balance   DECIMAL(18,4) DEFAULT 0.0000  -- القيمة الابتدائية للصندوق يحددها owner
is_active         BOOLEAN DEFAULT true
last_login_at     TIMESTAMP NULL
created_at
updated_at
deleted_at        TIMESTAMP NULL                 -- Soft Delete

INDEX (is_active)
INDEX (deleted_at)
```
> **ملاحظة:** لا يوجد `role_id` هنا — Spatie تدير الأدوار في جداولها الخاصة

---

### 2.2 جداول Spatie Permission (تُنشأ تلقائياً)
```sql
-- تُنشأ بـ: php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

roles                     -- الأدوار: owner, manager
permissions               -- الصلاحيات: transaction.create, customer.delete...
model_has_roles           -- ربط User بـ Role
model_has_permissions     -- صلاحيات مباشرة على User (اختياري)
role_has_permissions      -- ربط Role بـ Permissions
```

---

### 2.3 جدول `vaults` — الصناديق المالية ⭐
```sql
id                BIGINT PK AUTO_INCREMENT
user_id           BIGINT FK → users UNIQUE      -- كل مستخدم له صندوق واحد فقط
name              VARCHAR(100)                  -- اسم الصندوق (اختياري، مثل: صندوق محمد)
initial_balance   DECIMAL(18,4) DEFAULT 0.0000  -- الرصيد الابتدائي (يحدده owner)
balance_usd       DECIMAL(18,4) DEFAULT 0.0000  -- الرصيد الحالي (يُحدَّث تلقائياً)
currency_code     VARCHAR(10) DEFAULT 'USD'
note              TEXT NULL
is_active         BOOLEAN DEFAULT true
created_at
updated_at
deleted_at        TIMESTAMP NULL                -- Soft Delete

INDEX (user_id)
```

> **منطق الرصيد:**
> `balance_usd = initial_balance + مجموع العمليات الخاصة بالمستخدم`
> عند تعديل `initial_balance` من الـ owner → يُعاد حساب `balance_usd` تلقائياً

---

### 2.4 جدول `customers` — العملاء
```sql
id                BIGINT PK AUTO_INCREMENT
user_id           BIGINT FK → users             -- المستخدم المسؤول (صاحب العميل)
vault_id          BIGINT FK → vaults            -- الصندوق المرتبط بهذا العميل
name              VARCHAR(100)
phone             VARCHAR(30)  NULL
note              TEXT NULL
category          ENUM('regular','vip','agent','company') DEFAULT 'regular'
balance_usd       DECIMAL(18,4) DEFAULT 0.0000  -- رصيد العميل داخل الصندوق
country           VARCHAR(50)  NULL
is_active         BOOLEAN DEFAULT true
created_at
updated_at
deleted_at        TIMESTAMP NULL                -- Soft Delete

INDEX (user_id)
INDEX (vault_id)
INDEX (deleted_at)
```

---

### 2.5 جدول `currencies` — العملات
```sql
id                BIGINT PK AUTO_INCREMENT
code              VARCHAR(10) UNIQUE            -- USD, JOD, TRY, SAR, EGP, AED...
name              VARCHAR(100)
name_ar           VARCHAR(100)
symbol            VARCHAR(10)
rate_to_usd       DECIMAL(18,6)                -- سعر الصرف مقابل الدولار
is_active         BOOLEAN DEFAULT true
created_at
updated_at
```

---

### 2.6 جدول `exchange_rates` — سجل أسعار الصرف
```sql
id                BIGINT PK AUTO_INCREMENT
currency_code     VARCHAR(10) FK → currencies
rate              DECIMAL(18,6)
source            ENUM('manual','import') DEFAULT 'manual'
date              DATE
created_by        BIGINT FK → users NULL
created_at

INDEX (currency_code, date)
```

---

### 2.7 جدول `transactions` — العمليات المالية ⭐
```sql
id                BIGINT PK AUTO_INCREMENT
user_id           BIGINT FK → users            -- من أجرى العملية
vault_id          BIGINT FK → vaults           -- الصندوق المتأثر (دائماً موجود)
customer_id       BIGINT FK → customers NULL   -- مرتبط بعميل أم لا (عملية عامة)
type              ENUM('receive','send')
amount            DECIMAL(18,4)                -- المبلغ الأصلي بالعملة المحددة
currency_code     VARCHAR(10) FK → currencies
exchange_rate     DECIMAL(18,6)               -- السعر وقت العملية (يُحفظ — لا يتغير)
usd_value         DECIMAL(18,4)              -- المبلغ بالدولار = amount / exchange_rate (قبل العمولة)

-- ─── حقول العمولة ───────────────────────────────────────────
commission_type   ENUM('percentage','fixed') NULL  -- نوع العمولة: نسبة مئوية أو مبلغ ثابت
commission_rate   DECIMAL(8,4) NULL               -- قيمة العمولة: نسبة % أو مبلغ بالدولار
commission_sign   TINYINT(1) NULL                 -- +1 عمولة مضافة للرصيد | -1 عمولة مخصومة
commission_usd    DECIMAL(18,4) DEFAULT 0.0000    -- قيمة العمولة بالدولار (محسوبة)
net_usd_value     DECIMAL(18,4)                   -- الصافي بالدولار = usd_value + (commission_usd * commission_sign)
-- ─────────────────────────────────────────────────────────────

direction         TINYINT(1)                  -- +1 استلام | -1 تسليم
note              TEXT NULL
reference_number  VARCHAR(50) NULL
country           VARCHAR(50)  NULL           -- جهة التحويل
transaction_date  DATE                        -- تاريخ العملية (قد يختلف عن created_at)
created_at
updated_at
deleted_at        TIMESTAMP NULL              -- Soft Delete

UNIQUE (reference_number)
INDEX (user_id, transaction_date)
INDEX (vault_id)
INDEX (customer_id)
INDEX (transaction_date)
INDEX (type)
INDEX (deleted_at)
```

> ⚠️ **مهم — Soft Delete والرصيد:**
> عند soft delete لعملية → يجب عكس أثرها على الرصيد أولاً ثم الحذف.
> السجل يبقى في قاعدة البيانات ويظهر في تقارير الأرشيف.

#### منطق حساب العمولة

```
-- مثال 1: عمولة نسبة مئوية مضافة (+2%)
amount = 1000 TRY, rate = 30, usd_value = 33.33
commission_type = 'percentage', commission_rate = 2.00, commission_sign = +1
commission_usd  = 33.33 × 2% = 0.67
net_usd_value   = 33.33 + 0.67 = 34.00   ← هذا ما يُضاف للرصيد

-- مثال 2: عمولة مبلغ ثابت مخصوم (-5 USD)
amount = 1000 TRY, rate = 30, usd_value = 33.33
commission_type = 'fixed', commission_rate = 5.00, commission_sign = -1
commission_usd  = 5.00
net_usd_value   = 33.33 - 5.00 = 28.33   ← هذا ما يُضاف للرصيد

-- مثال 3: بدون عمولة
commission_type = NULL → commission_usd = 0 → net_usd_value = usd_value
```

> **ملاحظة:** الـ `net_usd_value` هو الرقم الذي يتأثر به **رصيد الصندوق والعميل**، وليس `usd_value`.
> `usd_value` يُحفظ كقيمة أصلية للمرجعية والتقارير.

---

### 2.8 جدول `settings` — الإعدادات العامة
```sql
id                BIGINT PK AUTO_INCREMENT
key               VARCHAR(100) UNIQUE
value             TEXT NULL
type              ENUM('string','integer','boolean','json','color')
group_name        VARCHAR(50)             -- general | financial | display | notifications | receipt
is_public         BOOLEAN DEFAULT false   -- يُرسل للـ Frontend بدون auth
description       VARCHAR(255) NULL
created_at
updated_at
```

---

### 2.9 جدول `notifications` — الإشعارات
```sql
id                BIGINT PK AUTO_INCREMENT
user_id           BIGINT FK → users
type              VARCHAR(100)            -- transaction.large, balance.low, report.ready...
title             VARCHAR(200)
body              TEXT NULL
data              JSON NULL               -- { transaction_id: 5, amount: 5000 }
is_read           BOOLEAN DEFAULT false
read_at           TIMESTAMP NULL
created_at
updated_at

INDEX (user_id, is_read)
INDEX (created_at)
```

---

### 2.10 جدول `audit_logs` — سجل التدقيق
```sql
id                BIGINT PK AUTO_INCREMENT
user_id           BIGINT FK → users NULL
action            VARCHAR(100)           -- transaction.created | customer.soft_deleted...
model_type        VARCHAR(50)  NULL
model_id          BIGINT NULL
old_values        JSON NULL
new_values        JSON NULL
ip_address        VARCHAR(45)  NULL
created_at

-- لا يوجد updated_at أو deleted_at — سجل التدقيق لا يُعدَّل أبداً
INDEX (user_id)
INDEX (action)
INDEX (model_type, model_id)
INDEX (created_at)
```

---

### 2.11 جدول `archives` — الأرشيف (للعمليات المؤرشفة يدوياً)
```sql
id                BIGINT PK AUTO_INCREMENT
archivable_type   VARCHAR(50)            -- transaction | customer
archivable_id     BIGINT
archived_by       BIGINT FK → users
reason            TEXT NULL
snapshot          JSON                   -- نسخة كاملة من البيانات وقت الأرشفة
created_at

INDEX (archivable_type, archivable_id)
```

---

### علاقات الجداول

```
users ──(Spatie)──→ roles ──→ permissions
  │
  ├──→ vaults (1:1) ← owner يحدد initial_balance
  │       │
  │       └──→ customers (1:many)
  │                 │
  │                 └──→ transactions
  │
  └──→ transactions ──→ currencies
                    └──→ vaults

settings         (مستقلة — إعدادات عامة)
notifications    ──→ users
audit_logs       ──→ users
archives         (polymorphic)
exchange_rates   ──→ currencies
```

---

## 3. نظام الصلاحيات

### 3.1 التثبيت والإعداد

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```

```php
// config/permission.php — تعديل مهم
'teams' => false,  // لا نحتاج teams

// app/Models/User.php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
    // ...
}
```

---

### 3.2 الأدوار في النظام

| الدور | الاسم | الوصف |
|-------|-------|-------|
| `owner` | مالك / أدمن | صلاحيات كاملة — لا يمكن تقييده — الـ Super Admin |
| `manager` | مدير | يعمل تحت إشراف الـ owner — صلاحيات محددة |

> **قاعدة الـ owner:** يتحقق منها قبل كل شيء — إذا كان المستخدم `owner` يمر مباشرة بدون فحص permissions.

---

### 3.3 قائمة الصلاحيات الكاملة

```
-- العمليات المالية (group: transactions)
transaction.viewAny          -- عرض قائمة العمليات
transaction.view             -- عرض عملية محددة
transaction.create           -- إنشاء عملية
transaction.update           -- تعديل عملية
transaction.delete           -- حذف (soft delete)
transaction.restore          -- استعادة محذوف
transaction.forceDelete      -- حذف نهائي
transaction.export           -- تصدير PDF/Excel

-- العملاء (group: customers)
customer.viewAny
customer.view
customer.create
customer.update
customer.delete              -- soft delete
customer.restore
customer.forceDelete
customer.viewBalance         -- عرض رصيد العميل
customer.viewStatement       -- عرض كشف حساب العميل

-- الصناديق (group: vaults)
vault.viewAny
vault.view
vault.update                 -- manager يعدّل بيانات صندوقه فقط
-- (create/delete للـ owner فقط — يُتحكم بها في الكود)

-- العملات وأسعار الصرف (group: currencies)
currency.viewAny
currency.manage              -- إضافة / تعديل العملات
exchange_rate.update         -- تحديث سعر الصرف

-- التقارير (group: reports)
report.daily                 -- تقرير يومي
report.monthly               -- تقرير شهري
report.export                -- تصدير التقارير
report.viewAll               -- عرض تقارير كل المستخدمين

-- المستخدمون (group: users)
user.viewAny
user.view
user.create                  -- إضافة مستخدم جديد
user.update                  -- تعديل بيانات مستخدم
user.delete                  -- soft delete مستخدم
user.setVaultBalance         -- تحديد قيمة الصندوق لمستخدم

-- الإعدادات (group: settings)
settings.view
settings.manage

-- الأرشيف (group: archive)
archive.view
archive.restore
```

---

### 3.4 توزيع الصلاحيات

| الصلاحية | owner | manager |
|----------|:-----:|:-------:|
| **العمليات** | | |
| transaction.viewAny | ✅ كل المستخدمين | ✅ خاصته فقط |
| transaction.view | ✅ | ✅ |
| transaction.create | ✅ | ✅ |
| transaction.update | ✅ | ✅ |
| transaction.delete | ✅ | ✅ |
| transaction.restore | ✅ | ❌ |
| transaction.forceDelete | ✅ | ❌ |
| transaction.export | ✅ | ✅ |
| **العملاء** | | |
| customer.viewAny | ✅ كل العملاء | ✅ عملاؤه فقط |
| customer.create | ✅ | ✅ |
| customer.update | ✅ | ✅ عملاؤه فقط |
| customer.delete | ✅ | ✅ عملاؤه فقط |
| customer.restore | ✅ | ❌ |
| customer.forceDelete | ✅ | ❌ |
| customer.viewBalance | ✅ | ✅ |
| customer.viewStatement | ✅ | ✅ |
| **الصناديق** | | |
| vault.viewAny | ✅ كل الصناديق | ✅ صندوقه فقط |
| vault.update | ✅ | ✅ صندوقه فقط |
| **العملات** | | |
| currency.manage | ✅ | ✅ |
| exchange_rate.update | ✅ | ✅ |
| **التقارير** | | |
| report.daily | ✅ | ✅ |
| report.monthly | ✅ | ✅ |
| report.export | ✅ | ✅ |
| report.viewAll | ✅ | ❌ |
| **المستخدمون** | | |
| user.viewAny | ✅ | ❌ |
| user.create | ✅ | ❌ |
| user.update | ✅ | ❌ |
| user.delete | ✅ | ❌ |
| user.setVaultBalance | ✅ | ❌ |
| **الإعدادات** | | |
| settings.view | ✅ | ✅ |
| settings.manage | ✅ | ❌ |
| **الأرشيف** | | |
| archive.view | ✅ | ✅ |
| archive.restore | ✅ | ❌ |

---

### 3.5 التطبيق في الكود

```php
// app/Models/User.php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, SoftDeletes;

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function vault(): HasOne
    {
        return $this->hasOne(Vault::class);
    }
}
```

```php
// app/Http/Middleware/EnsureUserActive.php
public function handle(Request $request, Closure $next): Response
{
    if (auth()->check() && !auth()->user()->is_active) {
        return response()->json(['success' => false, 'message' => 'الحساب موقوف'], 403);
    }
    return $next($request);
}
```

```php
// في الـ Controller — الطريقة الموصى بها مع Spatie
public function store(StoreTransactionRequest $request)
{
    // owner يمر دائماً
    if (!auth()->user()->isOwner()) {
        abort_unless(auth()->user()->can('transaction.create'), 403, 'غير مصرح');
    }
    // ...
}

// أو استخدام Policy
public function store(StoreTransactionRequest $request)
{
    $this->authorize('create', Transaction::class);
    // ...
}
```

```php
// RolePermissionSeeder.php
public function run(): void
{
    // إنشاء الصلاحيات
    $permissions = [
        'transaction.viewAny', 'transaction.view', 'transaction.create',
        'transaction.update', 'transaction.delete', 'transaction.restore',
        'transaction.forceDelete', 'transaction.export',
        // ... بقية الصلاحيات
    ];

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
    }

    // دور owner — كل الصلاحيات
    $owner = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'sanctum']);
    $owner->syncPermissions(Permission::all());

    // دور manager — صلاحيات محددة
    $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'sanctum']);
    $manager->syncPermissions([
        'transaction.viewAny', 'transaction.view', 'transaction.create',
        'transaction.update', 'transaction.delete', 'transaction.export',
        'customer.viewAny', 'customer.view', 'customer.create',
        'customer.update', 'customer.delete',
        'customer.viewBalance', 'customer.viewStatement',
        'vault.viewAny', 'vault.view', 'vault.update',
        'currency.viewAny', 'currency.manage', 'exchange_rate.update',
        'report.daily', 'report.monthly', 'report.export',
        'settings.view',
        'archive.view',
    ]);

    // إنشاء owner افتراضي
    $user = User::firstOrCreate(
        ['email' => 'owner@exchange.com'],
        ['name' => 'المالك', 'password' => bcrypt('password'), 'is_active' => true]
    );
    $user->assignRole('owner');
}
```

---

### 3.6 Guard Name مع Sanctum

```php
// config/auth.php — تأكد من وجود guard اسمه sanctum أو استخدم api
'guards' => [
    'api' => [
        'driver' => 'sanctum',
        'provider' => 'users',
    ],
],

// config/permission.php
'defaults' => [
    'guard' => 'sanctum',  // أو 'api'
],
```

---

## 4. الإعدادات العامة

### 4.1 مجموعة `general`

| المفتاح | النوع | الافتراضي | الوصف |
|---------|-------|-----------|-------|
| `shop_name` | string | — | اسم المحل |
| `shop_phone` | string | — | هاتف المحل |
| `shop_address` | string | — | العنوان |
| `default_currency` | string | `USD` | العملة الافتراضية |
| `timezone` | string | `Asia/Jerusalem` | المنطقة الزمنية |
| `date_format` | string | `DD/MM/YYYY` | تنسيق التاريخ |

### 4.2 مجموعة `financial`

| المفتاح | النوع | الافتراضي | الوصف |
|---------|-------|-----------|-------|
| `decimal_places` | integer | `2` | عدد الخانات العشرية |
| `low_balance_alert_usd` | integer | `0` | تنبيه عند انخفاض الرصيد |
| `large_transaction_threshold` | integer | `10000` | حد العملية الكبيرة (USD) |
| `notify_on_large_transaction` | boolean | `false` | تفعيل تنبيه العمليات الكبيرة |

### 4.3 مجموعة `display`

| المفتاح | النوع | الافتراضي | الوصف |
|---------|-------|-----------|-------|
| `language` | string | `ar` | لغة الواجهة |
| `direction` | string | `rtl` | اتجاه النص |
| `theme` | string | `dark` | المظهر |
| `primary_color` | color | `#1a56db` | اللون الرئيسي |
| `items_per_page` | integer | `20` | عناصر الصفحة |
| `show_usd_equivalent` | boolean | `true` | إظهار المعادل بالدولار |

### 4.4 مجموعة `receipt`

| المفتاح | النوع | الافتراضي | الوصف |
|---------|-------|-----------|-------|
| `receipt_show_logo` | boolean | `true` | إظهار شعار المحل |
| `receipt_show_phone` | boolean | `true` | إظهار الهاتف |
| `receipt_footer_text` | string | — | نص أسفل الإيصال |
| `receipt_language` | string | `ar` | لغة الإيصال |

### 4.5 SettingsService

```php
class SettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("settings.{$key}", now()->addHour(), function () use ($key, $default) {
            return Setting::where('key', $key)->value('value') ?? $default;
        });
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("settings.{$key}");
    }

    public function group(string $group): array
    {
        return Setting::where('group_name', $group)->pluck('value', 'key')->toArray();
    }

    public function publicSettings(): array
    {
        return Setting::where('is_public', true)->pluck('value', 'key')->toArray();
    }
}
```

---

## 5. طبقة الـ API

### BaseApiController

```php
// app/Http/Controllers/Api/BaseApiController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

class BaseApiController extends Controller
{
    public function sendResponse($result = null, $message = 'Success', $code = 200): JsonResponse
    {
        $response = ['success' => true, 'message' => $message];

        if ($result) {
            if ($result instanceof JsonResource && $result->resource instanceof AbstractPaginator) {
                $response['data'] = $result;
                $response['meta'] = $this->getPaginationData($result->resource);
            } elseif ($result instanceof AbstractPaginator || $result instanceof AbstractCursorPaginator) {
                $response['data'] = $result->items();
                $response['meta'] = $this->getPaginationData($result);
            } else {
                $response['data'] = $result;
            }
        }

        return response()->json($response, $code);
    }

    public function sendError($error, $errorMessages = [], $code = 404): JsonResponse
    {
        $response = ['success' => false, 'message' => $error];
        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }
        return response()->json($response, $code);
    }

    public function sendValidationError($validator): JsonResponse
    {
        return $this->sendError('Validation Error', $validator->errors(), 422);
    }

    private static function getPaginationData($resource): array
    {
        if ($resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator) {
            return [
                'total'         => method_exists($resource, 'total') ? $resource->total() : null,
                'count'         => $resource->count(),
                'per_page'      => $resource->perPage(),
                'current_page'  => $resource->currentPage(),
                'last_page'     => method_exists($resource, 'lastPage') ? $resource->lastPage() : null,
                'from'          => method_exists($resource, 'firstItem') ? $resource->firstItem() : null,
                'to'            => method_exists($resource, 'lastItem') ? $resource->lastItem() : null,
                'next_page_url' => method_exists($resource, 'nextPageUrl') ? $resource->nextPageUrl() : null,
                'prev_page_url' => method_exists($resource, 'previousPageUrl') ? $resource->previousPageUrl() : null,
                'path'          => method_exists($resource, 'path') ? $resource->path() : null,
            ];
        }
        return [];
    }
}
```

---

### 5.1 Auth
```
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
PUT    /api/v1/auth/change-password
```

### 5.2 Dashboard
```
GET    /api/v1/dashboard/summary
       Response: {
         total_balance_usd,         ← مجموع كل الصناديق (owner فقط)
         my_vault_balance,           ← رصيد صندوق المستخدم الحالي
         today_net_usd,
         customers_count,
         transactions_today_count,
         recent_transactions[],
         top_customers[]
       }

GET    /api/v1/dashboard/chart?period=7d|30d|3m
       Response: { labels[], receive[], send[], net[] }
```

### 5.3 Transactions
```
GET    /api/v1/transactions
       ?date_from= &date_to= &type= &customer_id=
       &currency= &user_id= &vault_id= &country=
       &min_usd= &max_usd= &with_trashed=false
       -- manager: يرى عملياته فقط تلقائياً
       -- owner: يرى الكل، يمكنه تحديد user_id

POST   /api/v1/transactions
       Body: {
         type, amount, currency_code,
         exchange_rate?,        ← اختياري، إذا غائب يُؤخذ من currencies
         customer_id?,
         note?, country?,
         reference_number?,
         transaction_date
       }

GET    /api/v1/transactions/{id}
PUT    /api/v1/transactions/{id}
DELETE /api/v1/transactions/{id}          ← soft delete + عكس الرصيد

-- Restore (owner فقط)
PATCH  /api/v1/transactions/{id}/restore  ← restore + إعادة تطبيق الرصيد
DELETE /api/v1/transactions/{id}/force    ← حذف نهائي (owner فقط)

GET    /api/v1/transactions/daily-summary?date=
POST   /api/v1/transactions/export
       Body: { format: pdf|excel, filters: {...} }
       Response: { job_id }
```

### 5.4 Customers
```
GET    /api/v1/customers
       ?search= &category= &user_id= &is_active= &with_trashed=false
       -- manager: عملاؤه فقط | owner: الكل

POST   /api/v1/customers
       Body: { name, phone?, note?, category?, country?, user_id? }
       -- manager: user_id يُضبط تلقائياً لنفسه

GET    /api/v1/customers/{id}
PUT    /api/v1/customers/{id}
DELETE /api/v1/customers/{id}             ← soft delete

PATCH  /api/v1/customers/{id}/restore     ← owner فقط
DELETE /api/v1/customers/{id}/force       ← owner فقط

GET    /api/v1/customers/{id}/transactions?date_from=&date_to=&type=
GET    /api/v1/customers/{id}/statement?date_from=&date_to=
GET    /api/v1/customers/{id}/balance
```

### 5.5 Vaults
```
GET    /api/v1/vaults
       -- manager: صندوقه فقط | owner: كل الصناديق

GET    /api/v1/vaults/{id}
PUT    /api/v1/vaults/{id}
       Body: { name?, note? }
       -- manager: بيانات صندوقه فقط (لا يعدّل initial_balance)

-- owner فقط:
PATCH  /api/v1/vaults/{id}/set-balance
       Body: { initial_balance: 5000.00 }
       -- يُعيد حساب balance_usd تلقائياً

GET    /api/v1/vaults/{id}/transactions
GET    /api/v1/vaults/{id}/summary
       Response: { initial_balance, total_receive, total_send, balance_usd }
```

### 5.6 Currencies & Rates
```
GET    /api/v1/currencies
PUT    /api/v1/currencies/{code}/rate
       Body: { rate, date? }
POST   /api/v1/exchange-rates/bulk-update
       Body: { rates: [{ code, rate }] }
GET    /api/v1/exchange-rates?currency=&date_from=&date_to=
```

### 5.7 Reports
```
GET    /api/v1/reports/daily?date=&user_id=
GET    /api/v1/reports/monthly?month=&year=&user_id=
GET    /api/v1/reports/users-comparison?date_from=&date_to=   ← owner فقط
GET    /api/v1/reports/customer/{id}/statement?date_from=&date_to=

POST   /api/v1/reports/export
       Body: { type: daily|monthly|statement|comparison, format: pdf|excel, params: {} }
       Response: { job_id }

GET    /api/v1/reports/export/{job_id}/status
       Response: { status: pending|ready|failed, download_url?, expires_at? }
```

### 5.8 Receipts
```
GET    /api/v1/receipts/{transaction_id}
       Response: PDF stream
```

### 5.9 Users (owner فقط)
```
GET    /api/v1/users
POST   /api/v1/users
       Body: { name, email, password, phone?, role: manager, initial_balance? }
       -- ينشئ المستخدم + vault تلقائياً بالـ initial_balance

GET    /api/v1/users/{id}
PUT    /api/v1/users/{id}
DELETE /api/v1/users/{id}                 ← soft delete

PATCH  /api/v1/users/{id}/restore         ← استعادة مستخدم محذوف
PATCH  /api/v1/users/{id}/toggle-active   ← تفعيل/إيقاف

PATCH  /api/v1/users/{id}/vault-balance
       Body: { initial_balance: 5000.00 }
       -- يُحدَّث vault.initial_balance ويُعاد حساب vault.balance_usd
```

### 5.10 Settings
```
GET    /api/v1/settings/public            ← بدون auth — للـ Frontend
GET    /api/v1/settings                   ← كل الإعدادات (owner)
GET    /api/v1/settings/{group}           ← مجموعة محددة
PUT    /api/v1/settings                   ← تحديث (owner)
POST   /api/v1/settings/reset/{group}     ← إعادة ضبط
```

### 5.11 Notifications
```
GET    /api/v1/notifications?is_read=false&type=
GET    /api/v1/notifications/unread-count
PUT    /api/v1/notifications/{id}/read
PUT    /api/v1/notifications/read-all
DELETE /api/v1/notifications/{id}
```

### 5.12 Archive
```
GET    /api/v1/archive?type=transaction|customer&date_from=&date_to=
GET    /api/v1/archive/{id}
POST   /api/v1/archive/{id}/restore       ← owner فقط
```

---

## 6. Service Layer

### TransactionService
```php
// store — إنشاء عملية جديدة
store(array $data, User $user): Transaction
  1. جلب سعر الصرف: $data['exchange_rate'] ?? ExchangeRateService::getRate($currency)
  2. حساب: usd_value = amount / exchange_rate
  3. حساب العمولة:
     إذا commission_type = 'percentage':
       commission_usd = usd_value × (commission_rate / 100)
     إذا commission_type = 'fixed':
       commission_usd = commission_rate  (مبلغ ثابت بالدولار)
     إذا لا عمولة:
       commission_usd = 0
     net_usd_value = usd_value + (commission_usd × commission_sign)
  4. تحديد: direction = type === 'receive' ? +1 : -1
  5. تحديد vault_id من $user->vault->id
  6. DB::transaction():
     a. إنشاء Transaction (يُحفظ usd_value + commission_* + net_usd_value)
     b. تحديث vault.balance_usd += (net_usd_value * direction)   ← الصافي
     c. تحديث customer.balance_usd إن وجد customer_id            ← الصافي
  7. AuditLog::record(...)
  8. event(new TransactionCreated($transaction))

// delete — soft delete مع عكس الرصيد
softDelete(Transaction $tx, User $user): void
  DB::transaction():
    1. عكس الأثر: vault.balance_usd -= (tx.usd_value * tx.direction)
    2. عكس الأثر على العميل إن وجد
    3. $tx->delete() ← soft delete
    4. AuditLog::record(...)

// restore — استعادة مع إعادة تطبيق الرصيد
restore(int $id): Transaction
  DB::transaction():
    1. $tx = Transaction::withTrashed()->findOrFail($id)
    2. $tx->restore()
    3. إعادة تطبيق الرصيد على vault وcustomer
    4. AuditLog::record(...)
```

### BalanceService
```php
getVaultBalance(int $vaultId): array
  → { initial_balance, total_receive, total_send, balance_usd }

getCustomerBalance(int $customerId): float
  → customer.balance_usd

getDailyNet(int $userId, string $date): array
  → { receive, send, net, count }
  -- يشمل فقط العمليات غير المحذوفة (بدون withTrashed)

getMonthlySummary(int $year, int $month, ?int $userId = null): array
  → إذا userId = null (owner) → كل المستخدمين
  → إذا userId محدد → مستخدم واحد
```

### ExchangeRateService
```php
getRate(string $currencyCode): float
  → Cache::remember("rate.{$currencyCode}", 3600, fn() => Currency::find($code)->rate_to_usd)

updateRate(string $code, float $rate, int $userId): void
  → DB::transaction():
      currencies.rate_to_usd = $rate
      ExchangeRate::create([...])
      Cache::forget("rate.{$code}")
      event(new ExchangeRateUpdated(...))

calculateUsdValue(float $amount, float $rate): float
  → round($amount / $rate, 4)
```

### VaultService
```php
// يُستدعى عند تغيير initial_balance من الـ owner
recalculateBalance(Vault $vault): void
  $transactionsNet = Transaction::where('vault_id', $vault->id)
                                ->sum(DB::raw('usd_value * direction'))
  $vault->update(['balance_usd' => $vault->initial_balance + $transactionsNet])

// يُستدعى عند إنشاء مستخدم جديد
createForUser(User $user, float $initialBalance = 0): Vault
```

### NotificationService
```php
send(int|array $userIds, string $type, string $title, string $body, array $data = []): void
  → ينشئ سجلات في notifications لكل user

markAsRead(int $notificationId, int $userId): void
markAllAsRead(int $userId): void

getUnreadCount(int $userId): int
  → Cache::remember("notif.unread.{$userId}", 300, fn() => ...)
  → يُمسح الـ cache عند كل إشعار جديد أو قراءة
```

---

## 7. Middleware Stack

```
API Request
    ↓
[1] throttle:60,1              → 60 طلب/دقيقة
    ↓
[2] auth:sanctum               → التحقق من التوكن
    ↓
[3] EnsureUserActive           → is_active = true؟
    ↓
[4] role:owner|manager         → Spatie middleware (على routes محددة)
    ↓
[5] permission:transaction.create → Spatie middleware (على actions محددة)
    ↓
[6] LogApiRequest              → تسجيل في AuditLog
    ↓
Controller → BaseApiController
```

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // متاح للجميع (owner + manager)
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('customers', CustomerController::class);

    // owner فقط
    Route::middleware('role:owner')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::patch('users/{user}/vault-balance', [UserController::class, 'setVaultBalance']);
        Route::apiResource('settings', SettingController::class);
        Route::patch('transactions/{id}/restore', [TransactionController::class, 'restore']);
        Route::delete('transactions/{id}/force', [TransactionController::class, 'forceDelete']);
    });
});
```

---

## 8. التقارير والإيصالات

### 8.1 أنواع المخرجات

| النوع | PDF | Excel |
|-------|:---:|:-----:|
| إيصال عملية | ✅ | ❌ |
| ملخص يومي | ✅ | ✅ |
| تقرير شهري | ✅ | ✅ |
| كشف حساب عميل | ✅ | ✅ |
| مقارنة المستخدمين | ✅ | ✅ |
| قائمة العمليات | ❌ | ✅ |

### 8.2 المكتبات
```bash
composer require barryvdh/laravel-dompdf    # PDF — دعم RTL + عربي
composer require maatwebsite/laravel-excel  # Excel
```

### 8.3 آلية التصدير (Queue)
```
POST /reports/export → { job_id }
         ↓
  GenerateReportJob (Queue)
         ↓
  الملف في storage/exports/{job_id}.pdf
         ↓
  Notification → "تقريرك جاهز"
         ↓
GET /reports/export/{job_id}/status
    → { status: ready, download_url, expires_at: +72hr }
```

### 8.4 محتوى الإيصال
```
┌─────────────────────────────────┐
│        [شعار المحل]             │
│      اسم المحل — الهاتف         │
├─────────────────────────────────┤
│  رقم الإيصال: TXN-2026-001234   │
│  التاريخ: 01/05/2026            │
│  المستخدم: محمد                 │
├─────────────────────────────────┤
│  العميل: أحمد محمود             │
│  نوع العملية: استلام            │
│  المبلغ: 1,500.00 TRY           │
│  سعر الصرف: 32.50               │
│  القيمة بالدولار: $46.15        │
│  ملاحظة: تحويل من تركيا         │
├─────────────────────────────────┤
│     شكراً لتعاملكم معنا         │
└─────────────────────────────────┘
```

---

## 9. الإشعارات (In-App)

### 9.1 أنواع الإشعارات

| النوع | المشغّل | المستلم |
|-------|---------|---------|
| `transaction.large` | عملية تتجاوز الحد المحدد | owner |
| `balance.low` | رصيد صندوق تحت الحد | owner |
| `report.ready` | تقرير جاهز للتحميل | من طلبه |
| `customer.deleted` | حذف عميل | owner |
| `user.created` | مستخدم جديد أُضيف | owner |
| `rate.updated` | تحديث سعر صرف | owner + manager |
| `transaction.restored` | استعادة عملية محذوفة | owner |

### 9.2 التطبيق
```php
// app/Listeners/CheckLargeTransactionListener.php
public function handle(TransactionCreated $event): void
{
    $threshold = app(SettingsService::class)->get('large_transaction_threshold', 10000);

    if ($event->transaction->usd_value >= $threshold) {
        $owner = User::role('owner')->first();
        app(NotificationService::class)->send(
            userIds: $owner->id,
            type: 'transaction.large',
            title: 'عملية كبيرة',
            body: "عملية بقيمة \${$event->transaction->usd_value} تمت",
            data: ['transaction_id' => $event->transaction->id]
        );
    }
}
```

### 9.3 Polling في الـ Frontend
```
كل 30 ثانية: GET /api/v1/notifications/unread-count
عند الضغط على الجرس: GET /api/v1/notifications
```

---

## 10. الأرشفة و Soft Delete

### 10.1 ما الفرق؟

| الإجراء | الآلية | من يستطيع | التأثير على الرصيد |
|---------|--------|-----------|-------------------|
| **Soft Delete** | `deleted_at` يُضبط | owner + manager | يُعكس الرصيد فوراً |
| **Restore** | `deleted_at = null` | owner فقط | يُعاد تطبيق الرصيد |
| **Force Delete** | حذف نهائي | owner فقط | لا شيء (الرصيد عُكس عند soft delete) |
| **Archive** | `archives` جدول + snapshot | owner + manager | لا تأثير (للحفظ فقط) |

### 10.2 Soft Delete على النماذج

```php
// يجب إضافة SoftDeletes Trait + deleted_at migration لـ:
// User, Customer, Vault, Transaction

class Transaction extends Model
{
    use SoftDeletes;

    // عند الاستعلام الافتراضي → لا يُظهر المحذوفين
    // Transaction::all() → بدون محذوفين
    // Transaction::withTrashed()->get() → مع المحذوفين
    // Transaction::onlyTrashed()->get() → المحذوفون فقط
}
```

### 10.3 قواعد الأرشفة

```
حذف عميل (soft delete):
  → customer.deleted_at = now()
  → تبقى عملياته موجودة في قاعدة البيانات
  → لا يظهر في قوائم العملاء الافتراضية
  → رصيده يُبقى في vault (لأن العمليات ما زالت موجودة)
  → يظهر في تقارير الأرشيف (withTrashed)
  → snapshot يُحفظ في archives

استعادة عميل:
  → customer.deleted_at = null
  → يعود للقوائم العادية
  → تسجيل في AuditLog

حذف مستخدم (soft delete):
  → user.deleted_at = now()
  → لا يستطيع تسجيل الدخول
  → صندوقه وعملاؤه وعملياته تبقى
  → لا يظهر في قوائم المستخدمين
```

---

## 11. Queue Jobs

| الـ Job | التوقيت | الوظيفة |
|---------|---------|---------|
| `GenerateReportJob` | عند الطلب | إنشاء PDF أو Excel في الخلفية |
| `SendDailySummaryNotificationJob` | يومياً 18:00 | إشعار ملخص اليوم للـ owner |
| `CleanOldExportFilesJob` | يومياً 02:00 | حذف ملفات التصدير القديمة (+72 ساعة) |
| `CleanReadNotificationsJob` | أسبوعياً | حذف إشعارات مقروءة (+30 يوم) |

```php
// app/Console/Kernel.php — أو routes/console.php في Laravel 12
Schedule::job(new SendDailySummaryNotificationJob)->dailyAt('18:00');
Schedule::job(new CleanOldExportFilesJob)->dailyAt('02:00');
Schedule::job(new CleanReadNotificationsJob)->weekly();
```

---

## 12. هيكل الملفات

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   ├── BaseApiController.php          ← الـ Base المشترك
│   │   │   └── V1/
│   │   │       ├── AuthController.php
│   │   │       ├── DashboardController.php
│   │   │       ├── TransactionController.php
│   │   │       ├── CustomerController.php
│   │   │       ├── VaultController.php
│   │   │       ├── CurrencyController.php
│   │   │       ├── ExchangeRateController.php
│   │   │       ├── ReportController.php
│   │   │       ├── ReceiptController.php
│   │   │       ├── UserController.php
│   │   │       ├── SettingController.php
│   │   │       ├── NotificationController.php
│   │   │       └── ArchiveController.php
│   │
│   ├── Middleware/
│   │   ├── EnsureUserActive.php
│   │   └── LogApiRequest.php
│   │   -- CheckPermission و role → Spatie middleware مباشرة
│   │
│   └── Requests/
│       ├── Auth/
│       │   ├── LoginRequest.php
│       │   └── ChangePasswordRequest.php
│       ├── Transaction/
│       │   ├── StoreTransactionRequest.php
│       │   └── UpdateTransactionRequest.php
│       ├── Customer/
│       │   ├── StoreCustomerRequest.php
│       │   └── UpdateCustomerRequest.php
│       ├── User/
│       │   ├── StoreUserRequest.php
│       │   └── SetVaultBalanceRequest.php
│       ├── Report/
│       │   └── ExportReportRequest.php
│       └── Setting/
│           └── UpdateSettingsRequest.php
│
├── Models/
│   ├── User.php              ← HasRoles (Spatie) + SoftDeletes
│   ├── Customer.php          ← SoftDeletes
│   ├── Vault.php             ← SoftDeletes
│   ├── Currency.php
│   ├── ExchangeRate.php
│   ├── Transaction.php       ← SoftDeletes ⚠️ عكس الرصيد عند الحذف
│   ├── Setting.php
│   ├── Notification.php
│   ├── AuditLog.php          ← بدون SoftDeletes — لا تُحذف أبداً
│   └── Archive.php
│
├── Services/
│   ├── TransactionService.php
│   ├── BalanceService.php
│   ├── VaultService.php
│   ├── ExchangeRateService.php
│   ├── ReportService.php
│   ├── PdfService.php
│   ├── ExcelService.php
│   ├── NotificationService.php
│   ├── ArchiveService.php
│   └── SettingsService.php
│
├── Events/
│   ├── TransactionCreated.php
│   ├── TransactionDeleted.php
│   ├── TransactionRestored.php
│   └── ExchangeRateUpdated.php
│
├── Listeners/
│   ├── CheckLargeTransactionListener.php
│   ├── LogTransactionAuditListener.php
│   └── NotifyOnRateUpdatedListener.php
│
├── Jobs/
│   ├── GenerateReportJob.php
│   ├── SendDailySummaryNotificationJob.php
│   ├── CleanOldExportFilesJob.php          ← يحذف ملفات +72 ساعة
│   └── CleanReadNotificationsJob.php
│
├── Observers/
│   ├── TransactionObserver.php             ← يراقب deleting/restoring لعكس الرصيد
│   └── UserObserver.php                    ← ينشئ vault تلقائياً عند إنشاء مستخدم
│
└── Policies/
    ├── TransactionPolicy.php
    ├── CustomerPolicy.php
    └── VaultPolicy.php

database/
├── migrations/
│   ├── 0001_create_users_table.php                    ← + deleted_at
│   ├── 0002_spatie_permission_tables.php              ← تُنشأ تلقائياً
│   ├── 0003_create_vaults_table.php                   ← + deleted_at
│   ├── 0004_create_customers_table.php                ← + deleted_at
│   ├── 0005_create_currencies_table.php
│   ├── 0006_create_exchange_rates_table.php
│   ├── 0007_create_transactions_table.php             ← + deleted_at ⚠️
│   ├── 0008_create_settings_table.php
│   ├── 0009_create_notifications_table.php
│   ├── 0010_create_audit_logs_table.php               ← بدون deleted_at
│   └── 0011_create_archives_table.php
│
└── seeders/
    ├── DatabaseSeeder.php
    ├── RolePermissionSeeder.php    ← owner + manager + كل الصلاحيات + owner افتراضي
    ├── CurrencySeeder.php          ← USD, JOD, TRY, SAR, EGP, AED, GBP, EUR
    ├── SettingsSeeder.php          ← إعدادات افتراضية لكل المجموعات
    └── DemoDataSeeder.php          ← بيانات تجريبية (dev فقط)

routes/
└── api.php

resources/
└── views/
    └── pdf/
        ├── receipt.blade.php
        ├── statement.blade.php
        ├── daily-report.blade.php
        └── monthly-report.blade.php

storage/
└── exports/                        ← ملفات PDF/Excel المولّدة (تُحذف بعد 72 ساعة)
```

---

## 13. خطة التنفيذ

### المرحلة 1 — الأساس (الأسبوع 1)
- [ ] `composer create-project laravel/laravel:^12 col-api`
- [ ] تثبيت: `laravel/sanctum` + `spatie/laravel-permission`
- [ ] إعداد Redis للـ Cache والـ Queue
- [ ] كتابة جميع الـ Migrations (11 migration)
- [ ] كتابة الـ Models مع العلاقات + SoftDeletes
- [ ] إنشاء `BaseApiController`
- [ ] `RolePermissionSeeder` + `CurrencySeeder` + `SettingsSeeder`
- [ ] `AuthController` (login, logout, me, change-password)
- [ ] `EnsureUserActive` Middleware
- [ ] `UserObserver` — ينشئ vault تلقائياً عند إضافة مستخدم

### المرحلة 2 — العمليات المالية (الأسبوع 2)
- [ ] `VaultService` (createForUser, recalculateBalance)
- [ ] `ExchangeRateService` + `CurrencyController`
- [ ] `TransactionService` (store, softDelete, restore) مع DB::transaction
- [ ] `TransactionObserver` (يراقب deleting/restoring لعكس الرصيد)
- [ ] `BalanceService`
- [ ] `TransactionController` مع الفلاتر المتقدمة
- [ ] `CustomerController` + `VaultController`
- [ ] `DashboardController`
- [ ] `UserController` (owner فقط) + set vault balance

### المرحلة 3 — التقارير والإيصالات (الأسبوع 3)
- [ ] تثبيت `barryvdh/laravel-dompdf` + `maatwebsite/laravel-excel`
- [ ] Blade templates للـ PDF (RTL + عربي)
- [ ] `PdfService` + `ExcelService`
- [ ] `ReportService` (daily, monthly, statement, comparison)
- [ ] `ReportController` + `ReceiptController`
- [ ] `GenerateReportJob` (Queue)

### المرحلة 4 — الإشعارات والأرشفة (الأسبوع 4)
- [ ] `NotificationService` + `NotificationController`
- [ ] Events + Listeners
- [ ] `ArchiveService` + `ArchiveController`
- [ ] `LogApiRequest` Middleware → AuditLog
- [ ] Scheduled Jobs (CleanOldExportFiles +72h, CleanReadNotifications)

### المرحلة 5 — الإعدادات والتشطيب (الأسبوع 5)
- [ ] `SettingsService` + `SettingController`
- [ ] Policies (TransactionPolicy, CustomerPolicy, VaultPolicy)
- [ ] Rate Limiting
- [ ] Feature Tests للـ Endpoints الأساسية
- [ ] Postman Collection أو Scribe للـ Documentation

---

### الأولويات الحقيقية
```
🔴 أساسي (لا يعمل النظام بدونه)
   Auth → Spatie Roles/Permissions → Vaults → Transactions → Customers → Dashboard

🟡 مهم (يكتمل النظام فيه)
   Reports PDF/Excel → Receipts → Exchange Rates → Soft Delete/Restore

🟢 تحسين (يُضاف في المرحلة الأخيرة)
   Notifications → Archive → Audit Logs → Settings → Tests
```

---

### Packages Summary
```bash
# مطلوبة
composer require laravel/sanctum
composer require spatie/laravel-permission
composer require barryvdh/laravel-dompdf
composer require maatwebsite/laravel-excel

# اختيارية لكن مفيدة
composer require knuckleswtf/scribe          # API Documentation تلقائي
composer require spatie/laravel-activitylog  # بديل جاهز لـ AuditLog (اختياري)
```

---

*آخر تحديث: 2026 — v3.0 — Laravel 12 + Spatie Permission + Soft Delete*
