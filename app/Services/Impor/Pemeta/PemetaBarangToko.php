<?php

namespace App\Services\Impor\Pemeta;

use App\Models\BarcodePersediaan;
use App\Models\CoaDetail;
use App\Models\ImporBatch;
use App\Models\Inventory;
use App\Models\InvoiceDetail;
use App\Models\KategoriBarang;
use App\Models\MerkBarang;
use App\Models\MutasiPersediaan;
use App\Models\PenerimaanBarangDetail;
use App\Models\PenjualanDetail;
use App\Models\PurchaseOrderDetail;
use App\Models\SatuanPersediaan;
use App\Models\TransferPersediaanDetail;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Impor\PemetaBisaDibatalkan;
use App\Support\Money;

/**
 * MASTER BARANG TOKO (Wafi Mart) — ribuan produk dari POS lama sekali jalan.
 *
 * Yang dibuat: barang (ditandai dijual di toko), barcode-barcodenya, satuan besar
 * opsional (DUS = 24 pcs), serta kategori & merk yang BELUM ada — dibuat otomatis
 * dari namanya. Akun persediaan/beban/selisih dipilih SEKALI untuk seluruh berkas.
 * STOK TIDAK ikut: stok awal per lokasi berkas tersendiri (PemetaStokAwalBarang),
 * supaya barang yang sama bisa diberi stok di beberapa toko.
 *
 * Aman diulang: barang yang kodenya/barcodenya sudah ada dilewati. Barcode dirapikan
 * dulu (akhiran ".00" buatan POS lama dibuang); notasi ilmiah Excel ditolak.
 */
class PemetaBarangToko implements Pemeta, PemetaBisaDibatalkan
{
    use BantuanPemeta;

    /** Barcode yang sudah dipakai baris SEBELUMNYA di berkas yang sama (satu kali baca). */
    private array $barcodeTerlihat = [];

    /** Urutan baris data yang sedang diperiksa (untuk menyebut baris pemakai barcode sebelumnya). */
    private int $urut = 0;

    public static function kunci(): string
    {
        return 'barang-toko';
    }

    public static function judul(): string
    {
        return 'Barang Toko (Wafi Mart)';
    }

    public static function penjelasan(): string
    {
        return 'Membuat master barang yang dijual di toko beserta barcode, harga jual, dan satuan besarnya. '
            .'Kategori & merk yang belum ada dibuat otomatis. Stok TIDAK ikut — isi lewat berkas "Stok Awal Barang per Lokasi" sesudahnya.';
    }

    public function kolom(): array
    {
        return [
            'kode_barang' => ['wajib' => false, 'contoh' => '', 'ket' => 'Kosongkan supaya dibuat otomatis (BRG0001, …). Barang yang kodenya sudah ada dilewati.'],
            'nama' => ['wajib' => true, 'contoh' => 'Teh Kotak 250ml', 'ket' => 'Nama barang seperti di rak.'],
            'satuan' => ['wajib' => true, 'contoh' => 'pcs', 'ket' => 'Satuan TERKECIL yang dijual (pcs, botol, bungkus).'],
            'kategori' => ['wajib' => false, 'contoh' => 'Minuman', 'ket' => 'Nama kategori; dibuat otomatis bila belum ada.'],
            'merk' => ['wajib' => false, 'contoh' => 'Sosro', 'ket' => 'Nama merk; dibuat otomatis bila belum ada.'],
            'barcode' => ['wajib' => false, 'contoh' => '8998009010231', 'ket' => 'Boleh lebih dari satu, pisahkan dengan titik koma (;). FORMAT KOLOM SEBAGAI TEKS di Excel.'],
            'harga_beli' => ['wajib' => false, 'contoh' => '3000', 'ket' => 'Harga beli per satuan terkecil — acuan opname & stok awal.'],
            'harga_jual' => ['wajib' => true, 'contoh' => '4000', 'ket' => 'Harga jual per satuan terkecil.'],
            'stok_minimum' => ['wajib' => false, 'contoh' => '24', 'ket' => 'Di bawah angka ini barang ditandai perlu dibeli lagi.'],
            'satuan_besar' => ['wajib' => false, 'contoh' => 'DUS', 'ket' => 'Opsional — mis. DUS / PAK.'],
            'isi_satuan_besar' => ['wajib' => false, 'contoh' => '24', 'ket' => 'Berapa satuan terkecil dalam satu satuan besar (wajib bila satuan besar diisi).'],
            'harga_jual_satuan_besar' => ['wajib' => false, 'contoh' => '90000', 'ket' => 'Kosong = isi × harga jual.'],
            'barcode_satuan_besar' => ['wajib' => false, 'contoh' => '8998009010248', 'ket' => 'Barcode kemasan besar (memindainya menjual 1 satuan besar).'],
        ];
    }

