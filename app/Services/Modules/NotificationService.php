<?php

namespace App\Services\Modules;

use App\Models\ApprovalInstance;
use App\Models\MutasiDompet;
use App\Models\Notification;
use App\Models\PembayaranSantri;
use App\Models\PengajuanPembayaran;

/**
 * Notifikasi dalam aplikasi. kirim() mengirim ke banyak penerima; duplikat
 * (id_pengguna|jenis|ref_id) dibuang.
 *
 * Isi lonceng dibagi dua, dan pembagian ini yang menentukan perilakunya:
 *  - TUGAS  → pekerjaan yang harus dikerjakan. Tak bisa didiamkan dengan
 *             "tandai dibaca"; hanya reda saat dokumennya benar-benar diproses.
 *  - KABAR  → pemberitahuan hasil (disetujui/ditolak/uang masuk). Boleh ditandai
 *             dibaca karena tak ada yang perlu dikerjakan.
 */
class NotificationService
{
    /** Jenis notifikasi yang berarti "ada yang harus Anda kerjakan". */
    public const JENIS_TUGAS = [
        'approval_menunggu',
        'verifikasi_menunggu',
        'pembayaran_santri_menunggu',
        'topup_dompet_menunggu',
    ];

    /**
     * @param  array<int,array{id_pengguna:int,judul:string,pesan:string,jenis:string,ref_jenis?:?string,ref_id?:?string}>  $items
     */
    public function kirim(array $items): array
    {
        $unik = [];
        foreach ($items as $it) {
            $key = "{$it['id_pengguna']}|{$it['jenis']}|".($it['ref_id'] ?? '');
            $unik[$key] = $it;
        }
        if (count($unik) === 0) {
            return ['terkirim' => 0];
        }
        $now = now();
        Notification::insert(array_map(fn ($i) => [
            'id_pengguna' => $i['id_pengguna'],
            'judul' => $i['judul'],
            'pesan' => $i['pesan'],
            'jenis' => $i['jenis'],
            'ref_jenis' => $i['ref_jenis'] ?? null,
            'ref_id' => $i['ref_id'] ?? null,
            'dibaca' => false,
            'created_at' => $now,
        ], array_values($unik)));

        return ['terkirim' => count($unik)];
    }

