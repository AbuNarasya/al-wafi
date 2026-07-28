<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN LAIN — biaya di luar registrasi/uang pangkal/SPP (seragam, study tour,
 * denda). Diterbitkan untuk BEBERAPA santri sekaligus. Akrual bila jenisnya
 * punya akun piutang (jurnal Piutang D / Pendapatan K), selain itu cash basis.
 */
class TagihanLainService
{
    public function terbitkan(array $data, int $idPengguna): array
    {
        $jenis = JenisBiaya::find($data['kode_jenis']);
        if (! $jenis) {
            throw new AppException(400, 'Jenis biaya tidak ditemukan.');
        }
        if (\App\Models\TipeBiaya::perilakuDari($jenis->tipe) !== 'lain') {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" bukan bertipe Lain-lain. Registrasi, uang pangkal, dan SPP punya jalurnya sendiri.");
        }
        if ($jenis->status !== 'aktif') {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" nonaktif.");
        }
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal tagihan harus lebih dari nol.');
        }

        $santri = Santri::whereIn('id', $data['id_santri'])->where('status', 'aktif')->get(['id', 'nama']);
        if ($santri->isEmpty()) {
            throw new AppException(422, 'Tidak ada santri aktif yang dipilih.');
        }

        $sudahAda = TagihanSantri::where('kode_jenis', $data['kode_jenis'])
            ->where('periode', $data['periode'] ?? null)
            ->whereIn('id_santri', $santri->pluck('id'))->pluck('id_santri')->all();
        $target = $santri->reject(fn ($s) => in_array($s->id, $sudahAda, true))->values();
        if ($target->isEmpty()) {
            throw new AppException(422, 'Seluruh santri yang dipilih sudah punya tagihan ini.');
        }

        $akrual = (bool) $jenis->kode_coa_piutang;
        $total = Money::mul($nominal, (string) $target->count());

        $hasil = DB::transaction(function () use ($data, $jenis, $nominal, $target, $akrual, $total, $idPengguna, $santri) {
            $referensi = null;
            if ($akrual) {
                $base = DocNumber::docBase('TGL', $data['tanggal']);
                $last = \App\Models\JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
                $referensi = DocNumber::nextDocNumber($base, $last);
                PostingService::postJournal([
                    'referensi' => $referensi, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                    'sumber_modul' => 'TagihanSpp', 'id_pengguna' => $idPengguna,
                    'keterangan' => "{$jenis->nama}".(! empty($data['periode']) ? " {$data['periode']}" : '')." — {$target->count()} santri",
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $total, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $total],
                    ],
                ]);
            }
            $now = now();
            TagihanSantri::insert($target->map(fn ($s) => [
                'id_santri' => $s->id, 'kode_jenis' => $data['kode_jenis'], 'periode' => $data['periode'] ?? null,
                'nominal' => $nominal, 'sisa' => $nominal, 'sudah_akrual' => $akrual, 'status' => 'belum_bayar',
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => $data['keterangan'] ?? $jenis->nama,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());

            return ['terbit' => $target->count(), 'dilewati' => $santri->count() - $target->count(), 'total' => $total, 'akrual' => $akrual, 'referensi' => $referensi];
        });

        $autoDebet = (new AutoDebetService)->jalankan($idPengguna, $data['tanggal']);

        return array_merge($hasil, ['auto_debet' => $autoDebet]);
    }

    public function batalkan(int $id): TagihanSantri
    {
        $t = TagihanSantri::with(['jenis', 'pembayaran' => fn ($q) => $q->where('status', '!=', 'ditolak')])->find($id);
        if (! $t) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if (\App\Models\TipeBiaya::perilakuDari($t->jenis->tipe) !== 'lain') {
            throw new AppException(422, 'Hanya tagihan lain-lain yang bisa dibatalkan di sini.');
        }
        if ($t->pembayaran->isNotEmpty()) {
            throw new AppException(422, 'Tagihan ini sudah punya pembayaran, jadi tidak bisa dibatalkan. Ajukan koreksi lewat modul keuangan.');
        }
        if ($t->sudah_akrual) {
            throw new AppException(422, 'Tagihan ini sudah diakrualkan ke buku besar. Pembatalannya harus lewat jurnal balik di modul keuangan, bukan dihapus di sini.');
        }
        $t->update(['status' => 'batal', 'sisa' => '0']);

        return $t;
    }
}
