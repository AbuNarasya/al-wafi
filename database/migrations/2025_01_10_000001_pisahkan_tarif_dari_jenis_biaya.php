<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MEMISAHKAN TARIF DARI IDENTITAS AKUNTANSI.
 *
 * Sebelum ini `jenis_biaya` merangkap dua peran: pemetaan akun (pendapatan,
 * piutang, unit bisnis) DAN tabel tarif (nominal per tahun ajaran per jalur).
 * Karena peran kedua itu barisnya beranak — 23 baris untuk satu T.A, dan harus
 * diduplikasi seluruhnya tiap tahun — padahal akunnya tak pernah berubah.
 *
 * Sesudah ini:
 *  • `jenis_biaya` = identitas akuntansi saja, satu baris per (jenjang,
 *    perilaku). Tanpa nominal, tanpa tahun ajaran, tanpa jalur. Diisi sekali.
 *  • `tarif_biaya` = grid tarif: satu baris per (T.A, jenjang, jalur, perilaku).
 *    Barisnya punya TIGA keadaan yang harus dibedakan, karena "kosong" tak boleh
 *    berarti dua hal sekaligus:
 *      - `nominal` terisi        → tarif berlaku
 *      - `bebas` = true          → sengaja tidak dipungut, tagihan tak terbit
 *      - tak ada barisnya        → belum diisi, penagihan BERHENTI dengan pesan
 *    Inilah yang menjaga santri OSS lanjutan (jalur 004) & anak karyawan (005)
 *    tidak ditagih uang pangkal saat naik jenjang: selnya bertanda `bebas`.
 *
 * `kode_jalur` NULL pada tarif berarti baris "Umum (semua jalur)" — satu-satunya
 * tingkat pencarian yang tersisa (jalur spesifik → Umum). Dimensi T.A & jenjang
 * WAJIB cocok persis; pencarian empat tingkat yang lama sudah pernah membuat
 * calon SMP reguler diam-diam ditagih tarif SMP OSS.
 *
 * `tagihan_santri` ikut dapat `perilaku`, `kode_jenjang`, & `tahun_ajaran` —
 * dulu ketiganya cuma tersirat di dalam kode jenis (`SMA27-02`). Begitu naik
 * jenjang menagih uang pangkal untuk kedua kalinya, penanda tersirat itu tak
 * cukup: dibutuhkan INDEKS UNIK sungguhan agar satu santri tak bisa ditagih dua
 * kali untuk (jenjang, T.A) yang sama, betapapun tombol massalnya ditekan.
 */
