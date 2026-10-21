<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BarcodePersediaan;
use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Models\Penjualan;
use App\Models\PermintaanKartu;
use App\Models\ReturPenjualan;
use App\Models\ReturPenjualanDetail;
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Penghubung\KlienDompetToko;
use App\Services\Penghubung\PenghubungTakPasti;
use App\Services\Penghubung\PermintaanKartuTertunda;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KASIR WAFI MART — shift, penjualan tunai, tutup shift, serah terima.
 *
 * Keputusan user 18 Okt 2026:
 *  • Jurnal RINGKAS PER SHIFT, dibuat saat shift ditutup:
 *      D Kas Laci        / K Pendapatan Penjualan   = omzet tunai shift
 *      D HPP             / K Persediaan (per akun)  = harga pokok FIFO barang terjual
 *      D/K Selisih Kas   / K/D Kas Laci             = uang dihitung − (modal + omzet)
 *    Stok sudah keluar saat struk disimpan; selama shift terbuka kartu stok sudah turun
 *    sementara jurnal HPP-nya belum ada — itu sebabnya shift WAJIB ditutup tiap hari.
 *  • Akun per LOKASI toko (lokasi_persediaan.kode_coa_*), Bagian = Bagian pengelola toko.
 *  • Selisih kas ke akun Selisih Kas.
 *
 * Serah terima: setoran = uang dihitung − modal awal (modal tetap di laci sebagai kas
 * kecil shift berikutnya), dikonfirmasi PENERIMA yang bukan kasirnya:
 *      D Kas tujuan / K Kas Laci
 */
