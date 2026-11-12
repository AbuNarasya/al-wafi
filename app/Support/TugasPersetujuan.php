<?php

namespace App\Support;

use App\Models\BatchTagihan;
use App\Models\KebijakanKhusus;
use App\Models\KunjunganKlinik;
use App\Models\Laundry;
use App\Models\OpnamePersediaan;
use App\Models\PengajuanKeluar;
use App\Models\PengajuanTarikTabungan;
use App\Models\PerintahPembayaran;
use App\Models\Perizinan;
use App\Models\PermohonanBukaPeriode;
use App\Models\RekonsiliasiDompetToko;
use App\Models\Santri;
use App\Models\ShiftKasir;
use App\Models\TagihanLaundry;
use App\Models\TransferPersediaan;
use App\Models\User;
use App\Services\Modules\DokumenSantriService;
use App\Services\Modules\PengunduranDiriService;

/**
 * SUMBER TUGAS TAMBAHAN untuk "Persetujuan Saya" & angka di menu (keputusan user 12 Nov 2026: semua hal
 * yang menunggu keputusan/tindakan staf punya pintasan di Persetujuan Saya, dan angka di modulnya).
 *
 * Bentuk tiap baris sama dengan TugasSaya::sumber(): [url menu, jumlah, label, bidang, tautan?].
 * ATURANNYA HARUS SAMA dengan tombolnya: pemisahan tugas (penyusun ≠ pemutus) ikut dicerminkan, supaya
 * angka tak menawarkan pekerjaan yang akan ditolak saat diklik. Hak modul lewat Akses::boleh (sudah mati
 * sendiri di instans yang tak memakai modulnya); aturan PERAN (wali kelas, kepala sekolah) dijaga
 * Instans::modulHidup karena tak melewati Akses.
 */
final class TugasPersetujuan
{
    public const KEUANGAN = 'Keuangan';

    public const KESANTRIAN = 'Kesantrian';

    public const PPSB = 'PPSB & Berkas Santri';

    public const PERSEDIAAN = 'Persediaan & Wafi Mart';

    public const LAYANAN = 'Laundry & Klinik';

    /** Urutan tampil kelompok di Persetujuan Saya. */
    public const URUTAN = [self::KEUANGAN, self::KESANTRIAN, self::PPSB, self::PERSEDIAAN, self::LAYANAN];

    /** @return list<array{0:string,1:int,2:string,3:?string,4?:string}> */
    public static function sumber(User $user): array
    {
        return [...self::keuangan($user), ...self::kesantrian($user), ...self::ppsb(), ...self::persediaan($user), ...self::layanan()];
    }

    private static function keuangan(User $user): array
    {
        $me = $user->id_pengguna;
        $hasil = [];

        // Tarik tabungan dari portal wali — gerbangnya sama dengan top-up di menu yang sama.
        if (($user->is_admin || $user->tim_keuangan) && Akses::boleh('dompet', 'ubah')) {
            $hasil[] = ['/kesantrian/dompet', PengajuanTarikTabungan::where('status', 'menunggu')->count(), 'penarikan tabungan dari wali menunggu persetujuan', self::KEUANGAN];
        }
        // Perintah Pembayaran: otorisasi oleh orang lain (empat mata), lalu dibayar keuangan.
        if (Akses::boleh('otorisasi-pembayaran', 'ubah')) {
            $hasil[] = ['/perintah-pembayaran', PerintahPembayaran::where('status', 'menunggu')->where('disusun_oleh', '!=', $me)->count(),
                'perintah pembayaran menunggu otorisasi', self::KEUANGAN];
        }
        if (Akses::boleh('cash-out', 'buat')) {
            $hasil[] = ['/perintah-pembayaran', PerintahPembayaran::whereIn('status', ['diotorisasi', 'sebagian'])->count(),
                'perintah pembayaran siap dibayar', self::KEUANGAN];
        }
        // Batch Tagihan: berlapis — hak layar + hak modul sumbernya (BatchTagihanController::HAK).
        if (Akses::boleh('batch-tagihan', 'ubah')) {
            $modul = array_keys(array_filter(['spp' => ['spp', 'ubah'], 'daftar_ulang' => ['tagihan-massal', 'buat'],
                'tagihan_lain' => ['tagihan-lain', 'buat']], fn ($h) => Akses::boleh(...$h)));
            $hasil[] = ['/kesantrian/batch-tagihan', $modul === [] ? 0 : BatchTagihan::where('status', 'draft')->whereIn('modul', $modul)->count(),
                'batch tagihan menunggu otorisasi', self::KEUANGAN];
        }
        // Buka periode: dua orang — pemohon tak memutus permohonannya sendiri.
        if (Akses::boleh('buka-periode', 'ubah')) {
            $hasil[] = ['/period-close', PermohonanBukaPeriode::where('status', 'diajukan')->where('diajukan_oleh', '!=', $me)->count(),
                'permohonan buka periode menunggu persetujuan', self::KEUANGAN];
        }

        return $hasil;
    }

