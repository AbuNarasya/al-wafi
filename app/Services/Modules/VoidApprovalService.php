<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CashIn;
use App\Models\CashOut;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OperationalAdvance;
use App\Models\VoidApproval;
use App\Services\Ledger\Authorization;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Approval SATU-LANGKAH untuk VOID transaksi. Bila pemohon berwenang atas
 * nominalnya → langsung void. Jika tidak → masuk daftar pending untuk level
 * lebih tinggi. Dispatch ke service void modul terkait.
 */
class VoidApprovalService
{
    private const MODULES = ['KasMasuk', 'KasKeluar', 'Invoice', 'PindahBuku', 'UangMukaOperasional', 'JurnalUmum'];

    /** Nominal & referensi transaksi untuk otorisasi/tampilan. */
    private function getMeta(string $modul, int $id): array
    {
        switch ($modul) {
            case 'KasMasuk':
                $r = CashIn::find($id);
                if (! $r) {
                    throw new AppException(404, 'Kas Masuk tidak ditemukan.');
                }

                return ['nominal' => (string) $r->nominal, 'ref' => $r->nomor_transaksi];
            case 'KasKeluar':
                $r = CashOut::find($id);
                if (! $r) {
                    throw new AppException(404, 'Kas Keluar tidak ditemukan.');
                }

                return ['nominal' => (string) $r->nominal, 'ref' => $r->nomor_transaksi];
            case 'Invoice':
                $r = Invoice::find($id);
                if (! $r) {
                    throw new AppException(404, 'Invoice tidak ditemukan.');
                }

                return ['nominal' => (string) $r->total, 'ref' => $r->nomor_invoice];
            case 'UangMukaOperasional':
                $r = OperationalAdvance::find($id);
                if (! $r) {
                    throw new AppException(404, 'Uang muka tidak ditemukan.');
                }

                return ['nominal' => (string) $r->nominal, 'ref' => $r->nomor_ref];
            case 'PindahBuku':
            case 'JurnalUmum':
                $r = JournalEntry::with('lines')->find($id);
                if (! $r) {
                    throw new AppException(404, 'Jurnal tidak ditemukan.');
                }
                $nominal = $r->lines->reduce(fn ($s, $l) => Money::add($s, $l->debet), '0');

                return ['nominal' => $nominal, 'ref' => $r->referensi];
            default:
                throw new AppException(400, 'Modul tidak dikenal.');
        }
    }

    /** Jalankan void aktual di modul terkait, sebagai penyetuju yang berwenang. */
    private function dispatchVoid(string $modul, int $id, string $alasan, ?int $idPengguna, ?string $nama): void
    {
        switch ($modul) {
            case 'KasMasuk':
                (new CashInService)->void($id, ['alasan' => $alasan], $idPengguna, $nama);
                break;
            case 'KasKeluar':
                (new CashOutService)->void($id, ['alasan' => $alasan], $idPengguna, $nama);
                break;
            case 'Invoice':
                (new InvoiceService)->void($id, $alasan, $idPengguna);
                break;
            case 'PindahBuku':
                (new BookTransferService)->void($id, $alasan, $idPengguna);
                break;
            case 'UangMukaOperasional':
                (new OperationalAdvanceService)->void($id, $alasan, $idPengguna, $nama);
                break;
            case 'JurnalUmum':
                (new JournalService)->void($id, ['id_pengguna' => $idPengguna]);
                break;
        }
    }

    /** Ajukan void. Berwenang → langsung void; jika tidak → pending. */
    public function request(array $input): array
    {
        $modul = $input['modul'];
        if (! in_array($modul, self::MODULES, true)) {
            throw new AppException(400, 'Modul tidak dikenal.');
        }
        ['nominal' => $nominal, 'ref' => $ref] = $this->getMeta($modul, $input['id_record']);

        if (Authorization::canAuthorize($input['id_pengguna'] ?? null, $nominal)) {
            $this->dispatchVoid($modul, $input['id_record'], $input['alasan'], $input['id_pengguna'] ?? null, $input['nama'] ?? null);

            return ['status' => 'voided', 'ref' => $ref];
        }

        $approval = VoidApproval::create([
            'modul' => $modul, 'id_record' => (string) $input['id_record'], 'ref' => $ref,
            'nominal' => Money::of($nominal), 'alasan' => $input['alasan'], 'status' => 'pending',
            'id_pengguna' => $input['id_pengguna'] ?? 0, 'nama_pemohon' => $input['nama'] ?? null,
        ]);

        return ['status' => 'pending', 'ref' => $ref, 'approval_id' => $approval->id];
    }

    public function list(?string $status = null)
    {
        return VoidApproval::when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->get();
    }

    /** Setujui → eksekusi void atas nama penyetuju. */
    public function approve(int $id, ?int $approverId, ?string $approverNama): VoidApproval
    {
        $ap = VoidApproval::find($id);
        if (! $ap) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($ap->status !== 'pending') {
            throw new AppException(409, 'Pengajuan sudah diproses.');
        }
        if (! Authorization::canAuthorize($approverId, $ap->nominal)) {
            throw new AppException(403, 'Anda tidak berwenang menyetujui void dengan nominal ini.');
        }
        $this->dispatchVoid($ap->modul, (int) $ap->id_record, $ap->alasan, $approverId, $approverNama);
        $ap->update(['status' => 'approved', 'decided_by' => $approverId, 'nama_penyetuju' => $approverNama, 'decided_at' => Carbon::now()]);

        return $ap;
    }

    public function reject(int $id, ?int $approverId, ?string $approverNama, ?string $catatan = null): VoidApproval
    {
        $ap = VoidApproval::find($id);
        if (! $ap) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($ap->status !== 'pending') {
            throw new AppException(409, 'Pengajuan sudah diproses.');
        }
        $ap->update(['status' => 'rejected', 'decided_by' => $approverId, 'nama_penyetuju' => $approverNama, 'catatan' => $catatan, 'decided_at' => Carbon::now()]);

        return $ap;
    }
}
