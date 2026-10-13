<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\PengajuanTarikTabungan;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Modules\AutoDebetService;
use App\Services\Modules\DompetService;
use App\Services\Modules\PembayaranSantriService;
use App\Services\Ppsb\BayarDompet;
use App\Services\Ppsb\Tahap;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PEMBAYARAN DARI PORTAL WALI — tiga alur, semuanya menumpang service yang sudah ada.
 *
 *  A. Santri AKTIF: bayar tagihan dari Dompet Wali (BayarDompet) — seketika,
 *     berjurnal, tanpa verifikasi.
 *  B. Isi Dompet Wali lewat transfer bank (DompetService::topUp) — menunggu
 *     verifikasi keuangan.
 *  C. CALON: bayar tagihan PSB langsung lewat transfer (PembayaranSantriService::catat)
 *     — menunggu verifikasi keuangan. Calon belum berdompet.
 *
 * Yang ditambahkan di sini hanyalah penjaga pintu publik: tagihan harus milik
 * keluarga yang masuk, status anak menentukan alur mana yang sah, rekening
 * hanya yang dipilih keuangan untuk portal, dan baris dompet & tagihan DIKUNCI
 * — wali yang menekan tombol dua kali tak boleh terpotong dua kali.
 */
class PembayaranPortal
{
    /** Tagihan yang wajib dilunasi sekaligus dari dompet — sama dengan AutoDebetService. */
    public const PENUH = ['spp', 'lain'];

    /** Tagihan PSB yang dibayar calon lewat transfer. */
    public const PSB = ['registrasi', 'uang_pangkal', 'perlengkapan'];

    /** @param  array{id:int, peran:string}  $pencatat */
    public function __construct(private readonly Wali $wali, private readonly array $pencatat) {}

    public static function dari(Wali $wali, string $peran): self
    {
        return new self($wali, ['id' => $wali->id, 'peran' => $peran]);
    }

    /** Tagihan milik keluarga ini, beserta santri & jenisnya — atau 404. */
    public function tagihan(int $id): TagihanSantri
    {
        $t = TagihanSantri::with(['santri', 'jenis'])->find($id);
        if (! $t || ! $t->santri || $t->santri->id_wali !== $this->wali->id) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }

