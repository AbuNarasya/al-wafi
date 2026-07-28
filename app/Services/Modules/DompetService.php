<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\DompetSantri;
use App\Models\DompetWali;
use App\Models\MutasiDompet;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\User;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DOMPET & TABUNGAN (wadi'ah). Saldo & mutasi SELALU bergerak bersama (satu
 * transaksi). Top-up butuh verifikasi keuangan (uang dari luar); pemindahan
 * antar-dompet tidak (reklasifikasi antar-liabilitas, kas tak bergerak).
 */
class DompetService
{
    private const SUMBER = 'MutasiDompet';

    public function ringkasanWali(int $idWali): Wali
    {
        $wali = Wali::with(['dompet', 'santri' => fn ($q) => $q->whereIn('status', ['aktif', 'lolos_kesehatan'])->with(['dompet', 'tabungan'])->orderBy('nama')])->find($idWali);
        if (! $wali) {
            throw new AppException(404, 'Wali tidak ditemukan.');
        }

        return $wali;
    }

    /** TOP-UP Dompet Wali — langkah 1 (belum menyentuh saldo/buku besar). */
    public function topUp(array $data, int $idPengguna): MutasiDompet
    {
        $dompet = $this->ambilDompetWali($data['id_wali']);
        $this->assertRekening($data['kode_rekening']);
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal top-up harus lebih dari nol.');
        }

        $mutasi = MutasiDompet::create([
            'nomor' => $this->nomorMutasi($data['tanggal']), 'pemilik' => 'wali', 'id_dompet' => $dompet->id,
            'jenis' => 'topup', 'nominal' => $nominal, 'saldo_setelah' => $dompet->saldo, 'tanggal' => $data['tanggal'],
            'keterangan' => $data['keterangan'] ?? 'Top-up Dompet Wali', 'status' => 'menunggu_verifikasi',
            'kode_rekening' => $data['kode_rekening'], 'bukti_path' => $data['bukti_path'] ?? null, 'dicatat_oleh' => $idPengguna,
        ]);

        $this->notifKeuangan("{$mutasi->nomor} — {$nominal}. Pastikan dananya sudah masuk sebelum menyetujui.", (string) $mutasi->id);