    private static function kesantrian(User $user): array
    {
        $hasil = self::pengunduranDiri($user);

        if (Akses::boleh('kebijakan-khusus', 'ubah')) {
            $hasil[] = ['/kesantrian/kebijakan-khusus', KebijakanKhusus::where('status', 'diajukan')->count(), 'kebijakan khusus menunggu persetujuan', self::KESANTRIAN];
        }
        if (Akses::boleh('perizinan', 'ubah')) {
            $hasil[] = ['/kesantrian/perizinan/pos', Perizinan::where('status', 'keluar')->where('rencana_kembali', '<', now())->count(),
                'santri izin terlambat kembali', self::KESANTRIAN];
        }

        return $hasil;
    }

    /** Aturannya cermin PengunduranDiriController::perluTindakan, tapi dihitung per status (tanpa memuat semua baris). */
    private static function pengunduranDiri(User $user): array
    {
        if (! Instans::modulHidup('pengunduran-diri')) {
            return [];
        }
        $me = $user->id_pengguna;
        $url = '/kesantrian/pengunduran-diri';
        $tautan = $url.'?tab=tindakan';
        $hitung = fn (string $status) => PengajuanKeluar::where('status', $status);

        $hasil = [
            [$url, $hitung('menunggu_wali_kelas')->when(! $user->is_admin, fn ($q) => $q->whereHas('santri.kelas', fn ($k) => $k->where('id_wali_kelas', $me)))->count(),
                'pengunduran diri menunggu verifikasi wali kelas', self::KESANTRIAN, $tautan],
            [$url, $hitung('menunggu_kepala_sekolah')->when(! $user->is_admin, fn ($q) => $q->whereHas('santri.jenjang', fn ($j) => $j->where('id_kepala_sekolah', $me)))
                // Yang memverifikasi sebagai wali kelas tak memutus sebagai kepala sekolah — kecuali memang rangkap jabatan.
                ->where(fn ($q) => $q->whereNull('wali_kelas_oleh')->orWhere('wali_kelas_oleh', '!=', $me)
                    ->orWhere(fn ($r) => $r->whereHas('santri.kelas', fn ($k) => $k->where('id_wali_kelas', $me))
                        ->whereHas('santri.jenjang', fn ($j) => $j->where('id_kepala_sekolah', $me))))->count(),
                'pengunduran diri menunggu keputusan kepala sekolah', self::KESANTRIAN, $tautan],
        ];
        foreach (PengunduranDiriService::MODUL_CLEARANCE as $pihak => $modul) {
            if (Akses::boleh($modul, 'buat')) {
                $hasil[] = [$url, $hitung('clearance')->whereNull("{$pihak}_pada")->count(),
                    'pengunduran diri menunggu clearance '.(['admin' => 'admin sekolah'][$pihak] ?? $pihak), self::KESANTRIAN, $tautan];
            }
        }
        if (Akses::boleh('clearance-admin', 'ubah')) {
            $hasil[] = [$url, $hitung('siap_dikeluarkan')->count(), 'santri siap dikeluarkan (pengunduran diri)', self::KESANTRIAN, $url.'?tab=siap'];
        }

        return $hasil;
    }

