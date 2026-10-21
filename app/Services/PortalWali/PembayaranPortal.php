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
use Illuminate\Support\Collection;
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

    /**
     * Sisa yang belum tertutup setoran lain yang masih menunggu verifikasi — termasuk
     * VA Midtrans yang sudah dipesan tapi belum dibayar.
     */
    public const PESAN_RUANG_HABIS = 'seluruh sisanya sedang menunggu — Virtual Account yang belum dibayar atau bukti transfer yang sedang diperiksa keuangan. Lihat kotak di halaman Tagihan untuk melanjutkan atau mengganti metode.';

    public static function ruang(TagihanSantri $t): string
    {
        // Tak pernah minus (21 Okt 2026): pembayaran staf tak menghitung VA, jadi VA yang lebih dulu
        // dipesan bisa melebihi sisa — dulu berbuah pesan "antara Rp 1 dan Rp -15.000.000".
        $ruang = self::ruangMentah($t);

        return Money::isNegative($ruang) ? Money::of('0') : $ruang;
    }

    /** ruang() tanpa batas bawah — minus = ada VA menunggu yang melebihi sisa (VA basi). */
    public static function ruangMentah(TagihanSantri $t): string
    {
        $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->sum('nominal');
        $online = \App\Models\TransaksiMidtrans::where('id_tagihan', $t->id)->where('status', 'menunggu')->sum('nominal');
        $onlineKelompok = \App\Models\TransaksiMidtransTagihan::where('id_tagihan', $t->id)
            ->whereHas('transaksi', fn ($q) => $q->where('status', 'menunggu'))->sum('nominal');

        return Money::sub(Money::sub(Money::sub($t->sisa, (string) $menunggu), (string) $online), (string) $onlineKelompok);
    }

    /** Bukti transfer / setoran yang masih menunggu diperiksa keuangan (bagian dari yang dikurangkan ruang()). */
    public static function menungguVerifikasi(int $idTagihan): string
    {
        return Money::of(PembayaranSantri::where('id_tagihan', $idTagihan)->where('status', 'menunggu_verifikasi')->sum('nominal'));
    }

    /**
     * Pesanan VA wali yang belum dibayar — satu tagihan maupun gabungan (bukan isi dompet), terbaru
     * dulu, rinciannya ikut dimuat. Ditampilkan supaya wali yang keluar dari halaman VA tetap bisa
     * kembali membayarnya atau mengganti cara bayar (21 Okt 2026): tanpa itu tagihannya tertahan
     * ruang() sampai VA kedaluwarsa.
     *
     * @param  int[]|null  $idTagihan  hanya pesanan yang memuat salah satu tagihan ini
     * @return Collection<int, \App\Models\TransaksiMidtrans>
     */
    public static function vaMenunggu(int $idWali, ?array $idTagihan = null): Collection
    {
        return \App\Models\TransaksiMidtrans::with(['tagihan.santri', 'tagihan.jenis', 'rincian.tagihan.santri', 'rincian.tagihan.jenis'])
            ->where('id_wali', $idWali)->where('status', 'menunggu')->whereIn('jenis', ['bayar_tagihan', 'bayar_kelompok'])
            ->when($idTagihan !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('id_tagihan', $idTagihan)
                ->orWhereHas('rincian', fn ($r) => $r->whereIn('id_tagihan', $idTagihan))))
            ->orderByDesc('id')->get();
    }

    /** Bagian satu tagihan di dalam pesanan VA (pesanan gabungan memuat beberapa tagihan). */
    public static function bagianVa(\App\Models\TransaksiMidtrans $p, int $idTagihan): string
    {
        if ($p->jenis !== 'bayar_kelompok') {
            return (int) $p->id_tagihan === $idTagihan ? Money::of($p->nominal) : '0';
        }

        return Money::of($p->rincian->where('id_tagihan', $idTagihan)->sum('nominal'));
    }

    // ---- Bayar beberapa tagihan sekaligus (keputusan user 13 Okt 2026) ----

    public const MAKS_KERANJANG = 24;

    /**
     * Keranjang: tagihan milik keluarga ini + nominalnya, beserta cara bayar yang sah untuk
     * SEMUA isinya ('dompet' | 'transfer' | 'online'). Campuran santri aktif + calon hanya
     * lewat dompet; transfer & online hanya bila semuanya tagihan PSB calon.
     *
     * @param  array<int|string,int|string>  $isian  [id_tagihan => nominal]
     * @return array{tagihan:\Illuminate\Support\Collection<int,TagihanSantri>, nominal:array<int,string>, total:string, cara:list<string>}
     */
    public function keranjang(array $isian): array
    {
        $isian = array_filter($isian, fn ($n) => $n !== null && $n !== '');
        if ($isian === []) {
            throw new AppException(422, 'Pilih minimal satu tagihan.');
        }
        if (count($isian) > self::MAKS_KERANJANG) {
            throw new AppException(422, 'Paling banyak '.self::MAKS_KERANJANG.' tagihan sekali bayar.');
        }

        $daftar = collect();
        $nominal = [];
        $semuaDompet = $semuaTransfer = true;
        foreach ($isian as $id => $n) {
            $t = $this->tagihan((int) $id);
            $nama = \App\Http\Controllers\PortalKeluargaController::namaTagihan($t).' — '.$t->santri->nama;
            [$alur, $alasan] = self::alur($t);
            if ($alur === null) {
                throw new AppException(422, "{$nama}: {$alasan}");
            }
            $n = Money::of($n);
            $ruang = self::ruang($t);
            if (Money::isZero($ruang)) {
                throw new AppException(422, "{$nama}: ".self::PESAN_RUANG_HABIS);
            }
            if (Money::lte($n, '0') || Money::gt($n, $ruang)) {
                throw new AppException(422, "{$nama}: nominal harus antara Rp 1 dan Rp ".number_format((float) $ruang, 0, ',', '.').'.');
            }
            $semuaDompet = $semuaDompet && self::bisaDompet($alur);
            $semuaTransfer = $semuaTransfer && self::bisaTransfer($alur);
            $daftar->push($t);
            $nominal[$t->id] = $n;
        }

        $cara = array_values(array_filter([
            $semuaDompet ? 'dompet' : null,
            $semuaTransfer && \App\Services\Midtrans\PembayaranMidtrans::siap() ? 'online' : null,
            $semuaTransfer ? 'transfer' : null,
        ]));
        if ($cara === []) {
            throw new AppException(422, 'Tagihan santri aktif dan tagihan pendaftaran calon tidak bisa dibayar bersamaan '
                .'kecuali dari Dompet Wali. Bayar terpisah, atau isi Dompet Wali dulu.');
        }

        return ['tagihan' => $daftar, 'nominal' => $nominal, 'total' => Money::of(array_sum(array_map('floatval', $nominal))), 'cara' => $cara];
    }

    public static function nomorKelompok(string $awalan = 'KB'): string
    {
        return $awalan.'-'.now()->format('ymdHis').'-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(4));
    }

    /**
     * Bayar keranjang dari Dompet Wali — SATU PIN, semua atau tidak sama sekali. Tetap
     * satu pembayaran + satu jurnal per tagihan (lewat jalur loket yang sama), diikat
     * `kelompok_bayar`. Langkah susulan (evaluasi potongan) sesudah transaksinya selesai.
     *
     * @return \Illuminate\Support\Collection<int,PembayaranSantri>
     */
    public function bayarKelompokDariDompet(array $isian, ?string $pin): \Illuminate\Support\Collection
    {
        $k = $this->keranjang($isian);
        if (! in_array('dompet', $k['cara'], true)) {
            throw new AppException(422, 'Tagihan-tagihan ini tidak bisa dibayar dari Dompet Wali.');
        }
        foreach ($k['tagihan'] as $t) {
            if (in_array($t->perilaku, self::PENUH, true) && ! Money::eq($k['nominal'][$t->id], $t->sisa)) {
                throw new AppException(422, \App\Http\Controllers\PortalKeluargaController::namaTagihan($t)
                    .' harus dilunasi sekaligus sebesar Rp '.number_format((float) $t->sisa, 0, ',', '.').'.');
            }
        }
        $saldo = DompetWali::where('id_wali', $this->wali->id)->value('saldo') ?? '0';
        if (Money::lt($saldo, $k['total'])) {
            throw new AppException(422, 'Saldo Dompet Wali tidak cukup untuk total Rp '.number_format((float) $k['total'], 0, ',', '.')
                .'. Isi dompet terlebih dahulu.');
        }
        // PIN SESUDAH semua pemeriksaan murah & DI LUAR transaksi (lihat bayarDariDompet).
        $this->pin()->periksa($pin);

        $kelompok = self::nomorKelompok();
        $hasil = DB::transaction(function () use ($k, $kelompok) {
            DompetWali::where('id_wali', $this->wali->id)->lockForUpdate()->first();
            $layanan = new PembayaranSantriService;

            return $k['tagihan']->sortBy('id')->values()->map(function (TagihanSantri $t) use ($k, $kelompok, $layanan) {
                $t = TagihanSantri::with(['santri', 'jenis'])->lockForUpdate()->find($t->id);
                [$alur, $alasan] = self::alur($t);
                if (! self::bisaDompet($alur)) {
                    throw new AppException(422, $alasan ?? 'Tagihan ini tidak bisa dibayar dari dompet.');
                }

                return $layanan->bayarDariDompet([
                    'id_tagihan' => $t->id, 'nominal' => (string) $k['nominal'][$t->id], 'tanggal' => now()->toDateString(),
                    'catatan' => 'Dibayar dari Portal Wali (sekaligus).', 'kelompok_bayar' => $kelompok,
                ], null, $this->pencatat, tundaSusulan: true);
            });
        });

        $layanan = new PembayaranSantriService;
        foreach ($k['tagihan'] as $t) {
            $layanan->sesudahBayarGateway($t->refresh());
        }

        return $hasil;
    }

    /**
     * Transfer SATU bukti untuk beberapa tagihan PSB calon. Tiap tagihan mendapat baris
     * pembayaran sendiri (menunggu verifikasi), diikat `kelompok_bayar`; keuangan
     * memverifikasi / menolaknya sekaligus. Satu kabar ke keuangan.
     *
     * @return \Illuminate\Support\Collection<int,PembayaranSantri>
     */
    public function bayarKelompokTransfer(array $isian, array $data): \Illuminate\Support\Collection
    {
        $k = $this->keranjang($isian);
        if (! in_array('transfer', $k['cara'], true)) {
            throw new AppException(422, 'Tagihan-tagihan ini tidak bisa dibayar lewat transfer.');
        }
        $this->pastikanRekeningPortal($data['kode_rekening']);
        $kelompok = self::nomorKelompok();
        $layanan = new PembayaranSantriService;

        $baris = DB::transaction(fn () => $k['tagihan']->sortBy('id')->values()->map(fn (TagihanSantri $t) => $layanan->catat([
            'id_santri' => $t->id_santri, 'id_tagihan' => $t->id, 'tanggal' => $data['tanggal'],
            'nominal' => (string) $k['nominal'][$t->id], 'kode_rekening' => $data['kode_rekening'], 'metode' => 'transfer',
            'bukti_path' => $data['bukti_path'], 'catatan' => 'Dikirim dari Portal Wali (satu bukti untuk '.$k['tagihan']->count().' tagihan).',
            'kelompok_bayar' => $kelompok,
        ], null, 'ppsb', $this->pencatat)));

        $layanan->kabariKelompok($baris);

        return $baris;
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
            if (Money::isZero($ruang)) {
                throw new AppException(422, self::PESAN_RUANG_HABIS);
            }
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

    /** Uang saku anak A → uang saku anak B (keduanya santri aktif keluarga ini). PIN. */
    public function pindahAntarAnak(int $idAsal, int $idTujuan, string $nominal, ?string $pin): void
    {
        $asal = $this->anakAktif($idAsal);
        $tujuan = $this->anakAktif($idTujuan);
        if ($asal->id === $tujuan->id) {
            throw new AppException(422, 'Pilih anak tujuan yang berbeda.');
        }
        $nominal = Money::of($nominal);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal harus lebih dari nol.');
        }
        $saldo = \App\Models\DompetSantri::where('id_santri', $asal->id)->value('saldo') ?? '0';
        if (Money::lt($saldo, $nominal)) {
            throw new AppException(422, "Uang saku {$asal->nama} tidak cukup (tersedia Rp ".number_format((float) $saldo, 0, ',', '.').').');
        }
        \App\Services\Modules\PenarikanTunaiService::pastikanTersedia($asal->id, $saldo, $nominal, "Uang saku {$asal->nama} tidak cukup");
        $this->pin()->periksa($pin);

        $nama = $this->wali->{'nama_'.$this->pencatat['peran']} ?: $this->wali->nama;
        (new DompetService)->pindahAntarSantri([
            'id_santri_asal' => $asal->id, 'id_santri_tujuan' => $tujuan->id, 'nominal' => (string) $nominal,
            'tanggal' => now()->toDateString(),
            'keterangan' => "Uang saku {$asal->nama} → {$tujuan->nama} (portal, {$nama})",
        ], null, $this->pencatat);
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
