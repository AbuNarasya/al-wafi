<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tahap "Mudir Umum" mengikuti STRUKTUR ORGANISASI, bukan seluruh yayasan.
 *
 * Dengan scope `yayasan`, tahap ini menawarkan SEMUA pemegang peringkat 2 —
 * termasuk mudir direktorat lain yang tak membawahi pemohonnya sama sekali,
 * dan pemegang peringkat yang kebetulan tak memimpin direktorat mana pun.
 * Akibatnya satu pengajuan tampak "menunggu di" empat orang sekaligus,
 * padahal atasannya hanya satu, dan keempatnya benar-benar bisa menyetujui.
 *
 * `induk` menyaringnya lewat `bagian.kode_induk` (mis. DIV-KEU → DIR-KAU).
 * Tahap Ketua Yayasan sengaja DIBIARKAN `yayasan`: ia memang wewenang puncak,
 * bukan atasan langsung siapa pun.
 *
 * Aman untuk dokumen yang sedang berjalan — yang berubah hanya SIAPA yang
 * berwenang di tahap itu, bukan posisi dokumennya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_steps')
            ->whereIn('kode_flow', ['BAYAR-STD', 'BUDGET-STD'])
            ->where('peringkat', 2)
            ->where('scope', 'yayasan')
            ->update(['scope' => 'induk']);
    }

    public function down(): void
    {
        DB::table('approval_steps')
            ->whereIn('kode_flow', ['BAYAR-STD', 'BUDGET-STD'])
            ->where('peringkat', 2)
            ->where('scope', 'induk')
            ->update(['scope' => 'yayasan']);
    }
};
