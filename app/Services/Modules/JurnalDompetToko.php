<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\MutasiDompet;
use App\Models\TransaksiDompetToko;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * JURNAL RINGKAS BELANJA KARTU DI WAFI MART — tiap malam (keputusan user 20 Okt 2026).
 *
 * Satu jurnal per TANGGAL untuk seluruh transaksi penghubung yang belum dijurnal, sebelum hari ini:
 *   bersih = potong − kembalikan
 *   bersih > 0 : D Titipan Uang Saku / K Hutang ke Wafi Mart
 *   bersih < 0 : kebalikannya (pengembalian lebih besar dari belanja hari itu)
 * Mutasi dompetnya ditautkan ke jurnal itu. Hari ini SENGAJA belum: transaksinya masih berjalan.
 * Aman diulang: yang sudah dijurnal (`dijurnal_pada`) dilewati.
 */
class JurnalDompetToko
{
    /** @return list<array{tanggal:string, bersih:string, transaksi:int, jurnal:?int}> */
    public function jalankan(?string $sampaiSebelum = null): array
    {
        $batas = $sampaiSebelum ?? now()->toDateString();
        $tanggal = TransaksiDompetToko::whereNull('dijurnal_pada')->where('status', 'berhasil')
            ->whereDate('tanggal', '<', $batas)->distinct()->orderBy('tanggal')->pluck('tanggal')
            ->map(fn ($t) => $t->toDateString())->unique()->values();
        if ($tanggal->isEmpty()) {
            return [];
        }
        $hutang = CompanySettings::query()->value('kode_coa_hutang_toko')
            ?? throw new AppException(422, 'Akun "Hutang ke Wafi Mart" belum ditetapkan di Pengaturan Perusahaan.');

        return $tanggal->map(fn (string $tgl) => $this->satuHari($tgl, $hutang))->all();
    }

    private function satuHari(string $tanggal, string $hutang): array
    {
        return DB::transaction(function () use ($tanggal, $hutang) {
            $baris = TransaksiDompetToko::whereNull('dijurnal_pada')->where('status', 'berhasil')
                ->whereDate('tanggal', $tanggal)->lockForUpdate()->get();
            $bersih = '0';
            foreach ($baris as $t) {
                $bersih = $t->jenis === 'potong' ? Money::add($bersih, $t->nominal) : Money::sub($bersih, $t->nominal);
            }

            $jurnal = null;
            if (! Money::isZero($bersih)) {
                $nilai = Money::isNegative($bersih) ? Money::sub('0', $bersih) : $bersih;
                $titipan = DompetPolicy::COA_TITIPAN['santri'];
                [$debet, $kredit] = Money::isNegative($bersih) ? [$hutang, $titipan] : [$titipan, $hutang];
                $ref = 'DTK-'.str_replace('-', '', $tanggal);
                $jurnal = PostingService::postJournal([
                    'referensi' => $ref, 'tanggal' => $tanggal, 'sumber_modul' => TransaksiDompetToko::SUMBER, 'id_sumber' => $ref,
                    'keterangan' => "Belanja kartu santri di Wafi Mart {$tanggal} ({$baris->count()} transaksi, bersih)",
                    'lines' => [
                        ['kode_coa' => $debet, 'debet' => $nilai, 'kredit' => '0'],
                        ['kode_coa' => $kredit, 'debet' => '0', 'kredit' => $nilai],
                    ],
                ]);
                MutasiDompet::whereIn('id', $baris->pluck('id_mutasi_dompet')->filter())->update(['journal_entry_id' => $jurnal->id]);
            }
            TransaksiDompetToko::whereIn('id', $baris->pluck('id'))->update(['journal_entry_id' => $jurnal?->id, 'dijurnal_pada' => now()]);

            return ['tanggal' => $tanggal, 'bersih' => Money::of($bersih), 'transaksi' => $baris->count(), 'jurnal' => $jurnal?->id];
        });
    }
}
