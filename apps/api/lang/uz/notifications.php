<?php

// Telegram / in-app texts (Uzbek, Latin). Placeholders are :name.
return [
    'daily_report_title' => 'BUGUNGI HISOBOT — :date',
    'branch' => 'Filial: :name',
    'sessions' => 'Sessiyalar: :count',
    'play_time' => "Umumiy o'yin vaqti: :time",
    'amount' => 'Summa: :amount',
    'unpaid' => "To'lanmagan: :count (:amount)",
    'table_line' => ':table: :time · :amount',
    'no_sessions' => "Bugun sessiyalar bo'lmadi.",
    'device_offline' => '⚠️ Qurilma aloqada emas: :device (:table, :branch)',
    'device_online' => '✅ Qurilma qayta ulandi: :device (:table, :branch)',
    'session_failed' => '❗ Sessiya boshlanmadi: :table (:branch). Sabab: :reason',
    'subscription_expiring' => 'Obuna muddati :days kundan keyin tugaydi (:date).',
    'subscription_expires_today' => 'Obuna muddati bugun tugaydi.',
    'subscription_expired' => "Obuna muddati tugadi. Ma'lumotlaringiz saqlanadi. To'lov uchun platforma administratori bilan bog'laning.",
    'linked' => 'Ushbu chat ":tenant" hisobotlari uchun ulandi.',
    'link_invalid' => "Kod noto'g'ri yoki muddati tugagan. Admin panelida yangi kod oling.",
    'not_linked' => 'Bu chat ulanmagan. Admin panelida kod oling va /start KOD yuboring.',
    'help' => "Buyruqlar:\n/report — bugungi hisobot\n/help — yordam",
    'test' => 'Test xabar: Telegram integratsiyasi ishlayapti ✅',
    'reason' => [
        'DEVICE_NO_ACK' => 'stol qurilmasi javob bermadi',
        'DEVICE_ERROR' => 'stol qurilmasida xato',
    ],
];
