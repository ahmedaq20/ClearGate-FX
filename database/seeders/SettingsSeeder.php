<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'shop_name', 'value' => null, 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'اسم المحل'],
            ['key' => 'shop_phone', 'value' => null, 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'هاتف المحل'],
            ['key' => 'shop_address', 'value' => null, 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'العنوان'],
            ['key' => 'default_currency', 'value' => 'USD', 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'العملة الافتراضية'],
            ['key' => 'timezone', 'value' => 'Asia/Jerusalem', 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'المنطقة الزمنية'],
            ['key' => 'date_format', 'value' => 'DD/MM/YYYY', 'type' => 'string', 'group_name' => 'general', 'is_public' => true, 'description' => 'تنسيق التاريخ'],
            ['key' => 'decimal_places', 'value' => '2', 'type' => 'integer', 'group_name' => 'financial', 'is_public' => false, 'description' => 'عدد الخانات العشرية'],
            ['key' => 'low_balance_alert_usd', 'value' => '0', 'type' => 'integer', 'group_name' => 'financial', 'is_public' => false, 'description' => 'تنبيه عند انخفاض الرصيد'],
            ['key' => 'large_transaction_threshold', 'value' => '10000', 'type' => 'integer', 'group_name' => 'financial', 'is_public' => false, 'description' => 'حد العملية الكبيرة بالدولار'],
            ['key' => 'notify_on_large_transaction', 'value' => '0', 'type' => 'boolean', 'group_name' => 'financial', 'is_public' => false, 'description' => 'تفعيل تنبيه العمليات الكبيرة'],
            ['key' => 'language', 'value' => 'ar', 'type' => 'string', 'group_name' => 'display', 'is_public' => true, 'description' => 'لغة الواجهة'],
            ['key' => 'direction', 'value' => 'rtl', 'type' => 'string', 'group_name' => 'display', 'is_public' => true, 'description' => 'اتجاه النص'],
            ['key' => 'theme', 'value' => 'dark', 'type' => 'string', 'group_name' => 'display', 'is_public' => true, 'description' => 'المظهر'],
            ['key' => 'primary_color', 'value' => '#1a56db', 'type' => 'color', 'group_name' => 'display', 'is_public' => true, 'description' => 'اللون الرئيسي'],
            ['key' => 'items_per_page', 'value' => '20', 'type' => 'integer', 'group_name' => 'display', 'is_public' => true, 'description' => 'عناصر الصفحة'],
            ['key' => 'show_usd_equivalent', 'value' => '1', 'type' => 'boolean', 'group_name' => 'display', 'is_public' => true, 'description' => 'إظهار المعادل بالدولار'],
            ['key' => 'receipt_show_logo', 'value' => '1', 'type' => 'boolean', 'group_name' => 'receipt', 'is_public' => false, 'description' => 'إظهار شعار المحل'],
            ['key' => 'receipt_show_phone', 'value' => '1', 'type' => 'boolean', 'group_name' => 'receipt', 'is_public' => false, 'description' => 'إظهار الهاتف'],
            ['key' => 'receipt_footer_text', 'value' => null, 'type' => 'string', 'group_name' => 'receipt', 'is_public' => false, 'description' => 'نص أسفل الإيصال'],
            ['key' => 'receipt_language', 'value' => 'ar', 'type' => 'string', 'group_name' => 'receipt', 'is_public' => false, 'description' => 'لغة الإيصال'],
            ['key' => 'notifications_enabled', 'value' => '1', 'type' => 'boolean', 'group_name' => 'notifications', 'is_public' => false, 'description' => 'تفعيل الإشعارات داخل النظام'],
            ['key' => 'notification_poll_interval_seconds', 'value' => '30', 'type' => 'integer', 'group_name' => 'notifications', 'is_public' => false, 'description' => 'فاصل تحديث الإشعارات بالثواني'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->firstOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'group_name' => $setting['group_name'],
                    'is_public' => $setting['is_public'],
                    'description' => $setting['description'],
                ]
            );
        }
    }
}
