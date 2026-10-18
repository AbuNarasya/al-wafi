<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BarcodePersediaan;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Models\Penjualan;
use App\Models\SatuanPersediaan;
use App\Models\ShiftKasir;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
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
     * @param  list<array{barcode?:?string, kode_persediaan?:?string, satuan?:?string, kuantiti:string|int|float}>  $baris
     */
    public function jual(int $idShift, array $baris, string|int|float $bayar, int $idKasir): Penjualan
    {
        $shift = $this->shiftKasir($idShift, $idKasir);
        if (! $baris) {
            throw new AppException(422, 'Keranjang masih kosong.');
        }

        $rincian = [];
        $total = '0';
        foreach ($baris as $i => $l) {
            [$item, $satuan, $isi, $harga, $barcode] = $this->resolusi($l, $i + 1);
            $qtyInput = Money::of($l['kuantiti'], 4);
            if (! Money::gtZero($qtyInput, 4)) {
                throw new AppException(422, "Jumlah {$item->nama_persediaan} harus lebih dari nol.");
            }
            $subtotal = Money::mul($qtyInput, $harga);
            $rincian[] = compact('item', 'satuan', 'isi', 'harga', 'barcode', 'qtyInput', 'subtotal');
            $total = Money::add($total, $subtotal);
        }

        $bayar = Money::of($bayar);
        if (Money::lt($bayar, $total)) {
            throw new AppException(422, 'Uang yang dibayarkan kurang dari total belanja.');
        }

        return DB::transaction(function () use ($shift, $rincian, $total, $bayar, $idKasir) {
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
                'metode' => 'tunai',
                'total' => $total,
                'bayar' => $bayar,
                'kembalian' => Money::sub($bayar, $total),
                'status' => 'selesai',
                'id_kasir' => $idKasir,
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
                    'total' => $r['subtotal'],
                    'hpp' => $keluar->nilai,
                ]);
                $hpp = Money::add($hpp, $keluar->nilai);
            }
            $jual->update(['hpp' => $hpp]);

            return $jual->load('details');
        });
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
        if ($jual->id_kasir === $idPenyetuju && ! User::find($idPenyetuju)?->is_admin) {
            throw new AppException(403, 'Pembatalan struk harus disetujui orang lain, bukan kasirnya sendiri.');
        }

        return DB::transaction(function () use ($jual, $alasan, $idPenyetuju) {
            InventoryMovement::batalkanDokumen(Penjualan::SUMBER, $jual->nomor, $idPenyetuju);
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
            $tunai = Money::of($jual->where('metode', 'tunai')->sum('total'));
            $hpp = Money::of($jual->sum('hpp'));
            $selisih = Money::sub($uang, Money::add($shift->modal_awal, $tunai));

            $entry = $this->jurnalShift($shift, $jual, $tunai, $hpp, $selisih, $idPengguna);

            if ($entry) {
                MutasiPersediaan::where('sumber_modul', Penjualan::SUMBER)
                    ->whereIn('sumber_ref', $jual->pluck('nomor'))
                    ->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $entry->id]);
            }

            $shift->update([
                'status' => 'tutup',
                'ditutup_pada' => now(),
                'jumlah_transaksi' => $jual->count(),
                'total_tunai' => $tunai,
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

    // ---- Bagian dalam ----

    private function jurnalShift(ShiftKasir $shift, $jual, string $tunai, string $hpp, string $selisih, int $idPengguna)
    {
        $l = $shift->lokasi;
        $ket = "Penjualan kasir {$shift->nomor} — {$l->nama} ({$jual->count()} struk)";
        $lines = [];

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
     * @return array{0:Inventory,1:string,2:string,3:string,4:?string}
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

        return [$item, $satuan, $isi, self::hargaJual($item, $satuan, $isi), $barcode];
    }

    /**
     * Harga jual per satuan: harga khusus satuan konversi bila diisi, selain itu
     * isi × harga jual satuan dasar. Satu-satunya rumus harga — layar kasir memakai
     * yang sama, jadi angka di layar tak pernah berbeda dari yang tersimpan.
     */
    public static function hargaJual(Inventory $item, string $satuan, string $isi): string
    {
        if (Money::eq($isi, '1', 4)) {
            return Money::of($item->harga_jual);
        }
        $khusus = SatuanPersediaan::where('kode_persediaan', $item->kode_persediaan)->where('satuan', $satuan)->value('harga_jual');

        return $khusus !== null ? Money::of($khusus) : Money::mul($isi, $item->harga_jual);
    }
}
