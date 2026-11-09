<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\MenuMitra;
use App\Models\Mitra;
use App\Models\PenjualanMitra;
use App\Models\User;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * MITRA KANTIN WAFI MART — sisi staf (hak `mitra-kantin`, keputusan user 9 Nov 2026): data mitra, daftar menu
 * (diinput kepala Wafi Mart dari setoran mitra), akun mitra, dan laporan penjualan. Kasirnya di [[KasirMitraService]].
 */
class MitraKantinService
{
    public const MODUL = 'mitra-kantin';

    public function simpanMitra(array $d, ?Mitra $mitra, int $idPengguna): Mitra
    {
        $kode = strtoupper(trim((string) ($d['kode'] ?? '')));
        if (! preg_match('/^[A-Z0-9-]{2,20}$/', $kode)) {
            throw new AppException(422, 'Kode mitra 2–20 huruf/angka (mis. KB01).');
        }
        if (Mitra::where('kode', $kode)->when($mitra, fn ($q) => $q->whereKeyNot($mitra->id))->exists()) {
            throw new AppException(422, "Kode {$kode} sudah dipakai mitra lain.");
        }
        $isi = [
            'kode' => $kode, 'nama' => trim((string) $d['nama']),
            'pemilik' => self::teks($d['pemilik'] ?? null), 'telepon' => self::teks($d['telepon'] ?? null),
            'bank_nama' => self::teks($d['bank_nama'] ?? null), 'bank_rekening' => self::teks($d['bank_rekening'] ?? null),
            'bank_atas_nama' => self::teks($d['bank_atas_nama'] ?? null),
            'sewa_mulai' => $d['sewa_mulai'] ?? null, 'sewa_sampai' => $d['sewa_sampai'] ?? null,
            'keterangan_sewa' => self::teks($d['keterangan_sewa'] ?? null),
            'status' => ($d['status'] ?? 'aktif') === 'nonaktif' ? 'nonaktif' : 'aktif',
        ];
        if ($mitra) {
            // Kode = kode toko yang tercatat di ERP Al Wafi untuk setiap transaksi: tak boleh berganti sesudah berjualan.
            if ($mitra->kode !== $kode && PenjualanMitra::where('id_mitra', $mitra->id)->exists()) {
                throw new AppException(422, 'Kode mitra tak bisa diganti sesudah ada penjualan.');
            }
            $mitra->update($isi);

            return $mitra;
        }

        return Mitra::create($isi + ['dibuat_oleh' => $idPengguna]);
    }

    public function simpanMenu(Mitra $mitra, array $d, ?MenuMitra $menu = null): MenuMitra
    {
        $nama = trim((string) ($d['nama'] ?? ''));
        $harga = Money::of($d['harga'] ?? 0);
        if ($nama === '' || ! Money::gtZero($harga)) {
            throw new AppException(422, 'Nama menu wajib dan harganya lebih dari nol.');
        }
        $bentrok = MenuMitra::where('id_mitra', $mitra->id)->whereRaw('lower(nama) = ?', [mb_strtolower($nama)])
            ->when($menu, fn ($q) => $q->whereKeyNot($menu->id))->exists();
        if ($bentrok) {
            throw new AppException(422, "Menu \"{$nama}\" sudah ada di {$mitra->nama}.");
        }
        $isi = ['nama' => $nama, 'harga' => $harga, 'aktif' => (bool) ($d['aktif'] ?? true)];
        if ($menu) {
            abort_unless($menu->id_mitra === $mitra->id, 404);
            $menu->update($isi);

            return $menu;
        }

        return MenuMitra::create($isi + ['id_mitra' => $mitra->id]);
    }

    /** Akun login untuk mitra — hanya bisa membuka layar /mitra (middleware AkunMitra), tanpa hak modul apa pun. */
    public function buatAkun(Mitra $mitra, string $username, string $sandi, int $idPengguna): User
    {
        $username = trim($username);
        if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new AppException(422, 'Username 3–50 huruf/angka (boleh titik, garis bawah, strip).');
        }
        if (User::whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            throw new AppException(422, "Username {$username} sudah dipakai.");
        }
        self::pastikanSandi($sandi);
        $level = \App\Models\Level::orderByDesc('kode_level')->value('kode_level')
            ?? throw new AppException(422, 'Belum ada Level Otorisasi — buat dulu di Setting Awal.');

