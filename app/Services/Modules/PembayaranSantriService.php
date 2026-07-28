<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\BayarDompet;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PEMBAYARAN SANTRI — dua tangan: PPSB mencatat (menunggu_verifikasi, belum
 * berjurnal) → Keuangan verifikasi (jurnal terbit, tagihan berkurang, santri maju).
 * Sisi KREDIT ditentukan `sudah_akrual`: belum = Pendapatan (cash basis), sudah
 * = Piutang (menghindari pendapatan dobel). Lingkup: ppsb | kesantrian.
 */
class PembayaranSantriService
{
    private const TIPE_LINGKUP = [
        'ppsb' => ['registrasi', 'uang_pangkal'],
        'kesantrian' => ['spp', 'lain'],
    ];

    private const LABEL_LINGKUP = [
        'ppsb' => 'PPSB (registrasi & uang pangkal)',
        'kesantrian' => 'Kesantrian (SPP & tagihan lain)',
    ];

    /** Langkah 1 — PPSB mencatat setoran. Tidak ada jurnal. */
    public function catat(array $data, int $idPengguna, string $lingkup): PembayaranSantri
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($data['id_tagihan']);
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        $this->assertLingkup($tagihan->jenis->tipe, $lingkup);
        if ($tagihan->id_santri !== $data['id_santri']) {
            throw new AppException(400, 'Tagihan itu bukan milik santri yang dipilih.');
        }
        if ($tagihan->status === 'lunas') {
            throw new AppException(422, 'Tagihan ini sudah lunas.');
        }
        if ($tagihan->status === 'batal') {
            throw new AppException(422, 'Tagihan ini sudah dibatalkan.');
        }

        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal pembayaran harus lebih dari nol.');
        }
        $ruang = $this->ruangTagihan($tagihan->id, $tagihan->sisa);
        if (Money::gt($nominal, $ruang)) {
            throw new AppException(422, "Nominal melebihi sisa tagihan yang belum tercatat ({$ruang}).");
        }
        $this->assertRekeningAda($data['kode_rekening']);

        $pembayaran = DB::transaction(function () use ($data, $idPengguna, $nominal) {
            $base = DocNumber::docBase('BYR', $data['tanggal']);
            $last = PembayaranSantri::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            return PembayaranSantri::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'id_santri' => $data['id_santri'], 'id_tagihan' => $data['id_tagihan'],
                'tanggal' => $data['tanggal'], 'nominal' => $nominal, 'kode_rekening' => $data['kode_rekening'],
                'metode' => $data['metode'] ?? null, 'bukti_path' => $data['bukti_path'] ?? null,
                'catatan' => $data['catatan'] ?? null, 'sumber' => 'manual',
                'status' => 'menunggu_verifikasi', 'dicatat_oleh' => $idPengguna,
            ]);
        });

        $this->notifKeuangan("{$pembayaran->nomor} — {$tagihan->jenis->nama} atas nama {$tagihan->santri->nama}. Pastikan dananya sudah masuk sebelum menyetujui.", (string) $pembayaran->id);

        return $pembayaran;
    }

    /** Langkah 2 — Keuangan verifikasi → jurnal terbit (satu transaksi). */
    public function verifikasi(int $id, int $idPengguna): PembayaranSantri
    {
        $this->assertTimKeuangan($idPengguna);
        $pembayaran = PembayaranSantri::with(['tagihan.jenis', 'santri'])->find($id);
        if (! $pembayaran) {
            throw new AppException(404, 'Pembayaran tidak ditemukan.');
        }
        if ($pembayaran->status !== 'menunggu_verifikasi') {
            throw new AppException(422, "Pembayaran ini sudah berstatus \"{$pembayaran->status}\".");
        }
        $tagihan = $pembayaran->tagihan;
        if (! $tagihan) {
            throw new AppException(422, 'Pembayaran ini tidak terkait tagihan mana pun.');
        }
        $nominal = Money::of($pembayaran->nominal);
        $sisaBaru = Money::sub($tagihan->sisa, $nominal);
        if (Money::isNegative($sisaBaru)) {
            throw new AppException(422, "Nominal {$nominal} melebihi sisa tagihan {$tagihan->sisa} — kemungkinan sudah ada pembayaran lain yang diverifikasi lebih dulu.");
        }

        return DB::transaction(function () use ($pembayaran, $tagihan, $nominal, $sisaBaru, $idPengguna, $id) {
            $jenis = $tagihan->jenis;
            $akunKredit = $jenis->kode_coa_pendapatan;
            if ($tagihan->sudah_akrual) {
                if (! $jenis->kode_coa_piutang) {
                    throw new AppException(422, "Tagihan \"{$jenis->nama}\" sudah diakrualkan tetapi jenis biayanya tidak punya akun piutang. Lengkapi dulu di master Jenis Biaya.");
                }
                $akunKredit = $jenis->kode_coa_piutang;
            }

            $entry = PostingService::postJournal([
                'referensi' => $pembayaran->nomor, 'tanggal' => $pembayaran->tanggal, 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $pembayaran->id, 'id_pengguna' => $idPengguna,
                'keterangan' => "{$jenis->nama} — {$pembayaran->santri->nama} ({$pembayaran->santri->no_pendaftaran})",
                'lines' => [
                    ['kode_coa' => $pembayaran->kode_rekening, 'debet' => $nominal, 'kredit' => '0'],
                    ['kode_coa' => $akunKredit, 'debet' => '0', 'kredit' => $nominal],
                ],
            ]);

            $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);

            // Registrasi lunas = gerbang tahap berikutnya (calon → terbayar).
            if (\App\Models\TipeBiaya::perilakuDari($jenis->tipe) === 'registrasi' && Money::isZero($sisaBaru) && $pembayaran->santri->status === 'calon') {
                Santri::where('id', $pembayaran->id_santri)->update(['status' => 'terbayar']);
            }

            $pembayaran->update([
                'status' => 'terverifikasi', 'diverifikasi_oleh' => $idPengguna,
                'diverifikasi_pada' => Carbon::now(), 'journal_entry_id' => $entry->id,
            ]);

            return $pembayaran;
        });
    }

    /** Bayar tagihan dari Dompet Wali (tanpa verifikasi — dana sudah diverifikasi saat top-up). */
    public function bayarDariDompet(array $data, int $idPengguna): PembayaranSantri
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($data['id_tagihan']);
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if ($tagihan->status === 'lunas') {
            throw new AppException(422, 'Tagihan ini sudah lunas.');
        }
        if ($tagihan->status === 'batal') {
            throw new AppException(422, 'Tagihan ini sudah dibatalkan.');
        }

        $dompet = DompetWali::where('id_wali', $tagihan->santri->id_wali)->first();
        if (! $dompet) {
            throw new AppException(422, 'Keluarga ini belum punya Dompet Wali. Lakukan top-up lebih dulu di menu Dompet & Tabungan.');
        }
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal pembayaran harus lebih dari nol.');
        }
        $ruang = $this->ruangTagihan($tagihan->id, $tagihan->sisa);
        if (Money::gt($nominal, $ruang)) {
            throw new AppException(422, "Nominal melebihi sisa tagihan yang belum tercatat ({$ruang}).");
        }
        if (Money::lt($dompet->saldo, $nominal)) {
            throw new AppException(422, "Saldo Dompet Wali tidak cukup (tersedia {$dompet->saldo}).");
        }

        return DB::transaction(fn () => BayarDompet::bayarTagihanDariDompet($tagihan, [
            'id_dompet' => $dompet->id, 'nominal' => (string) $nominal, 'tanggal' => $data['tanggal'],
            'id_pengguna' => $idPengguna, 'namaSantri' => $tagihan->santri->nama, 'catatan' => $data['catatan'] ?? null,
        ]));
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PembayaranSantri
    {
        $this->assertTimKeuangan($idPengguna);
        $pembayaran = PembayaranSantri::find($id);
        if (! $pembayaran) {
            throw new AppException(404, 'Pembayaran tidak ditemukan.');
        }
        if ($pembayaran->status !== 'menunggu_verifikasi') {
            throw new AppException(422, "Pembayaran ini sudah berstatus \"{$pembayaran->status}\".");
        }
        $pembayaran->update([
            'status' => 'ditolak', 'alasan_tolak' => $alasan,
            'diverifikasi_oleh' => $idPengguna, 'diverifikasi_pada' => Carbon::now(),
        ]);

        (new NotificationService)->kirim([[
            'id_pengguna' => $pembayaran->dicatat_oleh, 'judul' => 'Pembayaran santri ditolak keuangan',
            'pesan' => "{$pembayaran->nomor} ditolak: {$alasan}", 'jenis' => 'pembayaran_santri_ditolak',
            'ref_jenis' => 'PembayaranSantri', 'ref_id' => (string) $id,
        ]]);

        return $pembayaran;
    }

    // ---- Helper ----

    private function assertLingkup(string $tipe, string $lingkup): void
    {
        // Dibandingkan lewat PERILAKU tipe: tipe buatan sendiri ikut masuk lingkup
        // yang benar tanpa perlu didaftarkan lagi di sini.
        if (! in_array(\App\Models\TipeBiaya::perilakuDari($tipe), self::TIPE_LINGKUP[$lingkup], true)) {
            $lain = $lingkup === 'ppsb' ? 'kesantrian' : 'ppsb';
            throw new AppException(422, "Tagihan berjenis \"{$tipe}\" bukan urusan modul ".self::LABEL_LINGKUP[$lingkup].'. Pakai modul '.self::LABEL_LINGKUP[$lain].'.');
        }
    }

    private function assertTimKeuangan(int $idPengguna): void
    {
        $user = User::find($idPengguna);
        if (! $user || (! $user->tim_keuangan && ! $user->is_admin)) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh memverifikasi pembayaran santri.');
        }
    }

    /** Sisa yang belum tercatat = sisa - setoran menunggu verifikasi. */
    private function ruangTagihan(int $idTagihan, $sisa): string
    {
        $menunggu = PembayaranSantri::where('id_tagihan', $idTagihan)->where('status', 'menunggu_verifikasi')->sum('nominal');

        return Money::sub($sisa, $menunggu);
    }

    private function assertRekeningAda(string $kodeRekening): void
    {
        $rek = BankAccount::find($kodeRekening);
        if (! $rek) {
            throw new AppException(400, 'Kas/rekening penerima tidak ditemukan.');
        }
        if ($rek->status !== 'aktif') {
            throw new AppException(422, "Rekening \"{$rek->nama_rekening}\" berstatus nonaktif.");
        }
    }

    private function notifKeuangan(string $pesan, ?string $refId): void
    {
        $keuangan = User::where('tim_keuangan', true)->where('status', 'aktif')->get(['id_pengguna']);
        (new NotificationService)->kirim($keuangan->map(fn ($u) => [
            'id_pengguna' => $u->id_pengguna, 'judul' => 'Pembayaran santri menunggu verifikasi',
            'pesan' => $pesan, 'jenis' => 'pembayaran_santri_menunggu', 'ref_jenis' => 'PembayaranSantri', 'ref_id' => $refId,
        ])->all());
    }
}
