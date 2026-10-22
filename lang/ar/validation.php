<?php

/*
 * Pesan validasi bahasa Arab untuk portal wali (22 Okt 2026). Aturan yang tak tercantum memakai pesan
 * bawaan berbahasa Inggris.
 */
return [
    'accepted' => 'يجب قبول :attribute.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً مساوياً لـ :date أو بعده.',
    'alpha_num' => 'يجب أن يحتوي :attribute على حروف وأرقام فقط.',
    'array' => 'يجب أن يكون :attribute قائمة.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً مساوياً لـ :date أو قبله.',
    'between' => [
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute نعم أو لا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'date' => ':attribute ليس تاريخاً صحيحاً.',
    'different' => 'يجب أن يكون :attribute و :other مختلفين.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'digits_between' => 'يجب أن يتكون :attribute من :min إلى :max أرقام.',
    'distinct' => 'يحتوي :attribute على قيمة مكررة.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'exists' => ':attribute المختار غير صالح.',
    'file' => 'يجب أن يكون :attribute ملفاً.',
    'gt' => [
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'string' => 'يجب أن يكون طول :attribute أكثر من :value حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عناصر.',
    ],
    'gte' => [
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكثر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أكثر.',
        'array' => 'يجب أن يحتوي :attribute على :value عناصر أو أكثر.',
    ],
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => ':attribute المختار غير صالح.',
    'integer' => 'يجب أن يكون :attribute عدداً صحيحاً.',
    'lt' => [
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عناصر.',
    ],
    'lte' => [
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أقل.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أقل.',
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عناصر.',
    ],
    'max' => [
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
        'string' => 'يجب ألا يزيد طول :attribute عن :max حرفاً.',
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عناصر.',
    ],
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min' => [
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'present' => 'يجب أن يكون :attribute موجوداً.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'string' => 'يجب أن يكون طول :attribute :size حرفاً.',
        'array' => 'يجب أن يحتوي :attribute على :size عناصر.',
    ],
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => ':attribute مستخدم مسبقاً.',
    'uploaded' => 'فشل رفع :attribute.',

    'attributes' => [
        'email' => 'البريد الإلكتروني', 'sandi' => 'كلمة المرور', 'sandi_ulang' => 'تأكيد كلمة المرور', 'kode' => 'الرمز',
        'pin' => 'الرقم السري', 'nominal' => 'المبلغ', 'alasan' => 'السبب', 'surat' => 'الخطاب', 'berkas' => 'الملف',
        'bukti' => 'إيصال التحويل', 'telepon' => 'رقم الهاتف', 'tanggal' => 'التاريخ', 'nama' => 'الاسم',
    ],
];
