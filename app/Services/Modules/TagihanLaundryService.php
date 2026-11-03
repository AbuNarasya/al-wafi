<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Laundry;
use App\Models\Santri;
use App\Models\TagihanLaundry;
use App\Models\TagihanSantri;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LAUNDRY tahap 2 — kuota bulanan & tagihan kelebihan (keputusan user 26 Okt 2026).
 *
 *  • Pemakaian = jumlah berat kantong yang DITERIMA dalam bulan kalender itu (kecuali dibatalkan). Kuota tak punya
 *    saldo yang di-reset: tiap bulan dihitung dari awal, jadi sisa memang tak terbawa dan tak ada tombol reset.
 *  • Kuota = MATRIKS jenjang × ikhwan/akhwat (3 Nov 2026); sel kosong = kuota bawaan bagian itu.
 *  • Tanggal 1: draf disusun untuk bulan lalu. Keuangan meninjau (kecualikan / susun ulang). Pada `hari_terbit`
 *    draf terbit otomatis lewat TagihanLainService — jurnal, portal, VA & auto-debet ikut seperti tagihan lain.
 *  • Bulan yang sudah ada tagihannya TERKUNCI: kantong di bulan itu tak bisa dikoreksi beratnya / dibatalkan.
 */
class TagihanLaundryService
{
    /** @return object{tarif_per_kg:?string, kuota_ikhwan_kg:string, kuota_akhwat_kg:string, kode_jenis_bawaan:?string, hari_terbit:int, terbit_otomatis:bool, diubah_oleh:?int} */
    public function pengaturan(): object
    {
        return DB::table('pengaturan_laundry')->where('id', 1)->first()
            ?? (object) ['tarif_per_kg' => null, 'kuota_ikhwan_kg' => '20', 'kuota_akhwat_kg' => '20', 'kode_jenis_bawaan' => null, 'hari_terbit' => 5, 'terbit_otomatis' => true, 'diubah_oleh' => null, 'tahan_setelah_periode' => 0];
    }

    /** @return Collection<string,object> kode_jenjang → {kuota_ikhwan_kg, kuota_akhwat_kg, kode_jenis} */
    public function pengaturanJenjang(): Collection
    {
        return DB::table('pengaturan_laundry_jenjang')->get()->keyBy('kode_jenjang');
    }

    /**
     * Kuota satu sel matriks: (jenjang, jenis kelamin santri). Sel kosong → kuota bawaan bagian itu. Jenis kelamin
     * kosong dianggap ikhwan (santri seperti itu tak bisa menyetor laundry juga — lihat LaundryService::awalanNomor).
     */
    public function kuota(?string $kodeJenjang, ?string $jenisKelamin = null): string
    {
        $kolom = 'kuota_'.(\App\Support\BagianLaundry::dariJk($jenisKelamin) ?? 'ikhwan').'_kg';
        $j = $kodeJenjang ? $this->pengaturanJenjang()->get($kodeJenjang) : null;

        return Money::of($j?->{$kolom} ?? $this->pengaturan()->{$kolom});
    }

    public function jenisUntuk(?string $kodeJenjang): ?string
    {
        $j = $kodeJenjang ? $this->pengaturanJenjang()->get($kodeJenjang) : null;

        return $j?->kode_jenis ?: $this->pengaturan()->kode_jenis_bawaan;
    }

    /** @return array{0:Carbon,1:Carbon} awal & akhir bulan `YYYY-MM` (zona aplikasi) */
    public static function batas(string $periode): array
    {
        $awal = Carbon::createFromFormat('Y-m-d H:i:s', "{$periode}-01 00:00:00");

        return [$awal, $awal->copy()->endOfMonth()];
    }

    public function terpakai(int $idSantri, string $periode): string
    {
        [$awal, $akhir] = self::batas($periode);

        return Money::of(Laundry::where('id_santri', $idSantri)->where('status', '!=', 'dibatalkan')
            ->whereBetween('diterima_pada', [$awal, $akhir])->sum('berat'));
    }

    /**
     * Ringkasan kuota satu santri (layar penerimaan & portal wali). `$tambahan` = berat yang sedang ditimbang.
     *
     * @return array{periode:string, kuota:string, terpakai:string, sisa:string, lebih:string, tarif:?string, perkiraan:?string}
     */
    public function ringkas(Santri $santri, ?string $periode = null, string|float $tambahan = 0): array
    {
        $periode ??= now()->format('Y-m');
        $kuota = $this->kuota($santri->kode_jenjang, $santri->jenis_kelamin);
        $terpakai = Money::add($this->terpakai($santri->id, $periode), Money::of($tambahan));
        $lebih = Money::gt($terpakai, $kuota) ? Money::sub($terpakai, $kuota) : '0.00';
        $tarif = $this->pengaturan()->tarif_per_kg;

        return ['periode' => $periode, 'kuota' => $kuota, 'terpakai' => $terpakai,
            'sisa' => Money::gt($kuota, $terpakai) ? Money::sub($kuota, $terpakai) : '0.00', 'lebih' => $lebih,
            'tarif' => $tarif !== null ? Money::of($tarif) : null,
            'perkiraan' => $tarif !== null ? self::nominal($lebih, $tarif) : null];
    }