        $u = User::create([
            'username' => $username, 'nama' => $mitra->nama, 'jabatan' => 'Mitra kantin',
            'password_hash' => Hash::make($sandi), 'kode_level' => $level, 'is_admin' => false,
            'tim_keuangan' => false, 'status' => 'aktif', 'id_mitra' => $mitra->id,
        ]);
        Jejak::catat('buat_akun_mitra', ['modul' => self::MODUL, 'id_pengguna' => $idPengguna, 'ref_jenis' => 'mitra', 'ref_id' => $mitra->id,
            'detail' => "Akun {$username} untuk mitra {$mitra->nama}"]);

        return $u;
    }

    /** Ganti kata sandi dan/atau aktif-nonaktifkan akun mitra. */
    public function ubahAkun(Mitra $mitra, User $akun, ?string $sandi, ?string $status, int $idPengguna): User
    {
        abort_unless((int) $akun->id_mitra === $mitra->id, 404);
        $isi = [];
        if ($sandi !== null && $sandi !== '') {
            self::pastikanSandi($sandi);
            $isi['password_hash'] = Hash::make($sandi);
        }
        if (in_array($status, ['aktif', 'nonaktif'], true) && $status !== $akun->status) {
            $isi['status'] = $status;
        }
        if ($isi === []) {
            return $akun;
        }
        $akun->update($isi);
        Jejak::catat('ubah_akun_mitra', ['modul' => self::MODUL, 'id_pengguna' => $idPengguna, 'ref_jenis' => 'mitra', 'ref_id' => $mitra->id,
            'detail' => "Akun {$akun->username}: ".implode(', ', array_filter([
                isset($isi['password_hash']) ? 'kata sandi diganti' : null,
                isset($isi['status']) ? "status {$isi['status']}" : null,
            ]))]);

        return $akun;
    }

    /**
     * Laporan penjualan kartu satu mitra (atau semua bila null) dalam rentang tanggal: per hari, per menu, dan
     * daftar transaksinya. Hanya yang BERHASIL dihitung; yang dibatalkan ditampilkan terpisah.
     *
     * @return array{per_hari:\Illuminate\Support\Collection, per_menu:\Illuminate\Support\Collection, transaksi:\Illuminate\Support\Collection, total:string, jumlah:int, batal:int, nilai_batal:string}
     */
    public function laporan(?int $idMitra, string $dari, string $sampai): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->endOfDay();
        $dasar = fn () => PenjualanMitra::query()->when($idMitra, fn ($q) => $q->where('id_mitra', $idMitra))
            ->whereBetween('waktu', [$dari, $sampai]);

        $berhasil = $dasar()->where('status', 'berhasil');
        $perHari = (clone $berhasil)->selectRaw("to_char(waktu, 'YYYY-MM-DD') as tanggal, count(*) as jumlah, sum(total) as total")
            ->groupBy('tanggal')->orderBy('tanggal')->get();
        $perMenu = DB::table('penjualan_mitra_rincian as r')->join('penjualan_mitra as p', 'p.id', '=', 'r.id_penjualan_mitra')
            ->where('p.status', 'berhasil')->whereBetween('p.waktu', [$dari, $sampai])
            ->when($idMitra, fn ($q) => $q->where('p.id_mitra', $idMitra))
            ->selectRaw('r.nama, sum(r.jumlah) as jumlah, sum(r.subtotal) as nilai')->groupBy('r.nama')->orderByDesc('nilai')->get();
        $batal = $dasar()->where('status', 'batal');

        return [
            'per_hari' => $perHari,
            'per_menu' => $perMenu,
            'transaksi' => $dasar()->whereIn('status', ['berhasil', 'batal', 'belum_pasti', 'menunggu'])
                ->with('rincian', 'mitra:id,nama')->orderByDesc('waktu')->limit(500)->get(),
            'total' => Money::of((clone $berhasil)->sum('total')),
            'jumlah' => (clone $berhasil)->count(),
            'batal' => (clone $batal)->count(),
            'nilai_batal' => Money::of((clone $batal)->sum('total')),
        ];
    }

    private static function pastikanSandi(string $sandi): void
    {
        if (mb_strlen($sandi) < 8) {
            throw new AppException(422, 'Kata sandi minimal 8 karakter.');
        }
    }

    private static function teks(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
