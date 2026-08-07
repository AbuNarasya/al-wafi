<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BankLoan;
use App\Models\BusinessUnit;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PengajuanPembayaran;
use App\Models\Vendor;
use App\Services\Ledger\AssetDraft;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Kas Keluar (Payment Voucher, multi-baris). Tipe baris: lainnya | invoice
 * | inventory | pengajuan. Baris pengajuan membawa unitnya sendiri (komposisi
 * unit pengajuan); baris lain mewarisi unit dokumen. Kredit kas DIPECAH per unit.
 *
 * CATATAN: jalur pengajuan jenis `uang_muka` (cash-basis) belum dikonversi —
 * ditandai lanjutan (butuh PengajuanPembayaranService::applyUangMukaPayment).
 */
class CashOutService
{
    private const SUMBER = 'KasKeluar';

    /** Voucher butuh unit dokumen? Hanya baris `pengajuan` membawa unitnya sendiri. */
    public static function butuhUnitDokumen(array $details): bool
    {
        foreach ($details as $d) {
            if (($d['tipe'] ?? 'lainnya') !== 'pengajuan') {
                return true;
            }
        }

        return false;
    }

    public function create(array $input, ?int $idPengguna): CashOut
    {
        $rek = BankAccount::with('coa')->find($input['kode_rekening']);
        if (! $rek) {
            throw new AppException(400, 'Kas/Rekening tidak ditemukan.');
        }

        if (self::butuhUnitDokumen($input['details']) && empty($input['kode_unit'])) {
            throw new AppException(400, 'Unit bisnis wajib dipilih — ada baris yang tidak menentukan unitnya sendiri.');
        }
        if (! empty($input['kode_unit']) && ! BusinessUnit::find($input['kode_unit'])) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }
        if (! empty($input['kode_vendor']) && ! Vendor::find($input['kode_vendor'])) {
            throw new AppException(400, 'Vendor tidak ditemukan.');
        }

        $bankLoan = null;
        if (! empty($input['id_bank_loan'])) {
            $loan = BankLoan::find($input['id_bank_loan']);
            if (! $loan) {
                throw new AppException(400, 'Pembiayaan tidak ditemukan.');
            }
            if ($loan->status === 'void') {
                throw new AppException(409, 'Pembiayaan sudah di-void.');
            }
            $bankLoan = ['id' => $loan->id, 'kode_coa_hutang' => $loan->kode_coa_hutang];
        }

        // PERINTAH PEMBAYARAN. Dua hal sekaligus: baris yang mengaku berasal
        // dari PP diperiksa keabsahannya, dan kewajiban yang sedang terkunci di
        // PP hidup ditolak bila dibayar dari jalur lain.
        $perintahSvc = new PerintahPembayaranService;
        $perintahSvc->assertRealisasiSah(
            $input['details'],
            $input['id_perintah'] ?? null,
            $input['id_bank_loan'] ?? null,
        );

        $total = '0';
        $details = [];
        $jLines = [];
        $usedInvoices = [];
        $invoicePayments = [];
        $usedPengajuan = [];
        $pengajuanPayments = [];
        $penyelesaianPayments = [];
        $uangMukaPayments = [];
        $kasPerUnit = []; // per unit, urutan sisip
        $inventoryBuys = [];
        $assetBuys = [];

