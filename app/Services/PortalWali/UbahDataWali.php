<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\HakAksesModul;
use App\Models\NotifikasiWali;
use App\Models\Santri;
use App\Models\User;
use App\Models\Wali;
use App\Notifications\EmailWaliDiganti;
use App\Services\Modules\NotificationService;
use App\Services\Modules\WaliService;
use App\Services\Ppsb\Tahap;
use App\Support\Audit\Jejak;
use App\Support\Teks;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * MENU "DATA WALI & SANTRI" — wali membetulkan data pribadi keluarganya sendiri.
 *
 * Keputusan user 14 Okt 2026: SEMUA perubahan LANGSUNG tersimpan, tanpa menunggu
 * persetujuan; sebagai gantinya staf pemegang hak ubah santri diberi kabar berisi
 * rincian lama → baru, dan setiap perubahan tercatat di Jejak Audit (modul
 * `portal-wali`) dengan nama & peran pengubahnya.
 *
 * Yang SENGAJA tak bisa diubah wali: NIS, jenjang, tingkat, jalur, status, kontak
 * utama — keputusan administrasi sekolah, bukan data pribadi. Santri berstatus akhir
 * (alumni, keluar, …) hanya bisa dilihat.
 *
 * Email = identitas masuk portal, jadi gantiEmail() hanya boleh dipanggil SESUDAH
 * kode OTP yang dikirim ke alamat BARU terbukti benar (lihat PortalDataController),
 * dan pemilik alamat LAMA diberi tahu lewat surel.
 */
class UbahDataWali
{
    /** Jenis notifikasi staf — kabar, bukan tugas: tak ada yang perlu disetujui. */
    public const JENIS_NOTIF = 'data_wali_diubah';

    public const MODUL_JEJAK = 'portal-wali';

    /** Kolom santri yang boleh dibetulkan wali, beserta labelnya. */
    public const KOLOM_SANTRI = [
        'nama' => 'Nama lengkap', 'jenis_kelamin' => 'Jenis kelamin',
        'tempat_lahir' => 'Tempat lahir', 'tanggal_lahir' => 'Tanggal lahir', 'nisn' => 'NISN',
        'asal_sekolah' => 'Sekolah asal', 'alamat_sekolah_asal' => 'Alamat sekolah asal',
        'kepala_sekolah_asal' => 'Kepala sekolah asal', 'cp_kepala_sekolah_asal' => 'Kontak kepala sekolah asal',
        'wali_kelas_asal' => 'Wali kelas asal', 'cp_wali_kelas_asal' => 'Kontak wali kelas asal',
    ];

    /**
     * Kolom santri yang TAK boleh diubah wali walau tampil di KOLOM_SANTRI (keputusan user
     * 14 Okt 2026): nama tercetak di kuitansi, tagihan & berkas PSB — hanya admin yang
     * boleh mengubahnya (lihat SantriController::update). Diabaikan diam-diam bila dikirim.
     */
    public const KOLOM_SANTRI_TERKUNCI = ['nama'];

    /** Kolom keluarga per peran (nama_ayah, telepon_ayah, …). Email lewat gantiEmail(). */
    public const KOLOM_PERAN = ['nama' => 'Nama', 'telepon' => 'No. HP', 'pekerjaan' => 'Pekerjaan', 'pendapatan' => 'Pendapatan'];

    public const KOLOM_KELUARGA = ['nik' => 'NIK', 'alamat' => 'Alamat'];

    public const JENIS_KELAMIN = ['L' => 'Laki-laki', 'P' => 'Perempuan'];

    /**
     * @param  bool  $tanpaKabarStaf  MODE VERIFIKASI: ±1.100 keluarga yang serentak membetulkan
     *                                datanya akan membanjiri lonceng staf dengan ribuan kabar. Jejak
     *                                audit tetap ditulis; staf memantau lewat layar Verifikasi Data Wali.
     */
    public function __construct(
        private readonly Wali $wali,
        private readonly string $peran,
        private readonly bool $tanpaKabarStaf = false,
    ) {}

    public static function bolehUbahSantri(Santri $s): bool
    {
        return ! Tahap::isFinal((string) $s->status);
    }

    /** Label kolom keluarga untuk catatan & kabar: "No. HP Ibu". */
    public static function labelKeluarga(string $kolom): string
    {
        if (isset(self::KOLOM_KELUARGA[$kolom])) {
            return self::KOLOM_KELUARGA[$kolom];
        }
        [$jenis, $peran] = explode('_', $kolom, 2) + [1 => ''];

        return (self::KOLOM_PERAN[$jenis] ?? ($jenis === 'email' ? 'Email' : $jenis)).' '.(Wali::PERAN[$peran] ?? $peran);
    }

    // ══════════════════════════════════════════════════════════════════
    //  SANTRI
    // ══════════════════════════════════════════════════════════════════

