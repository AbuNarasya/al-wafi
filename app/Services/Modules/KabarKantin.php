<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\HakAksesModul;
use App\Models\Notification;
use App\Models\PenarikanMitra;
use App\Models\PenerimaanAlWafi;
use App\Models\User;
use App\Services\Penghubung\KlienDompetToko;
use Illuminate\Support\Facades\Cache;

/**
 * NOTIFIKASI ALUR UANG KANTIN & PENCAIRAN (keputusan user 10 Nov 2026).
 *
 *  A. Mitra mengajukan penarikan → TUGAS ke pemegang `mitra-kantin` (Ubah) & admin (lonceng + push).
 *  B. Pencairan dari Al Wafi belum dicatat diterima → TUGAS ke pemegang `penerimaan-al-wafi` (Buat) & admin.
 *     Daftarnya ada di ERP Al Wafi, jadi diperiksa berkala (`toko:cek-pencairan`, 15 menit) dan disimpan di
 *     cache untuk angka Tugas Saya — halaman tak pernah menunggu panggilan ke instans lain.
 *  C. Penarikan selesai/ditolak → KABAR ke akun mitra (titik di menu Saldo + kotak kabar di halaman Saldo).
 *
 * Angka Tugas Saya tetap dihitung dari keadaan nyata (TugasSaya); baris notifikasi ini untuk lonceng & push.
 */
class KabarKantin
{
    public const TUGAS_PENARIKAN = 'penarikan_mitra_menunggu';

    public const TUGAS_PENCAIRAN = 'pencairan_al_wafi_menunggu';

    public const KABAR_PENARIKAN = 'penarikan_mitra_diproses';

    /** Kunci cache: [nomor => total] pencairan Al Wafi yang belum dicatat diterima di Wafi Mart. */
    public const CACHE_PENCAIRAN = 'pencairan_al_wafi_menunggu';

    /** Staf aktif (bukan akun mitra) yang berhak `$aksi` atas `$modul`, ditambah admin. @return list<int> */
    public static function penerima(string $modul, string $aksi): array
    {
        $berhak = HakAksesModul::where('kode_modul', $modul)->where($aksi, true)->pluck('id_pengguna');

        return User::where('status', 'aktif')->whereNull('id_mitra')
            ->where(fn ($q) => $q->where('is_admin', true)->orWhereIn('id_pengguna', $berhak))
            ->pluck('id_pengguna')->map(fn ($v) => (int) $v)->all();
    }

    /** A — dipanggil sesudah pengajuan tersimpan. */
    public function penarikanDiajukan(PenarikanMitra $p): void
    {
        $p->loadMissing('mitra');
        $rp = 'Rp '.number_format((float) $p->nominal, 0, ',', '.');
        (new NotificationService)->kirim(array_map(fn ($id) => [
            'id_pengguna' => $id, 'judul' => 'Pengajuan penarikan saldo mitra',
            'pesan' => "{$p->mitra->nama} mengajukan penarikan {$rp} ({$p->nomor}) ke {$p->bank_nama} {$p->bank_rekening}.",
            'jenis' => self::TUGAS_PENARIKAN, 'ref_jenis' => 'PenarikanMitra', 'ref_id' => (string) $p->id,
        ], self::penerima(MitraKantinService::MODUL, 'ubah')));
    }

    /** C — kabar ke akun mitra sesudah petugas mentransfer atau menolak. */
    public function penarikanDiproses(PenarikanMitra $p): void
    {
        $akun = User::where('id_mitra', $p->id_mitra)->where('status', 'aktif')->pluck('id_pengguna');
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        [$judul, $pesan] = $p->status === 'selesai'
            ? ['Penarikan sudah ditransfer', "Penarikan {$rp($p->nominal)} ({$p->nomor}) sudah ditransfer — diterima {$rp($p->diterima())}, biaya transfer {$rp($p->biaya_transfer)}."]
            : ['Penarikan ditolak', "Penarikan {$rp($p->nominal)} ({$p->nomor}) ditolak: {$p->alasan_tolak}"];
        (new NotificationService)->kirim($akun->map(fn ($id) => [
            'id_pengguna' => (int) $id, 'judul' => $judul, 'pesan' => $pesan,
            'jenis' => self::KABAR_PENARIKAN, 'ref_jenis' => 'PenarikanMitraHasil', 'ref_id' => (string) $p->id,
        ])->all());
    }

    /**
     * B — tanyakan daftar pencairan ke ERP Al Wafi; simpan yang belum diterima ke cache dan kabari yang BARU.
     *
     * @return array{menunggu:int, dikabarkan:int}
     */
    public function cekPencairan(KlienDompetToko $klien): array
    {
        try {
            $daftar = collect($klien->daftarPencairan());
        } catch (AppException) {
            // ERP Al Wafi tak terjangkau: angka lama di cache dibiarkan, dicoba lagi 15 menit lagi.
            return ['menunggu' => count(self::pencairanMenunggu()), 'dikabarkan' => 0];
        }
        $diterima = PenerimaanAlWafi::pluck('nomor_pencairan')->flip();
        $menunggu = $daftar->filter(fn ($p) => ($p['status'] ?? '') === 'aktif' && ! isset($diterima[$p['nomor']]))
            ->mapWithKeys(fn ($p) => [(string) $p['nomor'] => (string) $p['total']])->all();
        Cache::put(self::CACHE_PENCAIRAN, $menunggu, now()->addDay());

        $sudah = Notification::where('jenis', self::TUGAS_PENCAIRAN)->whereIn('ref_id', array_keys($menunggu))->pluck('ref_id')->flip();
        $baru = array_diff_key($menunggu, $sudah->all());
        if ($baru === []) {
            return ['menunggu' => count($menunggu), 'dikabarkan' => 0];
        }
        $penerima = self::penerima('penerimaan-al-wafi', 'buat');
        $isi = [];
        foreach ($baru as $nomor => $total) {
            foreach ($penerima as $id) {
                $isi[] = [
                    'id_pengguna' => $id, 'judul' => 'Pencairan dari Al Wafi masuk',
                    'pesan' => "Al Wafi mencairkan belanja kartu santri Rp ".number_format((float) $total, 0, ',', '.')." ({$nomor}). Periksa rekening, lalu catat penerimaannya.",
                    'jenis' => self::TUGAS_PENCAIRAN, 'ref_jenis' => 'PencairanAlWafi', 'ref_id' => (string) $nomor,
                ];
            }
        }
        (new NotificationService)->kirim($isi);

        return ['menunggu' => count($menunggu), 'dikabarkan' => count($baru)];
    }

    /** @return array<string,string> nomor => total (dari pemeriksaan terakhir, dikurangi yang sudah diterima sejak itu) */
    public static function pencairanMenunggu(): array
    {
        $cache = Cache::get(self::CACHE_PENCAIRAN, []);
        if (! is_array($cache) || $cache === []) {
            return [];
        }

        return array_diff_key($cache, PenerimaanAlWafi::whereIn('nomor_pencairan', array_keys($cache))->pluck('nomor_pencairan')->flip()->all());
    }

    /** Kabar penarikan yang belum dibaca akun mitra ini (titik di menu Saldo). */
    public static function kabarMitraBelumDibaca(int $idPengguna): int
    {
        return Notification::where('id_pengguna', $idPengguna)->where('jenis', self::KABAR_PENARIKAN)->where('dibaca', false)->count();
    }
}
