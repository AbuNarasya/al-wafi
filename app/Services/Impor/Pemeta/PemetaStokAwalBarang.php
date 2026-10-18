<?php

namespace App\Services\Impor\Pemeta;

use App\Models\BarcodePersediaan;
use App\Models\CoaDetail;
use App\Models\ImporBatch;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\LapisanPersediaan;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Services\Impor\BantuanPemeta;
use App\Services\Impor\Pemeta;
use App\Services\Impor\PemetaBisaDibatalkan;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * STOK AWAL BARANG PER LOKASI — keadaan rak saat pindah dari POS lama.
 *
 * Tiap baris = satu barang di satu lokasi: stoknya masuk sebagai lapisan FIFO pertama
 * seharga `harga_pokok` (kosong = harga beli master), bertanggal cut-off. Keputusan
 * user 19 Okt 2026: BERJURNAL, pola yang sama dengan impor hutang vendor —
 *
 *     D Persediaan (akun tiap barang) / K Akun perantara saldo awal
 *
 * satu jurnal per unit bisnis lokasi. Buku besar & kartu stok langsung cocok; saldo
 * akun perantaranya diseimbangkan di menu Saldo Awal.
 *
 * Aman diulang: barang yang sudah punya stok awal di lokasi yang sama dilewati.
 */
class PemetaStokAwalBarang implements Pemeta, PemetaBisaDibatalkan
{
    use BantuanPemeta;

    public const SUMBER = 'ImporDataAwal';

    /** Pasangan barang|lokasi yang sudah muncul di baris sebelumnya (satu kali baca). */
    private array $terlihat = [];

    public static function kunci(): string
    {
        return 'stok-awal-barang';
    }

    public static function judul(): string
    {
        return 'Stok Awal Barang per Lokasi (Wafi Mart)';
    }

    public static function penjelasan(): string
    {
        return 'Mengisi stok pembuka tiap barang di tiap lokasi (gudang/toko), berjurnal D Persediaan / K akun perantara saldo awal. '
            .'Barangnya harus sudah ada (impor "Barang Toko" lebih dulu).';
    }

    public function kolom(): array
    {
        return [
            'kode_barang' => ['wajib' => false, 'contoh' => 'BRG0001', 'ket' => 'Isi kode ATAU barcode.'],
            'barcode' => ['wajib' => false, 'contoh' => '8998009010231', 'ket' => 'Dipakai bila kode kosong. Format kolom sebagai Teks.'],
            'lokasi' => ['wajib' => true, 'contoh' => 'Koperasi Banat', 'ket' => 'Nama atau kode lokasi.'],
            'jumlah' => ['wajib' => true, 'contoh' => '48', 'ket' => 'Dalam satuan TERKECIL barang.'],
            'harga_pokok' => ['wajib' => false, 'contoh' => '3000', 'ket' => 'Harga pokok per satuan terkecil. Kosong = harga beli di master barang.'],
        ];
    }

    public function parameter(): array
    {
        return [
            'tanggal_cutoff' => ['label' => 'Tanggal saldo awal', 'tipe' => 'tanggal', 'opsi' => [], 'ket' => 'Tanggal jurnal & kartu stok pembuka (mis. sehari sebelum mulai memakai kasir baru).'],
            'akun_perantara' => [
                'label' => 'Akun perantara saldo awal', 'tipe' => 'pilih',
                'opsi' => CoaDetail::orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
                    ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
                'ket' => 'Akun ekuitas penampung saldo pembukaan. JANGAN akun beban.',
            ],
        ];
    }

