<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL WALI — fondasi skema. Belum ada layar apa pun; hanya tempatnya.
 *
 * IDENTITAS WALI = (id_wali, peran). Tak ada tabel akun: orang yang masuk
 * dicari dari `email_ayah` / `email_ibu` / `email_wali`. Tabel akun berarti
 * email tersimpan dua kali, dan tiap suntingan email oleh staf harus ikut
 * memperbarui salinannya — yang terlupa mengunci wali tanpa sebab yang
 * terlihat. Tiga indeks `lower(trim(...))` menjaga pencariannya tetap murah.
 *
 * OTP per EMAIL, bukan per wali: pendaftar baru belum punya baris wali sama
 * sekali. Karena itu `wali.otp_hash` & `otp_expires` dibuang — satu kode per
 * keluarga tak cukup untuk ayah, ibu, dan wali yang masuk sendiri-sendiri,
 * dan tak pernah dipakai (0 baris terisi di produksi saat migrasi ini ditulis).
 *
 * PENCATAT TRANSAKSI: setoran & top-up dompet kini bisa berasal dari wali.
 * `dicatat_oleh` (staf) jadi nullable, dan CHECK `num_nonnulls(...) = 1`
 * menjamin TEPAT SATU pencatat — transaksi tanpa jejak pelaku tak bisa
 * tersimpan, dari pintu mana pun. Jurnal tak berubah: transaksi dari wali
 * tetap `menunggu_verifikasi`, jurnalnya terbit saat tim keuangan memverifikasi.
 *
 * SURAT PERNYATAAN: teksnya ditulis staf sekali di Setting (`term_template`),
 * lalu disetujui wali sendiri saat mendaftar — tak ada staf yang menyusunnya
 * per santri, jadi `persetujuan_term.disusun_oleh` jadi nullable.
 */
