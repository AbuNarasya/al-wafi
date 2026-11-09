<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\Mitra;
use App\Models\PenarikanMitra;
use App\Models\PenjualanMitra;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * SALDO, PENARIKAN & JURNAL MITRA KANTIN — Paket 3 (keputusan user 9 Nov 2026), instans Wafi Mart.
 *
 *  • Saldo bisa ditarik = penjualan kartu BERHASIL sampai KEMARIN (H+1, sesudah rekonsiliasi malam)
 *    − penarikan yang diajukan/selesai. Penjualan hari ini tampil sebagai "tertahan".
 *  • Mitra mengajukan kapan saja (satu pengajuan menunggu dalam satu waktu); petugas mentransfer, mengunggah bukti,
 *    lalu menandai selesai. Biaya transfer dipotong dari penarikan: mitra menerima nominal − biaya.
 *  • Jurnal: penjualan ringkas per tanggal tiap malam (D Piutang ke Al Wafi / K Hutang ke Mitra Kantin);
 *    penarikan selesai D Hutang ke Mitra Kantin / K Kas-Bank sebesar nominal.
 */
class SaldoMitraService
{
    public const SUMBER_JUAL = 'PenjualanMitra';

    public const SUMBER_TARIK = 'PenarikanMitra';

    /** @return array{penjualan:string, ditarik:string, diajukan:string, tertahan:string, bisa_ditarik:string, saldo:string} */
    public function saldo(int $idMitra): array
    {
        $kemarin = now()->startOfDay();
        $jual = PenjualanMitra::where('id_mitra', $idMitra)->where('status', 'berhasil');
        $penjualan = Money::of((clone $jual)->sum('total'));
        $tertahan = Money::of((clone $jual)->where('waktu', '>=', $kemarin)->sum('total'));
        $ditarik = Money::of(PenarikanMitra::where('id_mitra', $idMitra)->where('status', 'selesai')->sum('nominal'));
        $diajukan = Money::of(PenarikanMitra::where('id_mitra', $idMitra)->where('status', 'diajukan')->sum('nominal'));
        $bisa = Money::sub(Money::sub(Money::sub($penjualan, $tertahan), $ditarik), $diajukan);

        return [
            'penjualan' => $penjualan, 'ditarik' => $ditarik, 'diajukan' => $diajukan, 'tertahan' => $tertahan,
            'bisa_ditarik' => Money::isNegative($bisa) ? Money::of(0) : Money::of($bisa),
            // Kewajiban Wafi Mart ke mitra saat ini (termasuk penjualan hari ini).
            'saldo' => Money::of(Money::sub($penjualan, $ditarik)),
        ];
    }

    /** @return array<int, array> per id_mitra */
    public function saldoSemua(): array
    {
        return Mitra::pluck('id')->mapWithKeys(fn ($id) => [$id => $this->saldo($id)])->all();
    }

    public function ajukan(Mitra $mitra, string|int|float $nominal, ?string $catatan, int $idPengguna): PenarikanMitra
    {
        if (! $mitra->aktif()) {
            throw new AppException(403, 'Mitra ini sedang nonaktif — hubungi petugas Wafi Mart.');
        }
        if (! $mitra->bank_rekening) {
            throw new AppException(422, 'Rekening tujuan belum diisi — minta petugas Wafi Mart mengisinya di data mitra.');
        }
        $nominal = Money::of($nominal);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal penarikan harus lebih dari nol.');
        }