    public function periksaParameter(array $param): ?string
    {
        if (! $this->tanggalSah($param['tanggal_cutoff'] ?? '')) {
            return 'Tanggal saldo awal belum diisi atau tidak terbaca (format YYYY-MM-DD).';
        }
        $akun = trim($param['akun_perantara'] ?? '');
        if ($akun === '' || ! CoaDetail::find($akun)) {
            return 'Akun perantara belum dipilih.';
        }
        if (str_starts_with($akun, '5')) {
            return 'Akun perantara tidak boleh akun Beban (kelompok 5) — nilai stok lama akan terhitung sebagai beban tahun ini.';
        }
        if (str_starts_with($akun, '1')) {
            return 'Akun perantara tidak boleh akun aset — pilih akun ekuitas penampung saldo awal.';
        }

        return null;
    }

    public function kolomUnik(): array
    {
        return [];
    }

    public function periksa(array $baris, array $param): array
    {
        [$item, $salah] = $this->barang($baris);
        if ($salah) {
            return $this->masalah($salah);
        }
        if (! $item->kode_coa) {
            return $this->masalah("{$item->nama_persediaan} belum punya Akun Persediaan.");
        }

        $lokasi = $this->lokasi($baris['lokasi'] ?? '');
        if (! $lokasi) {
            return $this->masalah('Lokasi "'.trim($baris['lokasi'] ?? '').'" tidak ditemukan atau tidak aktif.');
        }

        if ($this->angkaPositif($baris['jumlah'] ?? '') === null) {
            return $this->masalah('Jumlah harus angka lebih dari nol.');
        }
        if ($this->angka($baris['harga_pokok'] ?? '') === null) {
            return $this->masalah('Harga pokok bukan angka yang sah.');
        }

        $kunci = "{$item->kode_persediaan}|{$lokasi->kode}";
        if (MutasiPersediaan::where('sumber_modul', self::SUMBER)->where('kode_persediaan', $item->kode_persediaan)
            ->where('kode_lokasi', $lokasi->kode)->where('alasan', 'saldo_awal')->exists()) {
            return $this->lewati();
        }
        if (isset($this->terlihat[$kunci])) {
            return $this->masalah("{$item->nama_persediaan} di {$lokasi->nama} ditulis dua kali di berkas ini — jumlahkan jadi satu baris.");
        }
        $this->terlihat[$kunci] = true;

        return $this->siap();
    }

    public function simpan(array $baris, array $param): array
    {
        $batch = $param['id_batch'] ?? 0;
        $ref = "STOK-{$batch}";
        $tanggal = $this->tanggal($param['tanggal_cutoff']);
        $idPengguna = Auth::user()?->id_pengguna;

        // [unit lokasi][akun persediaan] => nilai — satu jurnal per unit.
        $perUnit = [];
        $mutasi = [];
        $total = '0';
        foreach ($baris as $b) {
            [$item] = $this->barang($b);
            $lokasi = $this->lokasi($b['lokasi']);
            $qty = Money::of($this->angkaPositif($b['jumlah']), 4);
            $harga = trim($b['harga_pokok'] ?? '') === '' ? Money::of($item->harga_perolehan) : $this->angka($b['harga_pokok']);
            $nilai = Money::mul($qty, $harga);

            $m = InventoryMovement::masuk([
                'kode_persediaan' => $item->kode_persediaan,
                'kode_lokasi' => $lokasi->kode,
                'kuantiti' => $qty,
                'nilai' => $nilai,
                'tanggal' => $tanggal,
                'alasan' => 'saldo_awal',
                'sumber_modul' => self::SUMBER,
                'sumber_ref' => $ref,
                'keterangan' => 'Stok awal (impor data awal)',
                'id_pengguna' => $idPengguna,
            ]);
            $unit = $lokasi->kode_unit ?? '';
            $perUnit[$unit][$item->kode_coa] = Money::add($perUnit[$unit][$item->kode_coa] ?? '0', $nilai);
            $mutasi[$unit][] = $m->id;
            $total = Money::add($total, $nilai);
        }

        $jurnal = 0;
        foreach ($perUnit as $unit => $akun) {
            $jumlah = array_reduce($akun, fn ($s, $v) => Money::add($s, $v), '0');
            if (! Money::gtZero($jumlah)) {
                continue;
            }
            $ket = "Saldo awal persediaan — impor batch #{$batch}";
            $lines = [];
            foreach ($akun as $coa => $v) {
                if (Money::gtZero($v)) {
                    $lines[] = ['kode_coa' => $coa, 'debet' => $v, 'kredit' => '0', 'keterangan' => $ket];
                }
            }
            $lines[] = ['kode_coa' => $param['akun_perantara'], 'debet' => '0', 'kredit' => $jumlah, 'keterangan' => $ket];

            $entry = PostingService::postJournal([
                'referensi' => DocNumber::nextJournalRef('SAP', $tanggal),
                'tanggal' => $tanggal,
                'kode_unit' => $unit ?: null,
                'keterangan' => $ket,
                'sumber_modul' => self::SUMBER,
                'id_sumber' => $ref,
                'id_pengguna' => $idPengguna,
                'lines' => $lines,
            ]);
            MutasiPersediaan::whereIn('id', $mutasi[$unit])->update(['journal_entry_id' => $entry->id]);
            $jurnal++;
        }

        return ['baris_stok' => count($baris), 'jurnal' => $jurnal, 'nilai_rupiah' => (int) round((float) $total)];
    }

