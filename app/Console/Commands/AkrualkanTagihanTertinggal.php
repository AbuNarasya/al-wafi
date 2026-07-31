<?php

namespace App\Console\Commands;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Modules\SantriService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Akrualkan uang pangkal / perlengkapan yang TERTINGGAL tanpa jurnal.
 *
 * Latar: `PendaftaranLanjutanService::eksekusi()` dulu menerbitkan tagihan
 * kenaikan jenjang tanpa mengakrualkannya — hanya jalur daftar ulang yang
 * mengakru. Akibatnya santri yang naik jenjang punya kewajiban yang nyata
 * (jenjangnya sudah berubah, SPP jenjang barunya sudah ditagih) tetapi
 * piutangnya tak pernah muncul di buku besar.
 *
 * Servicenya sudah dibetulkan. Perintah ini membereskan baris yang telanjur ada.
 *
 * SENGAJA berupa perintah, BUKAN migrasi: yang diterbitkan di sini adalah
 * JURNAL, dan tanggal jurnal menentukan periode buku besarnya. Itu keputusan
 * keuangan, bukan sesuatu yang boleh diambil diam-diam oleh `artisan migrate`
 * pada tanggal kapan pun deploy kebetulan berjalan.
 */
class AkrualkanTagihanTertinggal extends Command
{
    protected $signature = 'tagihan:akrualkan-tertinggal
        {--pengguna= : id_pengguna yang tercatat sebagai pemosting (bawaan: admin aktif pertama)}
        {--terapkan : Benar-benar posting. Tanpa ini hanya menampilkan rencananya}';

    protected $description = 'Akrualkan uang pangkal/perlengkapan yang belum berjurnal pada santri AKTIF (sisa kenaikan jenjang lama).';

    /** Hanya dua komponen ini yang memang berpola cash-basis-lalu-akrual. */
    private const KOMPONEN = ['uang_pangkal', 'perlengkapan'];

    public function handle(): int
    {
        $idPengguna = (int) ($this->option('pengguna')
            ?: \App\Models\User::where('is_admin', true)->where('status', 'aktif')->value('id_pengguna'));
        if (! $idPengguna) {
            $this->error('Tidak ada pengguna admin aktif. Sebutkan lewat --pengguna=<id>.');

            return self::FAILURE;
        }

        // Hanya santri AKTIF: yang masih calon memang BELUM waktunya diakru —
        // akrualnya nanti terjadi sendiri saat daftar ulang.
        $baris = TagihanSantri::with(['jenis', 'santri'])
            ->whereIn('perilaku', self::KOMPONEN)
            ->where('sudah_akrual', false)
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->whereHas('santri', fn ($q) => $q->where('status', 'aktif'))
            ->orderBy('id')->get();

        if ($baris->isEmpty()) {
            $this->info('Tidak ada tagihan tertinggal — semuanya sudah berjurnal.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'Santri', 'NIS', 'Komponen', 'Jenjang', 'T.A', 'Sisa'],
            $baris->map(fn ($t) => [
                $t->id, $t->santri?->nama, $t->santri?->nis, $t->perilaku,
                $t->kode_jenjang, $t->tahun_ajaran, $t->sisa,
            ])->all(),
        );
        $total = $baris->reduce(fn ($a, $t) => Money::add($a, $t->sisa), '0');
        $this->line("Total piutang yang akan diakui: {$total}");

        if (! $this->option('terapkan')) {
            $this->warn('Ini baru RENCANA. Jalankan ulang dengan --terapkan untuk benar-benar memposting jurnalnya.');

            return self::SUCCESS;
        }

        $svc = new SantriService;
        $berhasil = 0;
        $gagal = [];

        // Dikelompokkan per santri supaya keterangan jurnalnya menyebut satu nama,
        // dan supaya kegagalan satu santri tak menjatuhkan santri yang lain.
        foreach ($baris->groupBy('id_santri') as $idSantri => $milikSantri) {
            $santri = Santri::find($idSantri);
            try {
                DB::transaction(fn () => $svc->akrualkanTagihan(
                    $santri, $milikSantri->all(), $idPengguna, 'susulan kenaikan jenjang',
                ));
                $berhasil += $milikSantri->count();
            } catch (AppException $e) {
                $gagal[] = "{$santri?->nama}: ".$e->getMessage();
            }
        }

        $this->newLine();
        $this->info("{$berhasil} tagihan diakrualkan.");
        foreach ($gagal as $g) {
            $this->warn('  • '.$g);
        }

        return self::SUCCESS;
    }
}