    /** kg × tarif, dibulatkan ke rupiah penuh. */
    public static function nominal(string $kg, string $tarif): string
    {
        return Money::of(bcadd(bcmul(Money::of($kg), Money::of($tarif), 4), '0.5', 0));
    }

    /** Bulan yang sudah punya tagihan terbit untuk santri ini → kantongnya terkunci. */
    public function terkunci(int $idSantri, string $periode): bool
    {
        return TagihanLaundry::where('id_santri', $idSantri)->where('periode', $periode)->where('status', 'terbit')->exists();
    }

    /**
     * Susun (ulang) draf bulan `$periode`: santri yang pemakaiannya melebihi kuota. Baris `terbit` & `dikecualikan`
     * tak disentuh; draf yang kini tak lagi berlebih dihapus.
     *
     * @return array{draf:int, dihapus:int}
     */
    public function susunDraf(string $periode): array
    {
        $this->assertPeriodeLewat($periode);
        $tarif = $this->pengaturan()->tarif_per_kg ?? throw new AppException(422, 'Tarif kelebihan per kg belum diatur — isi dulu di Pengaturan Laundry.');
        [$awal, $akhir] = self::batas($periode);

        return DB::transaction(function () use ($periode, $tarif, $awal, $akhir) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32("tagihan-laundry:{$periode}")]);
            $pakai = Laundry::where('status', '!=', 'dibatalkan')->whereBetween('diterima_pada', [$awal, $akhir])
                ->selectRaw('id_santri, sum(berat) as kg')->groupBy('id_santri')->pluck('kg', 'id_santri');
            $santri = Santri::whereIn('id', $pakai->keys())->get(['id', 'kode_jenjang', 'jenis_kelamin'])->keyBy('id');
            $tetap = TagihanLaundry::where('periode', $periode)->whereIn('status', ['terbit', 'dikecualikan'])->pluck('id_santri')->flip();

            $draf = 0;
            $masihLebih = [];
            foreach ($pakai as $id => $kg) {
                if (isset($tetap[$id]) || ! isset($santri[$id])) {
                    continue;
                }
                $jenjang = $santri[$id]->kode_jenjang;
                $kuota = $this->kuota($jenjang, $santri[$id]->jenis_kelamin);
                $kg = Money::of($kg);
                if (! Money::gt($kg, $kuota)) {
                    continue;
                }
                $lebih = Money::sub($kg, $kuota);
                TagihanLaundry::updateOrCreate(['periode' => $periode, 'id_santri' => $id], [
                    'kode_jenjang' => $jenjang, 'kg_total' => $kg, 'kuota_kg' => $kuota, 'kg_lebih' => $lebih,
                    'tarif_per_kg' => Money::of($tarif), 'nominal' => self::nominal($lebih, $tarif),
                    'kode_jenis' => $this->jenisUntuk($jenjang), 'status' => 'draf',
                ]);
                $masihLebih[] = $id;
                $draf++;
            }
            $dihapus = TagihanLaundry::where('periode', $periode)->where('status', 'draf')->whereNotIn('id_santri', $masihLebih)->delete();