    /**
     * @param  array<string,mixed>  $data  isian yang sudah divalidasi controller
     * @return array<string,array{lama:?string,baru:?string}> rincian yang berubah (kosong = tak ada)
     *
     * @throws AppException
     */
    public function ubahSantri(Santri $santri, array $data): array
    {
        if ((int) $santri->id_wali !== (int) $this->wali->id) {
            throw new AppException(404, __('Santri tidak ditemukan.'));
        }
        if (! self::bolehUbahSantri($santri)) {
            throw new AppException(422, __('Data santri berstatus :status tidak bisa diubah lewat portal. Hubungi bagian kesantrian.',
                ['status' => \App\Support\BahasaPortal::statusSantri((string) $santri->status)]));
        }

        $baru = [];
        foreach (array_keys(self::KOLOM_SANTRI) as $kolom) {
            if (array_key_exists($kolom, $data) && ! in_array($kolom, self::KOLOM_SANTRI_TERKUNCI, true)) {
                $baru[$kolom] = self::teks($data[$kolom]);
            }
        }
        // Lewat fill() + getDirty(): mutator nama (kapital) & cast tanggal ikut
        // menentukan "berubah" — "ahmad" yang dikirim ulang untuk "AHMAD" tak dicatat.
        $santri->fill($baru);
        $perubahan = [];
        foreach (array_keys($santri->getDirty()) as $kolom) {
            $perubahan[self::KOLOM_SANTRI[$kolom]] = [
                'lama' => self::tampil($kolom, $santri->getOriginal($kolom)),
                'baru' => self::tampil($kolom, $santri->getAttribute($kolom)),
            ];
        }
        if ($perubahan === []) {
            return [];
        }
        $santri->save();

        $this->umumkan('Santri', $santri->id, $perubahan, Teks::k('data santri :nama', ['nama' => $santri->nama]), $santri->id);

        return $perubahan;
    }

    // ══════════════════════════════════════════════════════════════════
    //  KELUARGA
    // ══════════════════════════════════════════════════════════════════

    /**
     * @param  array<string,mixed>  $data  nik, alamat, nama_/telepon_/pekerjaan_/pendapatan_{peran}
     * @return array<string,array{lama:?string,baru:?string}>
     *
     * @throws AppException
     */
    public function ubahKeluarga(array $data): array
    {
        $baru = [];
        foreach (array_keys(self::KOLOM_KELUARGA) as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $baru[$kolom] = self::teks($data[$kolom]);
            }
        }
        foreach (MasukWali::PERAN as $p) {
            foreach (array_keys(self::KOLOM_PERAN) as $jenis) {
                if (array_key_exists("{$jenis}_{$p}", $data)) {
                    $nilai = self::teks($data["{$jenis}_{$p}"]);
                    $baru["{$jenis}_{$p}"] = $jenis === 'telepon' && $nilai !== null ? PendaftaranPortal::normalTelepon($nilai) : $nilai;
                }
            }
        }

        $cek = (clone $this->wali)->fill($baru);
        $berubah = $cek->getDirty();
        if ($berubah === []) {
            return [];
        }

        // Hanya nomor yang BERUBAH yang diperiksa: kembaran lama di data impor tak
        // boleh menghalangi wali membetulkan alamatnya. Pesannya tak pernah menyebut
        // keluarga pemilik nomor itu (WaliService menyebut namanya — untuk staf).
        foreach (MasukWali::PERAN as $p) {
            $telepon = $berubah["telepon_{$p}"] ?? null;
            if ($telepon !== null && PendaftaranPortal::teleponTerdaftar($telepon, $this->wali->id)) {
                throw new AppException(409, __('Nomor HP :peran sudah terdaftar di data keluarga santri lain. Pakai nomor lain, atau hubungi bagian kesantrian.',
                    ['peran' => __(Wali::PERAN[$p])]));
            }
        }

        $perubahan = [];
        foreach ($berubah as $kolom => $nilai) {
            $perubahan[self::labelKeluarga($kolom)] = [
                'lama' => self::tampil($kolom, $this->wali->getOriginal($kolom)),
                'baru' => self::tampil($kolom, $nilai),
            ];
        }

        // WaliService menyalin ulang nama & telepon kontak utama (kolom `nama`/`telepon`)
        // dan menolak bila kontak utama jadi tak bernama / tak bernomor HP.
        (new WaliService)->update($this->wali->id, $berubah);
        $this->wali->refresh();

        $this->umumkan('Wali', $this->wali->id, $perubahan, Teks::k('data keluarga'));