        foreach ($input['details'] as $l) {
            $tipe = $l['tipe'] ?? 'lainnya';
            $sebelum = count($details);

            if ($tipe === 'invoice') {
                if (empty($l['id_invoice'])) {
                    throw new AppException(400, 'Pilih invoice pada baris pembayaran invoice.');
                }
                if (in_array($l['id_invoice'], $usedInvoices, true)) {
                    throw new AppException(409, 'Invoice yang sama tidak boleh dipilih lebih dari sekali dalam satu voucher.');
                }
                $usedInvoices[] = $l['id_invoice'];
                $inv = Invoice::find($l['id_invoice']);
                if (! $inv) {
                    throw new AppException(400, 'Invoice tidak ditemukan.');
                }
                $nominal = Money::of($l['nominal'] ?? 0);
                if (Money::lte($nominal, '0')) {
                    throw new AppException(400, 'Nominal pembayaran harus > 0.');
                }
                if (Money::gt($nominal, $inv->sisa_hutang)) {
                    throw new AppException(422, "Nominal melebihi sisa hutang invoice {$inv->nomor_invoice}.");
                }
                $hutangCoa = CoaDetail::find($inv->kode_coa_hutang);
                $total = Money::add($total, $nominal);
                $ket = $l['keterangan'] ?? "Pembayaran invoice {$inv->nomor_invoice}";
                $details[] = ['tipe' => 'invoice', 'id_invoice' => $inv->id_invoice, 'kode_coa' => $inv->kode_coa_hutang, 'nama_coa' => $hutangCoa?->nama_coa ?? $inv->kode_coa_hutang, 'nominal' => $nominal, 'keterangan' => $ket];
                $jLines[] = ['kode_coa' => $inv->kode_coa_hutang, 'nama_coa' => $hutangCoa?->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket, 'kode_bagian' => $l['kode_bagian'] ?? null];
                $invoicePayments[] = ['id_invoice' => $inv->id_invoice, 'nominal' => $nominal];
            } elseif ($tipe === 'pengajuan') {
                if (empty($l['id_pengajuan'])) {
                    throw new AppException(400, 'Pilih pengajuan pada baris pelunasan pengajuan.');
                }
                if (in_array($l['id_pengajuan'], $usedPengajuan, true)) {
                    throw new AppException(409, 'Pengajuan yang sama tidak boleh dipilih lebih dari sekali dalam satu voucher.');
                }
                $usedPengajuan[] = $l['id_pengajuan'];
                $pb = PengajuanPembayaran::with('details')->find($l['id_pengajuan']);
                if (! $pb) {
                    throw new AppException(400, 'Pengajuan tidak ditemukan.');
                }
                if ($pb->jenis === 'penyelesaian_uang_muka') {
                    // KEKURANGAN penyelesaian: bebannya sudah diakui saat posting,
                    // dan selisihnya ditahan di akun hutang. Di sini akun itu
                    // DIDEBIT — kas baru berkurang sekarang, saat uangnya memang
                    // benar-benar keluar.
                    $sisaKurang = Money::of($pb->sisa_kurang_bayar);
                    if (! Money::gtZero($sisaKurang)) {
                        throw new AppException(422, "Penyelesaian {$pb->nomor} tak punya kekurangan yang harus dibayar.");
                    }
                    if ($pb->status !== 'diposting') {
                        throw new AppException(422, "Penyelesaian {$pb->nomor} berstatus {$pb->status}; hanya yang sudah diposting yang bisa dibayar.");
                    }
                    if (! $pb->kode_coa_hutang) {
                        throw new AppException(422, "Penyelesaian {$pb->nomor} belum punya akun hutang penampung kekurangan.");
                    }
                    $nominal = Money::of($l['nominal'] ?? 0);
                    if (! Money::eq($nominal, $sisaKurang)) {
                        throw new AppException(422, "Kekurangan penyelesaian {$pb->nomor} harus dilunasi PENUH sebesar {$sisaKurang}.");
                    }
                    $hutangCoa = CoaDetail::find($pb->kode_coa_hutang);
                    $total = Money::add($total, $nominal);
                    $ket = $l['keterangan'] ?? "Pembayaran kekurangan penyelesaian {$pb->nomor}";
                    $unitBaris = $pb->details->first()?->kode_unit ?: ($input['kode_unit'] ?? null);
                    $details[] = ['tipe' => 'pengajuan', 'id_pengajuan' => $pb->id, 'kode_coa' => $pb->kode_coa_hutang, 'nama_coa' => $hutangCoa?->nama_coa ?? $pb->kode_coa_hutang, 'nominal' => $nominal, 'keterangan' => $ket];
                    $jLines[] = ['kode_coa' => $pb->kode_coa_hutang, 'nama_coa' => $hutangCoa?->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket, 'kode_unit' => $unitBaris, 'kode_bagian' => $pb->kode_bagian];
                    $kasPerUnit[$unitBaris] = Money::add($kasPerUnit[$unitBaris] ?? '0', $nominal);
                    $penyelesaianPayments[] = ['id' => $pb->id, 'nominal' => $nominal];

                    continue;
                }
                if ($pb->jenis === 'uang_muka') {
                    // CASH BASIS: jurnal Uang Muka(D)/Kas(K) dibuat sekarang; tiap baris
                    // jadi OperationalAdvance outstanding. WAJIB PENUH.
                    if ($pb->status !== 'diverifikasi') {
                        throw new AppException(422, "Pengajuan uang muka {$pb->nomor} berstatus {$pb->status}; harus diverifikasi keuangan dulu.");
                    }
                    $nominal = Money::of($l['nominal'] ?? 0);
                    if (! Money::eq($nominal, $pb->nominal)) {
                        throw new AppException(422, "Uang muka {$pb->nomor} harus dibayar PENUH sebesar ".Money::of($pb->nominal).'.');
                    }
                    $total = Money::add($total, $nominal);
                    $ket = $l['keterangan'] ?? "Pelunasan pengajuan {$pb->nomor}";
                    $details[] = ['tipe' => 'pengajuan', 'id_pengajuan' => $pb->id, 'kode_coa' => $pb->details->first()?->kode_coa ?? $pb->kode_bagian, 'nama_coa' => "Uang muka {$pb->nomor}", 'nominal' => $nominal, 'keterangan' => $ket];
                    foreach ($pb->details as $d) {
                        $jLines[] = ['kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa, 'debet' => $d->nominal, 'kredit' => '0', 'keterangan' => $ket, 'kode_unit' => $d->kode_unit, 'kode_bagian' => $pb->kode_bagian];
                        $kasPerUnit[$d->kode_unit] = Money::add($kasPerUnit[$d->kode_unit] ?? '0', $d->nominal);
                    }
                    $uangMukaPayments[] = $pb->id;

                    continue;
                }

                // PEMBAYARAN (accrual): mendebit akun Hutang Pengajuan.
                //
                // BOLEH SEBAGIAN. Dulu wajib lunas sekali bayar, bukan karena
                // aturan usaha melainkan karena baris hutangnya dipecah per
                // unit — cicilan mengharuskan porsi tiap unit diprorata. Sejak
                // hutang & kas dipusatkan di unit penampung neraca, pembagian
                // itu tak ada lagi, dan cicilan tinggal soal mengurangi sisa.
                if ($pb->status !== 'diposting') {
                    throw new AppException(422, "Pengajuan {$pb->nomor} berstatus {$pb->status}; hanya yang sudah disetujui & diposting yang bisa dibayar.");
                }
                $sisa = Money::of($pb->sisa_hutang);
                $nominal = Money::of($l['nominal'] ?? 0);
                if (! Money::gtZero($nominal)) {
                    throw new AppException(422, "Nominal pembayaran pengajuan {$pb->nomor} harus lebih dari nol.");
                }
                if (Money::gt($nominal, $sisa)) {
                    throw new AppException(422, "Nominal melebihi sisa hutang pengajuan {$pb->nomor} sebesar {$sisa}.");
                }
                $kodeHutang = $pb->kode_coa_hutang;
                if (! $kodeHutang) {
                    throw new AppException(422, "Pengajuan {$pb->nomor} belum punya akun hutang; tidak dapat dibayar.");
                }
                $pbCoa = CoaDetail::find($kodeHutang);
                $total = Money::add($total, $nominal);
                $ket = $l['keterangan'] ?? "Pelunasan pengajuan {$pb->nomor}";
                $details[] = ['tipe' => 'pengajuan', 'id_pengajuan' => $pb->id, 'kode_coa' => $kodeHutang, 'nama_coa' => $pbCoa?->nama_coa ?? $kodeHutang, 'nominal' => $nominal, 'keterangan' => $ket];
                // SATU baris hutang sebesar yang benar-benar dibayar — bukan
                // komposisi penuh pengajuannya. Dulu keduanya kebetulan sama
                // karena pelunasan wajib penuh; begitu cicilan dibolehkan,
                // memakai komposisi penuh berarti jurnal menggerakkan lebih
                // banyak uang daripada yang tertulis di vouchernya, dan tetap
                // balance sehingga tak ada yang menangkapnya.
                //
                // Unitnya tak ditentukan di sini — PostingService menaruh baris
                // hutang & kas di unit penampung neraca.
                $jLines[] = ['kode_coa' => $kodeHutang, 'nama_coa' => $pbCoa?->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket];

                // Sisi kas dititipkan ke unit PERTAMA pengajuannya semata-mata
                // agar $kasPerUnit punya kunci yang pasti — nominalnya utuh,
                // tidak dibagi. Bila unit penampung neraca disetel, baris kas
                // ini ditimpa PostingService dan pilihan kunci tak berpengaruh
                // sama sekali; bila tidak, perilakunya sama seperti dokumen
                // satu unit lain (invoice, uang muka).
                [$unitPertama] = PengajuanPembayaranService::ringkasPerUnit($pb->details)[0];
                $kasPerUnit[$unitPertama] = Money::add($kasPerUnit[$unitPertama] ?? '0', $nominal);
                $pengajuanPayments[] = ['id' => $pb->id, 'nominal' => $nominal];
            } elseif ($tipe === 'inventory') {
                if (empty($l['kode_persediaan'])) {
                    throw new AppException(400, 'Pilih item persediaan.');
                }
                $qty = Money::of($l['kuantiti'] ?? 0, 4);
                $harga = Money::of($l['harga_satuan'] ?? 0);
                if (Money::lte($qty, '0', 4) || Money::lte($harga, '0')) {
                    throw new AppException(400, 'Kuantiti dan harga satuan harus > 0.');
                }
                $item = Inventory::find($l['kode_persediaan']);
                if (! $item) {
                    throw new AppException(400, 'Item persediaan tidak ditemukan.');
                }
                if (! $item->kode_coa) {
                    throw new AppException(422, "Item \"{$item->nama_persediaan}\" belum memiliki Akun COA.");
                }
                $persCoa = CoaDetail::find($item->kode_coa);
                $nominal = Money::mul($qty, $harga);
                $total = Money::add($total, $nominal);
                $ket = $l['keterangan'] ?? "Pembelian {$item->nama_persediaan}";
                $details[] = ['tipe' => 'inventory', 'kode_persediaan' => $item->kode_persediaan, 'kuantiti' => $qty, 'harga_satuan' => $harga, 'kode_coa' => $item->kode_coa, 'nama_coa' => $persCoa?->nama_coa ?? $item->kode_coa, 'nominal' => $nominal, 'keterangan' => $ket];
                $jLines[] = ['kode_coa' => $item->kode_coa, 'nama_coa' => $persCoa?->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket, 'kode_bagian' => $l['kode_bagian'] ?? null];
                $inventoryBuys[] = ['kode_persediaan' => $item->kode_persediaan, 'kuantiti' => $qty, 'nominal' => $nominal];
            } else {
                if (empty($l['kode_coa'])) {
                    throw new AppException(400, 'Pilih Akun COA pada baris beban/lainnya.');
                }
                $nominal = Money::of($l['nominal'] ?? 0);
                if (Money::lte($nominal, '0')) {
                    throw new AppException(400, 'Nominal harus > 0.');
                }
                $coa = CoaDetail::find($l['kode_coa']);
                if (! $coa) {
                    throw new AppException(400, "Akun COA {$l['kode_coa']} tidak ditemukan.");
                }
                $total = Money::add($total, $nominal);
                $details[] = ['tipe' => 'lainnya', 'kode_coa' => $l['kode_coa'], 'nama_coa' => $coa->nama_coa, 'nominal' => $nominal, 'keterangan' => $l['keterangan'] ?? null];
                $jLines[] = ['kode_coa' => $l['kode_coa'], 'nama_coa' => $coa->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $l['keterangan'] ?? $input['keterangan'], 'kode_bagian' => $l['kode_bagian'] ?? null];
                if (! empty($l['kode_aset'])) {
                    $assetBuys[] = ['nama' => $l['keterangan'] ?? $coa->nama_coa, 'nominal' => $nominal, 'kode_coa' => $l['kode_coa'], 'kode_aset' => $l['kode_aset']];
                } elseif (! empty($l['buat_aset'])) {
                    $assetBuys[] = ['nama' => $l['keterangan'] ?? $coa->nama_coa, 'nominal' => $nominal, 'kode_coa' => $l['kode_coa']];
                }
            }

            // Tautkan ke baris Perintah Pembayaran-nya. Ditempel di SINI, sekali
            // untuk semua cabang, supaya cabang baru tak bisa terlewat.
            for ($i = $sebelum; $i < count($details); $i++) {
                $details[$i]['id_perintah_detail'] = $l['id_perintah_detail'] ?? null;
            }
        }

        // Baris non-pengajuan memakai unit dokumen (dihitung dari selisih).
        $kasPengajuan = array_reduce($kasPerUnit, fn ($s, $v) => Money::add($s, $v), '0');
        $kasLain = Money::sub($total, $kasPengajuan);
        if (Money::gtZero($kasLain)) {
            $unitDok = $input['kode_unit'] ?? null;
            if (! $unitDok) {
                throw new AppException(400, 'Unit bisnis wajib dipilih untuk baris non-pengajuan.');
            }
            $kasPerUnit[$unitDok] = Money::add($kasPerUnit[$unitDok] ?? '0', $kasLain);
        }

        // Kredit kas DIPECAH per unit.
        foreach ($kasPerUnit as $kodeUnit => $nominal) {
            $jLines[] = ['kode_coa' => $input['kode_rekening'], 'nama_coa' => $rek->coa->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => $input['keterangan'], 'kode_unit' => $kodeUnit];
        }

        return DB::transaction(function () use ($input, $idPengguna, $total, $details, $jLines, $bankLoan, $invoicePayments, $pengajuanPayments, $penyelesaianPayments, $uangMukaPayments, $inventoryBuys, $assetBuys) {
            $base = DocNumber::docBase('KK', $input['tanggal']);
            $last = CashOut::where('nomor_transaksi', 'like', $base.'%')->orderByDesc('nomor_transaksi')->value('nomor_transaksi');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $rec = CashOut::create([
                'nomor_transaksi' => $nomor, 'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'kode_rekening' => $input['kode_rekening'], 'kode_vendor' => $input['kode_vendor'] ?? null,
                'id_bank_loan' => $bankLoan['id'] ?? null, 'id_perintah' => $input['id_perintah'] ?? null,
                'metode' => $input['metode'] ?? null, 'referensi' => $input['referensi'] ?? null,
                'keterangan' => $input['keterangan'], 'nominal' => $total, 'status' => 'aktif', 'id_pengguna' => $idPengguna ?? 0,
            ]);
            foreach ($details as $d) {
                $rec->details()->create($d);
            }

            PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => $input['tanggal'], 'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => $input['keterangan'], 'sumber_modul' => self::SUMBER,
                'id_sumber' => (string) $rec->kode_transaksi, 'id_pengguna' => $idPengguna, 'lines' => $jLines,
            ]);

            // Angsuran pinjaman: pokok = Σ baris ke akun Hutang Bank pinjaman.
            if ($bankLoan) {
                $pokok = collect($details)->where('kode_coa', $bankLoan['kode_coa_hutang'])->reduce(fn ($a, $d) => Money::add($a, $d['nominal']), '0');
                (new BankLoanService)->applyPayment($bankLoan['id'], $pokok);
            }

            foreach ($invoicePayments as $p) {
                $inv = Invoice::find($p['id_invoice']);
                if (! $inv) {
                    continue;
                }
                $sisa = Money::sub($inv->sisa_hutang, $p['nominal']);
                $inv->update(['sisa_hutang' => $sisa, 'status' => Money::lte($sisa, '0') ? 'lunas' : 'sebagian']);
            }

            // Baris PP: terbayar & sisanya diperbarui, status PP menyesuaikan.
            (new PerintahPembayaranService)->terapkanRealisasi($details);

            $pengajuanSvc = new PengajuanPembayaranService;
            foreach ($pengajuanPayments as $p) {
                $pengajuanSvc->applyPayment($p['id'], $p['nominal']);
            }
            // Kekurangan penyelesaian uang muka — lunas begitu kasnya keluar.
            foreach ($penyelesaianPayments as $p) {
                $pengajuanSvc->applyKurangBayar($p['id'], $p['nominal']);
            }
            // Uang muka (cash basis): tandai lunas + daftarkan tiap baris ke pool.
            foreach ($uangMukaPayments as $pbId) {
                $pengajuanSvc->applyUangMukaPayment($pbId, ['kode_rekening' => $input['kode_rekening'], 'tanggal' => $input['tanggal']]);
            }

            foreach ($inventoryBuys as $b) {
                $item = Inventory::find($b['kode_persediaan']);
                if (! $item) {
                    continue;
                }
                $oldQty = Money::sub($item->stok_masuk, $item->stok_keluar, 4);
                $oldVal = Money::mul($oldQty, $item->harga_perolehan);
                $newQty = Money::add($oldQty, $b['kuantiti'], 4);
                $newVal = Money::add($oldVal, $b['nominal']);
                $item->update([
                    'harga_perolehan' => Money::gtZero($newQty, 4) ? Money::div($newVal, $newQty) : Money::of($item->harga_perolehan),
                    'stok_masuk' => Money::add($item->stok_masuk, $b['kuantiti'], 4),
                ]);
            }

            foreach ($assetBuys as $a) {
                if (! empty($a['kode_aset'])) {
                    AssetDraft::addToAsset($a['kode_aset'], ['nominal' => $a['nominal'], 'sumber_ref' => $rec->nomor_transaksi, 'sumber_modul' => self::SUMBER]);
                } else {
                    AssetDraft::createDraftAsset(['nama_aset' => $a['nama'], 'harga_perolehan' => $a['nominal'], 'tanggal_perolehan' => $input['tanggal'], 'kode_coa' => $a['kode_coa'], 'sumber_ref' => $rec->nomor_transaksi]);
                }
            }

            return $rec->load('details');
        });
    }

    /** Void: rollback invoice/pengajuan/stok/pinjaman/aset, reversal jurnal, catat alasan. */
    public function void(int $kodeTransaksi, array $input, ?int $idPengguna, ?string $nama): CashOut
    {
        $rec = CashOut::with('details')->find($kodeTransaksi);
        if (! $rec) {
            throw new AppException(404, 'Kas Keluar tidak ditemukan.');
        }
        if ($rec->status === 'void') {
            throw new AppException(409, 'Kas Keluar sudah di-void.');
        }

        return DB::transaction(function () use ($rec, $kodeTransaksi, $input, $idPengguna, $nama) {
            Authorization::authorizeByUser($idPengguna, $rec->nominal);

            // Perintah Pembayaran ikut dikembalikan. TANPA INI, membatalkan Kas
            // Keluar membuat kewajiban tampak lunas di PP padahal uangnya sudah
            // ditarik kembali — rusak tanpa gejala, dan baru ketahuan saat
            // vendornya menagih lagi.
            (new PerintahPembayaranService)->batalkanRealisasi(
                $rec->details->map(fn ($d) => [
                    'id_perintah_detail' => $d->id_perintah_detail,
                    'nominal' => (string) $d->nominal,
                ])->all(),
            );

            $pengajuanSvc = new PengajuanPembayaranService;
            foreach ($rec->details as $d) {
                if ($d->tipe === 'invoice' && $d->id_invoice) {
                    $inv = Invoice::find($d->id_invoice);
                    if ($inv) {
                        $sisa = Money::add($inv->sisa_hutang, $d->nominal);
                        if (Money::gt($sisa, $inv->total)) {
                            $sisa = Money::of($inv->total);
                        }
                        $status = Money::lte($sisa, '0') ? 'lunas' : (Money::lt($sisa, $inv->total) ? 'sebagian' : 'belum_bayar');
                        $inv->update(['sisa_hutang' => $sisa, 'status' => $status]);
                    }
                } elseif ($d->tipe === 'pengajuan' && $d->id_pengajuan) {
                    $pb = PengajuanPembayaran::find($d->id_pengajuan);
                    if ($pb && $pb->jenis === 'uang_muka') {
                        // Cash basis: batalkan advance-nya & kembalikan status "diverifikasi".
                        $pengajuanSvc->reverseUangMukaPayment($d->id_pengajuan);
                    } elseif ($pb && $pb->jenis === 'penyelesaian_uang_muka') {
                        // Kekurangan penyelesaian: kewajibannya hidup lagi.
                        $pengajuanSvc->reverseKurangBayar($d->id_pengajuan, (string) $d->nominal);
                    } else {
                        $pengajuanSvc->reversePayment($d->id_pengajuan, (string) $d->nominal);
                    }
                } elseif ($d->tipe === 'inventory' && $d->kode_persediaan) {
                    $item = Inventory::find($d->kode_persediaan);
                    if ($item) {
                        $masuk = Money::sub($item->stok_masuk, $d->kuantiti ?? 0, 4);
                        if (Money::isNegative($masuk, 4)) {
                            $masuk = '0';
                        }
                        $item->update(['stok_masuk' => $masuk]);
                    }
                }
            }

            if ($rec->id_bank_loan) {
                $loan = BankLoan::find($rec->id_bank_loan);
                if ($loan) {
                    $pokok = $rec->details->where('kode_coa', $loan->kode_coa_hutang)->reduce(fn ($a, $d) => Money::add($a, $d->nominal), '0');
                    (new BankLoanService)->reversePayment($rec->id_bank_loan, $pokok);
                }
            }

            AssetDraft::deleteDraftAssets($rec->nomor_transaksi);
            AssetDraft::reverseAssetMovements($rec->nomor_transaksi);

            $entry = JournalEntry::where('sumber_modul', self::SUMBER)->where('id_sumber', (string) $kodeTransaksi)->where('status', 'aktif')->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, ['tanggal' => $input['tanggal'] ?? Carbon::now()->toDateString(), 'id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Void — ']);
            }

            $rec->update(['status' => 'void', 'void_reason' => $input['alasan'], 'void_by' => $nama, 'void_at' => Carbon::now()]);

            return $rec;
        });
    }
}