return new class extends Migration
{
    private const PERAN = ['ayah', 'ibu', 'wali'];

    /** Tabel transaksi yang kini bisa dicatat oleh wali. */
    private const BERPENCATAT = ['pembayaran_santri', 'mutasi_dompet'];

    public function up(): void
    {
        foreach (self::PERAN as $p) {
            DB::statement("CREATE INDEX wali_email_{$p}_lower ON wali (lower(trim(email_{$p})))");
        }

        Schema::table('wali', fn (Blueprint $t) => $t->dropColumn(['otp_hash', 'otp_expires']));

        Schema::create('otp_wali', function (Blueprint $table) {
            $table->increments('id');
            // Disimpan sudah lower(trim()) — dicocokkan apa adanya.
            $table->string('email');
            $table->enum('tujuan', ['masuk', 'daftar', 'ttd']);
            $table->string('kode_hash');
            $table->timestamp('kedaluwarsa_pada');
            $table->smallInteger('percobaan')->default(0);
            $table->timestamp('dipakai_pada')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['email', 'tujuan']);
        });

        Schema::create('perangkat_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->string('token_hash', 64)->unique();
            $table->string('nama_perangkat')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_terakhir', 45)->nullable();
            $table->timestamp('terakhir_dipakai')->nullable();
            $table->timestamp('kedaluwarsa_pada');
            $table->timestamp('dicabut_pada')->nullable();
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->index(['id_wali', 'peran']);
        });

        // Satu baris per ORANG penerima: ayah membaca tak membuat milik ibu terbaca.
        Schema::create('notifikasi_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->unsignedInteger('id_santri')->nullable();
            $table->string('judul');
            $table->text('pesan');
            $table->string('jenis');
            $table->string('ref_jenis')->nullable();
            $table->string('ref_id')->nullable();
            $table->timestamp('dibaca_pada')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->index(['id_wali', 'peran', 'dibaca_pada']);
        });

        // Terpisah dari `push_langganan` staf, yang sudah hidup di produksi.
        Schema::create('push_langganan_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->string('perangkat')->nullable();
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
            $table->index(['id_wali', 'peran']);
        });

        foreach (self::BERPENCATAT as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->unsignedInteger('dicatat_oleh')->nullable()->change();
                $table->unsignedInteger('dicatat_oleh_wali')->nullable()->after('dicatat_oleh');
                $table->enum('dicatat_oleh_peran', self::PERAN)->nullable()->after('dicatat_oleh_wali');

                $table->foreign('dicatat_oleh_wali')->references('id')->on('wali')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE {$tabel} ADD CONSTRAINT {$tabel}_satu_pencatat
                CHECK (num_nonnulls(dicatat_oleh, dicatat_oleh_wali) = 1)");
            // Peran wajib menyertai wali, dan hanya wali.
            DB::statement("ALTER TABLE {$tabel} ADD CONSTRAINT {$tabel}_peran_pencatat
                CHECK ((dicatat_oleh_wali IS NULL) = (dicatat_oleh_peran IS NULL))");
        }

        // `diunggah_wali` sudah ada sejak awal tapi tanpa kunci asing. Nilai yatim
        // (bila ada) dikosongkan dulu, kalau tidak FK-nya ditolak.
        DB::statement('UPDATE dokumen_santri d SET diunggah_wali = NULL
            WHERE diunggah_wali IS NOT NULL AND NOT EXISTS (SELECT 1 FROM wali w WHERE w.id = d.diunggah_wali)');
        Schema::table('dokumen_santri', function (Blueprint $table) {
            $table->enum('diunggah_peran', self::PERAN)->nullable()->after('diunggah_wali');
            $table->foreign('diunggah_wali')->references('id')->on('wali')->nullOnDelete();
        });

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->enum('asal', ['staf', 'portal', 'ise', 'impor'])->default('staf')->after('jenis');
            $table->index('asal');
        });

        Schema::table('persetujuan_term', function (Blueprint $table) {
            $table->unsignedInteger('disusun_oleh')->nullable()->change();
            $table->string('metode_ttd')->default('otp_email')->change();
            $table->string('penanda_tangan_email')->nullable()->after('penanda_tangan_telepon');
            $table->enum('penanda_tangan_peran', self::PERAN)->nullable()->after('penanda_tangan_email');
        });
    }

    public function down(): void
    {
        // Baris dari wali tak punya tempat di skema lama, dan TIDAK dihapus diam-diam:
        // setoran yang sudah diverifikasi punya jurnal, menghapusnya membuat jurnal yatim.
        foreach ([...self::BERPENCATAT, 'persetujuan_term'] as $tabel) {
            $kolom = $tabel === 'persetujuan_term' ? 'disusun_oleh' : 'dicatat_oleh';
            if (DB::table($tabel)->whereNull($kolom)->exists()) {
                throw new RuntimeException("Rollback portal wali ditolak: {$tabel} sudah berisi baris dari wali.");
            }
        }

        Schema::table('persetujuan_term', function (Blueprint $table) {
            $table->dropColumn(['penanda_tangan_email', 'penanda_tangan_peran']);
            $table->string('metode_ttd')->default('otp_whatsapp')->change();
            $table->unsignedInteger('disusun_oleh')->nullable(false)->change();
        });

        Schema::table('pendaftaran', function (Blueprint $table) {
            $table->dropIndex(['asal']);
            $table->dropColumn('asal');
        });

        Schema::table('dokumen_santri', function (Blueprint $table) {
            $table->dropForeign(['diunggah_wali']);
            $table->dropColumn('diunggah_peran');
        });

        foreach (self::BERPENCATAT as $tabel) {
            DB::statement("ALTER TABLE {$tabel} DROP CONSTRAINT IF EXISTS {$tabel}_satu_pencatat");
            DB::statement("ALTER TABLE {$tabel} DROP CONSTRAINT IF EXISTS {$tabel}_peran_pencatat");
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropForeign(['dicatat_oleh_wali']);
                $table->dropColumn(['dicatat_oleh_wali', 'dicatat_oleh_peran']);
                $table->unsignedInteger('dicatat_oleh')->nullable(false)->change();
            });
        }

        Schema::dropIfExists('push_langganan_wali');
        Schema::dropIfExists('notifikasi_wali');
        Schema::dropIfExists('perangkat_wali');
        Schema::dropIfExists('otp_wali');

        Schema::table('wali', function (Blueprint $table) {
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires')->nullable();
        });

        foreach (self::PERAN as $p) {
            DB::statement("DROP INDEX IF EXISTS wali_email_{$p}_lower");
        }
    }
};