        return $perubahan;
    }

    // ══════════════════════════════════════════════════════════════════
    //  EMAIL
    // ══════════════════════════════════════════════════════════════════

    /**
     * Pemeriksaan SEBELUM kode dikirim — wali tak perlu menunggu kode untuk tahu
     * alamatnya ditolak.
     *
     * @throws AppException
     */
    public function periksaEmailBaru(string $sasaran, string $email): string
    {
        if (! in_array($sasaran, MasukWali::PERAN, true)) {
            throw new AppException(404, __('Peran tidak dikenal.'));
        }
        $label = __(Wali::PERAN[$sasaran]);
        $email = MasukWali::normalEmail($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException(422, __('Alamat email tidak sah.'));
        }
        if (! filled($this->wali->{"nama_{$sasaran}"})) {
            throw new AppException(422, __('Isi dulu nama :peran di Data Wali sebelum mengisi emailnya.', ['peran' => $label]));
        }
        if ($email === MasukWali::normalEmail((string) $this->wali->{"email_{$sasaran}"})) {
            throw new AppException(422, __('Email itu sudah tercatat sebagai email :peran.', ['peran' => $label]));
        }
        // Email yang tercatat di dua keluarga ditolak masuk ("kembar") — jadi dicegah di sini.
        if (PendaftaranPortal::emailTerdaftar($email, $this->wali->id)) {
            throw new AppException(409, __('Email ini sudah terdaftar di data keluarga santri lain. Pakai email lain, atau hubungi bagian kesantrian.'));
        }

        return $email;
    }

    /**
     * Dipanggil SESUDAH kode yang dikirim ke $email terbukti benar.
     *
     * Wali::booted mencabut semua perangkat yang diingat keluarga ini — perangkat
     * yang sedang dipakai diingat ulang oleh controller.
     *
     * @throws AppException
     */
    public function gantiEmail(string $sasaran, string $email): void
    {
        $email = $this->periksaEmailBaru($sasaran, $email);
        $lama = self::teks($this->wali->{"email_{$sasaran}"});

        DB::transaction(fn () => $this->wali->update(["email_{$sasaran}" => $email]));

        if ($lama !== null) {
            Notification::route('mail', $lama)->notify(new EmailWaliDiganti(
                strtolower(Wali::PERAN[$sasaran]), $this->namaPengubah(), $email,
            ));
        }

        $this->umumkan('Wali', $this->wali->id, [self::labelKeluarga("email_{$sasaran}") => ['lama' => $lama, 'baru' => $email]], Teks::k('email keluarga'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  JEJAK & KABAR
    // ══════════════════════════════════════════════════════════════════

    public function namaPengubah(): string
    {
        $nama = $this->wali->{"nama_{$this->peran}"} ?: $this->wali->nama;

        return trim($nama.' ('.(Wali::PERAN[$this->peran] ?? $this->peran).')');
    }

    /** namaPengubah() untuk kabar wali: perannya ("Ayah") ikut bahasa penerima. */
    private function namaPengubahTeks(): Teks
    {
        return Teks::k(':nama (:peran)', [
            'nama' => ltrim((string) ($this->wali->{"nama_{$this->peran}"} ?: $this->wali->nama)),
            'peran' => Teks::k(Wali::PERAN[$this->peran] ?? $this->peran),
        ]);
    }

    /**
     * Label kolom pada rincian (kunci $perubahan, mis. "No. HP Ibu") → Teks yang bisa diterjemahkan. Label yang tak
     * dikenal tampil apa adanya.
     */
    private static function labelTeks(string $label): string|Teks
    {
        static $peta = null;
        if ($peta === null) {
            $peta = [];
            foreach (self::KOLOM_SANTRI + self::KOLOM_KELUARGA as $l) {
                $peta[$l] = Teks::k($l);
            }
            foreach (Wali::PERAN as $p => $namaPeran) {
                foreach (self::KOLOM_PERAN + ['email' => 'Email'] as $jenis => $l) {
                    $peta[self::labelKeluarga("{$jenis}_{$p}")] = Teks::k(':kolom :peran', ['kolom' => Teks::k($l), 'peran' => Teks::k($namaPeran)]);
                }
            }
        }

        return $peta[$label] ?? $label;
    }

    /** Nilai rincian: label tetap (Laki-laki, rentang pendapatan) ikut diterjemahkan; isian wali apa adanya. */
    private static function nilaiTeks(?string $nilai): string|Teks
    {
        if ($nilai === null) {
            return '—';
        }

        return in_array($nilai, self::JENIS_KELAMIN, true) || in_array($nilai, Wali::PENDAPATAN, true) ? Teks::k($nilai) : $nilai;
    }

    /**
     * Jejak audit + kabar staf + kabar anggota keluarga lainnya. Kegagalan di sini
     * TIDAK membatalkan perubahannya (sudah tersimpan): data yang benar lebih penting
     * daripada pemberitahuannya.
     *
     * @param  array<string,array{lama:?string,baru:?string}>  $perubahan
     */
    private function umumkan(string $refJenis, int $refId, array $perubahan, Teks $apa, ?int $idSantri = null): void
    {
        $rincian = collect($perubahan)
            ->map(fn ($n, $label) => "{$label}: ".($n['lama'] ?? '—').' → '.($n['baru'] ?? '—'))
            ->implode('; ');

        try {
            Jejak::catat('ubah_data_portal', [
                'modul' => self::MODUL_JEJAK, 'ref_jenis' => $refJenis, 'ref_id' => $refId,
                'perubahan' => $perubahan, 'detail' => "Diubah oleh {$this->namaPengubah()} lewat Portal Wali",
            ]);
        } catch (\Throwable) {
            // sengaja dibiarkan — lihat docblock
        }

        if (! $this->tanpaKabarStaf) {
            try {
                (new NotificationService)->kirim(array_map(fn (int $id) => [
                    'id_pengguna' => $id,
                    'judul' => 'Wali mengubah '.$apa,
                    'pesan' => mb_strimwidth("{$this->namaPengubah()} mengubah {$apa} lewat Portal Wali. {$rincian}", 0, 1000, '…'),
                    'jenis' => self::JENIS_NOTIF, 'ref_jenis' => $refJenis, 'ref_id' => (string) $refId,
                ], self::penerimaStaf()));
            } catch (\Throwable) {
                // sengaja dibiarkan
            }
        }

        // Anggota keluarga lain yang bisa masuk portal ikut tahu — terutama bila
        // emailnya sendiri yang diganti. Disusun dalam bahasa portal masing-masing (portal tiga bahasa).
        try {
            foreach (MasukWali::PERAN as $p) {
                if ($p !== $this->peran && filled($this->wali->{"email_{$p}"})) {
                    $bahasa = KabarWali::bahasa($this->wali->id, $p);
                    $rincianBahasa = collect($perubahan)
                        ->map(fn ($n, $label) => Teks::k(':label: :lama → :baru', [
                            'label' => self::labelTeks($label), 'lama' => self::nilaiTeks($n['lama'] ?? null), 'baru' => self::nilaiTeks($n['baru'] ?? null),
                        ])->dalam($bahasa))
                        ->implode('; ');
                    NotifikasiWali::create([
                        'id_wali' => $this->wali->id, 'peran' => $p, 'id_santri' => $idSantri,
                        // ":Apa" = isian berhuruf awal kapital (seperti ucfirst dulu).
                        'judul' => Teks::k(':Apa diubah', ['apa' => $apa])->dalam($bahasa),
                        'pesan' => mb_strimwidth(Teks::k(':pengubah mengubah :apa. :rincian', [
                            'pengubah' => $this->namaPengubahTeks(), 'apa' => $apa, 'rincian' => $rincianBahasa,
                        ])->dalam($bahasa), 0, 1000, '…'),
                        'jenis' => self::JENIS_NOTIF, 'ref_jenis' => $refJenis, 'ref_id' => (string) $refId,
                    ]);
                }
            }
        } catch (\Throwable) {
            // sengaja dibiarkan
        }
    }

    /**
     * Pemegang hak UBAH modul santri (keputusan user 14 Okt 2026) + admin aktif.
     * Admin ikut sebagai jaring terakhir: hak akses diberikan manual, dan modul yang
     * belum diberikan ke siapa pun akan membuat kabarnya menguap tanpa ada yang tahu.
     *
     * @return list<int>
     */
    public static function penerimaStaf(): array
    {
        $ids = HakAksesModul::query()->where('kode_modul', 'santri')->where('ubah', true)
            ->whereHas('pengguna', fn ($q) => $q->where('status', 'aktif'))
            ->pluck('id_pengguna')->all();
        $ids = array_merge($ids, User::where('is_admin', true)->where('status', 'aktif')->pluck('id_pengguna')->all());

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Nilai untuk dibaca manusia: kode pendapatan → rentangnya, L → Laki-laki, tanggal → d/m/Y. */
    public static function tampil(string $kolom, mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }
        if ($nilai instanceof CarbonInterface) {
            return $nilai->format('d/m/Y');
        }
        if ($kolom === 'tanggal_lahir') {
            return \Illuminate\Support\Carbon::parse($nilai)->format('d/m/Y');
        }
        if ($kolom === 'jenis_kelamin') {
            return self::JENIS_KELAMIN[$nilai] ?? (string) $nilai;
        }
        if (str_starts_with($kolom, 'pendapatan_')) {
            return Wali::PENDAPATAN[$nilai] ?? (string) $nilai;
        }

        return (string) $nilai;
    }

    private static function teks(mixed $nilai): ?string
    {
        $nilai = trim((string) $nilai);

        return $nilai === '' ? null : $nilai;
    }
}