    public function halanganBatal(ImporBatch $batch): array
    {
        $ref = "STOK-{$batch->id}";
        $mutasi = MutasiPersediaan::where('sumber_modul', self::SUMBER)->where('sumber_ref', $ref)
            ->where('alasan', 'saldo_awal')->pluck('id');
        if ($mutasi->isEmpty()) {
            return ['Tidak ada stok dari batch ini yang tersisa.'];
        }

        $terpakai = LapisanPersediaan::whereIn('mutasi_id', $mutasi)->whereColumn('kuantiti_sisa', '<', 'kuantiti_awal')->count();

        return $terpakai > 0
            ? ["{$terpakai} stok awal sudah terpakai (terjual, ditransfer, atau dipakai). Batalkan transaksinya dulu, atau koreksi lewat opname."]
            : [];
    }

    public function batalkan(ImporBatch $batch, ?int $idPengguna): void
    {
        $ref = "STOK-{$batch->id}";
        JournalEntry::where('sumber_modul', self::SUMBER)->where('id_sumber', $ref)->where('status', 'aktif')->get()
            ->each(fn ($e) => ReversalService::reverseJournalEntry($e->id, ['id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Batal impor — ']));
        InventoryMovement::batalkanDokumen(self::SUMBER, $ref, $idPengguna);
    }

    /** @return array{0:?Inventory,1:?string} [barang, pesan salah] */
    private function barang(array $baris): array
    {
        $kode = trim($baris['kode_barang'] ?? '');
        if ($kode !== '') {
            $item = Inventory::find($kode);

            return $item ? [$item, null] : [null, "Barang {$kode} tidak ditemukan."];
        }
        $b = BarcodePersediaan::bersihkan($baris['barcode'] ?? '');
        if ($b === null) {
            return [null, 'Isi kode_barang atau barcode.'];
        }
        if ($b === false) {
            return [null, 'Barcode rusak (notasi ilmiah Excel). Format kolomnya sebagai Teks.'];
        }
        $bc = BarcodePersediaan::with('persediaan')->find($b);

        return $bc ? [$bc->persediaan, null] : [null, "Barcode {$b} belum terdaftar — impor master barangnya dulu."];
    }

    private function lokasi(string $teks): ?LokasiPersediaan
    {
        $teks = trim($teks);
        if ($teks === '') {
            return null;
        }

        return LokasiPersediaan::where('status', 'aktif')->where('jenis', '!=', 'transit')
            ->where(fn ($q) => $q->where('kode', $teks)->orWhereRaw('LOWER(nama) = ?', [mb_strtolower($teks)]))
            ->first();
    }
}