        return $t;
    }

    /**
     * 'dompet' | 'transfer' | 'keduanya' | null (tak bisa dibayar dari portal) — beserta alasannya.
     *
     *  • santri AKTIF: semua tagihannya dari Dompet Wali — termasuk registrasi
     *    pendaftaran lanjutan (gerbang tahapnya ditangani bayarDariDompet).
     *  • CALON: tagihan PSB lewat transfer; bila keluarganya SUDAH punya anak
     *    aktif (berarti punya Dompet Wali), boleh juga dari dompet — 'keduanya'.
     */
    public static function alur(TagihanSantri $t): array
    {
        if (! $t->berlaku() || $t->status === 'lunas' || Money::lte($t->sisa, '0')) {
            return [null, 'Tagihan ini sudah lunas atau tidak berlaku lagi.'];
        }
        $status = (string) $t->santri->status;
        if ($status === 'aktif') {
            return ['dompet', null];
        }
        if (! Tahap::isFinal($status) && in_array($t->perilaku, self::PSB, true)) {
            return [self::keluargaBerdompet((int) $t->santri->id_wali) ? 'keduanya' : 'transfer', null];
        }

        return [null, 'Tagihan ini tidak bisa dibayar dari portal. Silakan hubungi bagian keuangan.'];
    }

    /** Keluarga yang sudah punya anak berstatus aktif — merekalah yang ber-Dompet Wali. */
    public static function keluargaBerdompet(int $idWali): bool
    {
        // Sengaja tanpa cache statis: ia hidup melewati batas test dalam satu proses.
        return \App\Models\Santri::where('id_wali', $idWali)->where('status', 'aktif')->exists();
    }

    public static function bisaDompet(?string $alur): bool
    {
        return in_array($alur, ['dompet', 'keduanya'], true);
    }

    public static function bisaTransfer(?string $alur): bool
    {
        return in_array($alur, ['transfer', 'keduanya'], true);
    }

    /** Sisa yang belum tertutup setoran lain yang masih menunggu verifikasi. */
    public static function ruang(TagihanSantri $t): string
    {
        $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->sum('nominal');

        return Money::sub($t->sisa, (string) $menunggu);
    }

    public function pin(): PinTransaksi
    {
        return new PinTransaksi($this->wali, $this->pencatat['peran']);
    }

    // ---- A. Bayar dari dompet ----

    /**
     * PIN diperiksa LEBIH DULU dan DI LUAR transaksi pembayaran: di dalamnya,
     * pembayaran yang batal ikut membatalkan hitungan salah PIN.
     */
    public function bayarDariDompet(int $idTagihan, string $nominal, ?string $pin): PembayaranSantri
    {
        // Yang jelas tak sah ditolak SEBELUM meminta PIN — tagihan keluarga lain
        // atau tagihan calon tak boleh menambah hitungan salah PIN. Diperiksa ulang
        // di dalam transaksi bersama kuncinya.
        [$alur, $alasan] = self::alur($this->tagihan($idTagihan));
        if (! self::bisaDompet($alur)) {
            throw new AppException(422, $alasan ?? 'Tagihan ini dibayar lewat transfer, bukan dari dompet.');
        }
        $this->pin()->periksa($pin);

        return DB::transaction(function () use ($idTagihan, $nominal) {
            // Kunci dompet LEBIH DULU, baru tagihan — urutan tetap mencegah deadlock
            // dengan auto-debet yang juga memotong dompet keluarga yang sama.
            $dompet = DompetWali::where('id_wali', $this->wali->id)->lockForUpdate()->first();
            $t = $this->tagihan($idTagihan);
            $t = TagihanSantri::with(['santri', 'jenis'])->lockForUpdate()->find($t->id);

            [$alur, $alasan] = self::alur($t);
            if (! self::bisaDompet($alur)) {
                throw new AppException(422, $alasan ?? 'Tagihan ini dibayar lewat transfer, bukan dari dompet.');
            }
            $nominal = Money::of($nominal);
            if (Money::lte($nominal, '0')) {
                throw new AppException(422, 'Nominal harus lebih dari nol.');
            }
            $ruang = self::ruang($t);
            if (Money::gt($nominal, $ruang)) {
                throw new AppException(422, 'Nominal melebihi sisa tagihan (Rp '.number_format((float) $ruang, 0, ',', '.').').');
            }
            if (in_array($t->perilaku, self::PENUH, true) && ! Money::eq($nominal, $t->sisa)) {
                throw new AppException(422, 'Tagihan ini harus dilunasi sekaligus sebesar Rp '
                    .number_format((float) $t->sisa, 0, ',', '.').'.');
            }
            if (! $dompet || Money::lt($dompet->saldo, $nominal)) {
                throw new AppException(422, 'Saldo Dompet Wali tidak cukup. Isi dompet terlebih dahulu.');
            }

            // Lewat jalur dompet LOKET, bukan BayarDompet langsung: di sanalah gerbang
            // tahap PSB (registrasi lunas → calon maju / siklus lanjutan maju) dan
            // evaluasi potongan gelombang uang pangkal. Tanpa itu, registrasi calon
            // yang dibayar dari portal membuat tahapnya macet di "Calon".
            return (new PembayaranSantriService)->bayarDariDompet([
                'id_tagihan' => $t->id, 'nominal' => (string) $nominal, 'tanggal' => now()->toDateString(),
                'catatan' => 'Dibayar dari Portal Wali.',
            ], null, $this->pencatat);
        });
    }

    // ---- B. Isi dompet lewat transfer ----

    public function isiDompet(array $data): MutasiDompet
    {
        $this->pastikanRekeningPortal($data['kode_rekening']);
        $nama = $this->wali->{'nama_'.$this->pencatat['peran']} ?: $this->wali->nama;

        return (new DompetService)->topUp([
            'id_wali' => $this->wali->id, 'kode_rekening' => $data['kode_rekening'],
            'nominal' => $data['nominal'], 'tanggal' => $data['tanggal'], 'bukti_path' => $data['bukti_path'],
            'keterangan' => "Isi dompet dari Portal Wali — {$nama}",
        ], null, $this->pencatat);
    }

    // ---- C. Calon: bayar tagihan PSB lewat transfer ----

    public function bayarTransfer(int $idTagihan, array $data): PembayaranSantri
    {
        $t = $this->tagihan($idTagihan);
        [$alur, $alasan] = self::alur($t);
        if (! self::bisaTransfer($alur)) {
            throw new AppException(422, $alasan ?? 'Tagihan santri aktif dibayar dari Dompet Wali.');
        }
        $this->pastikanRekeningPortal($data['kode_rekening']);

        return (new PembayaranSantriService)->catat([
            'id_santri' => $t->id_santri, 'id_tagihan' => $t->id, 'tanggal' => $data['tanggal'],
            'nominal' => $data['nominal'], 'kode_rekening' => $data['kode_rekening'], 'metode' => 'transfer',
            'bukti_path' => $data['bukti_path'], 'catatan' => 'Dikirim dari Portal Wali.',
        ], null, 'ppsb', $this->pencatat);
    }

    // ---- D. Pindah saldo ----

    /** Arah yang bisa dipilih wali di portal. Tabungan → Wali lewat pengajuan, bukan di sini. */
    public const ARAH = [
        'wali_santri' => ['wali', 'santri', 'Dompet Wali → Uang saku'],
        'wali_tabungan' => ['wali', 'tabungan', 'Dompet Wali → Tabungan'],
        'santri_wali' => ['santri', 'wali', 'Uang saku → Dompet Wali'],
        'santri_tabungan' => ['santri', 'tabungan', 'Uang saku → Tabungan'],
    ];

    public function pindah(string $arah, int $idSantri, string $nominal, ?string $pin): void
    {
        if (! isset(self::ARAH[$arah])) {
            throw new AppException(422, 'Arah pemindahan tidak dikenal.');
        }
        $santri = $this->anakAktif($idSantri);
        $nominal = Money::of($nominal);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal harus lebih dari nol.');
        }
        $this->pin()->periksa($pin);

        [$dari, $ke, $label] = self::ARAH[$arah];
        $nama = $this->wali->{'nama_'.$this->pencatat['peran']} ?: $this->wali->nama;

        DB::transaction(function () use ($dari, $ke, $label, $santri, $nominal, $nama) {
            // Kunci dompet asal: dua pemindahan bersamaan tak boleh sama-sama lolos cek saldo.
            match ($dari) {
                'wali' => DompetWali::where('id_wali', $this->wali->id)->lockForUpdate()->first(),
                default => \App\Models\DompetSantri::where('id_santri', $santri->id)->lockForUpdate()->first(),
            };

            (new DompetService)->pindah([
                'dari' => $dari, 'ke' => $ke, 'id_wali' => $this->wali->id, 'id_santri' => $santri->id,
                'nominal' => (string) $nominal, 'tanggal' => now()->toDateString(),
                'keterangan' => "{$label} — {$santri->nama} (portal, {$nama})",
            ], null, $this->pencatat);
        });
    }

    // ---- E. Pengajuan tarik tabungan ----

    public function ajukanTarikTabungan(int $idSantri, string $nominal, string $alasan, ?string $pin): PengajuanTarikTabungan
    {
        $santri = $this->anakAktif($idSantri);
        $nominal = Money::of($nominal);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal harus lebih dari nol.');
        }
        $saldo = \App\Models\TabunganSantri::where('id_santri', $santri->id)->value('saldo') ?? '0';
        $menunggu = (string) PengajuanTarikTabungan::where('id_santri', $santri->id)->where('status', 'menunggu')->sum('nominal');
        if (Money::gt(Money::add($menunggu, $nominal), $saldo)) {
            throw new AppException(422, 'Melebihi saldo tabungan yang tersedia (Rp '
                .number_format((float) Money::sub($saldo, $menunggu), 0, ',', '.').').');
        }
        $this->pin()->periksa($pin);

        $p = DB::transaction(function () use ($santri, $nominal, $alasan) {
            $base = \App\Services\Ledger\DocNumber::docBase('TTB', now());
            $last = PengajuanTarikTabungan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            return PengajuanTarikTabungan::create([
                'nomor' => \App\Services\Ledger\DocNumber::nextDocNumber($base, $last), 'id_santri' => $santri->id,
                'id_wali' => $this->wali->id, 'peran' => $this->pencatat['peran'], 'nominal' => $nominal,
                'alasan' => trim($alasan), 'status' => 'menunggu',
            ]);
        });

        $keuangan = \App\Models\User::where('tim_keuangan', true)->where('status', 'aktif')->pluck('id_pengguna');
        (new \App\Services\Modules\NotificationService)->kirim($keuangan->map(fn ($id) => [
            'id_pengguna' => $id, 'judul' => 'Pengajuan tarik tabungan menunggu persetujuan',
            'pesan' => "{$p->nomor} — {$santri->nama}, Rp ".number_format((float) $nominal, 0, ',', '.').'.',
            'jenis' => 'tarik_tabungan_menunggu', 'ref_jenis' => 'PengajuanTarikTabungan', 'ref_id' => (string) $p->id,
        ])->all());

        return $p;
    }

    public function batalkanPengajuan(int $id): void
    {
        $jumlah = PengajuanTarikTabungan::where('id', $id)->where('id_wali', $this->wali->id)
            ->where('status', 'menunggu')->update(['status' => 'batal']);
        if (! $jumlah) {
            throw new AppException(422, 'Pengajuan tidak bisa dibatalkan (sudah diputus atau tidak ditemukan).');
        }
    }

    private function anakAktif(int $idSantri): \App\Models\Santri
    {
        $s = \App\Models\Santri::where('id', $idSantri)->where('id_wali', $this->wali->id)->first();
        if (! $s || $s->status !== 'aktif') {
            throw new AppException(422, 'Dompet & tabungan hanya untuk anak berstatus santri aktif.');
        }

        return $s;
    }

    // ---- Auto-debet ----

    /** Mengaktifkan = izin memotong untuk seterusnya → wajib PIN. Mematikan tidak. */
    public function setAutoDebet(bool $aktif, ?string $pin = null): Wali
    {
        if ($aktif) {
            $this->pin()->periksa($pin);
        }

        return (new AutoDebetService)->setIzin($this->wali->id, $aktif);
    }

    private function pastikanRekeningPortal(string $kode): void
    {
        if (! BankAccount::untukPortal()->contains('kode_coa', $kode)) {
            throw new AppException(422, 'Rekening tujuan tidak tersedia. Muat ulang halaman.');
        }
    }
}
