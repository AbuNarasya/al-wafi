<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Models\OperationalAdvance;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Uang Muka Belanja Operasional. create → jurnal Debit Uang Muka / Kredit
 * Kas. registerOutstanding menambahkan uang muka ke POOL tanpa jurnal (jurnalnya
 * milik Kas Keluar saat melunasi pengajuan uang muka). applySettlement mengurangi
 * outstanding saat diselesaikan.
 */
class OperationalAdvanceService
{
    private const SUMBER = 'UangMukaOperasional';

    public function list(array $f = [])
    {
        return OperationalAdvance::query()
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')->get();
    }

    /** Uang muka yang masih outstanding (sisa > 0). */
    public function listOutstanding()
    {
        return OperationalAdvance::where('status', 'outstanding')->orderByDesc('id')->get()
            ->filter(fn ($r) => Money::gtZero($r->sisa))->values();
    }

    /** Jurnal: Debit akun uang muka, Kredit kas/rekening sumber. */
    public function create(array $input, ?int $idPengguna): OperationalAdvance
    {
        $um = CoaDetail::find($input['kode_coa_uang_muka']);
        if (! $um) {
            throw new AppException(400, 'Akun uang muka tidak ditemukan.');
        }
        $rek = BankAccount::with('coa')->find($input['kode_rekening']);
        if (! $rek) {
            throw new AppException(400, 'Kas/Rekening tidak ditemukan.');
        }

        $nominal = Money::of($input['nominal']);

        return DB::transaction(function () use ($input, $idPengguna, $um, $rek, $nominal) {
            $ref = DocNumber::nextJournalRef('UMB', $input['tanggal']);
            $rec = OperationalAdvance::create([
                'nomor_ref' => $ref, 'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'kode_rekening' => $input['kode_rekening'], 'kode_coa_uang_muka' => $um->kode_coa,
                'nama_coa_uang_muka' => $um->nama_coa, 'penerima' => $input['penerima'] ?? null,
                'keterangan' => $input['keterangan'], 'nominal' => $nominal, 'nominal_diselesaikan' => '0',
                'status' => 'outstanding', 'id_pengguna' => $idPengguna,
            ]);
            PostingService::postJournal([
                'referensi' => $ref, 'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => "Uang muka belanja operasional — {$input['keterangan']}",
                'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $rec->id, 'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $um->kode_coa, 'nama_coa' => $um->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $input['keterangan'], 'kode_bagian' => $input['kode_bagian'] ?? null],
                    ['kode_coa' => $rek->kode_coa, 'nama_coa' => $rek->coa->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => $input['keterangan']],
                ],
            ]);

            return $rec;
        });
    }

    /**
     * Daftarkan uang muka outstanding TANPA jurnal (jurnalnya milik Kas Keluar).
     * Nomor UMP- (Uang Muka Pengajuan) terpisah dari UMB agar tak bertabrakan.
     * id_pengguna diisi PEMOHON pengajuan.
     */
    public function registerOutstanding(array $data): OperationalAdvance
    {
        $c = Carbon::parse($data['tanggal']);
        $base = 'UMP-'.substr((string) $c->year, 2).str_pad((string) $c->month, 2, '0', STR_PAD_LEFT).'-';
        $last = OperationalAdvance::where('nomor_ref', 'like', $base.'%')->orderByDesc('nomor_ref')->value('nomor_ref');
        $ref = DocNumber::nextDocNumber($base, $last);

        return OperationalAdvance::create([
            'nomor_ref' => $ref, 'tanggal' => $data['tanggal'], 'kode_unit' => $data['kode_unit'] ?? null,
            'kode_rekening' => $data['kode_rekening'], 'kode_coa_uang_muka' => $data['kode_coa_uang_muka'],
            'nama_coa_uang_muka' => $data['nama_coa_uang_muka'], 'penerima' => $data['penerima'] ?? null,
            'keterangan' => $data['keterangan'], 'nominal' => Money::of($data['nominal']), 'nominal_diselesaikan' => '0',
            'status' => 'outstanding', 'id_pengguna' => $data['id_pengguna'] ?? null,
            'id_pengajuan_sumber' => $data['id_pengajuan_sumber'] ?? null,
        ]);
    }

    /** Balik penyelesaian (saat void penyelesaian): kurangi diselesaikan & buka outstanding. */
    public function reverseSettlement(int $idUangMuka, string $nominal): void
    {
        $adv = OperationalAdvance::find($idUangMuka);
        if (! $adv) {
            return;
        }
        $baru = Money::sub($adv->nominal_diselesaikan, $nominal);
        if (Money::isNegative($baru)) {
            $baru = '0';
        }
        $adv->update([
            'nominal_diselesaikan' => $baru,
            'status' => $adv->status === 'void' ? 'void' : (Money::gte($baru, $adv->nominal) ? 'selesai' : 'outstanding'),
        ]);
    }

    /** Catat penyelesaian sebagian/penuh: naikkan diselesaikan & tutup bila lunas. */
    public function applySettlement(int $idUangMuka, string $nominal): OperationalAdvance
    {
        $adv = OperationalAdvance::find($idUangMuka);
        if (! $adv) {
            throw new AppException(400, 'Uang muka tidak ditemukan.');
        }
        if ($adv->status === 'void') {
            throw new AppException(409, 'Uang muka sudah di-void.');
        }
        $sisa = Money::sub($adv->nominal, $adv->nominal_diselesaikan);
        if (Money::gt($nominal, $sisa)) {
            throw new AppException(400, "Nominal uang muka melebihi sisa outstanding ({$sisa}).");
        }
        $baru = Money::add($adv->nominal_diselesaikan, $nominal);
        $adv->update([
            'nominal_diselesaikan' => $baru,
            'status' => Money::gte($baru, $adv->nominal) ? 'selesai' : 'outstanding',
        ]);

        return $adv;
    }

    public function void(int $id, string $alasan, ?int $idPengguna, ?string $nama): OperationalAdvance
    {
        $adv = OperationalAdvance::find($id);
        if (! $adv) {
            throw new AppException(404, 'Uang muka tidak ditemukan.');
        }
        if ($adv->status === 'void') {
            throw new AppException(409, 'Uang muka sudah di-void.');
        }
        if (Money::gtZero($adv->nominal_diselesaikan)) {
            throw new AppException(409, 'Uang muka sudah sebagian/seluruhnya diselesaikan; batalkan penyelesaiannya dulu.');
        }

        return DB::transaction(function () use ($id, $adv, $alasan, $idPengguna, $nama) {
            Authorization::authorizeByUser($idPengguna, $adv->nominal);
            $entry = JournalEntry::where('sumber_modul', self::SUMBER)->where('id_sumber', (string) $id)->where('status', 'aktif')->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, ['id_pengguna' => $idPengguna, 'keteranganPrefix' => "Void UM ({$alasan}) — "]);
            }
            $adv->update(['status' => 'void', 'void_reason' => $alasan, 'void_by' => $nama, 'void_at' => Carbon::now()]);

            return $adv;
        });
    }
}
