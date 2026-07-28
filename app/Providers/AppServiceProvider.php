<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\NotificationService;
use App\Services\Modules\PengajuanPembayaranService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->daftarApprovalPengajuan();

        // @rp(nilai) → "Rp 1.234.567" (nilai boleh string desimal "1234567.00").
        // Dibungkus <span class="rp"> + spasi tak-putus: "Rp" tak pernah pindah
        // baris meninggalkan angkanya, dan digit-nya tabular agar kolom lurus.
        Blade::directive('rp', fn ($expr) => "<?php echo '<span class=\"rp\">Rp&nbsp;' . number_format((float) ($expr), 0, ',', '.') . '</span>'; ?>");
    }

    /**
     * Sambungkan efek approval untuk Pengajuan Pembayaran ke mesin approval.
     * Pengajuan SENGAJA tidak memakai daftarHandler (posting menunggu verifikasi
     * keuangan, bukan rantai selesai) — lihat PengajuanPembayaranService.
     */
    private function daftarApprovalPengajuan(): void
    {
        $sumber = PengajuanPembayaranService::SUMBER;

        // Rantai selesai → beri tahu tim keuangan agar memverifikasi.
        ApprovalService::daftarSelesai($sumber, function (string $idDokumen) use ($sumber) {
            $rec = \App\Models\PengajuanPembayaran::find((int) $idDokumen);
            if (! $rec) {
                return;
            }
            $keuangan = User::where('tim_keuangan', true)->where('status', 'aktif')->get(['id_pengguna']);
            (new NotificationService)->kirim($keuangan->map(fn ($u) => [
                'id_pengguna' => $u->id_pengguna,
                'judul' => 'Pengajuan menunggu verifikasi keuangan',
                'pesan' => "{$rec->nomor} sudah disetujui seluruh approver. Tetapkan akun hutang & verifikasi agar biayanya diakui.",
                'jenis' => 'verifikasi_menunggu',
                'ref_jenis' => $sumber,
                'ref_id' => (string) $rec->id,
            ])->all());
        });

        // Ditolak → dokumen ikut ditolak (melepas komitmen anggaran).
        ApprovalService::daftarPenolakan($sumber, function (string $idDokumen) {
            (new PengajuanPembayaranService)->applyRejected($idDokumen);
        });
    }
}
