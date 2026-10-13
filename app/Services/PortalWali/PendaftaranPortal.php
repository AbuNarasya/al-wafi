<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\JalurNonaktif;
use App\Models\JalurPendaftaran;
use App\Models\Jenjang;
use App\Models\Pendaftaran;
use App\Models\PersetujuanTerm;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\Wali;
use App\Services\Modules\GelombangService;
use App\Services\Modules\SantriService;
use App\Services\Modules\SuratPernyataanService;
use App\Services\Modules\TahunAjaranService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PENDAFTARAN MANDIRI LEWAT PORTAL WALI.
 *
 * Menulis lewat SantriService::create — jalur yang SAMA dengan panitia — supaya
 * aturan tarif, gelombang, registrasi, dan nomor pendaftaran tak ditulis dua
 * kali. Yang ditambahkan di sini hanyalah apa yang membedakan pintu publik:
 *
 *  • T.A & gelombang TIDAK dipilih bebas — hanya gelombang yang sedang dibuka
 *    hari itu. Tak ada yang buka = pendaftaran portal tutup;
 *  • jalur hanya yang dicentang "boleh dipilih wali" di master Jalur;
 *  • NOMOR HP YANG SUDAH TERDAFTAR DITOLAK. Menempelkan pendaftar ke keluarga
 *    yang sudah ada berarti siapa pun yang tahu nomor HP seorang wali bisa
 *    melihat anak-anak & dompet keluarga itu. Pesannya pun tak menyebut nama
 *    keluarga pemilik nomor;
 *  • surat pernyataan versi aktif WAJIB disetujui, dan salinan teks + hash-nya
 *    disimpan bersama tanda tangan elektronik (email yang terbukti lewat OTP).
 */
class PendaftaranPortal
{
    /**
     * Pilihan formulir — hanya yang sedang dibuka.
     *
     * @return array{gelombang:list<array{tahun_ajaran:string,kode:string,nama:string,sampai:?string}>, jenjang:list<array<string,mixed>>, jalur:array<string,string>, surat:?\App\Models\TermTemplate}
     */
    public function pilihan(): array
    {
        $gelombang = [];
        $taSah = [];
        foreach (TahunAjaran::where('status', 'aktif')->orderBy('tanggal_mulai')->get() as $ta) {
            try {
                (new TahunAjaranService)->assertTidakMundur($ta->kode);
            } catch (AppException) {
                continue;
            }
            foreach ((new GelombangService)->daftar($ta->kode)->filter(fn ($g) => $g->keadaan() === 'berlaku') as $g) {
                $gelombang[] = ['tahun_ajaran' => $ta->kode, 'kode' => $g->kode, 'nama' => $g->nama,
                    'sampai' => $g->berlaku_sampai?->format('d/m/Y')];
                $taSah[$ta->kode] = true;
            }
        }

        $jalurPortal = JalurPendaftaran::where('status', 'aktif')->where('tampil_di_portal', true)
            ->orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();

        $jenjang = Jenjang::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get()
            ->filter(fn (Jenjang $j) => $j->tingkatAkhir() > 0)
            ->map(fn (Jenjang $j) => [
                'kode' => $j->kode, 'nama' => $j->nama,
                'tingkat' => range($j->tingkatMulai(), $j->tingkatAkhir()),
                // Jalur yang dikecualikan pada (T.A, jenjang) — diterapkan di layar per T.A.
                'jalur_nonaktif' => collect(array_keys($taSah))
                    ->mapWithKeys(fn ($ta) => [$ta => JalurNonaktif::kodeUntuk($ta, $j->kode)])->all(),
            ])->values()->all();

        return ['gelombang' => $gelombang, 'jenjang' => $jenjang, 'jalur' => $jalurPortal,
            'surat' => SuratPernyataanService::aktif()];
    }

    public static function normalTelepon(string $telepon): string
    {
        $angka = preg_replace('/\D/', '', $telepon);

        return str_starts_with($angka, '62') ? '0'.substr($angka, 2) : $angka;
    }

