<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Asset;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aset tetap (port assets module dev): CRUD + jalankan depresiasi bulanan
 * (posting jurnal Debit beban / Kredit akumulasi untuk semua aset aktif).
 */
class AssetService
{
    public function nextKode(): string
    {
        $last = Asset::where('kode_aset', 'like', 'AST%')->orderByDesc('kode_aset')->value('kode_aset');
        $n = 1;
        if ($last && is_numeric($tail = substr($last, 3))) {
            $n = (int) $tail + 1;
        }

        return 'AST'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function bookValue(Asset $a): string
    {
        return Money::sub($a->harga_perolehan, $a->akumulasi_depresiasi);
    }

    /** Depresiasi 1 bulan (garis lurus / saldo menurun double-declining). */
    public function monthlyDepreciation(Asset $a): string
    {
        if ($a->umur_manfaat <= 0) {
            return '0';
        }
        if ($a->metode_depresiasi === 'garis_lurus') {
            return Money::div(Money::sub($a->harga_perolehan, $a->nilai_residu), (string) $a->umur_manfaat);
        }
        $rate = Money::div('2', (string) $a->umur_manfaat, 6);

        return Money::mul($this->bookValue($a), $rate);
    }

    /**
     * Jalankan depresiasi bulanan semua aset aktif: 1 jurnal (Debit beban,
     * Kredit akumulasi), update akumulasi_depresiasi tiap aset.
     *
     * @param  array{kode_coa_beban:string,kode_coa_akumulasi:string,kode_unit?:?string}  $input
     */
    public function runDepreciation(array $input, ?int $idPengguna): array
    {
        $beban = CoaDetail::find($input['kode_coa_beban']);
        if (! $beban) {
            throw new AppException(400, 'Akun beban depresiasi tidak ditemukan.');
        }
        $akumulasi = CoaDetail::find($input['kode_coa_akumulasi']);
        if (! $akumulasi) {
            throw new AppException(400, 'Akun akumulasi depresiasi tidak ditemukan.');
        }

        $now = Carbon::now();
        $periode = $now->format('Ym');
        $lines = [];
        $updates = [];

        foreach (Asset::where('status', 'aktif')->get() as $a) {
            $d = $this->monthlyDepreciation($a);
            $maxAllowed = Money::sub($this->bookValue($a), $a->nilai_residu);
            if (Money::gt($d, $maxAllowed)) {
                $d = $maxAllowed;
            }
            if (Money::lte($d, 0)) {
                continue;
            }
            $updates[] = ['kode_aset' => $a->kode_aset, 'akumulasi' => Money::add($a->akumulasi_depresiasi, $d)];
            $lines[] = ['kode_coa' => $input['kode_coa_beban'], 'nama_coa' => $beban->nama_coa, 'debet' => $d, 'kredit' => '0', 'keterangan' => "Beban depresiasi {$a->nama_aset} periode {$periode}"];
            $lines[] = ['kode_coa' => $input['kode_coa_akumulasi'], 'nama_coa' => $akumulasi->nama_coa, 'debet' => '0', 'kredit' => $d, 'keterangan' => "Akumulasi depresiasi {$a->nama_aset} periode {$periode}"];
        }

        if (! $lines) {
            throw new AppException(422, 'Tidak ada nilai depresiasi yang perlu dijurnal (semua aset aktif sudah mencapai nilai residu).');
        }

        return DB::transaction(function () use ($lines, $updates, $input, $now, $periode, $idPengguna) {
            $base = "DEPR-{$periode}-";
            $last = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
            $seq = 1;
            if ($last && is_numeric($tail = substr($last, strlen($base)))) {
                $seq = (int) $tail + 1;
            }
            $referensi = $base.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

            PostingService::postJournal([
                'referensi' => $referensi,
                'tanggal' => $now->toDateString(),
                'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => "Depresiasi bulanan periode {$periode}",
                'sumber_modul' => 'Depresiasi',
                'id_sumber' => $referensi,
                'id_pengguna' => $idPengguna,
                'lines' => $lines,
            ]);

            foreach ($updates as $u) {
                Asset::where('kode_aset', $u['kode_aset'])->update(['akumulasi_depresiasi' => $u['akumulasi']]);
            }

            return ['referensi' => $referensi, 'jumlah_aset' => count($updates)];
        });
    }
}