        return $mutasi;
    }

    /** TOP-UP Dompet Santri lewat setor tunai (opsional, ada saklar kebijakan). */
    public function topUpSantri(array $data, int $idPengguna): MutasiDompet
    {
        $this->assertTopUpTunaiDiizinkan();
        $dompet = $this->ambilDompetSantri($data['id_santri']);
        $this->assertRekening($data['kode_rekening']);
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal top-up harus lebih dari nol.');
        }

        $mutasi = MutasiDompet::create([
            'nomor' => $this->nomorMutasi($data['tanggal']), 'pemilik' => 'santri', 'id_dompet' => $dompet->id,
            'jenis' => 'topup', 'nominal' => $nominal, 'saldo_setelah' => $dompet->saldo, 'tanggal' => $data['tanggal'],
            'keterangan' => $data['keterangan'] ?? 'Setor tunai Dompet Santri', 'status' => 'menunggu_verifikasi',
            'kode_rekening' => $data['kode_rekening'], 'bukti_path' => $data['bukti_path'] ?? null, 'dicatat_oleh' => $idPengguna,
        ]);

        $this->notifKeuangan("{$mutasi->nomor} — {$nominal}. Pastikan dananya sudah masuk sebelum menyetujui.", (string) $mutasi->id);

        return $mutasi;
    }

    /** TOP-UP langkah 2 — verifikasi: saldo bertambah + jurnal (D Kas / K Titipan). */
    public function verifikasiTopUp(int $id, int $idPengguna): MutasiDompet
    {
        $this->assertTimKeuangan($idPengguna);
        $mutasi = MutasiDompet::find($id);
        if (! $mutasi) {
            throw new AppException(404, 'Mutasi tidak ditemukan.');
        }
        if ($mutasi->jenis !== 'topup') {
            throw new AppException(422, 'Hanya top-up yang perlu diverifikasi.');
        }
        if ($mutasi->status !== 'menunggu_verifikasi') {
            throw new AppException(422, "Top-up ini sudah berstatus \"{$mutasi->status}\".");
        }
        if (! $mutasi->kode_rekening) {
            throw new AppException(422, 'Top-up ini tidak menyebut kas/rekening penerima.');
        }
        $nominal = Money::of($mutasi->nominal);

        return DB::transaction(function () use ($mutasi, $nominal, $idPengguna, $id) {
            $saldo = $this->tambahSaldo($mutasi->pemilik, $mutasi->id_dompet, $nominal);
            $entry = PostingService::postJournal([
                'referensi' => $mutasi->nomor, 'tanggal' => $mutasi->tanggal, 'sumber_modul' => self::SUMBER,
                'id_sumber' => (string) $mutasi->id, 'id_pengguna' => $idPengguna,
                'keterangan' => $mutasi->keterangan ?? 'Top-up '.DompetPolicy::labelDompet($mutasi->pemilik),
                'lines' => [
                    ['kode_coa' => $mutasi->kode_rekening, 'debet' => $nominal, 'kredit' => '0'],
                    ['kode_coa' => DompetPolicy::COA_TITIPAN[$mutasi->pemilik], 'debet' => '0', 'kredit' => $nominal],
                ],
            ]);
            $mutasi->update([
                'status' => 'terverifikasi', 'saldo_setelah' => $saldo, 'diverifikasi_oleh' => $idPengguna,
                'diverifikasi_pada' => Carbon::now(), 'journal_entry_id' => $entry->id,
            ]);

            return $mutasi;
        });
    }

    public function tolakTopUp(int $id, string $alasan, int $idPengguna): MutasiDompet
    {
        $this->assertTimKeuangan($idPengguna);
        $mutasi = MutasiDompet::find($id);
        if (! $mutasi) {
            throw new AppException(404, 'Mutasi tidak ditemukan.');
        }
        if ($mutasi->status !== 'menunggu_verifikasi') {
            throw new AppException(422, "Top-up ini sudah berstatus \"{$mutasi->status}\".");
        }
        $mutasi->update(['status' => 'ditolak', 'alasan_tolak' => $alasan, 'diverifikasi_oleh' => $idPengguna, 'diverifikasi_pada' => Carbon::now()]);
        (new NotificationService)->kirim([[
            'id_pengguna' => $mutasi->dicatat_oleh, 'judul' => 'Top-up dompet ditolak keuangan',
            'pesan' => "{$mutasi->nomor} ditolak: {$alasan}", 'jenis' => 'topup_dompet_ditolak',
            'ref_jenis' => 'MutasiDompet', 'ref_id' => (string) $id,
        ]]);

        return $mutasi;
    }

    /** PEMINDAHAN antar-dompet (distribusi jajan / isi tabungan). Tanpa verifikasi. */
    public function pindah(array $data, int $idPengguna): array
    {
        DompetPolicy::assertArahSah($data['dari'], $data['ke']);
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal pemindahan harus lebih dari nol.');
        }
        $asal = $this->ambilDompet($data['dari'], $data['id_santri'] ?? null, $data['id_wali'] ?? null);
        $tujuan = $this->ambilDompet($data['ke'], $data['id_santri'] ?? null, $data['id_wali'] ?? null);
        if (Money::lt($asal->saldo, $nominal)) {
            throw new AppException(422, 'Saldo '.DompetPolicy::labelDompet($data['dari'])." tidak cukup (tersedia {$asal->saldo}).");
        }

        return DB::transaction(function () use ($data, $idPengguna, $nominal, $asal, $tujuan) {
            $saldoAsal = $this->kurangiSaldo($data['dari'], $asal->id, $nominal);
            $saldoTujuan = $this->tambahSaldo($data['ke'], $tujuan->id, $nominal);
            $nomorKeluar = $this->nomorMutasi($data['tanggal']);

            $entry = PostingService::postJournal([
                'referensi' => $nomorKeluar, 'tanggal' => $data['tanggal'], 'sumber_modul' => self::SUMBER, 'id_pengguna' => $idPengguna,
                'keterangan' => $data['keterangan'] ?? DompetPolicy::labelDompet($data['dari']).' → '.DompetPolicy::labelDompet($data['ke']),
                'lines' => [
                    ['kode_coa' => DompetPolicy::COA_TITIPAN[$data['dari']], 'debet' => $nominal, 'kredit' => '0'],
                    ['kode_coa' => DompetPolicy::COA_TITIPAN[$data['ke']], 'debet' => '0', 'kredit' => $nominal],
                ],
            ]);

            $keluar = MutasiDompet::create([
                'nomor' => $nomorKeluar, 'pemilik' => $data['dari'], 'id_dompet' => $asal->id,
                'jenis' => $data['ke'] === 'tabungan' ? 'tabung_keluar' : 'distribusi_keluar',
                'nominal' => Money::sub('0', $nominal), 'saldo_setelah' => $saldoAsal, 'tanggal' => $data['tanggal'],
                'keterangan' => $data['keterangan'] ?? null, 'dicatat_oleh' => $idPengguna, 'journal_entry_id' => $entry->id,
            ]);
            $masuk = MutasiDompet::create([
                'nomor' => $this->nomorMutasi($data['tanggal']), 'pemilik' => $data['ke'], 'id_dompet' => $tujuan->id,
                'jenis' => $data['ke'] === 'tabungan' ? 'tabung_masuk' : 'distribusi_masuk',
                'nominal' => $nominal, 'saldo_setelah' => $saldoTujuan, 'tanggal' => $data['tanggal'],
                'keterangan' => $data['keterangan'] ?? null, 'dicatat_oleh' => $idPengguna, 'id_pasangan' => $keluar->id, 'journal_entry_id' => $entry->id,
            ]);
            $keluar->update(['id_pasangan' => $masuk->id]);

            return ['keluar' => $keluar, 'masuk' => $masuk];
        });
    }

    public function setKunciTarik(int $idSantri, bool $kunci): DompetSantri
    {
        $dompet = $this->ambilDompetSantri($idSantri);
        $dompet->update(['kunci_tarik' => $kunci]);

        return $dompet;
    }

    // ---- pembantu ----

    private function ambilDompetWali(int $idWali): DompetWali
    {
        if (! Wali::find($idWali)) {
            throw new AppException(404, 'Wali tidak ditemukan.');
        }

        return DompetWali::firstOrCreate(['id_wali' => $idWali], ['saldo' => '0']);
    }

    private function ambilDompetSantri(int $idSantri): DompetSantri
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, 'Dompet hanya untuk santri aktif. Selesaikan daftar ulang terlebih dahulu.');
        }

        return DompetSantri::firstOrCreate(['id_santri' => $idSantri], ['saldo' => '0']);
    }

    private function ambilTabungan(int $idSantri): TabunganSantri
    {
        $this->ambilDompetSantri($idSantri); // pagar status sama

        return TabunganSantri::firstOrCreate(['id_santri' => $idSantri], ['saldo' => '0']);
    }

    private function ambilDompet(string $pemilik, ?int $idSantri, ?int $idWali)
    {
        if ($pemilik === 'wali') {
            if (! $idWali) {
                throw new AppException(400, 'Wali wajib dipilih.');
            }

            return $this->ambilDompetWali($idWali);
        }
        if (! $idSantri) {
            throw new AppException(400, 'Santri wajib dipilih.');
        }

        return $pemilik === 'santri' ? $this->ambilDompetSantri($idSantri) : $this->ambilTabungan($idSantri);
    }

    private function tambahSaldo(string $pemilik, int $id, string $nominal): string
    {
        return $this->ubahSaldo($pemilik, $id, $nominal, true);
    }

    private function kurangiSaldo(string $pemilik, int $id, string $nominal): string
    {
        return $this->ubahSaldo($pemilik, $id, $nominal, false);
    }

    private function ubahSaldo(string $pemilik, int $id, string $nominal, bool $tambah): string
    {
        $model = match ($pemilik) {
            'wali' => DompetWali::find($id),
            'santri' => DompetSantri::find($id),
            default => TabunganSantri::find($id),
        };
        $model->saldo = $tambah ? Money::add($model->saldo, $nominal) : Money::sub($model->saldo, $nominal);
        $model->save();

        return $model->saldo;
    }

    private function nomorMutasi(string $tanggal): string
    {
        $base = DocNumber::docBase('DMP', $tanggal);
        $last = MutasiDompet::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

        return DocNumber::nextDocNumber($base, $last);
    }

    private function assertTopUpTunaiDiizinkan(): void
    {
        $setelan = CompanySettings::query()->value('topup_tunai_dompet_santri');
        if ($setelan !== null && ! $setelan) {
            throw new AppException(422, 'Setor tunai langsung ke Dompet Santri sedang dinonaktifkan. Isi lewat Dompet Wali lalu distribusikan, atau nyalakan kembali di Pengaturan Perusahaan.');
        }
    }

    private function assertRekening(string $kodeRekening): void
    {
        $rek = BankAccount::find($kodeRekening);
        if (! $rek) {
            throw new AppException(400, 'Kas/rekening penerima tidak ditemukan.');
        }
        if ($rek->status !== 'aktif') {
            throw new AppException(422, "Rekening \"{$rek->nama_rekening}\" berstatus nonaktif.");
        }
    }

    private function assertTimKeuangan(int $idPengguna): void
    {
        $user = User::find($idPengguna);
        if (! $user || (! $user->tim_keuangan && ! $user->is_admin)) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh memverifikasi top-up dompet.');
        }
    }

    private function notifKeuangan(string $pesan, string $refId): void
    {
        $keuangan = User::where('tim_keuangan', true)->where('status', 'aktif')->get(['id_pengguna']);
        (new NotificationService)->kirim($keuangan->map(fn ($u) => [
            'id_pengguna' => $u->id_pengguna, 'judul' => 'Top-up dompet menunggu verifikasi',
            'pesan' => $pesan, 'jenis' => 'topup_dompet_menunggu', 'ref_jenis' => 'MutasiDompet', 'ref_id' => $refId,
        ])->all());
    }
}