    public static function teleponTerdaftar(string $telepon): bool
    {
        $normal = self::normalTelepon($telepon);
        $ekspresi = fn (string $kol) => "regexp_replace(regexp_replace(COALESCE({$kol}, ''), '\\D', '', 'g'), '^62', '0')";

        return Wali::where(function ($q) use ($ekspresi, $normal) {
            foreach (['telepon', 'telepon_ayah', 'telepon_ibu', 'telepon_wali'] as $kol) {
                $q->orWhereRaw($ekspresi($kol).' = ?', [$normal]);
            }
        })->exists();
    }

    public static function emailTerdaftar(string $email): bool
    {
        return Wali::where(function ($q) use ($email) {
            foreach (MasukWali::PERAN as $p) {
                $q->orWhereRaw("lower(trim(email_{$p})) = ?", [MasukWali::normalEmail($email)]);
            }
        })->exists();
    }

    /**
     * Data keluarga baru selengkap modul Wali/Keluarga: ayah, ibu, wali — masing-masing
     * nama, HP, email, pekerjaan, pendapatan.
     *
     * SETIAP nomor HP & email yang diisi diperiksa, bukan cuma milik pendaftar: email
     * ibu yang sudah tercatat di keluarga lain akan membuat ibu itu ditolak masuk
     * ("kembar"), dan nomor yang sudah terdaftar menandai keluarga yang sudah ada —
     * yang harus masuk lewat email tercatatnya, bukan membuat keluarga kedua. Pesannya
     * tak pernah menyebut pemilik data yang bentrok.
     *
     * Email pendaftar diambil dari OTP, bukan dari form: itulah identitas masuknya.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws AppException
     */
    private function keluargaBaru(array $data, string $peran, string $emailPendaftar): array
    {
        $keluarga = [
            'kontak_utama' => $peran, 'status' => 'aktif',
            'nik' => self::teks($data['nik'] ?? null), 'alamat' => self::teks($data['alamat'] ?? null),
        ];

        foreach (MasukWali::PERAN as $p) {
            $label = Wali::PERAN[$p];
            $telepon = self::teks($data["telepon_{$p}"] ?? null);
            $email = $p === $peran ? $emailPendaftar : self::teks($data["email_{$p}"] ?? null);

            if ($telepon !== null && self::teleponTerdaftar($telepon)) {
                throw new AppException(409, $p === $peran
                    ? 'Nomor HP ini sudah terdaftar di data keluarga santri. Masuklah dengan email yang tercatat di '
                        .'keluarga Anda, atau hubungi panitia PSB untuk memperbarui email Anda.'
                    : "Nomor HP {$label} sudah terdaftar di data keluarga santri lain. Kosongkan isiannya, atau hubungi panitia PSB.");
            }
            if ($p !== $peran && $email !== null && self::emailTerdaftar($email)) {
                throw new AppException(409, "Email {$label} sudah terdaftar di data keluarga santri lain. Kosongkan isiannya, atau hubungi panitia PSB.");
            }

            $pekerjaan = $data["pekerjaan_{$p}"] ?? null;
            $keluarga += [
                "nama_{$p}" => self::teks($data["nama_{$p}"] ?? null),
                "telepon_{$p}" => $telepon !== null ? self::normalTelepon($telepon) : null,
                "email_{$p}" => $email !== null ? MasukWali::normalEmail($email) : null,
                "pekerjaan_{$p}" => $pekerjaan === Wali::PEKERJAAN_LAIN
                    ? self::teks($data["pekerjaan_lain_{$p}"] ?? null)
                    : self::teks($pekerjaan),
                "pendapatan_{$p}" => self::teks($data["pendapatan_{$p}"] ?? null),
            ];
        }

        return $keluarga;
    }

    private static function teks(mixed $nilai): ?string
    {
        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }

