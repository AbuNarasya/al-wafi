<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Models\TransaksiDompetToko;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * PENGHUBUNG DOMPET SANTRI UNTUK KASIR WAFI MART — Fase 3a (keputusan user 20 Okt 2026).
 * Dipanggil lewat API bertanda tangan ([[App\Http\Middleware\TandaTanganToko]]) dari instans Wafi Mart.
 *
 *  • Semua aturan dompet diperiksa DI SINI (pemilik uangnya): kartu aktif, santri aktif, saldo, cadangan
 *    tarik tunai, batas harian & PIN belanja anak — lewat [[DompetBelanja]], pintu yang sama dengan kasir lokal.
 *  • `nomor` dari Wafi Mart UNIK (kunci idempoten): permintaan berulang dengan nomor & nominal sama
 *    mengembalikan hasil pertama; nomor sama dengan isi berbeda DITOLAK (409).
 *  • Saldo santri TIDAK dikirim di cek kartu; hanya sisa sesudah potong, untuk dicetak di struk.
 *  • Tanpa jurnal di sini — jurnal ringkas tiap malam (`dompet:jurnal-belanja-toko`).
 */
class LayananDompetToko
{
    /** Info kartu sebelum bayar: nama, sisa batas hari ini, perlu PIN atau tidak. */
    public function cekKartu(?string $uid, string|int|float $total): array
    {
        $santri = $this->santriKartu($uid);
        $total = Money::of($total);
        $pakai = DompetBelanja::hariIni($santri->id);
        $batas = KartuSantriService::batasHarian($santri->id);

        return [
            'nama' => $santri->nama,
            'nis' => $santri->nis,
            'sisa_batas' => Money::of(Money::isNegative(Money::sub($batas, $pakai)) ? '0' : Money::sub($batas, $pakai)),
            'perlu_pin' => Money::gt(Money::add($pakai, $total), $batas),
            'punya_pin' => KartuSantriService::punyaPin($santri->id),
        ];
    }

    /**
     * @param  array{nomor:string, uid:string, nominal:string|int|float, pin?:?string, kode_toko?:?string, nama_toko?:?string, keterangan?:?string}  $d
     */
    public function potong(array $d): array
    {
        $nomor = $this->nomor($d['nomor'] ?? null);
        $nominal = Money::of($d['nominal'] ?? 0);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal belanja harus lebih dari nol.');
        }
        if ($ada = TransaksiDompetToko::where('nomor_toko', $nomor)->first()) {
            return $this->ulang($ada, 'potong', $nominal);
        }

        $santri = $this->santriKartu($d['uid'] ?? null);
        // PIN di LUAR transaksi: hitungan salah PIN tak boleh ikut tergulung saat galat dilempar.
        $pinBenar = false;
        if (DompetBelanja::lewatBatas($santri->id, $nominal)) {
            // PIN belum dikirim ≠ PIN salah: jangan dihitung sebagai percobaan gagal (5× = terkunci).
            if (trim((string) ($d['pin'] ?? '')) === '' && KartuSantriService::punyaPin($santri->id)) {
                throw new AppException(409, 'Melewati batas belanja harian — minta santri mengetik PIN belanjanya.');
            }
            (new KartuSantriService)->periksaPin($santri->id, $d['pin'] ?? null);
            $pinBenar = true;
        }