            return ['draf' => $draf, 'dihapus' => $dihapus];
        });
    }

    public function kecualikan(TagihanLaundry $t, string $alasan, int $idPengguna): void
    {
        if ($t->status !== 'draf') {
            throw new AppException(422, 'Hanya draf yang bisa dikecualikan.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan pengecualian.');
        }
        $t->update(['status' => 'dikecualikan', 'alasan_kecuali' => trim($alasan), 'dikecualikan_oleh' => $idPengguna]);
        Jejak::catat('kecualikan_tagihan_laundry', ['modul' => 'laundry-tagihan', 'ref_jenis' => 'TagihanLaundry', 'ref_id' => $t->id,
            'id_pengguna' => $idPengguna, 'detail' => ['periode' => $t->periode, 'id_santri' => $t->id_santri, 'nominal' => $t->nominal, 'alasan' => $alasan]]);
    }

    /** Pengecualian ditarik → kembali jadi draf (dihitung ulang saat susun berikutnya). */
    public function batalKecuali(TagihanLaundry $t): void
    {
        if ($t->status !== 'dikecualikan') {
            throw new AppException(422, 'Baris ini tidak sedang dikecualikan.');
        }
        $t->update(['status' => 'draf', 'alasan_kecuali' => null, 'dikecualikan_oleh' => null]);
    }

    /**
     * Terbitkan seluruh draf `$periode`: draf disusun ulang dulu (angka terbaru), lalu dikelompokkan per jenis biaya
     * dan diterbitkan lewat TagihanLainService. Draf tanpa jenis biaya → seluruh penerbitan ditolak dengan pesan.
     *
     * @return array{terbit:int, total:string}
     */
    public function terbitkan(string $periode, int $idPengguna): array
    {
        $this->susunDraf($periode);
        $draf = TagihanLaundry::with('santri:id,nama,status')->where('periode', $periode)->where('status', 'draf')->get();
        if ($draf->isEmpty()) {
            return ['terbit' => 0, 'total' => '0.00'];
        }
        if ($tanpa = $draf->whereNull('kode_jenis')->first()) {
            throw new AppException(422, 'Jenis biaya penagihan laundry belum diatur (mis. untuk jenjang santri '.$tanpa->santri?->nama.') — atur di Pengaturan Laundry.');
        }
        $bulan = Carbon::createFromFormat('Y-m-d', "{$periode}-01")->locale('id')->translatedFormat('F Y');
        $terbit = 0;
        $total = '0';

        DB::transaction(function () use ($draf, $periode, $bulan, $idPengguna, &$terbit, &$total) {
            foreach ($draf->groupBy('kode_jenis') as $kode => $baris) {
                $aktif = $baris->filter(fn ($t) => $t->santri?->status === 'aktif');
                if ($aktif->isEmpty()) {
                    continue;
                }
                $jenis = JenisBiaya::find($kode) ?? throw new AppException(422, "Jenis biaya {$kode} tidak ditemukan.");
                (new TagihanLainService)->terbitkanSnapshot($jenis, $aktif->mapWithKeys(fn ($t) => [$t->id_santri => Money::of($t->nominal)])->all(), [
                    'tanggal' => now()->toDateString(), 'periode' => $periode, 'jatuh_tempo' => now()->addDays(14)->toDateString(),
                    'keterangan' => "Kelebihan kuota laundry {$bulan}",
                ], $idPengguna);
                $idTagihan = TagihanSantri::where('kode_jenis', $kode)->where('periode', $periode)
                    ->whereIn('id_santri', $aktif->pluck('id_santri'))->pluck('id', 'id_santri');
                foreach ($aktif as $t) {
                    $t->update(['status' => 'terbit', 'id_tagihan' => $idTagihan[$t->id_santri] ?? null, 'terbit_pada' => now(), 'terbit_oleh' => $idPengguna]);
                    $terbit++;
                    $total = Money::add($total, $t->nominal);
                }
            }
        });

        return ['terbit' => $terbit, 'total' => Money::of($total)];
    }

    /**
     * Jadwal harian (lihat routes/console.php): tanggal 1 menyusun draf bulan lalu; sejak `hari_terbit` — bila
     * terbit otomatis menyala — draf bulan lalu yang tersisa diterbitkan. Aman diulang.
     *
     * @return array{periode:string, disusun:?array, diterbitkan:?array, pesan:?string}
     */
    public function jalankanJadwal(?Carbon $hari = null): array
    {
        $hari ??= now();
        $periode = $hari->copy()->subMonthNoOverflow()->format('Y-m');
        $p = $this->pengaturan();
        $hasil = ['periode' => $periode, 'disusun' => null, 'diterbitkan' => null, 'pesan' => null];
        if ($p->tarif_per_kg === null) {
            $hasil['pesan'] = 'Tarif kelebihan per kg belum diatur.';

            return $hasil;
        }
        $hasil['disusun'] = $this->susunDraf($periode);
        if ($p->terbit_otomatis && $hari->day >= (int) $p->hari_terbit) {
            if (! $p->diubah_oleh) {
                $hasil['pesan'] = 'Terbit otomatis butuh pelaku jurnal — simpan ulang Pengaturan Laundry oleh staf keuangan.';

                return $hasil;
            }
            $hasil['diterbitkan'] = $this->terbitkan($periode, (int) $p->diubah_oleh);
        }

        return $hasil;
    }

    private function assertPeriodeLewat(string $periode): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
            throw new AppException(422, 'Periode tidak sah.');
        }
        if ($periode >= now()->format('Y-m')) {
            throw new AppException(422, 'Tagihan laundry hanya untuk bulan yang sudah lewat — bulan berjalan masih bisa bertambah.');
        }
    }
}