    public function list(int $idPengguna, bool $hanyaBelumDibaca = false)
    {
        return Notification::where('id_pengguna', $idPengguna)
            ->when($hanyaBelumDibaca, fn ($q) => $q->where('dibaca', false))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    public function hitungBelumDibaca(int $idPengguna): int
    {
        return Notification::where('id_pengguna', $idPengguna)->where('dibaca', false)->count();
    }

    /**
     * Kabar (bukan tugas) yang belum dibaca — dipakai lonceng di header. Sengaja
     * TIDAK memuat jenis tugas: jumlah tugas datang dari TugasSaya yang membaca
     * keadaan nyata, bukan dari baris notifikasi yang bisa basi.
     */
    public function kabarBelumDibaca(int $idPengguna, ?int $limit = null)
    {
        return Notification::where('id_pengguna', $idPengguna)
            ->whereNotIn('jenis', self::JENIS_TUGAS)
            ->where('dibaca', false)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();
    }

    public function hitungKabarBelumDibaca(int $idPengguna): int
    {
        return Notification::where('id_pengguna', $idPengguna)
            ->whereNotIn('jenis', self::JENIS_TUGAS)
            ->where('dibaca', false)->count();
    }

    /**
     * Menandai dibaca — HANYA untuk kabar. Notifikasi tugas sengaja ditolak:
     * penandanya harus bertahan sampai dokumennya diproses, bukan sampai
     * dilihat. (Baris tugas yang pekerjaannya sudah selesai dibereskan sendiri
     * oleh rapikan(), jadi ia tetap tak akan menggantung selamanya.)
     */
    public function tandaiDibaca(int $id, int $idPengguna): void
    {
        Notification::where('id', $id)->where('id_pengguna', $idPengguna)
            ->whereNotIn('jenis', self::JENIS_TUGAS)
            ->update(['dibaca' => true]);
    }

    public function tandaiSemuaDibaca(int $idPengguna): array
    {
        $n = Notification::where('id_pengguna', $idPengguna)->where('dibaca', false)
            ->whereNotIn('jenis', self::JENIS_TUGAS)
            ->update(['dibaca' => true]);

        return ['ditandai' => $n];
    }

    /**
     * Isi lonceng & halaman notifikasi: kabar terbaru + tugas yang MASIH nyata.
     *
     * @return array{tugas:\Illuminate\Support\Collection,kabar:\Illuminate\Support\Collection,belum_dibaca:int}
     */
    public function feed(int $idPengguna): array
    {
        $this->rapikan($idPengguna);

        $rows = Notification::where('id_pengguna', $idPengguna)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        [$tugas, $kabar] = $rows->partition(fn ($n) => in_array($n->jenis, self::JENIS_TUGAS, true));

        return [
            // Tugas yang sudah dibaca = sudah diproses (lihat rapikan) → tak perlu tampil.
            'tugas' => $tugas->where('dibaca', false)->values(),
            'kabar' => $kabar->values(),
            'belum_dibaca' => $kabar->where('dibaca', false)->count(),
        ];
    }

    /**
     * Menyembuhkan notifikasi tugas yang sudah kedaluwarsa: dokumennya sudah
     * diproses, tetapi barisnya masih berbunyi "menunggu". Tanpa ini, notifikasi
     * lama menyuruh mengerjakan sesuatu yang sudah selesai — dan menyuruhnya
     * selamanya, karena baris notifikasi tak pernah ikut berubah.
     *
     * @return int jumlah baris yang dibereskan
     */
    public function rapikan(int $idPengguna): int
    {
        $tugas = Notification::where('id_pengguna', $idPengguna)
            ->where('dibaca', false)->whereIn('jenis', self::JENIS_TUGAS)->get();

        $selesai = $tugas->reject(fn ($n) => $this->masihMenunggu($n))->pluck('id')->all();
        if ($selesai === []) {
            return 0;
        }

        return Notification::whereIn('id', $selesai)->update(['dibaca' => true]);
    }

    /**
     * Tujuan klik sebuah notifikasi: halaman tempat dokumennya bisa dilihat /
     * dikerjakan. Pembayaran santri dipisah dua lingkup (PPSB vs Kesantrian)
     * mengikuti tipe tagihannya — daftar id lingkup PPSB dikirim pemanggil agar
     * cukup satu query untuk seluruh isi lonceng.
     *
     * @param  list<int>  $idPembayaranPpsb
     */
    public function tautan(Notification $n, array $idPembayaranPpsb = []): ?string
    {
        return match ($n->ref_jenis) {
            'PembayaranSantri' => in_array((int) $n->ref_id, $idPembayaranPpsb, true)
                ? '/ppsb/pembayaran' : '/kesantrian/pembayaran',
            'MutasiDompet' => '/kesantrian/dompet',
            PengajuanPembayaranService::SUMBER => $n->jenis === 'approval_menunggu'
                ? '/approvals' : '/pengajuan-pembayaran/'.$n->ref_id,
            default => $n->jenis === 'approval_menunggu' ? '/approvals' : null,
        };
    }

    /**
     * Id pembayaran santri yang tagihannya bertipe PPSB (registrasi/uang pangkal)
     * di antara notifikasi yang akan ditampilkan — penentu tautan lingkup.
     *
     * @param  \Illuminate\Support\Collection<int,Notification>  $notifikasi
     * @return list<int>
     */
    public function idPembayaranPpsb($notifikasi): array
    {
        $ids = $notifikasi->where('ref_jenis', 'PembayaranSantri')->pluck('ref_id')
            ->filter()->map(fn ($v) => (int) $v)->unique()->all();
        if ($ids === []) {
            return [];
        }

        return PembayaranSantri::whereIn('id', $ids)
            ->whereHas('tagihan.jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kode('registrasi', 'uang_pangkal')))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Apakah dokumen yang dirujuk notifikasi tugas ini masih menunggu tindakan? */
    private function masihMenunggu(Notification $n): bool
    {
        if (! $n->ref_id) {
            return true; // tanpa rujukan tak bisa dipastikan → jangan diam-diam dibuang
        }

        return match ($n->jenis) {
            'approval_menunggu' => ApprovalInstance::where('jenis_dokumen', $n->ref_jenis)
                ->where('id_dokumen', $n->ref_id)->where('status', 'berjalan')->exists(),

            'verifikasi_menunggu' => PengajuanPembayaran::where('id', (int) $n->ref_id)
                ->where('status', 'diajukan')->exists()
                && ApprovalInstance::where('jenis_dokumen', $n->ref_jenis)->where('id_dokumen', $n->ref_id)
                    ->where('status', 'disetujui')->where('posted', false)->exists(),

            'pembayaran_santri_menunggu' => PembayaranSantri::where('id', (int) $n->ref_id)
                ->where('status', 'menunggu_verifikasi')->exists(),

            'topup_dompet_menunggu' => MutasiDompet::where('id', (int) $n->ref_id)
                ->where('status', 'menunggu_verifikasi')->exists(),

            default => true,
        };
    }
}
