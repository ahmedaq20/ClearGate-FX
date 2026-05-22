<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'حقل :attribute مطلوب.',
            'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
            'sometimes' => 'حقل :attribute غير صالح.',
            'nullable' => 'حقل :attribute غير صالح.',
            'string' => 'يجب أن يكون حقل :attribute نصاً.',
            'email' => 'يجب أن يكون حقل :attribute بريداً إلكترونياً صالحاً.',
            'max' => 'يجب ألا يزيد حقل :attribute عن :max حرفاً.',
            'min' => 'يجب ألا يقل حقل :attribute عن :min.',
            'numeric' => 'يجب أن يكون حقل :attribute رقماً.',
            'integer' => 'يجب أن يكون حقل :attribute رقماً صحيحاً.',
            'boolean' => 'يجب أن يكون حقل :attribute صحيحاً أو خطأ.',
            'array' => 'يجب أن يكون حقل :attribute مصفوفة.',
            'date' => 'يجب أن يكون حقل :attribute تاريخاً صالحاً.',
            'confirmed' => 'تأكيد حقل :attribute غير مطابق.',
            'unique' => 'قيمة :attribute مستخدمة من قبل.',
            'exists' => 'القيمة المحددة في حقل :attribute غير موجودة.',
            'in' => 'القيمة المحددة في حقل :attribute غير صالحة.',
            'gt' => 'يجب أن يكون حقل :attribute أكبر من :value.',
            'gte' => 'يجب أن يكون حقل :attribute أكبر من أو يساوي :value.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'رمز العملة',
            'country' => 'الدولة',
            'currency_code' => 'رمز العملة',
            'current_password' => 'كلمة المرور الحالية',
            'customer_id' => 'العميل',
            'from_customer_id' => 'العميل المُحوَّل منه',
            'to_customer_id' => 'العميل المُحوَّل إليه',
            'date' => 'التاريخ',
            'email' => 'البريد الإلكتروني',
            'exchange_rate' => 'سعر الصرف',
            'format' => 'صيغة التصدير',
            'initial_balance' => 'الرصيد الابتدائي',
            'is_active' => 'حالة التفعيل',
            'is_read' => 'حالة القراءة',
            'month' => 'الشهر',
            'name' => 'الاسم',
            'name_ar' => 'الاسم العربي',
            'note' => 'الملاحظة',
            'params' => 'المعاملات',
            'password' => 'كلمة المرور',
            'phone' => 'رقم الهاتف',
            'rate' => 'السعر',
            'rate_to_usd' => 'السعر مقابل الدولار',
            'reference_number' => 'رقم المرجع',
            'role' => 'الدور',
            'settings' => 'الإعدادات',
            'symbol' => 'الرمز',
            'transaction_date' => 'تاريخ العملية',
            'type' => 'النوع',
            'user_id' => 'المستخدم',
            'year' => 'السنة',
            'amount' => 'المبلغ',
            'category' => 'التصنيف',
            'commission_rate' => 'قيمة العمولة',
            'commission_sign' => 'إشارة العمولة',
            'commission_type' => 'نوع العمولة',
            'rates' => 'أسعار الصرف',
            'rates.*.code' => 'رمز العملة',
            'rates.*.rate' => 'سعر الصرف',
            'settings.*' => 'قيمة الإعداد',
            'permissions' => 'الصلاحيات',
            'permissions.*' => 'الصلاحية',
        ];
    }
}