    /**
     * @param  array<string,mixed>  $data  isian formulir yang sudah divalidasi controller
     * @param  ?Wali  $wali  keluarga yang sedang masuk; null = pendaftar baru
     * @param  array{email:string, peran:string, ip:?string, user_agent:?string}  $penanda
     *
     * @throws AppException
     */
    public function daftar(array $data, ?Wali $wali, array $penanda): Santri
    {
        $pilihan = $this->pilihan();

        $surat = $pilihan['surat'];
        if (! $surat || (int) ($data['versi_surat'] ?? 0) !== $surat->versi) {
            // Versi yang dibaca wali harus versi yang disimpan: bila panitia
            // mengaktifkan versi baru saat formulir terbuka, wali membaca ulang.
            throw new AppException(409, 'Surat pernyataan baru saja diperbarui panitia. Muat ulang halaman dan baca kembali sebelum mendaftar.');
        }

        $gelombang = collect($pilihan['gelombang'])->first(fn ($g) => $g['tahun_ajaran'] === ($data['tahun_ajaran'] ?? null)
            && $g['kode'] === ($data['gelombang'] ?? null));
        if (! $gelombang) {
            throw new AppException(422, 'Gelombang pendaftaran yang dipilih sudah ditutup. Muat ulang halaman.');
        }
        if (! array_key_exists($data['jalur'] ?? '', $pilihan['jalur'])) {
            throw new AppException(422, 'Jalur pendaftaran ini tidak tersedia di portal.');
        }
        $jenjang = collect($pilihan['jenjang'])->firstWhere('kode', $data['kode_jenjang'] ?? null);
        if (! $jenjang || ! in_array((int) ($data['tingkat'] ?? 0), $jenjang['tingkat'], true)) {
            throw new AppException(422, 'Jenjang atau kelas tidak tersedia.');
        }
        if (in_array($data['jalur'], $jenjang['jalur_nonaktif'][$data['tahun_ajaran']] ?? [], true)) {
            throw new AppException(422, "Jalur {$pilihan['jalur'][$data['jalur']]} tidak dibuka untuk {$jenjang['nama']}.");
        }

        $isianSantri = [
            'nama' => $data['nama'], 'jenis_kelamin' => $data['jenis_kelamin'],
            'tempat_lahir' => $data['tempat_lahir'] ?? null, 'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
            'asal_sekolah' => $data['asal_sekolah'] ?? null,
            'kode_jenjang' => $data['kode_jenjang'], 'tingkat' => (int) $data['tingkat'],
            'tahun_ajaran' => $data['tahun_ajaran'], 'jalur' => $data['jalur'], 'gelombang' => $data['gelombang'],
            'sumber_informasi' => $data['sumber_informasi'] ?? null,
            'sumber_informasi_lain' => $data['sumber_informasi_lain'] ?? null,
        ];

        if ($wali) {
            $isianSantri['id_wali'] = $wali->id;
            $peran = $penanda['peran'];
        } else {
            $peran = $data['peran'];
            $isianSantri['wali_baru'] = $this->keluargaBaru($data, $peran, $penanda['email']);
        }

        try {
            return DB::transaction(function () use ($isianSantri, $surat, $peran, $penanda, $data, $wali) {
                $santri = (new SantriService)->create($isianSantri);

                Pendaftaran::where('id_santri', $santri->id)->update(['asal' => 'portal']);

                $nama = $wali ? (string) $wali->{"nama_{$peran}"} : (string) $data["nama_{$peran}"];
                PersetujuanTerm::create([
                    'id_santri' => $santri->id, 'id_wali' => $santri->id_wali, 'id_term_template' => $surat->id,
                    'isi_umum' => $surat->isi, 'hash_sha256' => hash('sha256', $surat->isi),
                    'metode_ttd' => 'otp_email', 'status' => 'disetujui', 'disetujui_pada' => now(),
                    'penanda_tangan_nama' => $nama ?: $santri->wali->nama,
                    'penanda_tangan_telepon' => $wali ? $wali->{"telepon_{$peran}"} : self::normalTelepon($data["telepon_{$peran}"]),
                    'penanda_tangan_email' => $penanda['email'], 'penanda_tangan_peran' => $peran,
                    'ip_address' => $penanda['ip'], 'user_agent' => mb_substr((string) $penanda['user_agent'], 0, 1000),
                    // Email-nya dibuktikan dengan kode saat masuk sesi ini.
                    'otp_terverifikasi_pada' => now(),
                ]);

                return $santri;
            });
        } catch (AppException $e) {
            // Pesan service ditulis untuk panitia (mis. "isi selnya di Setting Awal → Tarif").
            // Wali tak bisa berbuat apa-apa atasnya — dicatat untuk panitia, wali diberi pesan umum.
            Log::warning('Pendaftaran portal ditolak service', ['pesan' => $e->getMessage(), 'email' => $penanda['email']]);
            throw new AppException(422, 'Pendaftaran untuk pilihan ini belum bisa diproses. Silakan hubungi panitia PSB.');
        }
    }
}
