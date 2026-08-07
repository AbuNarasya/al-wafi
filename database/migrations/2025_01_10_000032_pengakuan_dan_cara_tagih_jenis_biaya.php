<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENGAKUAN DIPILIH, BUKAN DISIMPULKAN.
 *
 * Sampai hari ini sifat akrual sebuah biaya ditebak dari satu hal yang tak
 * pernah dimaksudkan untuk itu: terisinya `kode_coa_piutang`. Akibatnya petugas
 * yang mengisi akun piutang "supaya lengkap" diam-diam mengubah kapan pendapatan
 * diakui — dan tak ada satu pun layar yang menyebutkan itu terjadi.
 *
 * Sesudah ini keduanya berdiri sendiri: `pengakuan` menyatakan maksudnya, akun
 * piutang sekadar menyediakan alamat bukunya. Karena bisa berselisih, layar yang
 * mengisinya wajib menuntut akun piutang saat pengakuannya akrual.
 *
 * ISI MUNDURNYA memakai aturan lama persis — akrual bila akun piutang terisi —
 * sehingga tak ada satu baris pun yang berubah perilaku karena migrasi ini.
 *
 * BAWAAN `kas`, bukan `akrual`. Baris yang lahir tanpa menyebut pengakuannya
 * (mis. lewat seeder atau fixture) lebih baik tidak menjurnal apa pun daripada
 * diam-diam menaruh piutang di buku besar: yang pertama kelihatan sebagai
 * pendapatan yang belum diakui, yang kedua sebagai neraca yang salah.
 *
 * `cara_tagih` sengaja NULLABLE dan tanpa bawaan. Ia hanya bermakna bagi
 * perilaku `lain`, dan tiga baris "Tagihan Lainnya" yang ada sekarang memang
 * belum bisa dijawab — rancangan v2 menuntut ketiganya dipecah dulu per layanan.
 * NULL di sini berarti "belum ditentukan", bukan "tidak keduanya".
 */
return new class extends Migration
{
    private const PENGAKUAN = ['akrual', 'kas'];

    private const CARA_TAGIH = ['pemakaian', 'kepesertaan'];

    public function up(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->string('pengakuan')->default('kas');
            $table->string('cara_tagih')->nullable();
        });

        // Aturan implisit yang berlaku sebelum migrasi ini, ditulis apa adanya.
        DB::table('jenis_biaya')->whereNotNull('kode_coa_piutang')->update(['pengakuan' => 'akrual']);
        DB::table('jenis_biaya')->whereNull('kode_coa_piutang')->update(['pengakuan' => 'kas']);

        $this->pasangCek('pengakuan', self::PENGAKUAN, bolehNull: false);
        $this->pasangCek('cara_tagih', self::CARA_TAGIH, bolehNull: true);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_pengakuan_check');
        DB::statement('ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_cara_tagih_check');

        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->dropColumn(['pengakuan', 'cara_tagih']);
        });
    }

    /** Enum Laravel di PostgreSQL = varchar + CHECK; dipasang manual agar namanya pasti. */
    private function pasangCek(string $kolom, array $nilai, bool $bolehNull): void
    {
        $daftar = implode(',', array_map(fn ($v) => "'{$v}'", $nilai));
        $syarat = $bolehNull ? "{$kolom} IS NULL OR {$kolom} IN ({$daftar})" : "{$kolom} IN ({$daftar})";

        DB::statement("ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_{$kolom}_check");
        DB::statement("ALTER TABLE jenis_biaya ADD CONSTRAINT jenis_biaya_{$kolom}_check CHECK ({$syarat})");
    }
};
