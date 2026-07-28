<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\EditApproval;
use App\Models\Invoice;
use App\Services\Ledger\Authorization;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Approval SATU-LANGKAH untuk EDIT transaksi (kini: Invoice) di atas batas
 * otorisasi pemohon. Berwenang → langsung terapkan; jika tidak → pending.
 */
class EditApprovalService
{
    private const MODULES = ['Invoice'];

    private function getMeta(string $modul, int $id): array
    {
        // modul saat ini hanya Invoice.
        $r = Invoice::find($id);
        if (! $r) {
            throw new AppException(404, 'Invoice tidak ditemukan.');
        }

        return ['nominal' => (string) $r->total, 'ref' => $r->nomor_invoice];
    }

    private function dispatchEdit(string $modul, int $id, array $payload): void
    {
        (new InvoiceService)->updateMeta($id, $payload);
    }

    private function ringkas(string $modul, array $p): string
    {
        $parts = [];
        if (! empty($p['tanggal_jatuh_tempo'])) {
            $parts[] = 'Jatuh tempo → '.mb_substr((string) $p['tanggal_jatuh_tempo'], 0, 10);
        }
        if (array_key_exists('keterangan', $p)) {
            $parts[] = 'Keterangan → '.($p['keterangan'] ? (string) $p['keterangan'] : '(kosong)');
        }

        return implode('; ', $parts);
    }

    public function request(array $input): array
    {
        $modul = $input['modul'];
        if (! in_array($modul, self::MODULES, true)) {
            throw new AppException(400, 'Modul tidak dikenal.');
        }
        ['nominal' => $nominal, 'ref' => $ref] = $this->getMeta($modul, $input['id_record']);
        $payload = $input['payload'];

        if (Authorization::canAuthorize($input['id_pengguna'] ?? null, $nominal)) {
            $this->dispatchEdit($modul, $input['id_record'], $payload);

            return ['status' => 'applied', 'ref' => $ref];
        }

        $approval = EditApproval::create([
            'modul' => $modul, 'id_record' => (string) $input['id_record'], 'ref' => $ref,
            'nominal' => Money::of($nominal), 'payload' => json_encode($payload),
            'ringkasan' => $this->ringkas($modul, $payload), 'status' => 'pending',
            'id_pengguna' => $input['id_pengguna'] ?? 0, 'nama_pemohon' => $input['nama'] ?? null,
        ]);

        return ['status' => 'pending', 'ref' => $ref, 'approval_id' => $approval->id];
    }

    public function list(?string $status = null)
    {
        return EditApproval::when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->get();
    }

    public function approve(int $id, ?int $approverId, ?string $approverNama): EditApproval
    {
        $ap = EditApproval::find($id);
        if (! $ap) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($ap->status !== 'pending') {
            throw new AppException(409, 'Pengajuan sudah diproses.');
        }
        if (! Authorization::canAuthorize($approverId, $ap->nominal)) {
            throw new AppException(403, 'Anda tidak berwenang menyetujui edit dengan nominal ini.');
        }
        $this->dispatchEdit($ap->modul, (int) $ap->id_record, json_decode($ap->payload, true));
        $ap->update(['status' => 'approved', 'decided_by' => $approverId, 'nama_penyetuju' => $approverNama, 'decided_at' => Carbon::now()]);

        return $ap;
    }

    public function reject(int $id, ?int $approverId, ?string $approverNama, ?string $catatan = null): EditApproval
    {
        $ap = EditApproval::find($id);
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
