<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Biaya masuk tak lagi punya baris "Umum (semua jalur)".
 *
 * Jalur WAJIB diisi saat registrasi (`santri.jalur` NOT NULL), jadi baris Umum
 * tak pernah dibutuhkan untuk MENEMUKAN tarif — ia cuma jalan pintas pengisian.
 * Masalahnya: jalan pintas itu tak terlihat. Sel yang bertuliskan "belum diisi"
 * diam-diam menagih angka baris Umum, dan matriks meminta baris yang tak punya
 * padanan di dropdown registrasi.
 *
 * Baris Umum tidak sekadar dibuang — nilainya DIMATERIALKAN dulu ke tiap jalur
 * yang selama ini menumpang, lalu hasilnya diperiksa: tarif setiap kombinasi
 * (T.A × jenjang × jalur × perilaku) harus persis sama seperti sebelum migrasi.
 * Satu saja bergeser, seluruh migrasi dibatalkan.
 *
 * TIDAK menyentuh SPP & daftar ulang: keduanya memang tak mengenal jalur dan
 * tersimpan dengan `kode_jalur` kosong. Baris merekalah yang tampak seperti
 * "Umum" di database, padahal di layar ada di panel "Biaya santri aktif".
 */
return new class extends Migration
{
    /** Hanya biaya MASUK yang mengenal jalur. */
    private const PERILAKU = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    public function up(): void
    {
        DB::transaction(function () {
            $sebelum = $this->petaTarif();

            $jalur = DB::table('jalur_pendaftaran')->get(['kode', 'bebas_uang_pangkal']);
            $umumRows = DB::table('tarif_biaya')
                ->whereNull('kode_jalur')->whereIn('perilaku', self::PERILAKU)->get();

            foreach ($umumRows as $u) {
                foreach ($jalur as $j) {
                    $sudahAda = DB::table('tarif_biaya')
                        ->where('tahun_ajaran', $u->tahun_ajaran)
                        ->whereRaw('kode_jenjang IS NOT DISTINCT FROM ?', [$u->kode_jenjang])
                        ->where('perilaku', $u->perilaku)
                        ->where('kode_jalur', $j->kode)
                        ->whereRaw('tingkat IS NOT DISTINCT FROM ?', [$u->tingkat])
                        ->exists();
                    if ($sudahAda) {
                        continue; // baris jalurnya sendiri sudah menang atas Umum
                    }

                    // Nilai disalin APA ADANYA — termasuk untuk jalur bertanda
                    // "bebas uang pangkal". Penanda itu ternyata tak pernah
                    // menentukan tagihan (JalurPendaftaran::bebasUangPangkal()
                    // tak dipanggil di mana pun); jalur tersebut hari ini
                    // BENAR-BENAR menagih angka baris Umum. Menjadikannya bebas
                    // di sini berarti menggratiskan uang pangkal diam-diam —
                    // perubahan kebijakan, bukan pemindahan bentuk data.
                    $bebas = (bool) $u->bebas;

                    DB::table('tarif_biaya')->insert([
                        'tahun_ajaran' => $u->tahun_ajaran,
                        'kode_jenjang' => $u->kode_jenjang,
                        'kode_jalur' => $j->kode,
                        'perilaku' => $u->perilaku,
                        'tingkat' => $u->tingkat,
                        'nominal' => $bebas ? null : $u->nominal,
                        'bebas' => $bebas,
                        'keterangan' => $u->keterangan,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('tarif_biaya')->whereNull('kode_jalur')->whereIn('perilaku', self::PERILAKU)->delete();

            $sesudah = $this->petaTarif();
            $beda = [];
            foreach ($sebelum as $kunci => $nilai) {
                if (($sesudah[$kunci] ?? null) !== $nilai) {
                    $beda[] = $kunci.': '.$nilai.' → '.($sesudah[$kunci] ?? 'HILANG');
                }
            }
            if ($beda !== []) {
                throw new RuntimeException(
                    'Migrasi dibatalkan — tarif berubah untuk '.count($beda).' kombinasi: '
                    .implode('; ', array_slice($beda, 0, 5)).(count($beda) > 5 ? ' …' : '')
                );
            }
        });
    }

    /**
     * Tarif efektif setiap (T.A × jenjang × jalur × perilaku) menurut aturan
     * pencarian: baris jalur menang, baris Umum sebagai cadangan. Dipakai untuk
     * membandingkan keadaan sebelum & sesudah.
     *
     * @return array<string,string>
     */
    private function petaTarif(): array
    {
        $baris = DB::table('tarif_biaya')->whereIn('perilaku', self::PERILAKU)->get();
        $jalur = DB::table('jalur_pendaftaran')->pluck('kode');

        $indeks = [];
        foreach ($baris as $b) {
            $indeks[$b->tahun_ajaran.'|'.($b->kode_jenjang ?? '-').'|'.$b->perilaku][$b->kode_jalur ?? '*'] = $b;
        }

        $peta = [];
        foreach ($indeks as $konteks => $isi) {
            foreach ($jalur as $kodeJalur) {
                $row = $isi[$kodeJalur] ?? $isi['*'] ?? null;
                $peta[$konteks.'|'.$kodeJalur] = $row === null
                    ? 'kosong'
                    : ($row->bebas ? 'bebas' : (string) $row->nominal);
            }
        }

        return $peta;
    }

    public function down(): void
    {
        // Tak bisa dipulihkan sebagai baris Umum: sesudah dimaterialkan, tak ada
        // lagi keterangan mana yang dulu menumpang dan mana yang memang diisi
        // sendiri. Datanya tetap utuh & lengkap per jalur — hanya bentuk
        // pengisiannya yang tak kembali.
    }
};
