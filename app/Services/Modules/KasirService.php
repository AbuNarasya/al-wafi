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
use App\Models\ReturPenjualan;
use App\Models\ReturPenjualanDetail;
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

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
    public function jual(int $idShift, array $baris, string|int|float $bayar, int $idKasir, array $diskon = [], ?array $kartu = null): Penjualan
    {
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
            $kotor = Money::mul($qtyInput, $harga);
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
        if ($kartu !== null) {
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

        return DB::transaction(function () use ($shift, $rincian, $total, $bayar, $idKasir, $diskonStruk, $penyetuju, $adaDiskon, $alasan, $santri, $pinBenar, $kartu) {
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
                'metode' => $santri ? 'dompet' : 'tunai',
                'id_santri' => $santri?->id,
                'uid_kartu' => $santri ? KartuSantriService::bersihkanUid($kartu['uid']) : null,
                'total' => $total,
                'diskon_struk' => $diskonStruk,
                'bayar' => $bayar,
                'kembalian' => Money::sub($bayar, $total),
                'status' => 'selesai',
                'id_kasir' => $idKasir,
                'disetujui_oleh' => $penyetuju?->id_pengguna,
                'alasan_diskon' => $adaDiskon ? $alasan : null,
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
        $idDompet = DompetSantri::where('id_santri', $idSantri)->value('id');
        if (! $idDompet) {
            return '0';
        }

        return Money::sub('0', Money::of(MutasiDompet::where('pemilik', 'santri')->where('id_dompet', $idDompet)
            ->where('jenis', 'jajan')->whereDate('tanggal', now()->toDateString())->sum('nominal')));
    }

    /** Potong saldo dompet santri (dalam transaksi pemanggil, baris saldo dikunci). */
    private function potongDompet(int $idSantri, string $nominal, string $nomorStruk, int $idKasir, bool $pinBenar): MutasiDompet
    {
        $dompet = DompetSantri::where('id_santri', $idSantri)->lockForUpdate()->first();
        if (! $dompet || Money::lt($dompet->saldo, $nominal)) {
            throw new AppException(422, 'Saldo uang saku tidak cukup untuk belanja ini.');
        }
        if (! $pinBenar && Money::gt(Money::add(self::belanjaKartuHariIni($idSantri), $nominal), KartuSantriService::batasHarian($idSantri))) {
            throw new AppException(409, 'Batas belanja harian terlampaui — ulangi dengan PIN belanja.');
        }

        return $this->mutasiDompet($dompet, Money::sub('0', $nominal), "Belanja Wafi Mart {$nomorStruk}", $idKasir);
    }

    /** Kembalikan uang ke dompet santri (batal struk / retur struk kartu). */
    private function kembalikanDompet(int $idSantri, string $nominal, string $keterangan, int $idPengguna): MutasiDompet
    {
        $dompet = DompetSantri::where('id_santri', $idSantri)->lockForUpdate()->firstOrFail();

        return $this->mutasiDompet($dompet, $nominal, $keterangan, $idPengguna);
    }

    /**
     * Satu baris mutasi `jajan` (bertanda: − potong, + kembali). TANPA jurnal di sini —
     * jurnalnya ringkas di tutup shift (D/K Titipan Uang Saku ↔ Pendapatan toko).
     */
    private function mutasiDompet(DompetSantri $dompet, string $nominalBertanda, string $keterangan, int $idPengguna): MutasiDompet
    {
        $saldo = Money::add($dompet->saldo, $nominalBertanda);
        $dompet->update(['saldo' => $saldo]);
        $base = DocNumber::docBase('DMP', now());
        $nomor = DocNumber::nextDocNumber($base, MutasiDompet::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));

        return MutasiDompet::create([
            'nomor' => $nomor, 'pemilik' => 'santri', 'id_dompet' => $dompet->id, 'jenis' => 'jajan',
            'nominal' => $nominalBertanda, 'saldo_setelah' => $saldo, 'tanggal' => now()->toDateString(),
            'keterangan' => $keterangan, 'status' => 'terverifikasi', 'dicatat_oleh' => $idPengguna,
        ]);
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
            InventoryMovement::batalkanDokumen(Penjualan::SUMBER, $jual->nomor, $idPenyetuju);
            if ($jual->metode === 'dompet' && $jual->id_santri) {
                // Struk kartu: uangnya kembali ke dompet santri, bukan dari laci.
                $this->kembalikanDompet($jual->id_santri, Money::of($jual->total), "Batal struk Wafi Mart {$jual->nomor}", $idPenyetuju);
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

        $setoran = Money::sub($shift->uang_dihitung, $shift->modal_awal);

        return DB::transaction(function () use ($shift, $tujuan, $setoran, $idPenerima) {
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
        $faktor = Money::gtZero($jumlahBaris) ? Money::div($jual->total, $jumlahBaris, 8) : '0';

        $rincian = [];
        $total = '0';
        foreach ($baris as $l) {
            $d = $jual->details->firstWhere('id', (int) $l['id_penjualan_detail'])
                ?? throw new AppException(422, 'Baris retur tidak cocok dengan struknya.');
            $qty = Money::of($l['kuantiti'], 4);
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

            $bersihBaris = Money::mul($d->total, $faktor);
            if (Money::eq($qty, $sisa, 4)) {
                $nilai = Money::sub($bersihBaris, $s->nilai ?? '0');
                $hpp = Money::sub($d->hpp, $s->hpp ?? '0');
            } else {
                $nilai = Money::div(Money::mul($bersihBaris, $qty, 4), $d->kuantiti_input);
                $hpp = Money::div(Money::mul($d->hpp, $qty, 4), $d->kuantiti_input);
            }
            $rincian[] = ['d' => $d, 'qty' => $qty, 'kondisi' => $l['kondisi'], 'nilai' => $nilai, 'hpp' => $hpp];
            $total = Money::add($total, $nilai);
        }
        if (! $rincian) {
            throw new AppException(422, 'Belum ada barang yang diretur.');
        }

        $pengawas = (new PinPengawasService)->verifikasi($idPengawas, $pin, $idKasir);

        return DB::transaction(function () use ($shift, $jual, $rincian, $total, $alasan, $pengawas, $idKasir) {
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
        $titipanSantri = DompetPolicy::COA_TITIPAN['santri'];

        // BELANJA KARTU: tak ada kas yang masuk — titipan uang saku santri berkurang, pendapatan
        // toko bertambah. Uangnya baru pindah ke kas toko lewat pencairan mingguan (D3).
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

        return [$item, $satuan, $isi, self::hargaJual($item, $satuan, $isi), $barcode, self::hargaNormal($item, $satuan, $isi)];
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