class KasirService
{
    public function bukaShift(string $kodeLokasi, string|int|float $modal, int $idKasir): ShiftKasir
    {
        $lokasi = LokasiPersediaan::find($kodeLokasi);
        if (! $lokasi || $lokasi->status !== 'aktif' || $lokasi->jenis !== 'toko') {
            throw new AppException(422, 'Shift kasir hanya bisa dibuka di lokasi berjenis toko yang aktif.');
        }
        if ($kurang = $lokasi->akunKasirKurang()) {
            throw new AppException(422, "{$lokasi->nama} belum siap untuk kasir — lengkapi dulu di master Lokasi: ".implode(', ', $kurang).'.');
        }
        $modal = Money::of($modal);
        if (Money::isNegative($modal)) {
            throw new AppException(422, 'Modal awal tidak boleh negatif.');
        }
        if ($buka = ShiftKasir::where('id_kasir', $idKasir)->where('status', 'buka')->first()) {
            throw new AppException(409, "Anda masih punya shift terbuka ({$buka->nomor}). Tutup dulu sebelum membuka yang baru.");
        }

        try {
            return DB::transaction(function () use ($lokasi, $modal, $idKasir) {
                $base = DocNumber::docBase('SK', now());
                $last = ShiftKasir::where('nomor', 'like', $base.'%')->lockForUpdate()->orderByDesc('nomor')->value('nomor');

                return ShiftKasir::create([
                    'nomor' => DocNumber::nextDocNumber($base, $last),
                    'kode_lokasi' => $lokasi->kode,
                    'id_kasir' => $idKasir,
                    'dibuka_pada' => now(),
                    'modal_awal' => $modal,
                    'status' => 'buka',
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Dua kiriman "buka shift" bersamaan: indeks unik `shift_kasir_satu_terbuka` menolak yang kedua.
            throw new AppException(409, 'Anda sudah punya shift terbuka — muat ulang halaman.');
        }
    }

    /**
     * Simpan satu struk. Tiap baris: `barcode` ATAU `kode_persediaan` (+ `satuan`), dan `kuantiti`.
     * Harga diambil dari master — kasir tak mengetik harga.
     *
     * @param  list<array{barcode?:?string, kode_persediaan?:?string, satuan?:?string, kuantiti:string|int|float, diskon?:string|int|float|null}>  $baris
     * @param  array{struk?:string|int|float|null, alasan?:?string, id_pengawas?:?int, pin?:?string}  $diskon
     *         Diskon manual (nominal per baris di `$baris[*]['diskon']`, per struk di `struk`) SELALU
     *         butuh PIN pengawas — keputusan user 19 Okt 2026. Promo terjadwal tak butuh apa pun.
     */
    public function jual(int $idShift, array $baris, string|int|float $bayar, int $idKasir, array $diskon = [], ?array $kartu = null, ?string $kunci = null): Penjualan
    {
        // Kunci klien (audit 21 Okt 2026, Paket 3): kiriman ULANG keranjang yang sama — koneksi putus sesudah server
        // menyimpan — mengembalikan struk yang sudah ada, bukan struk/potongan kartu kedua.
        $kunci = ($kunci = trim((string) $kunci)) !== '' ? mb_substr($kunci, 0, 64) : null;
        if ($kunci && ($ada = Penjualan::where('kunci_klien', $kunci)->where('id_kasir', $idKasir)->first())) {
            return $ada->load('details');
        }
        $bayarAwal = $bayar;
        $shift = $this->shiftKasir($idShift, $idKasir);
        if (! $baris) {
            throw new AppException(422, 'Keranjang masih kosong.');
        }

        $rincian = [];
        $total = '0';
        $adaDiskon = false;
        foreach ($baris as $i => $l) {
            [$item, $satuan, $isi, $harga, $barcode, $normal] = $this->resolusi($l, $i + 1);
            $qtyInput = Money::of($l['kuantiti'], 4);
            if (! Money::gtZero($qtyInput, 4)) {
                throw new AppException(422, "Jumlah {$item->nama_persediaan} harus lebih dari nol.");
            }
            // Kuantiti skala 4 lalu dibulatkan (dulu terpotong ke 2 desimal: 0,125 kg × 20.000 = 2.400, bukan 2.500).
            $kotor = self::bulat(bcmul($qtyInput, Money::of($harga), 6));
            $potong = Money::of($l['diskon'] ?? 0);
            if (Money::isNegative($potong) || Money::gt($potong, $kotor)) {
                throw new AppException(422, "Diskon {$item->nama_persediaan} tidak boleh negatif atau melebihi harganya.");
            }
            $adaDiskon = $adaDiskon || Money::gtZero($potong);
            $subtotal = Money::sub($kotor, $potong);
            $rincian[] = compact('item', 'satuan', 'isi', 'harga', 'barcode', 'qtyInput', 'subtotal', 'normal', 'potong');
            $total = Money::add($total, $subtotal);
        }

        $diskonStruk = Money::of($diskon['struk'] ?? 0);
        if (Money::isNegative($diskonStruk) || Money::gt($diskonStruk, $total)) {
            throw new AppException(422, 'Diskon struk tidak boleh negatif atau melebihi total belanja.');
        }
        $adaDiskon = $adaDiskon || Money::gtZero($diskonStruk);
        $total = Money::sub($total, $diskonStruk);

        $penyetuju = null;
        $alasan = trim((string) ($diskon['alasan'] ?? ''));
        if ($adaDiskon) {
            if ($alasan === '') {
                throw new AppException(422, 'Tulis alasan diskon.');
            }
            $penyetuju = (new PinPengawasService)->verifikasi($diskon['id_pengawas'] ?? null, $diskon['pin'] ?? null, $idKasir);
        }

        // ---- Bayar KARTU (dompet santri) atau TUNAI ----
        $santri = null;
        $pinBenar = false;
        $kartuJauh = $kartu !== null && self::lewatPenghubung();
        if ($kartuJauh) {
            // Instans Wafi Mart: kartu, saldo, batas & PIN diperiksa ERP Al Wafi lewat penghubung —
            // dipanggil di dalam transaksi di bawah, sesudah struk & stok siap.
            if (trim((string) ($kartu['uid'] ?? '')) === '') {
                throw new AppException(422, 'Tempelkan kartu santri dulu.');
            }
            $bayar = $total;
        } elseif ($kartu !== null) {
            $k = (new KartuSantriService)->cariAktif($kartu['uid'] ?? null)
                ?? throw new AppException(404, 'Kartu tidak dikenal atau sudah diblokir.');
            $santri = $k->santri;
            if ($santri->status !== 'aktif') {
                throw new AppException(422, "{$santri->nama} bukan santri aktif — kartunya tak bisa dipakai belanja.");
            }
            // Di atas batas harian → PIN belanja anak. Diperiksa DI LUAR transaksi supaya
            // hitungan salah PIN tak ikut tergulung saat galat dilempar.
            if (Money::gt(Money::add(self::belanjaKartuHariIni($santri->id), $total), KartuSantriService::batasHarian($santri->id))) {
                (new KartuSantriService)->periksaPin($santri->id, $kartu['pin'] ?? null);
                $pinBenar = true;
            }
            $bayar = $total;   // kartu membayar persis totalnya
        }

        $bayar = Money::of($bayar);
        if (Money::lt($bayar, $total)) {
            throw new AppException(422, 'Uang yang dibayarkan kurang dari total belanja.');
        }

        // Kartu lewat penghubung: NIAT BAYAR disimpan dulu, di luar transaksi struk (Fase 3c-1) — nomor rujukannya
        // tetap, jadi pembayaran yang jawabannya hilang bisa dipastikan, bukan ditebak "gagal".
        $permintaan = $kartuJauh ? $this->siapkanPermintaan($kartu, $total, $shift, $idKasir, $kunci) : null;
        $dipotong = null;   // jawaban potong bila sudah terjadi di dalam transaksi (diisi lewat referensi)

        try {
            return $this->simpanJual($shift, $rincian, $total, $bayar, $idKasir, $diskonStruk, $penyetuju, $adaDiskon, $alasan,
                $santri, $pinBenar, $kartu, $kartuJauh, $permintaan, $dipotong, $kunci);
        } catch (PenghubungTakPasti) {
            // Jawaban hilang di jalan — tanyakan nasib nomornya; berhasil → simpan struk tanpa memotong lagi.
            return $this->setelahDipastikan($permintaan,
                fn () => $this->jual($idShift, $baris, $bayarAwal, $idKasir, $diskon, ['permintaan' => $permintaan->id] + $kartu, $kunci));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Dua kiriman dengan kunci yang sama bersamaan: yang kedua mengembalikan struk yang pertama.
            if ($kunci && ($ada = Penjualan::where('kunci_klien', $kunci)->where('id_kasir', $idKasir)->first())) {
                return $ada->load('details');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($permintaan && ! $e instanceof PermintaanKartuTertunda) {
                $this->catatPermintaanGagal($permintaan, $dipotong, $e);
            }
            throw $e;
        }
    }

    private function simpanJual(ShiftKasir $shift, array $rincian, string $total, string $bayar, int $idKasir, string $diskonStruk,
        $penyetuju, bool $adaDiskon, string $alasan, $santri, bool $pinBenar, ?array $kartu, bool $kartuJauh,
        ?PermintaanKartu $permintaan, ?array &$dipotong, ?string $kunci = null): Penjualan
    {
        return DB::transaction(function () use ($shift, $rincian, $total, $bayar, $idKasir, $diskonStruk, $penyetuju, $adaDiskon, $alasan, $santri, $pinBenar, $kartu, $kartuJauh, $permintaan, &$dipotong, $kunci) {
            // Shift dikunci: struk tak boleh tersimpan ke shift yang baru saja ditutup (paksa) — stoknya keluar
            // tetapi pendapatannya tak pernah dijurnal (audit 21 Okt 2026).
            $this->kunciShift($shift->id_shift, ['buka'], 'Shift sudah ditutup — muat ulang halaman.');
            // Nomor struk harian per toko; kunci advisori supaya dua kasir yang menyimpan
            // bersamaan tak berebut nomor yang sama.
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('penjualan-nomor')]);
            $base = 'JL-'.now()->format('ymd').'-';
            $last = Penjualan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $jual = Penjualan::create([
                'nomor' => $nomor,
                'id_shift' => $shift->id_shift,
                'kode_lokasi' => $shift->kode_lokasi,
                'waktu' => now(),
                'metode' => ($santri || $kartuJauh) ? 'dompet' : 'tunai',
                'id_santri' => $santri?->id,
                'uid_kartu' => ($santri || $kartuJauh) ? KartuSantriService::bersihkanUid($kartu['uid']) : null,
                'total' => $total,
                'diskon_struk' => $diskonStruk,
                'bayar' => $bayar,
                'kembalian' => Money::sub($bayar, $total),
                'status' => 'selesai',
                'id_kasir' => $idKasir,
                'disetujui_oleh' => $penyetuju?->id_pengguna,
                'alasan_diskon' => $adaDiskon ? $alasan : null,
                'kunci_klien' => $kunci,
            ]);

            $hpp = '0';
            foreach ($rincian as $r) {
                $qty = Money::mul($r['qtyInput'], $r['isi'], 4);
                // Stok minus DITOLAK (POS lama membiarkannya): keluar() melempar bila stok toko kurang.
                $keluar = InventoryMovement::keluar([
                    'kode_persediaan' => $r['item']->kode_persediaan,
                    'kode_lokasi' => $shift->kode_lokasi,
                    'kuantiti' => $qty,
                    'alasan' => 'penjualan',
                    'sumber_modul' => Penjualan::SUMBER,
                    'sumber_ref' => $nomor,
                    'id_pengguna' => $idKasir,
                ]);
                $jual->details()->create([
                    'kode_persediaan' => $r['item']->kode_persediaan,
                    'barcode' => $r['barcode'],
                    'satuan' => $r['satuan'],
                    'isi' => $r['isi'],
                    'kuantiti_input' => $r['qtyInput'],
                    'kuantiti' => $qty,
                    'harga_satuan' => $r['harga'],
                    'harga_normal' => $r['normal'],
                    'promo' => ! Money::eq($r['harga'], $r['normal']),
                    'diskon' => $r['potong'],
                    'total' => $r['subtotal'],
                    'hpp' => $keluar->nilai,
                ]);
                $hpp = Money::add($hpp, $keluar->nilai);
            }
            $jual->update(['hpp' => $hpp]);

            if ($santri) {
                // Dihitung ULANG di bawah kunci saldo: dua tempelan kartu yang nyaris bersamaan
                // tak boleh sama-sama lolos dari batas harian.
                $mutasi = $this->potongDompet($santri->id, $total, $jual->nomor, $idKasir, $pinBenar);
                $jual->update(['id_mutasi_dompet' => $mutasi->id]);
            } elseif ($kartuJauh) {
                // PALING AKHIR, sesudah struk & stok siap: bila penghubung menolak (saldo, PIN, kartu) galatnya
                // menggulung seluruh struk. Nomor rujukan = nomor NIAT BAYAR (tetap, disimpan sebelum transaksi ini).
                // Permintaan yang sudah dipastikan terpotong (lanjutan "Cek lagi") tak memotong untuk kedua kalinya.
                if ($permintaan->status === 'berhasil') {
                    // Dikunci & diperiksa ULANG (audit 21 Okt 2026): rekonsiliasi malam bisa saja baru mengembalikan
                    // potongan ini (status → gagal) — struknya tak boleh tersimpan juga (barang gratis).
                    $kini = PermintaanKartu::lockForUpdate()->find($permintaan->id);
                    if ($kini->status !== 'berhasil' || $kini->id_penjualan) {
                        throw new AppException(409, $kini->id_penjualan ? 'Pembayaran kartu ini sudah tersimpan sebagai struk.' : self::PESAN_TIDAK_TERJADI);
                    }
                    $h = $permintaan->hasil ?? [];
                } else {
                    $lokasi = $shift->lokasi ?? LokasiPersediaan::find($shift->kode_lokasi);
                    $h = app(KlienDompetToko::class)->potong($permintaan->nomor, KartuSantriService::bersihkanUid($kartu['uid']),
                        $total, $kartu['pin'] ?? null, $shift->kode_lokasi, $lokasi?->nama,
                        RincianBelanja::dariPenjualan($jual->load('details.persediaan'), $lokasi?->nama));
                    $dipotong = self::ringkasHasil($h);
                    $permintaan->update(['status' => 'berhasil', 'hasil' => $dipotong]);
                }
                $jual->update(['ref_dompet' => $permintaan->nomor, 'nama_kartu' => $h['nama'] ?? null, 'sisa_saldo_kartu' => $h['sisa_saldo'] ?? null]);
                $permintaan->update(['id_penjualan' => $jual->id_penjualan]);
            }

            return $jual->load('details');
        });
    }

    /**
     * Belanja kartu bersih hari ini untuk satu santri (potongan dikurangi pengembalian).
     * Dihitung dari mutasi `jajan`, jadi batal struk & retur ke dompet ikut mengembalikan jatahnya.
     */
    public static function belanjaKartuHariIni(int $idSantri): string
    {
        return DompetBelanja::hariIni($idSantri);
    }

    /**
     * Kartu santri dibayar lewat PENGHUBUNG (Fase 3b) — di instans Wafi Mart, yang dompet santrinya milik
     * ERP Al Wafi. Di instans Al Wafi kasir memotong dompet langsung (mode lokal, perilaku lama).
     */
    public static function lewatPenghubung(): bool
    {
        return \App\Support\Instans::adalah('wafimart');
    }

    // ---- Niat bayar kartu & pemastian (Fase 3c-1: pencegahan potongan yatim) ----

    public const PESAN_BELUM_PASTI = 'Status pembayaran kartu BELUM PASTI — JANGAN terima tunai dulu. Tekan "Cek lagi" sebentar lagi.';

    public const PESAN_KEMBALI_BELUM_PASTI = 'Status pengembalian ke dompet santri BELUM PASTI (penghubung tak menjawab) — JANGAN beri uang tunai. Ulangi sebentar lagi: aman, tidak akan dikembalikan dua kali.';

    /**
     * Pengembalian lewat penghubung (batal/retur kartu) yang jawabannya hilang bisa saja SUDAH masuk ke dompet santri.
     * Pesan "bayar tunai" bawaan TakPasti menyesatkan di sini (santri bisa dapat tunai + saldo kembali) — audit 21 Okt.
     * Nomornya tetap, jadi mengulang aman.
     */
    private static function kembalikanPasti(callable $panggil): array
    {
        try {
            return $panggil();
        } catch (PenghubungTakPasti) {
            throw new AppException(503, self::PESAN_KEMBALI_BELUM_PASTI);
        }
    }

    public const PESAN_TIDAK_TERJADI ='Pembayaran kartu TIDAK terjadi (penghubung tak menjawab tepat waktu) — aman: ulangi bayar kartu, atau terima tunai.';

    /** Niat bayar baru (status menunggu), atau lanjutan "Cek lagi" atas permintaan yang sudah pasti terpotong. */
    private function siapkanPermintaan(array $kartu, string $total, ShiftKasir $shift, int $idKasir, ?string $kunci = null): PermintaanKartu
    {
        $uid = KartuSantriService::bersihkanUid($kartu['uid']);
        // Kiriman ULANG dengan kunci klien yang sama (koneksi putus di tengah): lanjutkan niat bayar yang lama —
        // dipastikan dulu bila nasibnya belum jelas — alih-alih membuat niat baru yang memotong kartu kedua kalinya.
        if (empty($kartu['permintaan']) && $kunci
            && ($lama = PermintaanKartu::where('kunci_klien', $kunci)->where('id_kasir', $idKasir)->where('jenis', 'potong')
                ->whereNull('id_penjualan')->latest('id')->first())) {
            $status = $this->pastikanPermintaan($lama);
            if ($status === 'belum_pasti') {
                throw new PermintaanKartuTertunda($lama->id, self::PESAN_BELUM_PASTI);
            }
            if ($status === 'berhasil') {
                $kartu['permintaan'] = $lama->id;
            }
        }
        if (! empty($kartu['permintaan'])) {
            $p = PermintaanKartu::where('id', (int) $kartu['permintaan'])->where('id_kasir', $idKasir)->where('jenis', 'potong')->first()
                ?? throw new AppException(404, 'Pembayaran kartu yang dilanjutkan tidak ditemukan.');
            if ($p->id_penjualan) {
                throw new AppException(409, 'Pembayaran kartu ini sudah tersimpan sebagai struk — buka Riwayat Struk.');
            }
            if ($p->status !== 'berhasil') {
                throw new AppException(409, $p->status === 'gagal' ? self::PESAN_TIDAK_TERJADI : self::PESAN_BELUM_PASTI);
            }
            if (! Money::eq($p->nominal, $total) || $p->uid_kartu !== $uid) {
                throw new PermintaanKartuTertunda($p->id, 'Keranjang berubah dari pembayaran kartu yang SUDAH terpotong (Rp '
                    .number_format((float) $p->nominal, 0, ',', '.').'). Kembalikan keranjang seperti semula lalu simpan lagi — '
                    .'atau biarkan: potongan itu dikembalikan ke santri otomatis saat pencocokan malam.');
            }

            return $p;
        }

        return PermintaanKartu::create([
            'nomor' => 'WM-'.now()->format('ymd').'-'.strtoupper(Str::random(10)), 'jenis' => 'potong', 'nominal' => $total,
            'uid_kartu' => $uid, 'id_shift' => $shift->id_shift, 'id_kasir' => $idKasir, 'status' => 'menunggu',
            'kunci_klien' => $kunci,
        ]);
    }

    /** Jawaban hilang: pastikan dulu. Berhasil → `$lanjut` (simpan struk tanpa memotong lagi). */
    private function setelahDipastikan(PermintaanKartu $p, callable $lanjut): Penjualan
    {
        return match ($this->pastikanPermintaan($p)) {
            'berhasil' => $lanjut(),
            'gagal' => throw new AppException(503, self::PESAN_TIDAK_TERJADI),
            default => throw new PermintaanKartuTertunda($p->id, self::PESAN_BELUM_PASTI),
        };
    }

    /**
     * Tanyakan nasib nomornya ke ERP Al Wafi (beberapa kali, berjeda). Jawaban Al Wafi FINAL; tak terjangkau sama
     * sekali → `belum_pasti` (kasir menekan "Cek lagi"; sisanya dicocokkan malam hari).
     */
    public function pastikanPermintaan(PermintaanKartu $p): string
    {
        if (in_array($p->status, ['berhasil', 'gagal'], true)) {
            return $p->status;
        }
        $jeda = (int) config('penghubung.jeda_pastikan_ms', 1000);
        for ($i = 0; $i < 3; $i++) {
            if ($i > 0 && $jeda > 0) {
                usleep($jeda * 1000);
            }
            try {
                $h = app(KlienDompetToko::class)->pastikan($p->nomor, $p->jenis, Money::of($p->nominal));
            } catch (AppException) {
                continue;
            }
            if (($h['status'] ?? '') === 'berhasil') {
                $p->update(['status' => 'berhasil', 'hasil' => self::ringkasHasil($h)]);

                return 'berhasil';
            }
            $p->update(['status' => 'gagal', 'pesan' => 'Dipastikan tidak terjadi di ERP Al Wafi.']);

            return 'gagal';
        }
        $p->update(['status' => 'belum_pasti']);

        return 'belum_pasti';
    }

    /** Tombol "Cek lagi" di layar kasir. */
    public function cekPermintaan(int $id, int $idKasir): array
    {
        $p = PermintaanKartu::where('id', $id)->where('id_kasir', $idKasir)->first()
            ?? throw new AppException(404, 'Pembayaran kartu tidak ditemukan.');

        return ['status' => $this->pastikanPermintaan($p), 'sudah_struk' => (bool) $p->id_penjualan];
    }

    /**
     * Transaksi struk gagal. Sudah terpotong (galat SESUDAH penghubung menjawab, atau lanjutan yang gagal) →
     * catat "berhasil" & tahan di kasir; belum → penolakan pasti, niat bayarnya gagal.
     */
    private function catatPermintaanGagal(PermintaanKartu $p, ?array $dipotong, \Throwable $e): void
    {
        $segar = $p->fresh();
        if ($dipotong !== null) {
            $segar->update(['status' => 'berhasil', 'hasil' => $dipotong]);
        }
        if ($dipotong !== null || $segar->status === 'berhasil') {
            throw new PermintaanKartuTertunda($p->id, 'Kartu SUDAH terpotong, tetapi struk gagal disimpan: '.$e->getMessage()
                .' — betulkan lalu tekan "Simpan struk" (tidak memotong lagi), atau biarkan: potongannya dikembalikan otomatis malam ini.');
        }
        if ($segar->status === 'menunggu') {
            $segar->update(['status' => 'gagal', 'pesan' => mb_substr($e->getMessage(), 0, 250)]);
        }
    }

    private static function ringkasHasil(array $h): array
    {
        return ['nama' => $h['nama'] ?? null, 'sisa_saldo' => $h['sisa_saldo'] ?? null];
    }

    /**
     * Potong / kembalikan uang saku — aturan & mutasi `jajan`-nya di [[DompetBelanja]], satu pintu
     * bersama penghubung Wafi Mart. Jurnalnya ringkas di tutup shift (D/K Titipan Uang Saku ↔ Pendapatan toko).
     */
    private function potongDompet(int $idSantri, string $nominal, string $nomorStruk, int $idKasir, bool $pinBenar): MutasiDompet
    {
        return DompetBelanja::potong($idSantri, $nominal, "Belanja Wafi Mart {$nomorStruk}", $idKasir, $pinBenar);
    }

    private function kembalikanDompet(int $idSantri, string $nominal, string $keterangan, int $idPengguna): MutasiDompet
    {
        return DompetBelanja::kembalikan($idSantri, $nominal, $keterangan, $idPengguna);
    }

    /**
     * Batalkan struk dalam shift yang MASIH TERBUKA — stoknya kembali persis. Harus disetujui
     * orang lain (pemegang hak persetujuan, ditegakkan controller); kasirnya sendiri tak boleh,
     * kecuali admin. Struk shift yang sudah ditutup sudah masuk jurnal: koreksinya lewat retur.
     */
    public function batalPenjualan(int $idPenjualan, string $alasan, int $idPenyetuju): Penjualan
    {
        $jual = Penjualan::with('shift')->find($idPenjualan) ?? throw new AppException(404, 'Struk tidak ditemukan.');
        if ($jual->status === 'batal') {
            throw new AppException(409, "Struk {$jual->nomor} sudah dibatalkan.");
        }
        if ($jual->shift->status !== 'buka') {
            throw new AppException(409, 'Shift struk ini sudah ditutup dan dijurnal — pembatalan tak bisa lagi lewat sini.');
        }
        // Struk yang sudah diretur sebagian tak dibatalkan utuh — stok & uangnya akan pulih dua kali.
        if (ReturPenjualan::where('id_penjualan', $jual->id_penjualan)->exists()) {
            throw new AppException(409, "Struk {$jual->nomor} sudah pernah diretur — pembatalan utuh tak bisa lagi.");
        }
        if ($jual->id_kasir === $idPenyetuju && ! User::find($idPenyetuju)?->is_admin) {
            throw new AppException(403, 'Pembatalan struk harus disetujui orang lain, bukan kasirnya sendiri.');
        }

        return DB::transaction(function () use ($jual, $alasan, $idPenyetuju) {
            // Dikunci & diperiksa ULANG (audit 21 Okt 2026): dua kiriman batal bersamaan dulu sama-sama lolos →
            // stok & uang dompet kembali dua kali. Urutan kunci: shift dulu, baru struk.
            $this->kunciShift($jual->id_shift, ['buka'], 'Shift struk ini sudah ditutup dan dijurnal — pembatalan tak bisa lagi lewat sini.');
            $jual = Penjualan::lockForUpdate()->find($jual->id_penjualan);
            if ($jual->status === 'batal') {
                throw new AppException(409, "Struk {$jual->nomor} sudah dibatalkan.");
            }
            if (ReturPenjualan::where('id_penjualan', $jual->id_penjualan)->exists()) {
                throw new AppException(409, "Struk {$jual->nomor} sudah pernah diretur — pembatalan utuh tak bisa lagi.");
            }
            InventoryMovement::batalkanDokumen(Penjualan::SUMBER, $jual->nomor, $idPenyetuju);
            if ($jual->metode === 'dompet' && $jual->id_santri) {
                // Struk kartu: uangnya kembali ke dompet santri, bukan dari laci.
                $this->kembalikanDompet($jual->id_santri, Money::of($jual->total), "Batal struk Wafi Mart {$jual->nomor}", $idPenyetuju);
            } elseif ($jual->metode === 'dompet' && $jual->ref_dompet) {
                // Kartu lewat penghubung: nomornya tetap (bukan acak) supaya mengulang pembatalan yang
                // tergulung tak mengembalikan uang dua kali — ERP Al Wafi menjawab ulang transaksi yang sama.
                $ref = 'WM-BATAL-'.$jual->ref_dompet;
                self::kembalikanPasti(fn () => app(KlienDompetToko::class)->kembalikan($ref, $jual->ref_dompet, Money::of($jual->total), "Batal struk Wafi Mart {$jual->nomor}"));
                $jual->ref_dompet_batal = $ref;
            }
            $jual->update(['status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $idPenyetuju, 'dibatalkan_pada' => now()]);

            return $jual;
        });
    }

    /**
     * Tutup shift: hitung uang laci, bandingkan dengan modal + omzet tunai, terbitkan jurnal ringkas.
     *
     * @param  array<int|string,int|string>|null  $pecahan  [nominal => lembar]; bila diisi, `$uangDihitung` diabaikan
     */
    public function tutupShift(int $idShift, ?array $pecahan, string|int|float|null $uangDihitung, ?string $catatan, int $idPengguna): ShiftKasir
    {
        $shift = ShiftKasir::with('lokasi')->find($idShift) ?? throw new AppException(404, 'Shift tidak ditemukan.');
        if ($shift->status !== 'buka') {
            throw new AppException(409, "Shift {$shift->nomor} sudah ditutup.");
        }

        if ($pecahan) {
            $rincian = [];
            $uang = '0';
            foreach ($pecahan as $nominal => $lembar) {
                $lembar = (int) $lembar;
                if ($lembar < 0) {
                    throw new AppException(422, 'Jumlah lembar/keping tidak boleh negatif.');
                }
                if ($lembar > 0) {
                    $rincian[(string) (int) $nominal] = $lembar;
                    $uang = Money::add($uang, Money::mul($nominal, $lembar));
                }
            }
        } else {
            if ($uangDihitung === null || $uangDihitung === '') {
                throw new AppException(422, 'Isi jumlah uang di laci.');
            }
            $rincian = null;
            $uang = Money::of($uangDihitung);
        }
        if (Money::isNegative($uang)) {
            throw new AppException(422, 'Uang dihitung tidak boleh negatif.');
        }

        return DB::transaction(function () use ($shift, $rincian, $uang, $catatan, $idPengguna) {
            // Dikunci & diperiksa ULANG: tutup oleh kasir + tutup paksa pengawas (atau Enter dua kali) dulu
            // menerbitkan DUA jurnal shift. Struk & retur baru pun menunggu kunci ini (simpanJual/retur).
            $shift = $this->kunciShift($shift->id_shift, ['buka'], "Shift {$shift->nomor} sudah ditutup.");
            $jual = Penjualan::with('details.persediaan')->where('id_shift', $shift->id_shift)->where('status', 'selesai')->get();
            $retur = ReturPenjualan::with('details.persediaan')->where('id_shift', $shift->id_shift)->get();
            $tunai = Money::of($jual->where('metode', 'tunai')->sum('total'));
            $dompet = Money::of($jual->where('metode', 'dompet')->sum('total'));
            $hpp = Money::of($jual->sum('hpp'));
            // Hanya retur TUNAI yang keluar dari laci shift ini (walau struk asalnya dari shift lain);
            // retur struk kartu kembali ke dompet santri.
            $totalRetur = Money::of($retur->where('metode', 'tunai')->sum('total'));
            $selisih = Money::sub($uang, Money::sub(Money::add($shift->modal_awal, $tunai), $totalRetur));

            $entry = $this->jurnalShift($shift, $jual, $tunai, $hpp, $selisih, $idPengguna, $retur, $dompet);

            if ($entry) {
                MutasiPersediaan::where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('sumber_modul', Penjualan::SUMBER)->whereIn('sumber_ref', $jual->pluck('nomor')))
                    ->orWhere(fn ($w) => $w->where('sumber_modul', ReturPenjualan::SUMBER)->whereIn('sumber_ref', $retur->pluck('nomor'))))
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $entry->id]);
                // Mutasi dompet kartu (belanja, batal, retur) ikut menunjuk jurnal ringkas ini.
                MutasiDompet::whereIn('id', $jual->pluck('id_mutasi_dompet')->merge($retur->pluck('id_mutasi_dompet'))->filter())
                    ->whereNull('journal_entry_id')->update(['journal_entry_id' => $entry->id]);
            }

            $shift->update([
                'status' => 'tutup',
                'ditutup_pada' => now(),
                'jumlah_transaksi' => $jual->count(),
                'total_tunai' => $tunai,
                'total_dompet' => $dompet,
                'total_retur' => $totalRetur,
                'total_hpp' => $hpp,
                'uang_dihitung' => $uang,
                'rincian_pecahan' => $rincian,
                'selisih' => $selisih,
                'catatan_tutup' => $catatan,
                'journal_entry_id' => $entry?->id,
            ]);

            return $shift->refresh();
        });
    }

    /** Serah terima setoran ke kas keuangan — dikonfirmasi penerima, bukan kasirnya. */
    public function serahTerima(int $idShift, string $kodeCoaTujuan, int $idPenerima): ShiftKasir
    {
        $shift = ShiftKasir::with('lokasi')->find($idShift) ?? throw new AppException(404, 'Shift tidak ditemukan.');
        if ($shift->status !== 'tutup') {
            throw new AppException(409, $shift->status === 'buka' ? 'Shift belum ditutup.' : 'Setoran shift ini sudah diterima.');
        }
        if ($shift->id_kasir === $idPenerima && ! User::find($idPenerima)?->is_admin) {
            throw new AppException(403, 'Setoran harus diterima orang lain, bukan kasirnya sendiri.');
        }
        $tujuan = BankAccount::where('kode_coa', $kodeCoaTujuan)->where('status', 'aktif')->first();
        if (! $tujuan) {
            throw new AppException(422, 'Kas tujuan harus rekening kas/bank yang aktif.');
        }
        if ($tujuan->kode_coa === $shift->lokasi->kode_coa_kas) {
            throw new AppException(422, 'Kas tujuan tidak boleh sama dengan kas laci toko.');
        }

        return DB::transaction(function () use ($shift, $tujuan, $idPenerima) {
            // Dikunci & diperiksa ULANG: dua kiriman bersamaan dulu menerbitkan dua jurnal setoran.
            $shift = $this->kunciShift($shift->id_shift, ['tutup'], 'Setoran shift ini sudah diterima.');
            $setoran = Money::sub($shift->uang_dihitung, $shift->modal_awal);
            $entry = null;
            if (Money::gtZero($setoran)) {
                $ket = "Setoran kasir {$shift->nomor} — {$shift->lokasi->nama}";
                $entry = PostingService::postJournal([
                    'referensi' => DocNumber::nextJournalRef('STK', now()),
                    'tanggal' => now()->toDateString(),
                    'kode_unit' => $shift->lokasi->kode_unit,
                    'keterangan' => $ket,
                    'sumber_modul' => 'SetoranKasir',
                    'id_sumber' => (string) $shift->id_shift,
                    'id_pengguna' => $idPenerima,
                    'lines' => [
                        ['kode_coa' => $tujuan->kode_coa, 'debet' => $setoran, 'kredit' => '0', 'keterangan' => $ket],
                        ['kode_coa' => $shift->lokasi->kode_coa_kas, 'debet' => '0', 'kredit' => $setoran, 'keterangan' => $ket],
                    ],
                ]);
            }

            $shift->update([
                'status' => 'diserahkan',
                'setoran' => Money::gtZero($setoran) ? $setoran : '0',
                'kode_coa_kas_tujuan' => $tujuan->kode_coa,
                'diterima_oleh' => $idPenerima,
                'diterima_pada' => now(),
                'journal_setoran_id' => $entry?->id,
            ]);

            return $shift->refresh();
        });
    }

    /**
     * RETUR PENJUALAN — dari struk asal, uangnya keluar dari laci shift yang SEDANG BUKA.
     *
     * Uang kembali = bagian yang SUDAH DIBAYAR atas barang itu: harga baris dikurangi diskon
     * barisnya, lalu dikalikan porsi diskon struk. Retur yang menghabiskan sisa baris memakai
     * sisa nilainya persis, supaya tak ada rupiah pembulatan yang tertinggal. Barang LAYAK
     * kembali ke stok seharga pokok FIFO baris asalnya; barang RUSAK tidak kembali (keputusan
     * user 19 Okt 2026). Jurnalnya menyusul ringkas saat shift ditutup. PIN pengawas wajib.
     *
     * @param  list<array{id_penjualan_detail:int, kuantiti:string|int|float, kondisi:string}>  $baris
     *         `kuantiti` dalam satuan struk asal (mis. 1 DUS).
     */
    public function retur(int $idShift, string $nomorStruk, array $baris, string $alasan, ?int $idPengawas, ?string $pin, int $idKasir): ReturPenjualan
    {
        $shift = $this->shiftKasir($idShift, $idKasir);
        $jual = Penjualan::with('details.persediaan', 'shift.lokasi')->where('nomor', trim($nomorStruk))->first()
            ?? throw new AppException(404, "Struk {$nomorStruk} tidak ditemukan.");
        if ($jual->status !== 'selesai') {
            throw new AppException(409, "Struk {$jual->nomor} sudah dibatalkan — tak ada yang bisa diretur.");
        }
        if ($jual->kode_lokasi !== $shift->kode_lokasi) {
            throw new AppException(422, 'Retur dilayani di toko tempat barang dibeli ('.$jual->shift->lokasi->nama.').');
        }
        $batas = (int) ($shift->lokasi->batas_hari_retur ?? 7);
        if ($jual->waktu->copy()->startOfDay()->lt(now()->startOfDay()->subDays($batas))) {
            throw new AppException(422, "Struk {$jual->nomor} sudah lewat batas retur {$batas} hari.");
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Tulis alasan retur.');
        }

        // Yang sudah pernah diretur per baris struk.
        $sudah = ReturPenjualanDetail::whereIn('id_penjualan_detail', $jual->details->pluck('id'))
            ->groupBy('id_penjualan_detail')
            ->selectRaw('id_penjualan_detail, SUM(kuantiti_input) AS qty, SUM(nilai) AS nilai, SUM(hpp) AS hpp')
            ->get()->keyBy('id_penjualan_detail');

        // Porsi diskon struk: yang dibayar dibagi jumlah baris (bersih diskon baris).
        $jumlahBaris = Money::of($jual->details->sum('total'));
        // Dihitung langsung (baris × dibayar ÷ jumlah baris) lalu DIBULATKAN — dulu lewat faktor yang terpotong ke 2
        // desimal, sehingga retur atas struk berdiskon struk mengembalikan kurang (105.000 diskon 5.000 → 99.750).
        $porsi = fn (string $nilaiBaris) => Money::gtZero($jumlahBaris)
            ? self::bulat(bcdiv(bcmul(Money::of($nilaiBaris), Money::of($jual->total), 8), $jumlahBaris, 8))
            : '0.00';

        $rincian = [];
        $total = '0';
        foreach ($baris as $l) {
            $d = $jual->details->firstWhere('id', (int) $l['id_penjualan_detail'])
                ?? throw new AppException(422, 'Baris retur tidak cocok dengan struknya.');
            // Baris yang sudah habis diretur terkirim tanpa jumlah (isiannya disabled) — dulu galat 500.
            $qty = Money::of($l['kuantiti'] ?? 0, 4);
            if (! Money::gtZero($qty, 4)) {
                continue;
            }
            if (! in_array($l['kondisi'] ?? '', ['layak', 'rusak'], true)) {
                throw new AppException(422, "Pilih kondisi {$d->persediaan->nama_persediaan}: layak jual atau rusak.");
            }
            $s = $sudah[$d->id] ?? null;
            $sisa = Money::sub($d->kuantiti_input, $s->qty ?? '0', 4);
            if (Money::gt($qty, $sisa, 4)) {
                throw new AppException(422, "{$d->persediaan->nama_persediaan}: diretur melebihi yang dibeli (sisa {$this->angka($sisa)} {$d->satuan}).");
            }

            $bersihBaris = $porsi($d->total);
            if (Money::eq($qty, $sisa, 4)) {
                $nilai = Money::sub($bersihBaris, $s->nilai ?? '0');
                $hpp = Money::sub($d->hpp, $s->hpp ?? '0');
            } else {
                $nilai = self::bulat(bcdiv(bcmul($bersihBaris, $qty, 8), Money::of($d->kuantiti_input, 4), 8));
                $hpp = self::bulat(bcdiv(bcmul(Money::of($d->hpp), $qty, 8), Money::of($d->kuantiti_input, 4), 8));
            }
            $rincian[] = ['d' => $d, 'qty' => $qty, 'kondisi' => $l['kondisi'], 'nilai' => $nilai, 'hpp' => $hpp];
            $total = Money::add($total, $nilai);
        }
        if (! $rincian) {
            throw new AppException(422, 'Belum ada barang yang diretur.');
        }

        $pengawas = (new PinPengawasService)->verifikasi($idPengawas, $pin, $idKasir);

        return DB::transaction(function () use ($shift, $jual, $rincian, $total, $alasan, $pengawas, $idKasir, $sudah) {
            // Dikunci & diperiksa ULANG (audit 21 Okt 2026): dua retur bersamaan atas struk yang sama dulu sama-sama
            // melihat sisa penuh → uang & stok kembali dua kali. Urutan kunci: shift, lalu struk.
            $shift = $this->kunciShift($shift->id_shift, ['buka'], 'Shift sudah ditutup — muat ulang halaman.');
            if (Penjualan::lockForUpdate()->whereKey($jual->id_penjualan)->value('status') !== 'selesai') {
                throw new AppException(409, "Struk {$jual->nomor} baru saja dibatalkan — tak bisa diretur.");
            }
            $kini = ReturPenjualanDetail::whereIn('id_penjualan_detail', $jual->details->pluck('id'))->groupBy('id_penjualan_detail')
                ->selectRaw('id_penjualan_detail, SUM(kuantiti_input) AS qty')->pluck('qty', 'id_penjualan_detail');
            foreach ($jual->details as $d) {
                if (! Money::eq($kini[$d->id] ?? '0', $sudah[$d->id]->qty ?? '0', 4)) {
                    throw new AppException(409, "Struk {$jual->nomor} baru saja diretur di tempat lain — muat ulang lalu ulangi.");
                }
            }
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('retur-nomor')]);
            $base = 'RT-'.now()->format('ymd').'-';
            $nomor = DocNumber::nextDocNumber($base, ReturPenjualan::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));

            $retur = ReturPenjualan::create([
                'nomor' => $nomor, 'id_penjualan' => $jual->id_penjualan, 'id_shift' => $shift->id_shift,
                'kode_lokasi' => $shift->kode_lokasi, 'waktu' => now(), 'total' => $total, 'alasan' => trim($alasan),
                'id_kasir' => $idKasir, 'disetujui_oleh' => $pengawas->id_pengguna,
                // Struk kartu → uang kembali ke DOMPET santri, bukan dari laci.
                'metode' => $jual->metode === 'dompet' ? 'dompet' : 'tunai',
            ]);

            $hppKembali = '0';
            foreach ($rincian as $r) {
                $d = $r['d'];
                $qtyDasar = Money::mul($r['qty'], $d->isi, 4);
                $retur->details()->create([
                    'id_penjualan_detail' => $d->id, 'kode_persediaan' => $d->kode_persediaan,
                    'kuantiti_input' => $r['qty'], 'kuantiti' => $qtyDasar, 'kondisi' => $r['kondisi'],
                    'nilai' => $r['nilai'], 'hpp' => $r['hpp'],
                ]);
                if ($r['kondisi'] === 'layak') {
                    InventoryMovement::masuk([
                        'kode_persediaan' => $d->kode_persediaan, 'kode_lokasi' => $shift->kode_lokasi,
                        'kuantiti' => $qtyDasar, 'nilai' => $r['hpp'], 'alasan' => 'retur',
                        'sumber_modul' => ReturPenjualan::SUMBER, 'sumber_ref' => $nomor,
                        'keterangan' => "Retur dari {$jual->nomor}", 'id_pengguna' => $idKasir,
                    ]);
                    $hppKembali = Money::add($hppKembali, $r['hpp']);
                }
            }
            $retur->update(['hpp_kembali' => $hppKembali]);

            if ($retur->metode === 'dompet' && $jual->id_santri) {
                $m = $this->kembalikanDompet($jual->id_santri, $total, "Retur {$nomor} dari {$jual->nomor}", $idKasir);
                $retur->update(['id_mutasi_dompet' => $m->id]);
            } elseif ($retur->metode === 'dompet' && $jual->ref_dompet) {
                // Paling akhir: penghubung menolak/tak menjawab → retur tergulung utuh, stok tak jadi masuk.
                // Nomor TETAP per niat retur (audit 21 Okt 2026): sidik struk + yang sudah diretur + baris retur ini.
                // Mengulang retur yang tergulung (jawaban hilang) memakai nomor yang sama → Al Wafi menjawab ulang,
                // tak mengembalikan dua kali. Retur sah berikutnya punya "yang sudah diretur" berbeda → nomor baru.
                $sidik = sha1($jual->ref_dompet.'|'.json_encode($jual->details->map(fn ($d) => Money::of($sudah[$d->id]->qty ?? '0', 4))->all())
                    .'|'.json_encode(array_map(fn ($r) => [$r['d']->id, $r['qty']], $rincian)));
                $ref = 'WM-RT-'.strtoupper(substr($sidik, 0, 24));
                self::kembalikanPasti(fn () => app(KlienDompetToko::class)->kembalikan($ref, $jual->ref_dompet, $total, "Retur {$nomor} dari {$jual->nomor}",
                    RincianBelanja::dariRetur($retur->load('details.persediaan', 'details.baris'), $jual, $shift->lokasi?->nama)));
                $retur->update(['ref_dompet' => $ref]);
            }

            return $retur->load('details');
        });
    }

    private function angka(string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    }

    // ---- Bagian dalam ----

    private function jurnalShift(ShiftKasir $shift, $jual, string $tunai, string $hpp, string $selisih, int $idPengguna, $retur = null, string $dompet = '0')
    {
        $l = $shift->lokasi;
        $ket = "Penjualan kasir {$shift->nomor} — {$l->nama} ({$jual->count()} struk)";
        $lines = [];

        // RETUR: uang kembali membalik pendapatan; barang LAYAK yang kembali ke rak membalik
        // HPP per akun persediaannya. Barang rusak tak kembali — harga pokoknya tetap HPP.
        $retur ??= collect();
        // Akun kartu: mode lokal (instans Al Wafi) = Titipan Uang Saku santri; instans Wafi Mart =
        // Piutang ke Al Wafi (company_settings.kode_coa_kartu_santri, Fase 3b) — dompetnya milik entitas lain.
        $titipanSantri = \App\Models\CompanySettings::query()->value('kode_coa_kartu_santri') ?: DompetPolicy::COA_TITIPAN['santri'];

        // BELANJA KARTU: tak ada kas yang masuk — titipan uang saku santri berkurang (atau piutang ke Al Wafi
        // bertambah), pendapatan toko bertambah. Uangnya pindah ke kas toko lewat pencairan mingguan.
        if (Money::gtZero($dompet)) {
            $ketKartu = "Belanja kartu {$shift->nomor} — {$l->nama}";
            $lines[] = ['kode_coa' => $titipanSantri, 'debet' => $dompet, 'kredit' => '0', 'keterangan' => $ketKartu];
            $lines[] = ['kode_coa' => $l->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $dompet, 'keterangan' => $ketKartu, 'kode_bagian' => $l->kode_bagian];
        }

        foreach (['tunai' => $l->kode_coa_kas, 'dompet' => $titipanSantri] as $metode => $akunKembali) {
            $nilai = Money::of($retur->where('metode', $metode)->sum('total'));
            if (Money::gtZero($nilai)) {
                $ketRetur = "Retur penjualan {$shift->nomor} — {$l->nama} (".($metode === 'tunai' ? 'tunai' : 'ke dompet santri').')';
                $lines[] = ['kode_coa' => $l->kode_coa_pendapatan, 'debet' => $nilai, 'kredit' => '0', 'keterangan' => $ketRetur, 'kode_bagian' => $l->kode_bagian];
                $lines[] = ['kode_coa' => $akunKembali, 'debet' => '0', 'kredit' => $nilai, 'keterangan' => $ketRetur];
            }
        }
        $kembali = [];
        foreach ($retur as $r) {
            foreach ($r->details->where('kondisi', 'layak') as $d) {
                $kembali[$d->persediaan->kode_coa] = Money::add($kembali[$d->persediaan->kode_coa] ?? '0', $d->hpp);
            }
        }
        $hppKembali = array_reduce($kembali, fn ($s, $v) => Money::add($s, $v), '0');
        if (Money::gtZero($hppKembali)) {
            foreach ($kembali as $akun => $nilai) {
                if (Money::gtZero($nilai)) {
                    $lines[] = ['kode_coa' => $akun, 'debet' => $nilai, 'kredit' => '0', 'keterangan' => "Retur layak jual {$shift->nomor}"];
                }
            }
            $lines[] = ['kode_coa' => $l->kode_coa_hpp, 'debet' => '0', 'kredit' => $hppKembali, 'keterangan' => "Retur layak jual {$shift->nomor}", 'kode_bagian' => $l->kode_bagian];
        }

        if (Money::gtZero($tunai)) {
            $lines[] = ['kode_coa' => $l->kode_coa_kas, 'debet' => $tunai, 'kredit' => '0', 'keterangan' => $ket];
            $lines[] = ['kode_coa' => $l->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $tunai, 'keterangan' => $ket, 'kode_bagian' => $l->kode_bagian];
        }

        if (Money::gtZero($hpp)) {
            $lines[] = ['kode_coa' => $l->kode_coa_hpp, 'debet' => $hpp, 'kredit' => '0', 'keterangan' => "HPP {$ket}", 'kode_bagian' => $l->kode_bagian];
            // Kredit per AKUN persediaan barang — buku pembantu & buku besar tetap cocok per akun.
            $perAkun = [];
            foreach ($jual as $j) {
                foreach ($j->details as $d) {
                    $akun = $d->persediaan->kode_coa;
                    $perAkun[$akun] = Money::add($perAkun[$akun] ?? '0', $d->hpp);
                }
            }
            foreach ($perAkun as $akun => $nilai) {
                if (Money::gtZero($nilai)) {
                    $lines[] = ['kode_coa' => $akun, 'debet' => '0', 'kredit' => $nilai, 'keterangan' => "HPP {$ket}"];
                }
            }
        }

        if (! Money::isZero($selisih)) {
            $nilai = Money::isNegative($selisih) ? Money::sub('0', $selisih) : $selisih;
            $ketSelisih = (Money::isNegative($selisih) ? 'Kurang' : 'Lebih')." kas laci {$shift->nomor} — kasir {$shift->kasir?->nama}";
            $kas = ['kode_coa' => $l->kode_coa_kas, 'keterangan' => $ketSelisih];
            $akunSelisih = ['kode_coa' => $l->kode_coa_selisih_kas, 'keterangan' => $ketSelisih, 'kode_bagian' => $l->kode_bagian];
            if (Money::isNegative($selisih)) {
                $lines[] = [...$akunSelisih, 'debet' => $nilai, 'kredit' => '0'];
                $lines[] = [...$kas, 'debet' => '0', 'kredit' => $nilai];
            } else {
                $lines[] = [...$kas, 'debet' => $nilai, 'kredit' => '0'];
                $lines[] = [...$akunSelisih, 'debet' => '0', 'kredit' => $nilai];
            }
        }

        if (! $lines) {
            return null;
        }

        return PostingService::postJournal([
            'referensi' => DocNumber::nextJournalRef('KSR', $shift->dibuka_pada),
            'tanggal' => $shift->dibuka_pada->toDateString(),
            'kode_unit' => $l->kode_unit,
            'keterangan' => $ket,
            'sumber_modul' => Penjualan::SUMBER,
            'id_sumber' => (string) $shift->id_shift,
            'id_pengguna' => $idPengguna,
            'lines' => $lines,
        ]);
    }

    /** Shift terbuka milik kasir ini. */
    private function shiftKasir(int $idShift, int $idKasir): ShiftKasir
    {
        $shift = ShiftKasir::find($idShift) ?? throw new AppException(404, 'Shift tidak ditemukan.');
        if ($shift->status !== 'buka') {
            throw new AppException(409, 'Shift sudah ditutup — buka shift baru untuk berjualan.');
        }
        if ($shift->id_kasir !== $idKasir) {
            throw new AppException(403, 'Shift ini milik kasir lain.');
        }

        return $shift;
    }

    /**
     * Baris keranjang → [barang, satuan, isi, harga per satuan, barcode].
     * Harga satuan konversi: harga khususnya bila diisi, selain itu isi × harga jual dasar.
     *
     * @return array{0:Inventory,1:string,2:string,3:string,4:?string,5:string} [.., harga berlaku, barcode, harga normal]
     */
    /**
     * Kunci baris shift & periksa ULANG statusnya di DALAM transaksi (audit 21 Okt 2026). Urutan kunci di seluruh
     * kasir: shift dulu, baru struk — supaya batal, retur, jual & tutup shift tak saling menunggu (deadlock).
     *
     * @param  list<string>  $status  status yang diizinkan
     */
    private function kunciShift(int $idShift, array $status, string $pesan): ShiftKasir
    {
        $s = ShiftKasir::with('lokasi')->lockForUpdate()->find($idShift) ?? throw new AppException(404, 'Shift tidak ditemukan.');
        if (! in_array($s->status, $status, true)) {
            throw new AppException(409, $pesan);
        }

        return $s;
    }

    /** Bulatkan ke 2 desimal (setengah menjauhi nol) — bcmath hanya memotong. */
    private static function bulat(string $v): string
    {
        return bcadd($v, str_starts_with(ltrim($v), '-') ? '-0.005' : '0.005', 2);
    }

    private function resolusi(array $l, int $no): array
    {
        $barcode = trim((string) ($l['barcode'] ?? ''));
        if ($barcode !== '') {
            $b = BarcodePersediaan::with('persediaan')->find($barcode) ?? throw new AppException(404, "Barcode {$barcode} tidak dikenal.");
            $item = $b->persediaan;
            $satuanDiminta = $b->satuan;
        } else {
            $item = Inventory::find($l['kode_persediaan'] ?? '') ?? throw new AppException(404, "Baris {$no}: barang tidak ditemukan.");
            $satuanDiminta = $l['satuan'] ?? null;
            $barcode = null;
        }

        if ($item->status !== 'aktif' || ! $item->dijual_di_toko || $item->harga_jual === null) {
            throw new AppException(422, "{$item->nama_persediaan} belum ditandai dijual di toko atau belum punya harga jual.");
        }
        if (! $item->kode_coa) {
            throw new AppException(422, "{$item->nama_persediaan} belum punya Akun Persediaan.");
        }

        [$satuan, $isi] = $item->satuanKe($satuanDiminta)
            ?? throw new AppException(422, "Satuan {$satuanDiminta} belum terdaftar untuk {$item->nama_persediaan}.");
        $harga = self::hargaJual($item, $satuan, $isi);
        // Harga Rp 0 = belum diisi (impor POS lama meninggalkan beberapa), bukan barang gratis (audit 21 Okt 2026).
        // Barang yang memang digratiskan dijual dengan diskon berpersetujuan pengawas.
        if (! Money::gtZero($harga)) {
            throw new AppException(422, "{$item->nama_persediaan} ({$satuan}) belum punya harga jual — isi harganya di master barang dulu.");
        }

        return [$item, $satuan, $isi, $harga, $barcode, self::hargaNormal($item, $satuan, $isi)];
    }

    /**
     * Harga jual per satuan: harga khusus satuan konversi bila diisi, selain itu
     * isi × harga jual satuan dasar. Satu-satunya rumus harga — layar kasir memakai
     * yang sama, jadi angka di layar tak pernah berbeda dari yang tersimpan.
     */
    public static function hargaJual(Inventory $item, string $satuan, string $isi): string
    {
        // Promo terjadwal hanya untuk SATUAN DASAR; satuan besar memakai harganya sendiri.
        if (Money::eq($isi, '1', 4) && $item->promoAktif()) {
            return Money::of($item->harga_promo);
        }

        return self::hargaNormal($item, $satuan, $isi);
    }

    /** Harga master tanpa promo — dicetak di struk sebagai pembanding. */
    public static function hargaNormal(Inventory $item, string $satuan, string $isi): string
    {
        if (Money::eq($isi, '1', 4)) {
            return Money::of($item->harga_jual);
        }
        $khusus = SatuanPersediaan::where('kode_persediaan', $item->kode_persediaan)->where('satuan', $satuan)->value('harga_jual');

        return $khusus !== null ? Money::of($khusus) : Money::mul($isi, $item->harga_jual);
    }
}
