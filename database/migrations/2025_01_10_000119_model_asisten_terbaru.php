<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Model bawaan asisten bantuan diganti (11 Nov 2026): `llama-3.3-70b-versatile` dihentikan Groq untuk paket
 * gratis sejak 16 Agu 2026, dan `gemini-2.5-flash-lite` hanya terbuka bagi akun yang pernah memakainya.
 * Hanya baris yang MASIH memakai nilai bawaan lama yang diganti — pilihan staf yang lain dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pengaturan_asisten_wali ALTER COLUMN model_groq SET DEFAULT 'openai/gpt-oss-120b'");
        DB::statement("ALTER TABLE pengaturan_asisten_wali ALTER COLUMN model_gemini SET DEFAULT 'gemini-3.5-flash-lite'");
        DB::table('pengaturan_asisten_wali')->where('model_groq', 'llama-3.3-70b-versatile')->update(['model_groq' => 'openai/gpt-oss-120b']);
        DB::table('pengaturan_asisten_wali')->where('model_gemini', 'gemini-2.5-flash-lite')->update(['model_gemini' => 'gemini-3.5-flash-lite']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pengaturan_asisten_wali ALTER COLUMN model_groq SET DEFAULT 'llama-3.3-70b-versatile'");
        DB::statement("ALTER TABLE pengaturan_asisten_wali ALTER COLUMN model_gemini SET DEFAULT 'gemini-2.5-flash-lite'");
    }
};