return new class extends Migration
{
    /** perilaku → akhiran kode jenis biaya yang baru. */
    private const SUFFIX = [
        'registrasi' => 'REG',
        'uang_pangkal' => 'UP',
        'perlengkapan' => 'PLK',
        'spp' => 'SPP',
    ];

    /** perilaku → nama baris identitas yang baru (ucwords atas kode perilaku menghasilkan "Spp"). */
    private const NAMA = [
        'registrasi' => 'Registrasi',
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Perlengkapan',
        'spp' => 'SPP',
    ];

    /** Perilaku yang tagihannya hanya boleh SEKALI per (santri, jenjang, T.A, periode). */
    private const SEKALI = ['registrasi', 'uang_pangkal', 'perlengkapan', 'spp'];

    public function up(): void
    {
        Schema::create('tarif_biaya', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_ajaran');
            // NULL = untuk santri yang memang TIDAK berjenjang (pesantren yang tak
            // memakai jenjang sama sekali). Ini BUKAN tingkat cadangan: jenjang
            // harus cocok persis, dan NULL hanya cocok dengan NULL.
            $table->string('kode_jenjang')->nullable();
            // NULL = baris "Umum (semua jalur)". Inilah satu-satunya cadangan.
            $table->string('kode_jalur')->nullable();
            $table->string('perilaku');
            // NULL sah: baris yang ada tapi bernominal kosong dipakai untuk
            // menandai `bebas`. Yang tak boleh adalah nominal kosong TANPA bebas.
            $table->decimal('nominal', 18, 2)->nullable();
            $table->boolean('bebas')->default(false);
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->cascadeOnDelete();
            $table->foreign('kode_jenjang')->references('kode')->on('jenjang')->cascadeOnDelete();
            $table->foreign('kode_jalur')->references('kode')->on('jalur_pendaftaran')->cascadeOnDelete();
            $table->index(['tahun_ajaran', 'kode_jenjang']);
        });

        // Unique lewat ekspresi COALESCE: di PostgreSQL dua NULL dianggap BERBEDA,
        // jadi unique biasa tak akan menghalangi dua baris "Umum" yang kembar.
        DB::statement("CREATE UNIQUE INDEX tarif_biaya_sel_unik ON tarif_biaya
            (tahun_ajaran, COALESCE(kode_jenjang, '-'), COALESCE(kode_jalur, '-'), perilaku)");

        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->string('perilaku')->nullable()->after('kode_jenis');
            $table->string('kode_jenjang')->nullable()->after('perilaku');
            $table->string('tahun_ajaran')->nullable()->after('kode_jenjang');
        });

        $this->isiKolomTagihan();

        // Rencana disusun SELAGI kolom lama masih ada, tapi baru ditulis setelah
        // kolomnya dibuang: baris identitas yang baru tak punya tahun ajaran,
        // dan kolom itu masih NOT NULL sampai di-drop.
        $rencana = $this->rencanaPemindahan();
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->dropForeign(['tahun_ajaran']);
            $table->dropColumn(['nominal', 'tahun_ajaran', 'kode_jalur']);
        });
        $this->terapkanPemindahan($rencana);
        $this->pastikanTakAdaTagihanGanda();

        // Pengaman keras anti tagih-ganda. Parsial: perilaku "lain" memang boleh
        // berganda (satu santri bisa kena dua tagihan insidental di tahun sama).
        $daftar = "'".implode("','", self::SEKALI)."'";
        DB::statement("CREATE UNIQUE INDEX tagihan_santri_sekali_per_ta ON tagihan_santri
            (id_santri, perilaku, COALESCE(kode_jenjang, '-'), COALESCE(tahun_ajaran, '-'), COALESCE(periode, '-'))
            WHERE perilaku IN ({$daftar}) AND status <> 'batal'");
    }

    /**
     * Isi kolom baru tagihan dari data yang sudah ada. Sumbernya jenis biaya
     * (di sanalah jenjang & T.A tarif tercatat saat tagihan terbit), baru santri
     * sebagai cadangan — bukan sebaliknya: santri yang sudah naik jenjang
     * membawa jenjang BARU, sedangkan tagihan lamanya milik jenjang lama.
     */
    private function isiKolomTagihan(): void
    {
        DB::statement("
            UPDATE tagihan_santri t SET
                perilaku      = COALESCE(tp.perilaku, jb.tipe),
                kode_jenjang  = COALESCE(jb.kode_jenjang, s.kode_jenjang),
                tahun_ajaran  = COALESCE(jb.tahun_ajaran, s.tahun_ajaran)
            FROM jenis_biaya jb
            LEFT JOIN tipe_biaya tp ON tp.kode = jb.tipe,
                 santri s
            WHERE jb.kode = t.kode_jenis AND s.id = t.id_santri
        ");
    }

    /**
     * Pecah tiap baris jenis_biaya lama menjadi (a) satu baris identitas
     * akuntansi per (jenjang, perilaku) dan (b) satu sel tarif.
     */
    private function rencanaPemindahan(): array
    {
        $lama = DB::table('jenis_biaya')->orderBy('kode')->get();
        if ($lama->isEmpty()) {
            return ['baru' => [], 'peta' => [], 'tarif' => [], 'sudah_ada' => []];
        }
        $perilakuDari = DB::table('tipe_biaya')->pluck('perilaku', 'kode')->all();
        $jenjangSemua = DB::table('jenjang')->pluck('kode')->all();
        $now = now();

        // ---- (a) identitas akuntansi ----
        // Akun diambil dari baris paling UMUM (tanpa jalur); baris pengecualian
        // per jalur hanya berbeda nominalnya, jadi akunnya pasti sama.
        $baru = [];      // kode_baru => atribut
        $petaKode = [];  // kode_lama => kode_baru
        foreach ($lama as $j) {
            $perilaku = $perilakuDari[$j->tipe] ?? $j->tipe;
            if (! isset(self::SUFFIX[$perilaku])) {
                continue; // perilaku "lain" ditangani di bawah
            }
            $kodeBaru = ($j->kode_jenjang ?: 'UMUM').'-'.self::SUFFIX[$perilaku];
            $petaKode[$j->kode] = $kodeBaru;
            if (! isset($baru[$kodeBaru]) || $j->kode_jalur === null) {
                $baru[$kodeBaru] = [
                    'kode' => $kodeBaru,
                    'nama' => trim(self::NAMA[$perilaku].' '.($j->kode_jenjang ?: '')),
                    'tipe' => $j->tipe,
                    'kode_jenjang' => $j->kode_jenjang,
                    'kode_coa_pendapatan' => $j->kode_coa_pendapatan,
                    'kode_coa_piutang' => $j->kode_coa_piutang,
                    'kode_coa_diterima_dimuka' => $j->kode_coa_diterima_dimuka,
                    'kode_unit' => $j->kode_unit,
                    'berulang' => $j->berulang,
                    'status' => $j->status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Perilaku "lain" tetap satu baris per baris lama (dipilih manual saat
        // menagih, jadi boleh berganda) — tahunnya saja yang dibuang dari kode.
        $terpakai = array_flip(array_keys($baru));
        foreach ($lama as $j) {
            $perilaku = $perilakuDari[$j->tipe] ?? $j->tipe;
            if (isset(self::SUFFIX[$perilaku])) {
                continue;
            }
            $kodeBaru = $this->tanpaTahun($j->kode, $j->tahun_ajaran);
            if ($kodeBaru !== $j->kode && isset($terpakai[$kodeBaru])) {
                $kodeBaru = $j->kode; // bentrok antar-T.A: pertahankan kode aslinya
            }
            $terpakai[$kodeBaru] = true;
            $petaKode[$j->kode] = $kodeBaru;
            $baru[$kodeBaru] = [
                'kode' => $kodeBaru,
                'nama' => $this->tanpaTahun($j->nama, $j->tahun_ajaran),
                'tipe' => $j->tipe,
                'kode_jenjang' => $j->kode_jenjang,
                'kode_coa_pendapatan' => $j->kode_coa_pendapatan,
                'kode_coa_piutang' => $j->kode_coa_piutang,
                'kode_coa_diterima_dimuka' => $j->kode_coa_diterima_dimuka,
                'kode_unit' => $j->kode_unit,
                'berulang' => $j->berulang,
                'status' => $j->status,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // ---- (b) sel tarif ----
        $tarif = [];
        foreach ($lama as $j) {
            $perilaku = $perilakuDari[$j->tipe] ?? $j->tipe;
            if (! isset(self::SUFFIX[$perilaku]) || $j->nominal === null) {
                continue;
            }
            // Baris lama tanpa jenjang dulu berlaku untuk SEMUA jenjang; kini
            // jenjang harus cocok persis, jadi ia dimekarkan menjadi satu sel di
            // tiap jenjang yang ada. Bila belum ada jenjang sama sekali, selnya
            // tetap dibuat tanpa jenjang agar tarifnya tidak hilang.
            foreach ($j->kode_jenjang ? [$j->kode_jenjang] : ($jenjangSemua ?: [null]) as $jenjang) {
                $kunci = $j->tahun_ajaran.'|'.$jenjang.'|'.($j->kode_jalur ?? '-').'|'.$perilaku;
                $tarif[$kunci] ??= [
                    'tahun_ajaran' => $j->tahun_ajaran, 'kode_jenjang' => $jenjang,
                    'kode_jalur' => $j->kode_jalur, 'perilaku' => $perilaku,
                    'nominal' => $j->nominal, 'bebas' => false,
                    'keterangan' => 'Dipindahkan dari jenis biaya "'.$j->kode.'".',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        // Jalur bertanda bebas uang pangkal → selnya ditandai `bebas`, bukan
        // dibiarkan kosong. Kosong berarti "belum diisi" dan akan menghentikan
        // penagihan; yang kita mau adalah "memang tidak dipungut".
        $jalurBebas = DB::table('jalur_pendaftaran')->where('bebas_uang_pangkal', true)->pluck('kode')->all();
        $taTerpakai = $lama->pluck('tahun_ajaran')->unique()->filter()->all();
        foreach ($taTerpakai as $ta) {
            foreach ($jenjangSemua as $jenjang) {
                foreach ($jalurBebas as $jalur) {
                    $kunci = $ta.'|'.$jenjang.'|'.$jalur.'|uang_pangkal';
                    $tarif[$kunci] ??= [
                        'tahun_ajaran' => $ta, 'kode_jenjang' => $jenjang,
                        'kode_jalur' => $jalur, 'perilaku' => 'uang_pangkal',
                        'nominal' => null, 'bebas' => true,
                        'keterangan' => 'Jalur ini bertanda bebas uang pangkal.',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        // Kode baru yang KEBETULAN sudah dipakai baris lama (mis. master sudah
        // bernama "SDTQ-UP") disunting, bukan disisipkan — insert akan menabrak
        // primary key dan menggagalkan seluruh migrasi.
        return ['baru' => $baru, 'peta' => $petaKode, 'tarif' => $tarif, 'sudah_ada' => $lama->pluck('kode')->flip()->all()];
    }

    /** Menulis rencana dari rencanaPemindahan() — dipanggil setelah kolom lama dibuang. */
    private function terapkanPemindahan(array $rencana): void
    {
        ['baru' => $baru, 'peta' => $petaKode, 'tarif' => $tarif, 'sudah_ada' => $sudahAda] = $rencana;
        if ($baru === []) {
            return;
        }

        DB::transaction(function () use ($baru, $petaKode, $tarif, $sudahAda) {
            $sisip = [];
            foreach ($baru as $kode => $atribut) {
                if (isset($sudahAda[$kode])) {
                    unset($atribut['created_at']);
                    DB::table('jenis_biaya')->where('kode', $kode)->update($atribut);
                } else {
                    $sisip[] = $atribut;
                }
            }
            if ($sisip !== []) {
                DB::table('jenis_biaya')->insert($sisip);
            }
            foreach ($petaKode as $dari => $ke) {
                if ($dari !== $ke) {
                    DB::table('tagihan_santri')->where('kode_jenis', $dari)->update(['kode_jenis' => $ke]);
                }
            }
            DB::table('jenis_biaya')->whereIn('kode', array_keys($petaKode))
                ->whereNotIn('kode', array_values($petaKode))->delete();
            if ($tarif !== []) {
                DB::table('tarif_biaya')->insert(array_values($tarif));
            }
        });
    }

    /** "SDTQ27-05" + T.A 2027/2028 → "SDTQ-05"; nama ikut dibersihkan. */
    private function tanpaTahun(string $teks, ?string $ta): string
    {
        if (! $ta || ! preg_match('/^(\d{4})/', $ta, $m)) {
            return $teks;
        }
        $penuh = $m[1];
        $pendek = substr($penuh, 2);
        $teks = str_replace([' '.$ta, ' '.$penuh, $pendek.'-'], ['', '', '-'], $teks);

        return trim(preg_replace('/\s{2,}/', ' ', $teks));
    }

    /**
     * Indeks unik akan menolak data yang sudah telanjur ganda. Diperiksa lebih
     * dulu supaya pesannya menyebut santrinya, bukan sekadar galat SQL — kegagalan
     * migrasi di tengah deploy pernah mematikan container dan menahan rilis.
     */
    private function pastikanTakAdaTagihanGanda(): void
    {
        $daftar = "'".implode("','", self::SEKALI)."'";
        $ganda = DB::select("
            SELECT t.id_santri, t.perilaku, t.kode_jenjang, t.tahun_ajaran, t.periode, COUNT(*) AS jumlah
            FROM tagihan_santri t
            WHERE t.perilaku IN ({$daftar}) AND t.status <> 'batal'
            GROUP BY 1,2,3,4,5 HAVING COUNT(*) > 1
        ");
        if ($ganda === []) {
            return;
        }
        $rincian = implode('; ', array_map(
            fn ($g) => "santri #{$g->id_santri} {$g->perilaku} {$g->kode_jenjang} {$g->tahun_ajaran} ({$g->jumlah}×)",
            array_slice($ganda, 0, 10)
        ));
        throw new RuntimeException(
            'Ada tagihan ganda untuk kombinasi (santri, perilaku, jenjang, T.A, periode) yang sama, '
            .'sehingga indeks unik anti tagih-ganda tak bisa dipasang. Batalkan dulu tagihan yang berlebih: '
            .$rincian
        );
    }

    /**
     * BALIKAN TIDAK UTUH — dan memang tak bisa utuh: banyak baris lama dilebur
     * jadi satu, jadi pemecahannya kembali mustahil ditebak. Kolomnya dikembalikan
     * (tahun_ajaran jadi nullable, dulu NOT NULL) supaya skema lama bisa jalan,
     * tetapi nominal & baris pengecualian per jalur TIDAK dipulihkan.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tagihan_santri_sekali_per_ta');
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->decimal('nominal', 18, 2)->nullable();
            $table->string('tahun_ajaran')->nullable();
            $table->string('kode_jalur')->nullable();
        });
        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->dropColumn(['perilaku', 'kode_jenjang', 'tahun_ajaran']);
        });
        Schema::dropIfExists('tarif_biaya');
    }
};
