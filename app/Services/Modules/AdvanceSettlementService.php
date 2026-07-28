<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\AdvanceSettlement;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\OperationalAdvance;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Penyelesaian Uang Muka (standalone). Jurnal: Kredit akun uang muka,
 * Debit akun realisasi; selisih via Kas/Bank. Mengurangi outstanding pool.
 */
class AdvanceSettlementService
{
    private const SUMBER = 'PenyelesaianUangMuka';

    public function list()
    {
        return AdvanceSettlement::with('rekening')->orderByDesc('id')->get();
    }

    public function create(array $input, ?int $idPengguna): AdvanceSettlement
    {
        $advance = OperationalAdvance::find($input['id_uang_muka']);
        if (! $advance) {
            throw new AppException(400, 'Uang muka operasional tidak ditemukan.');
        }
        if ($advance->status === 'void') {
            throw new AppException(409, 'Uang muka sudah di-void.');
        }
        $sisaUM = Money::sub($advance->nominal, $advance->nominal_diselesaikan);
        if (Money::gt($input['nominal_uang_muka'], $sisaUM)) {
            throw new AppException(400, "Nominal uang muka melebihi sisa outstanding ({$sisaUM}).");
        }
        $kodeCoaUm = $advance->kode_coa_uang_muka;
        $um = CoaDetail::find($kodeCoaUm);
        if (! $um) {
            throw new AppException(400, 'Akun uang muka tidak ditemukan.');
        }
        if ($kodeCoaUm === $input['kode_coa_realisasi']) {
            throw new AppException(400, 'Akun uang muka dan realisasi tidak boleh sama.');
        }
        $real = CoaDetail::find($input['kode_coa_realisasi']);
        if (! $real) {
            throw new AppException(400, 'Akun realisasi tidak ditemukan.');
        }
        $rek = BankAccount::with('coa')->find($input['kode_rekening']);
        if (! $rek) {
            throw new AppException(400, 'Kas/Rekening tidak ditemukan.');
        }

        $umN = Money::of($input['nominal_uang_muka']);
        $realN = Money::of($input['nominal_realisasi']);
        $diff = Money::sub($realN, $umN);

        $jLines = [
            ['kode_coa' => $kodeCoaUm, 'nama_coa' => $um->nama_coa, 'debet' => '0', 'kredit' => $umN, 'keterangan' => $input['keterangan']],
            ['kode_coa' => $input['kode_coa_realisasi'], 'nama_coa' => $real->nama_coa, 'debet' => $realN, 'kredit' => '0', 'keterangan' => $input['keterangan'], 'kode_bagian' => $input['kode_bagian'] ?? null],
        ];
        if (Money::gtZero($diff)) {
            $jLines[] = ['kode_coa' => $input['kode_rekening'], 'nama_coa' => $rek->coa->nama_coa, 'debet' => '0', 'kredit' => $diff, 'keterangan' => "Pembayaran selisih realisasi uang muka — {$input['keterangan']}"];
        } elseif (Money::isNegative($diff)) {
            $jLines[] = ['kode_coa' => $input['kode_rekening'], 'nama_coa' => $rek->coa->nama_coa, 'debet' => Money::sub('0', $diff), 'kredit' => '0', 'keterangan' => "Pengembalian sisa uang muka — {$input['keterangan']}"];
        }

        return DB::transaction(function () use ($input, $idPengguna, $kodeCoaUm, $um, $real, $umN, $realN, $jLines) {
            $c = Carbon::parse($input['tanggal']);
            $base = 'PUM-'.$c->year.str_pad((string) $c->month, 2, '0', STR_PAD_LEFT).'-';
            $last = AdvanceSettlement::where('nomor_referensi', 'like', $base.'%')->orderByDesc('nomor_referensi')->value('nomor_referensi');
            $seq = 1;
            if ($last) {
                $tail = substr($last, strlen($base));
                if (is_numeric($tail)) {
                    $seq = ((int) $tail) + 1;
                }
            }
            $ref = $base.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

            $rec = AdvanceSettlement::create([
                'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'kode_coa_uang_muka' => $kodeCoaUm, 'nama_coa_uang_muka' => $um->nama_coa, 'nominal_uang_muka' => $umN,
                'kode_coa_realisasi' => $input['kode_coa_realisasi'], 'nama_coa_realisasi' => $real->nama_coa,
                'nominal_realisasi' => $realN, 'kode_rekening' => $input['kode_rekening'], 'nomor_referensi' => $ref,
                'id_uang_muka' => $input['id_uang_muka'], 'keterangan' => $input['keterangan'], 'status' => 'aktif',
                'id_pengguna' => $idPengguna ?? 0,
            ]);

            PostingService::postJournal([
                'referensi' => $ref, 'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => $input['keterangan'], 'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $rec->id,
                'id_pengguna' => $idPengguna, 'lines' => $jLines,
            ]);

            (new OperationalAdvanceService)->applySettlement($input['id_uang_muka'], $umN);

            return $rec;
        });
    }
}