        return DB::transaction(function () use ($d, $nomor, $nominal, $santri, $pinBenar) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('dompet-toko:'.$nomor)]);
            if ($ada = TransaksiDompetToko::where('nomor_toko', $nomor)->first()) {
                return $this->ulang($ada, 'potong', $nominal);
            }
            $toko = trim((string) ($d['nama_toko'] ?? '')) ?: 'Wafi Mart';
            $mutasi = DompetBelanja::potong($santri->id, $nominal, "Belanja {$toko} {$nomor}", $this->pencatat(), $pinBenar);
            $t = TransaksiDompetToko::create([
                'nomor_toko' => $nomor, 'jenis' => 'potong', 'id_santri' => $santri->id,
                'uid_kartu' => KartuSantriService::bersihkanUid($d['uid']), 'nominal' => $nominal,
                'kode_toko' => $d['kode_toko'] ?? null, 'nama_toko' => $toko, 'keterangan' => $d['keterangan'] ?? null,
                'id_mutasi_dompet' => $mutasi->id, 'status' => 'berhasil', 'tanggal' => now()->toDateString(),
            ]);

            return $this->hasil($t, $mutasi->saldo_setelah);
        });
    }

    /**
     * Kembalikan sebagian/seluruh potongan (batal struk / retur di Wafi Mart).
     *
     * @param  array{nomor:string, nomor_asal:string, nominal:string|int|float, keterangan?:?string}  $d
     */
    public function kembalikan(array $d): array
    {
        $nomor = $this->nomor($d['nomor'] ?? null);
        $nominal = Money::of($d['nominal'] ?? 0);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal pengembalian harus lebih dari nol.');
        }
        if ($ada = TransaksiDompetToko::where('nomor_toko', $nomor)->first()) {
            return $this->ulang($ada, 'kembalikan', $nominal);
        }

        return DB::transaction(function () use ($d, $nomor, $nominal) {
            $asal = TransaksiDompetToko::where('nomor_toko', (string) ($d['nomor_asal'] ?? ''))->where('jenis', 'potong')
                ->where('status', 'berhasil')->lockForUpdate()->first()
                ?? throw new AppException(404, 'Transaksi asal tidak ditemukan.');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('dompet-toko:'.$nomor)]);
            if ($ada = TransaksiDompetToko::where('nomor_toko', $nomor)->first()) {
                return $this->ulang($ada, 'kembalikan', $nominal);
            }
            $sudah = Money::of(TransaksiDompetToko::where('nomor_asal', $asal->nomor_toko)->where('jenis', 'kembalikan')
                ->where('status', 'berhasil')->sum('nominal'));
            $sisa = Money::sub($asal->nominal, $sudah);
            if (Money::gt($nominal, $sisa)) {
                throw new AppException(422, 'Pengembalian melebihi sisa potongan transaksi asal (Rp '.number_format((float) $sisa, 0, ',', '.').').');
            }
            $mutasi = DompetBelanja::kembalikan($asal->id_santri, $nominal, "Kembali dari {$asal->nama_toko} {$nomor} (asal {$asal->nomor_toko})", $this->pencatat());
            $t = TransaksiDompetToko::create([
                'nomor_toko' => $nomor, 'jenis' => 'kembalikan', 'nomor_asal' => $asal->nomor_toko, 'id_santri' => $asal->id_santri,
                'uid_kartu' => $asal->uid_kartu, 'nominal' => $nominal, 'kode_toko' => $asal->kode_toko, 'nama_toko' => $asal->nama_toko,
                'keterangan' => $d['keterangan'] ?? null, 'id_mutasi_dompet' => $mutasi->id, 'status' => 'berhasil',
                'tanggal' => now()->toDateString(),
            ]);

            return $this->hasil($t, $mutasi->saldo_setelah);
        });
    }

    /** Daftar transaksi satu tanggal — dasar rekonsiliasi malam di sisi Wafi Mart. */
    public function daftar(string $tanggal): array
    {
        return TransaksiDompetToko::whereDate('tanggal', $tanggal)->orderBy('id')->get()
            ->map(fn ($t) => ['nomor' => $t->nomor_toko, 'jenis' => $t->jenis, 'nomor_asal' => $t->nomor_asal,
                'nominal' => Money::of($t->nominal), 'status' => $t->status, 'kode_toko' => $t->kode_toko])->all();
    }

    // ---- pembantu ----

    public const PENGGUNA_SISTEM = 'sistem-wafimart';

    /**
     * Pencatat mutasi dari penghubung. Mutasi dompet WAJIB punya tepat satu pencatat (staf atau wali,
     * constraint `mutasi_dompet_satu_pencatat`) — jejak audit yang tak boleh dilonggarkan. Karena itu
     * penghubung mencatat atas nama akun sistem yang NONAKTIF (tak bisa masuk) tanpa hak apa pun.
     */
    private function pencatat(): int
    {
        $id = User::where('username', self::PENGGUNA_SISTEM)->value('id_pengguna');
        if ($id) {
            return $id;
        }
        $level = \App\Models\Level::orderByDesc('kode_level')->value('kode_level')
            ?? throw new AppException(500, 'Belum ada Level Otorisasi — akun sistem penghubung tak bisa dibuat.');

        return User::create([
            'username' => self::PENGGUNA_SISTEM, 'nama' => 'Sistem — Penghubung Wafi Mart', 'jabatan' => 'Akun sistem',
            'password_hash' => Hash::make(bin2hex(random_bytes(32))), 'kode_level' => $level,
            'is_admin' => false, 'tim_keuangan' => false, 'status' => 'nonaktif',
        ])->id_pengguna;
    }

    private function santriKartu(?string $uid): Santri
    {
        $k = (new KartuSantriService)->cariAktif($uid);
        if (! $k || $k->santri->status !== 'aktif') {
            throw new AppException(404, 'Kartu tidak dikenal, diblokir, atau bukan santri aktif.');
        }

        return $k->santri;
    }

    private function nomor(?string $nomor): string
    {
        $nomor = trim((string) $nomor);
        if ($nomor === '' || strlen($nomor) > 64) {
            throw new AppException(422, 'Nomor transaksi toko wajib (maks. 64 karakter).');
        }

        return $nomor;
    }

    /** Permintaan berulang: hasil yang sama bila isinya sama; nomor dipakai ulang untuk isi lain = DITOLAK. */
    private function ulang(TransaksiDompetToko $ada, string $jenis, string $nominal): array
    {
        if ($ada->jenis !== $jenis || ! Money::eq($ada->nominal, $nominal)) {
            throw new AppException(409, "Nomor {$ada->nomor_toko} sudah dipakai untuk transaksi lain.");
        }

        return $this->hasil($ada, $ada->mutasi?->saldo_setelah) + ['ulang' => true];
    }

    private function hasil(TransaksiDompetToko $t, string|int|float|null $saldoSetelah): array
    {
        return [
            'nomor' => $t->nomor_toko, 'id' => $t->id, 'jenis' => $t->jenis, 'nominal' => Money::of($t->nominal),
            'nama' => $t->santri?->nama, 'sisa_saldo' => $saldoSetelah !== null ? Money::of($saldoSetelah) : null,
            'sisa_batas' => Money::of(max(0, (float) Money::sub(KartuSantriService::batasHarian($t->id_santri), DompetBelanja::hariIni($t->id_santri)))),
        ];
    }
}
