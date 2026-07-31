<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JenisBiaya;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;

/**
 * Master jenis biaya kesantrian — IDENTITAS AKUNTANSI saja: nama, perilaku,
 * jenjang, akun COA, unit bisnis. Registrasi = cash basis (piutang null);
 * SPP/uang pangkal/perlengkapan = akrual.
 *
 * TARIFNYA TIDAK DI SINI. Besaran per (T.A, jenjang, jalur) diatur di menu Tarif
 * (App\Services\Modules\TarifService). Karena itu modul ini tak lagi punya
 * mesin duplikasi antar tahun ajaran: barisnya diisi sekali dan dipakai terus.
 */
class JenisBiayaService
{
    /**
     * Perilaku yang barisnya DICARI PROGRAM lewat `JenisBiaya::untuk()`, jadi
     * kombinasi (perilaku, jenjang) wajib tunggal di antara baris aktif.
     * Perilaku "lain" tidak masuk: tagihannya dipilih manual, jadi boleh berganda.
     */
    private const PERILAKU_TUNGGAL = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp'];

    public function list()
    {
        // `jenjang` dimuat karena daftarnya menyebut NAMA jenjang, bukan kode `J001`.
        return JenisBiaya::with('jenjang')->orderBy('tipe')->orderBy('kode')->get();
    }

    public function get(string $kode): JenisBiaya
    {
        $row = JenisBiaya::find($kode);
        if (! $row) {
            throw new AppException(404, 'Jenis biaya tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): JenisBiaya
    {
        $this->assertAkunAda($data['kode_coa_pendapatan'], $data['kode_coa_piutang'] ?? null);
        $this->assertUnitAda($data['kode_unit']);
        $this->assertBarisTunggal($data);

        return JenisBiaya::create($data);
    }

    public function update(string $kode, array $data): JenisBiaya
    {
        $lama = JenisBiaya::find($kode);
        if (! $lama) {
            throw new AppException(404, 'Jenis biaya tidak ditemukan.');
        }
        $gabungan = array_merge($lama->toArray(), $data);
        $this->assertAkunAda($gabungan['kode_coa_pendapatan'], $gabungan['kode_coa_piutang'] ?? null);
        $this->assertUnitAda($gabungan['kode_unit']);
        $this->assertBarisTunggal($gabungan, $kode);
        $lama->update($data);

        return $lama;
    }

    public function remove(string $kode): void
    {
        $dipakai = TagihanSantri::where('kode_jenis', $kode)->count();
        if ($dipakai > 0) {
            throw new AppException(422, "Jenis biaya ini sudah dipakai {$dipakai} tagihan santri. Nonaktifkan saja — menghapusnya akan memutus riwayat tagihan yang sudah terbit.");
        }
        JenisBiaya::destroy($kode);
    }

    /**
     * Registrasi, uang pangkal, perlengkapan, & SPP dicari program lewat
     * `JenisBiaya::untuk(perilaku, jenjang)` — jadi kombinasi itu HARUS tunggal
     * di antara baris aktif. Dua baris bersaing membuat yang terpilih sekadar
     * "urutan kode terkecil", dan pemakainya tak punya cara tahu yang mana.
     * Jenjang kosong = baris cadangan UMUM, juga hanya boleh satu.
     *
     * Perbandingannya per PERILAKU, bukan per kode tipe: dua tipe berperilaku
     * sama tetap membuat pencarian bimbang.
     */
    private function assertBarisTunggal(array $data, ?string $kecualiKode = null): void
    {
        $tipe = $data['tipe'] ?? null;
        $perilaku = TipeBiaya::perilakuDari($tipe);
        if (! in_array($perilaku, self::PERILAKU_TUNGGAL, true) || ($data['status'] ?? 'aktif') !== 'aktif') {
            return;
        }

        $kodeJenjang = ($data['kode_jenjang'] ?? null) ?: null;
        $bentrok = JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku((string) $perilaku))
            ->where('status', 'aktif')
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang), fn ($q) => $q->whereNull('kode_jenjang'))
            ->when($kecualiKode, fn ($q) => $q->where('kode', '!=', $kecualiKode))
            ->first();

        if ($bentrok) {
            $label = $kodeJenjang ? "jenjang {$kodeJenjang}" : 'UMUM (semua jenjang)';
            $labelPerilaku = str_replace('_', ' ', (string) $perilaku);
            throw new AppException(409, "Jenis biaya {$labelPerilaku} untuk {$label} sudah ada di \"{$bentrok->kode}\". "
                .'Sunting baris itu atau nonaktifkan — satu jenjang cukup satu baris, karena baris ini hanya '
                .'menentukan akun & unit bisnisnya. Kalau yang ingin dibedakan adalah BESARANNYA, aturlah di menu Tarif.');
        }
    }

    private function assertUnitAda(string $kodeUnit): void
    {
        $unit = BusinessUnit::find($kodeUnit);
        if (! $unit) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }
        if ($unit->status !== 'aktif') {
            throw new AppException(422, "Unit \"{$unit->nama_unit}\" berstatus nonaktif.");
        }
    }

    private function assertAkunAda(string $kodePendapatan, ?string $kodePiutang): void
    {
        foreach ([[$kodePendapatan, 'Akun pendapatan'], [$kodePiutang, 'Akun piutang']] as [$kode, $label]) {
            if (! $kode) {
                continue;
            }
            $akun = CoaDetail::find($kode);
            if (! $akun) {
                throw new AppException(400, "{$label} \"{$kode}\" tidak ada di Chart of Account.");
            }
            if ($akun->status !== 'aktif') {
                throw new AppException(422, "{$label} \"{$akun->nama_coa}\" berstatus nonaktif.");
            }
        }
    }
}