        return DB::transaction(function () use ($mitra, $nominal, $catatan, $idPengguna) {
            Mitra::whereKey($mitra->id)->lockForUpdate()->first();
            if (PenarikanMitra::where('id_mitra', $mitra->id)->where('status', 'diajukan')->exists()) {
                throw new AppException(422, 'Masih ada pengajuan penarikan yang menunggu diproses.');
            }
            $saldo = $this->saldo($mitra->id);
            if (Money::gt($nominal, $saldo['bisa_ditarik'])) {
                throw new AppException(422, 'Melebihi saldo yang bisa ditarik (Rp '.number_format((float) $saldo['bisa_ditarik'], 0, ',', '.').').');
            }
            $base = DocNumber::docBase('PNM', now());
            $nomor = DocNumber::nextDocNumber($base, PenarikanMitra::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));

            return PenarikanMitra::create([
                'nomor' => $nomor, 'id_mitra' => $mitra->id, 'nominal' => $nominal, 'status' => 'diajukan',
                'catatan_mitra' => ($c = trim((string) $catatan)) !== '' ? mb_substr($c, 0, 255) : null,
                'bank_nama' => $mitra->bank_nama, 'bank_rekening' => $mitra->bank_rekening, 'bank_atas_nama' => $mitra->bank_atas_nama,
                'diajukan_oleh' => $idPengguna,
            ]);
        });
    }

    /** Mitra membatalkan pengajuannya sendiri sebelum diproses. */
    public function batalkan(Mitra $mitra, int $id): PenarikanMitra
    {
        return DB::transaction(function () use ($mitra, $id) {
            $p = PenarikanMitra::where('id_mitra', $mitra->id)->lockForUpdate()->findOrFail($id);
            if ($p->status !== 'diajukan') {
                throw new AppException(422, 'Pengajuan ini sudah diproses petugas.');
            }
            $p->update(['status' => 'dibatalkan']);

            return $p;
        });
    }

    /** Petugas Wafi Mart: transfer sudah dikirim → tandai selesai, simpan bukti, jurnal. */
    public function proses(int $id, string|int|float|null $biaya, string $kodeCoaBank, string $tanggal, ?UploadedFile $bukti, int $idPengguna): PenarikanMitra
    {
        if (! BankAccount::where('kode_coa', $kodeCoaBank)->where('status', 'aktif')->exists()) {
            throw new AppException(422, 'Pilih rekening kas/bank yang aktif.');
        }
        $hutang = self::akunHutang();
        $biaya = Money::of($biaya ?? 0);
        if (Money::isNegative($biaya)) {
            throw new AppException(422, 'Biaya transfer tidak boleh negatif.');
        }
        $path = $bukti ? Storage::disk('local')->putFile('penarikan-mitra', $bukti) : null;

        try {
            return DB::transaction(function () use ($id, $biaya, $kodeCoaBank, $tanggal, $bukti, $path, $hutang, $idPengguna) {
                $p = PenarikanMitra::with('mitra')->lockForUpdate()->findOrFail($id);
                if ($p->status !== 'diajukan') {
                    throw new AppException(422, 'Pengajuan ini sudah diproses.');
                }
                if (! Money::lt($biaya, $p->nominal)) {
                    throw new AppException(422, 'Biaya transfer harus lebih kecil dari nominal penarikan.');
                }
                $ket = "Penarikan saldo mitra {$p->mitra->nama} {$p->nomor}"
                    .(Money::gtZero($biaya) ? ' (diterima mitra Rp '.number_format((float) Money::sub($p->nominal, $biaya), 0, ',', '.').', biaya transfer Rp '.number_format((float) $biaya, 0, ',', '.').')' : '');
                $entry = PostingService::postJournal([
                    'referensi' => $p->nomor, 'tanggal' => $tanggal, 'keterangan' => $ket,
                    'sumber_modul' => self::SUMBER_TARIK, 'id_sumber' => $p->nomor, 'id_pengguna' => $idPengguna,
                    'lines' => [
                        ['kode_coa' => $hutang, 'debet' => Money::of($p->nominal), 'kredit' => '0', 'keterangan' => $ket],
                        ['kode_coa' => $kodeCoaBank, 'debet' => '0', 'kredit' => Money::of($p->nominal), 'keterangan' => $ket],
                    ],
                ]);
                $p->update([
                    'status' => 'selesai', 'biaya_transfer' => $biaya, 'kode_coa_bank' => $kodeCoaBank, 'tanggal_transfer' => $tanggal,
                    'bukti_path' => $path, 'bukti_nama' => $bukti?->getClientOriginalName(),
                    'diproses_oleh' => $idPengguna, 'diproses_pada' => now(), 'journal_entry_id' => $entry->id,
                ]);
                Jejak::catat('proses_penarikan_mitra', ['modul' => MitraKantinService::MODUL, 'id_pengguna' => $idPengguna,
                    'ref_jenis' => 'penarikan_mitra', 'ref_id' => $p->id, 'detail' => $ket]);

                return $p;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PenarikanMitra
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            throw new AppException(422, 'Tulis alasan penolakan.');
        }

        return DB::transaction(function () use ($id, $alasan, $idPengguna) {
            $p = PenarikanMitra::lockForUpdate()->findOrFail($id);
            if ($p->status !== 'diajukan') {
                throw new AppException(422, 'Pengajuan ini sudah diproses.');
            }
            $p->update(['status' => 'ditolak', 'alasan_tolak' => mb_substr($alasan, 0, 255), 'diproses_oleh' => $idPengguna, 'diproses_pada' => now()]);
            Jejak::catat('tolak_penarikan_mitra', ['modul' => MitraKantinService::MODUL, 'id_pengguna' => $idPengguna,
                'ref_jenis' => 'penarikan_mitra', 'ref_id' => $p->id, 'detail' => "{$p->nomor}: {$alasan}"]);

            return $p;
        });
    }

    /**
     * Jurnal ringkas penjualan mitra per TANGGAL (sebelum hari ini) yang belum dijurnal — tiap malam sesudah
     * rekonsiliasi. Batal hanya bisa di hari yang sama, jadi yang dijurnal cukup yang BERHASIL. Satu tanggal yang
     * ditolak (mis. periode tutup) tak menahan tanggal lain.
     *
     * @return list<array{tanggal:string, total:string, transaksi:int, jurnal:?int, galat?:string}>
     */
    public function jurnalPenjualan(?string $sebelum = null): array
    {
        $batas = $sebelum ?? now()->toDateString();
        $tanggal = PenjualanMitra::where('status', 'berhasil')->whereNull('dijurnal_pada')->where('waktu', '<', $batas)
            ->selectRaw("distinct to_char(waktu, 'YYYY-MM-DD') as t")->orderBy('t')->pluck('t');
        if ($tanggal->isEmpty()) {
            return [];
        }
        $piutang = CompanySettings::query()->value('kode_coa_kartu_santri')
            ?? throw new AppException(422, 'Akun "Piutang ke Al Wafi" (kode_coa_kartu_santri) belum ditetapkan.');
        $hutang = self::akunHutang();

        return $tanggal->map(function (string $tgl) use ($piutang, $hutang) {
            try {
                return DB::transaction(function () use ($tgl, $piutang, $hutang) {
                    $baris = PenjualanMitra::with('mitra:id,nama')->where('status', 'berhasil')->whereNull('dijurnal_pada')
                        ->whereRaw("to_char(waktu, 'YYYY-MM-DD') = ?", [$tgl])->lockForUpdate()->get();
                    if ($baris->isEmpty()) {
                        return ['tanggal' => $tgl, 'total' => '0', 'transaksi' => 0, 'jurnal' => null];
                    }
                    $lines = [];
                    $total = '0';
                    foreach ($baris->groupBy('id_mitra') as $grup) {
                        $nilai = Money::of($grup->sum('total'));
                        $total = Money::add($total, $nilai);
                        $lines[] = ['kode_coa' => $hutang, 'debet' => '0', 'kredit' => $nilai,
                            'keterangan' => "Penjualan kartu {$grup->first()->mitra->nama} {$tgl} ({$grup->count()} transaksi)"];
                    }
                    array_unshift($lines, ['kode_coa' => $piutang, 'debet' => Money::of($total), 'kredit' => '0',
                        'keterangan' => "Belanja kartu santri di kantin mitra {$tgl}"]);
                    $ref = 'PKM-'.str_replace('-', '', $tgl);
                    $entry = PostingService::postJournal([
                        'referensi' => $ref, 'tanggal' => $tgl, 'sumber_modul' => self::SUMBER_JUAL, 'id_sumber' => $ref,
                        'keterangan' => "Penjualan kartu santri di kantin mitra {$tgl} ({$baris->count()} transaksi)", 'lines' => $lines,
                    ]);
                    PenjualanMitra::whereIn('id', $baris->pluck('id'))->update(['dijurnal_pada' => now(), 'journal_entry_id' => $entry->id]);

                    return ['tanggal' => $tgl, 'total' => Money::of($total), 'transaksi' => $baris->count(), 'jurnal' => $entry->id];
                });
            } catch (AppException $e) {
                report($e);

                return ['tanggal' => $tgl, 'total' => '0', 'transaksi' => 0, 'jurnal' => null, 'galat' => $e->getMessage()];
            }
        })->all();
    }

    public static function akunHutang(): string
    {
        return CompanySettings::query()->value('kode_coa_hutang_mitra')
            ?? throw new AppException(422, 'Akun "Hutang ke Mitra Kantin" belum ditetapkan — pilih di layar Mitra Kantin.');
    }
}