    private static function ppsb(): array
    {
        $hasil = [];
        // Berkas unggahan wali yang belum diperiksa — dipisah calon & santri aktif karena daftarnya dua layar.
        if (Akses::boleh('dokumen-santri', 'buat')) {
            foreach (['calon' => ['/ppsb/calon-santri', 'calon santri'], 'aktif' => ['/kesantrian/santri', 'santri aktif']] as $lingkup => [$url, $nama]) {
                $hasil[] = [$url, DokumenSantriService::saringBerkasMenunggu(Santri::query()->lingkup($lingkup))->count(),
                    "{$nama} dengan berkas unggahan wali belum diperiksa", self::PPSB, $url.'?berkas=menunggu'];
            }
        }
        if (Akses::boleh('santri', 'ubah')) {
            foreach (['terbayar' => 'menunggu verifikasi berkas', 'terverifikasi' => 'menunggu seleksi',
                'diseleksi' => 'menunggu pengumuman', 'diterima' => 'menunggu med check'] as $status => $label) {
                $hasil[] = ['/ppsb/calon-santri', Santri::where('status', $status)->count(), "calon santri {$label}", self::PPSB, '/ppsb/calon-santri?status='.$status];
            }
        }

        return $hasil;
    }

    private static function persediaan(User $user): array
    {
        $me = $user->id_pengguna;
        $hasil = [];

        if (Akses::boleh('transfer-persediaan', 'ubah')) {
            $hasil[] = ['/transfer-persediaan', TransferPersediaan::where('status', 'dikirim')->count(), 'transfer barang menunggu diterima', self::PERSEDIAAN];
        }
        if (Akses::boleh('persetujuan-persediaan', 'ubah')) {
            // Pencatat penerimaan / pembuat-pengaju opname tak memutus sendiri — kecuali admin (sama dengan servisnya).
            $hasil[] = ['/transfer-persediaan', TransferPersediaan::where('status', 'selisih')
                ->when(! $user->is_admin, fn ($q) => $q->where(fn ($w) => $w->whereNull('diterima_oleh')->orWhere('diterima_oleh', '!=', $me)))->count(),
                'selisih transfer barang menunggu diputus', self::PERSEDIAAN];
            $hasil[] = ['/opname-persediaan', OpnamePersediaan::where('status', 'diajukan')
                ->when(! $user->is_admin, fn ($q) => $q->where(fn ($w) => $w->whereNull('diajukan_oleh')->orWhere('diajukan_oleh', '!=', $me))
                    ->where(fn ($w) => $w->whereNull('dibuat_oleh')->orWhere('dibuat_oleh', '!=', $me)))->count(),
                'stok opname menunggu persetujuan', self::PERSEDIAAN];
        }
        if (Akses::boleh('shift-kasir', 'ubah')) {
            $hasil[] = ['/shift-kasir', ShiftKasir::where('status', 'tutup')->when(! $user->is_admin, fn ($q) => $q->where('id_kasir', '!=', $me))->count(),
                'setoran shift kasir menunggu diterima', self::PERSEDIAAN];
            $hasil[] = ['/shift-kasir', ShiftKasir::where('status', 'buka')->whereDate('dibuka_pada', '<', today())->count(),
                'shift kasir hari sebelumnya belum ditutup', self::PERSEDIAAN];
        }
        if (Akses::boleh('rekonsiliasi-kartu', 'ubah')) {
            $hasil[] = ['/rekonsiliasi-kartu', RekonsiliasiDompetToko::where('status', 'gagal')->count(), 'rekonsiliasi kartu gagal dijalankan', self::PERSEDIAAN];
        }

        return $hasil;
    }

    private static function layanan(): array
    {
        $hasil = [];
        if (($jk = BagianLaundry::jkBoleh('ubah')) !== []) {
            $hasil[] = ['/laundry/pemeriksaan', Laundry::where('status', 'diterima')->whereHas('santri', fn ($s) => $s->whereIn('jenis_kelamin', $jk))->count(),
                'kantong laundry menunggu diperiksa', self::LAYANAN];
        }
        if (Akses::boleh('laundry-tagihan', 'buat')) {
            $hasil[] = ['/laundry/tagihan', TagihanLaundry::where('status', 'draf')->count(), 'draf tagihan laundry menunggu diterbitkan', self::LAYANAN];
        }
        if (Akses::boleh('klinik', 'ubah')) {
            $hasil[] = ['/klinik', KunjunganKlinik::where('status', 'dirawat')->count(), 'santri sedang dirawat di klinik', self::LAYANAN];
        }

        return $hasil;
    }
}