    public function parameter(): array
    {
        $akun = CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
            ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all();

        return [
            'kode_coa' => ['label' => 'Akun Persediaan', 'tipe' => 'pilih', 'opsi' => $akun, 'ket' => 'Aset — nilai barang di gudang/toko.'],
            'kode_coa_beban' => ['label' => 'Akun Beban Pemakaian', 'tipe' => 'pilih', 'opsi' => $akun, 'ket' => 'Dipakai bila barang dipakai sendiri (bukan dijual).'],
            'kode_coa_selisih' => ['label' => 'Akun Selisih Persediaan', 'tipe' => 'pilih', 'opsi' => $akun, 'ket' => 'Selisih opname, kehilangan, selisih harga invoice.'],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        foreach (['kode_coa' => 'Akun Persediaan', 'kode_coa_beban' => 'Akun Beban Pemakaian', 'kode_coa_selisih' => 'Akun Selisih Persediaan'] as $k => $label) {
            if (trim($param[$k] ?? '') === '' || ! CoaDetail::find($param[$k])) {
                return "{$label} belum dipilih.";
            }
        }
        if (! str_starts_with((string) $param['kode_coa'], '1')) {
            return 'Akun Persediaan harus akun aset (kelompok 1).';
        }

        return null;
    }

    public function kolomUnik(): array
    {
        return ['kode_barang'];
    }

    public function periksa(array $baris, array $param): array
    {
        $this->urut++;
        $nama = trim($baris['nama'] ?? '');
        if ($nama === '') {
            return $this->masalah('Nama barang kosong.');
        }
        if (trim($baris['satuan'] ?? '') === '') {
            return $this->masalah('Satuan kosong.');
        }

        $kode = trim($baris['kode_barang'] ?? '');
        if ($kode !== '' && Inventory::whereKey($kode)->exists()) {
            return $this->lewati();
        }

        // Barcode: rapikan, cek rusak, cek kembar di berkas & di database.
        $semua = [];
        foreach (['barcode', 'barcode_satuan_besar'] as $kolom) {
            foreach (explode(';', (string) ($baris[$kolom] ?? '')) as $mentah) {
                $b = BarcodePersediaan::bersihkan($mentah);
                if ($b === false) {
                    return $this->masalah("Barcode \"{$mentah}\" rusak (notasi ilmiah Excel atau terlalu panjang). Format kolomnya sebagai Teks lalu salin ulang dari sumbernya.");
                }
                if ($b !== null) {
                    $semua[] = $b;
                }
            }
        }
        if (count($semua) !== count(array_unique($semua))) {
            return $this->masalah('Barcode yang sama ditulis dua kali di baris ini.');
        }
        foreach ($semua as $b) {
            $ada = BarcodePersediaan::with('persediaan:kode_persediaan,nama_persediaan')->find($b);
            if ($ada) {
                // Barang yang sama sudah masuk (impor ulang) → lewati; barang lain → bentrok.
                return mb_strtolower($ada->persediaan->nama_persediaan) === mb_strtolower($nama)
                    ? $this->lewati()
                    : $this->masalah("Barcode {$b} sudah dipakai \"{$ada->persediaan->nama_persediaan}\".");
            }
            if (isset($this->barcodeTerlihat[$b])) {
                return $this->masalah("Barcode {$b} sudah dipakai baris data ke-{$this->barcodeTerlihat[$b]} di berkas ini.");
            }
        }
        if (! $semua && $kode === '' && Inventory::whereRaw('LOWER(nama_persediaan) = ?', [mb_strtolower($nama)])->exists()) {
            return $this->lewati();
        }

        if ($this->angka($baris['harga_jual'] ?? '') === null || trim($baris['harga_jual'] ?? '') === '') {
            return $this->masalah('Harga jual kosong atau bukan angka.');
        }
        foreach (['harga_beli', 'stok_minimum', 'harga_jual_satuan_besar'] as $k) {
            if ($this->angka($baris[$k] ?? '') === null) {
                return $this->masalah("Kolom {$k} bukan angka yang sah.");
            }
        }

        $besar = trim($baris['satuan_besar'] ?? '');
        if ($besar !== '') {
            $isi = $this->angka($baris['isi_satuan_besar'] ?? '');
            if ($isi === null || ! Money::gt($isi, '1')) {
                return $this->masalah('Isi satuan besar harus lebih dari 1.');
            }
            if (mb_strtolower($besar) === mb_strtolower(trim($baris['satuan']))) {
                return $this->masalah('Satuan besar sama dengan satuan terkecil.');
            }
        } elseif (trim($baris['barcode_satuan_besar'] ?? '') !== '') {
            return $this->masalah('Barcode satuan besar diisi, tetapi satuan besarnya kosong.');
        }

        foreach ($semua as $b) {
            $this->barcodeTerlihat[$b] = $this->urut;
        }

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $batch = $param['id_batch'] ?? null;
        $hitung = ['barang' => 0, 'barcode' => 0, 'kategori' => 0, 'merk' => 0];
        $nomor = $this->nomorTerakhir();
        // Kode yang ditulis eksplisit di berkas tak boleh "disalip" kode otomatis baris lain.
        $eksplisit = array_flip(array_filter(array_map(fn ($b) => trim($b['kode_barang'] ?? ''), $baris)));

        foreach ($baris as $b) {
            $kode = trim($b['kode_barang'] ?? '');
            while ($kode === '' || (! trim($b['kode_barang'] ?? '') && isset($eksplisit[$kode]))) {
                $kode = 'BRG'.str_pad((string) ++$nomor, 4, '0', STR_PAD_LEFT);
            }
            $hargaJual = $this->angka($b['harga_jual']);

            $item = Inventory::create([
                'kode_persediaan' => $kode,
                'nama_persediaan' => trim($b['nama']),
                'satuan' => trim($b['satuan']),
                'kode_kategori' => $this->master(KategoriBarang::class, 'KTG', $b['kategori'] ?? '', $batch, $hitung['kategori']),
                'kode_merk' => $this->master(MerkBarang::class, 'MRK', $b['merk'] ?? '', $batch, $hitung['merk']),
                'harga_perolehan' => $this->angka($b['harga_beli'] ?? ''),
                'harga_jual' => $hargaJual,
                'stok_minimum' => trim($b['stok_minimum'] ?? '') === '' ? null : $this->angka($b['stok_minimum']),
                'dijual_di_toko' => true,
                'kode_coa' => $param['kode_coa'],
                'kode_coa_beban' => $param['kode_coa_beban'],
                'kode_coa_selisih' => $param['kode_coa_selisih'],
                'status' => 'aktif',
                'id_batch' => $batch,
            ]);
            $hitung['barang']++;

            $besar = trim($b['satuan_besar'] ?? '');
            if ($besar !== '') {
                SatuanPersediaan::create([
                    'kode_persediaan' => $kode, 'satuan' => $besar, 'isi' => $this->angka($b['isi_satuan_besar']),
                    'harga_jual' => trim($b['harga_jual_satuan_besar'] ?? '') === '' ? null : $this->angka($b['harga_jual_satuan_besar']),
                ]);
            }
            foreach (['barcode' => null, 'barcode_satuan_besar' => $besar ?: null] as $kolom => $satuan) {
                foreach (explode(';', (string) ($b[$kolom] ?? '')) as $mentah) {
                    if ($bc = BarcodePersediaan::bersihkan($mentah)) {
                        BarcodePersediaan::create(['barcode' => $bc, 'kode_persediaan' => $item->kode_persediaan, 'satuan' => $satuan]);
                        $hitung['barcode']++;
                    }
                }
            }
        }

        return $hitung;
    }

    public function halanganBatal(ImporBatch $batch): array
    {
        $kode = Inventory::where('id_batch', $batch->id)->pluck('kode_persediaan');
        if ($kode->isEmpty()) {
            return ['Tidak ada barang dari batch ini yang tersisa.'];
        }

        // Riwayat dari impor stok awal yang SUDAH dibatalkan tak menghalangi: urutan wajar
        // membetulkan berkas yang keliru adalah batalkan stok → batalkan barang.
        $stokBatal = ImporBatch::where('kunci', PemetaStokAwalBarang::kunci())->whereNotNull('dibatalkan_pada')
            ->pluck('id')->map(fn ($id) => "STOK-{$id}")->all();
        $halangan = [];
        $pergerakan = MutasiPersediaan::whereIn('kode_persediaan', $kode)
            ->where(fn ($q) => $q->where('sumber_modul', '!=', PemetaStokAwalBarang::SUMBER)->orWhereNotIn('sumber_ref', $stokBatal ?: ['-']))
            ->distinct()->count('kode_persediaan');
        if ($pergerakan > 0) {
            $halangan[] = "{$pergerakan} barang sudah punya pergerakan stok (stok awal, pembelian, penjualan) — batalkan itu dulu.";
        }
        foreach ([
            [PurchaseOrderDetail::class, 'sudah dipakai di Purchase Order'],
            [InvoiceDetail::class, 'sudah dipakai di Invoice Vendor'],
            [PenerimaanBarangDetail::class, 'sudah dipakai di Penerimaan Barang'],
            [TransferPersediaanDetail::class, 'sudah dipakai di Transfer'],
            [PenjualanDetail::class, 'sudah terjual di kasir'],
        ] as [$model, $pesan]) {
            if (($n = $model::whereIn('kode_persediaan', $kode)->distinct()->count('kode_persediaan')) > 0) {
                $halangan[] = "{$n} barang {$pesan}.";
            }
        }

        return $halangan;
    }

    public function batalkan(ImporBatch $batch, ?int $idPengguna): void
    {
        // Barcode, satuan konversi, lapisan & mutasi ikut terhapus lewat cascade.
        Inventory::where('id_batch', $batch->id)->delete();

        // Kategori/merk yang lahir dari batch ini dan kini tak dipakai barang mana pun.
        KategoriBarang::where('id_batch', $batch->id)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('inventory')->whereColumn('inventory.kode_kategori', 'kategori_barang.kode'))
            ->delete();
        MerkBarang::where('id_batch', $batch->id)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('inventory')->whereColumn('inventory.kode_merk', 'merk_barang.kode'))
            ->delete();
    }

    /** Kode master dari NAMA; dibuat (bertanda batch) bila belum ada. Kosong → null. */
    private function master(string $model, string $awalan, string $nama, ?int $batch, int &$dibuat): ?string
    {
        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }
        $ada = $model::whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->value('kode');
        if ($ada) {
            return $ada;
        }

        $n = (int) $model::where('kode', '~', '^'.$awalan.'[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(kode FROM '.(strlen($awalan) + 1).') AS INTEGER)) AS n')->value('n');
        $kode = $awalan.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
        $model::create(['kode' => $kode, 'nama' => $nama, 'status' => 'aktif', 'id_batch' => $batch]);
        $dibuat++;

        return $kode;
    }

    private function nomorTerakhir(): int
    {
        return (int) Inventory::where('kode_persediaan', '~', '^BRG[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(kode_persediaan FROM 4) AS INTEGER)) AS n')->value('n');
    }
}
