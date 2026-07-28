<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PostingApproval;
use App\Services\Ledger\Authorization;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Approval SATU-LANGKAH untuk POSTING Accrue / Jurnal Umum di atas batas
 * otorisasi pemohon. Berwenang → langsung posting; jika tidak → pending.
 */
class PostingApprovalService
{
    private const MODULES = ['Accrue', 'JurnalUmum'];

    /** Nominal transaksi dari payload. */
    private function nominalOf(string $modul, array $payload): string
    {
        if ($modul === 'Accrue') {
            return (string) ($payload['nominal'] ?? '0');
        }
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

        return array_reduce($lines, fn ($s, $l) => Money::add($s, $l['debet'] ?? '0'), '0');
    }

    /** Buat aktual di modul terkait dari payload, atas nama user. */
    private function dispatchCreate(string $modul, array $payload, ?int $idPengguna): void
    {
        if ($modul === 'Accrue') {
            (new AccrueService)->create($payload, $idPengguna);
        } else {
            (new JournalService)->create(array_merge($payload, ['id_pengguna' => $idPengguna]));
        }
    }

    private function ringkas(string $modul, array $payload): string
    {
        if ($modul === 'Accrue') {
            return "Accrue ".($payload['periode'] ?? '').": {$payload['kode_coa_debet']} → {$payload['kode_coa_kredit']} — ".($payload['keterangan'] ?? '');
        }
        $n = is_array($payload['lines'] ?? null) ? count($payload['lines']) : 0;

        return "Jurnal Umum {$n} baris — ".($payload['keterangan'] ?? '');
    }

    public function request(array $input): array
    {
        $modul = $input['modul'];
        if (! in_array($modul, self::MODULES, true)) {
            throw new AppException(400, 'Modul tidak dikenal.');
        }
        $payload = $input['payload'];
        $nominal = $this->nominalOf($modul, $payload);

        if (Authorization::canAuthorize($input['id_pengguna'] ?? null, $nominal)) {
            $this->dispatchCreate($modul, $payload, $input['id_pengguna'] ?? null);

            return ['status' => 'posted'];
        }

        $approval = PostingApproval::create([
            'modul' => $modul, 'ref' => mb_substr($this->ringkas($modul, $payload), 0, 120),
            'nominal' => Money::of($nominal), 'payload' => json_encode($payload),
            'ringkasan' => $this->ringkas($modul, $payload), 'status' => 'pending',
            'id_pengguna' => $input['id_pengguna'] ?? 0, 'nama_pemohon' => $input['nama'] ?? null,
        ]);

        return ['status' => 'pending', 'approval_id' => $approval->id];
    }

    public function list(?string $status = null)
    {
        return PostingApproval::when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->get();
    }

    public function approve(int $id, ?int $approverId, ?string $approverNama): PostingApproval
    {
        $ap = PostingApproval::find($id);
        if (! $ap) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($ap->status !== 'pending') {
            throw new AppException(409, 'Pengajuan sudah diproses.');
        }
        if (! Authorization::canAuthorize($approverId, $ap->nominal)) {
            throw new AppException(403, 'Anda tidak berwenang menyetujui posting dengan nominal ini.');
        }
        $this->dispatchCreate($ap->modul, json_decode($ap->payload, true), $approverId);
        $ap->update(['status' => 'approved', 'decided_by' => $approverId, 'nama_penyetuju' => $approverNama, 'decided_at' => Carbon::now()]);

        return $ap;
    }

    public function reject(int $id, ?int $approverId, ?string $approverNama, ?string $catatan = null): PostingApproval
    {
        $ap = PostingApproval::find($id);
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
