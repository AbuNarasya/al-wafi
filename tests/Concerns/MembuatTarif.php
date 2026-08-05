<?php

namespace Tests\Concerns;

use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\TarifBiaya;
use App\Services\Modules\JenisBiayaService;
use App\Services\Modules\TarifService;

/**
 * Bantuan fixture: dulu satu baris `jenis_biaya` memuat akun DAN tarif sekaligus,
 * sehingga fixture cukup memanggil JenisBiayaService::create() dengan `nominal`,
 * `tahun_ajaran`, dan `kode_jalur`. Sejak tarif pindah ke `tarif_biaya`, keduanya
 * harus ditulis terpisah.
 *
 * Trait ini menerima bentuk larik LAMA apa adanya lalu memecahnya sendiri, supaya
 * puluhan fixture yang sudah ada tak perlu ditulis ulang satu per satu.
 */
trait MembuatTarif
{
    /** Jenjang bawaan fixture — tarif selalu melekat pada satu jenjang. */
    protected const JENJANG_UJI = 'XJ';

    /** @var array<string,true> sel yang disebut per jalur; tak boleh tertimpa penyebaran */
    private array $selJalurEksplisit = [];

    /** Dipakai bila fixture menyebut tahun ajaran tapi tidak menyebut nominal. */
    protected const NOMINAL_BAWAAN = '1000000';

    /**
     * Buat baris identitas akuntansi + (bila `nominal`/`bebas` disebut) sel tarifnya.
     *
     * Kunci yang dipahami di luar kolom jenis_biaya: `nominal`, `tahun_ajaran`,
     * `kode_jalur`, `bebas`.
     */
    protected function buatBiaya(array $data): JenisBiaya
    {
        $nominal = $data['nominal'] ?? null;
        $ta = $data['tahun_ajaran'] ?? null;
        $jalur = ($data['kode_jalur'] ?? null) ?: null;
        $bebas = (bool) ($data['bebas'] ?? false);
        unset($data['nominal'], $data['tahun_ajaran'], $data['kode_jalur'], $data['bebas']);

        $jenjang = ($data['kode_jenjang'] ?? null) ?: null;
        $jenis = (new JenisBiayaService)->create($data);
        $perilaku = (string) \App\Models\TipeBiaya::perilakuDari($jenis->tipe);

        if ($ta === null || ! isset(TarifService::PERILAKU[$perilaku])) {
            return $jenis;
        }

        // Fixture lama tak menyebut nominal untuk uang pangkal — dulu angkanya
        // memang selalu diketik saat menagih. Kini selnya HARUS ada (kosong =
        // "belum diisi" dan menghentikan penagihan), jadi diisikan angka bawaan;
        // test yang peduli angkanya tetap mengirim nominalnya sendiri.
        $nominal ??= $bebas ? null : self::NOMINAL_BAWAAN;

        if ($jenjang !== null) {
            $this->pasangTarif($ta, $jenjang, $jalur, $perilaku, $nominal, $bebas);

            return $jenis;
        }

        // Tanpa jenjang, selnya dicerminkan ke SETIAP jenjang yang sudah ada
        // (plus kotak tanpa-jenjang): fixture tak memberi tahu jenjang santrinya,
        // sedangkan tarif dicocokkan persis. Sel yang sudah ada tidak ditimpa,
        // supaya tarif khusus per jenjang yang dibuat lebih dulu tetap menang.
        foreach ([...Jenjang::pluck('kode')->all(), null] as $j) {
            $this->pasangTarif($ta, $j, $jalur, $perilaku, $nominal, $bebas, timpa: false);
        }

        return $jenis;
    }

    /**
     * Pasang/ubah satu sel tarif tanpa menyentuh master jenis biaya.
     *
     * Biaya masuk tak lagi punya baris "Umum (semua jalur)". Fixture lama yang
     * tak menyebut jalur karena itu dicerminkan ke SETIAP jalur terdaftar —
     * maksudnya memang "tarif ini berlaku bagi semua pendaftar", dan dulu itu
     * ditulis sebagai satu baris tanpa jalur. SPP & daftar ulang tetap ditulis
     * tanpa jalur, karena keduanya memang tak mengenalnya.
     */
    protected function pasangTarif(string $ta, ?string $jenjang, ?string $jalur, string $perilaku, string|int|float|null $nominal, bool $bebas = false, bool $timpa = true): TarifBiaya
    {
        $berjalur = ! in_array($perilaku, TarifService::TANPA_JALUR, true);

        if ($berjalur && $jalur !== null) {
            // Sel yang SENGAJA disebut per jalur — kelak tak boleh tertimpa
            // penyebaran, persis seperti baris jalur dulu menang atas Umum.
            $this->selJalurEksplisit[$this->kunciSel($ta, $jenjang, $jalur, $perilaku)] = true;
        }

        if ($berjalur && $jalur === null) {
            $semua = \App\Models\JalurPendaftaran::pluck('kode')->all();
            $terakhir = null;
            foreach ($semua as $kode) {
                // Melewati sel yang sudah dipasang khusus; sisanya boleh
                // diperbarui, karena dulu mengubah baris Umum memang mengubah
                // tarif semua jalur yang menumpang padanya.
                if (isset($this->selJalurEksplisit[$this->kunciSel($ta, $jenjang, $kode, $perilaku)])) {
                    continue;
                }
                $terakhir = $this->tulisSelTarif($ta, $jenjang, $kode, $perilaku, $nominal, $bebas, $timpa);
            }
            // Tanpa satu pun jalur terdaftar, selnya tetap ditulis tanpa jalur
            // agar fixture yang belum membuat master jalur tak gagal senyap.
            return $terakhir ?? $this->tulisSelTarif($ta, $jenjang, null, $perilaku, $nominal, $bebas, $timpa);
        }

        return $this->tulisSelTarif($ta, $jenjang, $berjalur ? $jalur : null, $perilaku, $nominal, $bebas, $timpa);
    }

    private function kunciSel(string $ta, ?string $jenjang, string $jalur, string $perilaku): string
    {
        return $ta.'|'.($jenjang ?: '-').'|'.$jalur.'|'.$perilaku;
    }

    private function tulisSelTarif(string $ta, ?string $jenjang, ?string $jalur, string $perilaku, string|int|float|null $nominal, bool $bebas, bool $timpa): TarifBiaya
    {
        $kunci = ['tahun_ajaran' => $ta, 'kode_jenjang' => $jenjang ?: null,
            'kode_jalur' => $jalur, 'perilaku' => $perilaku];
        $isi = ['nominal' => $bebas ? null : $nominal, 'bebas' => $bebas];

        if (! $timpa && ($ada = TarifBiaya::where($kunci)->first())) {
            return $ada;
        }

        return TarifBiaya::updateOrCreate($kunci, $isi);
    }

    /** Jenjang bawaan fixture, dibuat sekali bila belum ada. */
    protected function jenjangUji(): string
    {
        Jenjang::firstOrCreate(
            ['kode' => self::JENJANG_UJI],
            ['nama' => 'Jenjang Uji', 'urutan' => 99, 'status' => 'aktif', 'jumlah_tingkat' => 6],
        );

        return self::JENJANG_UJI;
    }
}
