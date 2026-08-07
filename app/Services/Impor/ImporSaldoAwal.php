<?php

namespace App\Services\Impor;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\DompetSantri;
use App\Models\ImporBatch;
use App\Models\MutasiDompet;
use App\Models\NisSantri;
use App\Models\PembayaranSantri;
use App\Models\PrabayarSpp;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Impor\Pemeta\PemetaAccruePrepaid;
use App\Services\Impor\Pemeta\PemetaAsetTetap;
use App\Services\Impor\Pemeta\PemetaInvoiceVendor;
use App\Services\Impor\Pemeta\PemetaPengajuanBelumDibayar;
use App\Services\Impor\Pemeta\PemetaPinjamanBank;
use App\Services\Impor\Pemeta\PemetaPinjamanKaryawan;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use App\Services\Impor\Pemeta\PemetaUangMukaOperasional;
use Illuminate\Support\Facades\DB;

/**
 * KERANGKA IMPOR DATA AWAL — satu mesin, banyak pemeta.
 *
 * Dipakai memasukkan keadaan yang sudah berjalan di luar sistem (santri lama
 * beserta tunggakannya, hutang vendor, pinjaman bank, dst.) pada saat pindah
 * ke aplikasi ini. Bukan alat pemasukan harian: jalurnya sengaja terpisah dari
 * modul biasa karena dokumen yang dibuatnya BUKAN transaksi baru.
 *
 * Alurnya selalu: unduh template → isi di Excel → unggah → PRATINJAU → Impor.
 * Pratinjau wajib; tak ada jalan langsung menulis tanpa melihat dulu.
 *
 * Aman dijalankan ulang: baris yang sudah pernah masuk ditandai "lewati", jadi
 * berkas yang sama boleh diunggah lagi setelah baris bermasalahnya dibetulkan.
 */
class ImporSaldoAwal
{
    /** @var list<class-string<Pemeta>> */
    private const PEMETA = [
        PemetaSantriLama::class,
        PemetaInvoiceVendor::class,
        PemetaPinjamanBank::class,
        PemetaPinjamanKaryawan::class,
        PemetaUangMukaOperasional::class,
        PemetaAccruePrepaid::class,
        PemetaAsetTetap::class,
        PemetaPengajuanBelumDibayar::class,
    ];

    /** @return array<string,class-string<Pemeta>> */
    public static function daftar(): array
    {
        $hasil = [];
        foreach (self::PEMETA as $kelas) {
            $hasil[$kelas::kunci()] = $kelas;
        }

        return $hasil;
    }

    public static function pemeta(string $kunci): Pemeta
    {
        $kelas = self::daftar()[$kunci] ?? null;
        if (! $kelas) {
            throw new AppException(404, "Jenis impor \"{$kunci}\" tidak dikenal.");
        }

        return app($kelas);
    }

    /**
     * Seragamkan isi berkas ke UTF-8 SEBELUM apa pun dibaca darinya.
     *
     * Excel di Windows menyimpan "CSV (Comma delimited)" sebagai ANSI, bukan
     * UTF-8. Satu nama seperti "AL AUZA’I" sudah cukup: tanda kutip melengkungnya
     * tersimpan sebagai bita 0x92 yang BUKAN UTF-8 sah, lolos melewati seluruh
     * pemetaan, lalu ditolak PostgreSQL — dan karena impornya satu transaksi,
     * DUA RATUS baris lain ikut batal. Galat yang muncul pun tak menyebut baris
     * mana pun, jadi petugas tak punya petunjuk berkasnya harus diapakan.
     *
     * CP1252 yang dipakai, BUKAN ISO-8859-1: keduanya sama persis di 0xA0–0xFF,
     * tapi hanya CP1252 yang mengisi 0x80–0x9F — dan justru di sanalah tanda
     * kutip melengkung serta tanda hubung panjang buatan Excel berada.
     */
    private function keUtf8(string $isi): string
    {
        $hasil = match (true) {
            // "Unicode Text" dari Excel = UTF-16 ber-BOM.
            str_starts_with($isi, "\xFF\xFE") => mb_convert_encoding(substr($isi, 2), 'UTF-8', 'UTF-16LE'),
            str_starts_with($isi, "\xFE\xFF") => mb_convert_encoding(substr($isi, 2), 'UTF-8', 'UTF-16BE'),
            mb_check_encoding($isi, 'UTF-8') => $isi,
            default => mb_convert_encoding($isi, 'UTF-8', 'Windows-1252'),
        };

        // PostgreSQL menolak NUL di kolom teks walau UTF-8-nya sah.
        return str_replace("\0", '', (string) $hasil);
    }

    /**
     * Baca CSV jadi baris berkunci nama kolom.
     *
     * Menerima pemisah koma maupun titik koma — Excel berbahasa Indonesia
     * menyimpan dengan titik koma, dan berkas seperti itu kalau dipaksa dibaca
     * sebagai koma akan tampak "satu kolom" tanpa penjelasan apa pun.
     *
     * @return list<array<string,string>>
     */
    public function baca(string $path): array
    {
        $isi = file_get_contents($path);
        if ($isi === false || trim($isi) === '') {
            throw new AppException(422, 'Berkas kosong atau tidak terbaca.');
        }
        $isi = $this->keUtf8($isi);
        // Buang BOM UTF-8 — kalau tidak, nama kolom pertama tak pernah cocok.
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);

        $barisPertama = strtok($isi, "\n");
        $pemisah = substr_count($barisPertama, ';') > substr_count($barisPertama, ',') ? ';' : ',';

        $f = fopen('php://memory', 'r+');
        fwrite($f, $isi);
        rewind($f);

        $judul = fgetcsv($f, 0, $pemisah, '"', '\\');
        if (! $judul) {
            throw new AppException(422, 'Baris judul kolom tidak ditemukan.');
        }
        $judul = array_map(fn ($h) => strtolower(trim((string) $h)), $judul);

        $rows = [];
        while (($data = fgetcsv($f, 0, $pemisah, '"', '\\')) !== false) {
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue; // baris kosong di ujung berkas
            }
            $baris = [];
            foreach ($judul as $i => $nama) {
                $baris[$nama] = trim((string) ($data[$i] ?? ''));
            }
            $rows[] = $baris;
        }
        fclose($f);

        if ($rows === []) {
            throw new AppException(422, 'Berkas hanya berisi judul kolom, tidak ada datanya.');
        }

        return $rows;
    }

    /**
     * Periksa seluruh berkas tanpa menulis apa pun.
     *
     * Sengaja TIDAK mengembalikan 600 baris mentah: yang berguna dilihat hanya
     * ringkasannya dan baris yang bermasalah beserta alasannya.
     *
     * @param  array<string,string>  $param
     * @return array{jumlah:int,siap:int,lewati:int,masalah:int,kolom_hilang:list<string>,baris_masalah:list<array{nomor:int,alasan:string,ringkas:string}>}
     */
    public function pratinjau(string $kunci, string $path, array $param = []): array
    {
        $pemeta = self::pemeta($kunci);
        if ($salah = $pemeta->periksaParameter($param)) {
            throw new AppException(422, $salah);
        }
        $rows = $this->baca($path);

        $kolomHilang = [];
        foreach ($pemeta->kolom() as $nama => $def) {
            if ($def['wajib'] && ! array_key_exists($nama, $rows[0])) {
                $kolomHilang[] = $nama;
            }
        }
        if ($kolomHilang !== []) {
            return [
                'jumlah' => count($rows), 'siap' => 0, 'lewati' => 0, 'masalah' => count($rows),
                'kolom_hilang' => $kolomHilang, 'baris_masalah' => [],
            ];
        }

        $kembar = $this->kembarDalamBerkas($pemeta, $rows);

        $siap = $lewati = 0;
        $masalah = [];
        foreach ($rows as $i => $baris) {
            // Kekembaran didahulukan: baris ini boleh saja lolos periksa() —
            // yang membuatnya tak bisa masuk adalah baris lain di berkas ini.
            $p = isset($kembar[$i])
                ? ['status' => 'masalah', 'alasan' => $kembar[$i]]
                : $pemeta->periksa($baris, $param);

            match ($p['status']) {
                'siap' => $siap++,
                'lewati' => $lewati++,
                default => $masalah[] = [
                    'nomor' => $i + 2, // +2: baris 1 judul, hitungan Excel mulai 1
                    'alasan' => $p['alasan'] ?? 'Tidak sah.',
                    'ringkas' => $this->ringkas($baris),
                ],
            };
        }

        return [
            'jumlah' => count($rows), 'siap' => $siap, 'lewati' => $lewati,
            'masalah' => count($masalah), 'kolom_hilang' => [],
            'baris_masalah' => array_slice($masalah, 0, 200),
        ];
    }

    /**
     * Jalankan impor. Hanya baris "siap" yang ditulis; "lewati" dan "masalah"
     * dilewatkan — bukan menggagalkan seluruh berkas, supaya sisanya bisa
     * diperbaiki lalu diunggah ulang.
     *
     * @param  array<string,string>  $param
     * @return array{tersimpan:array<string,int>,siap:int,lewati:int,masalah:int}
     */
    public function jalankan(string $kunci, string $path, array $param = [], ?int $idPengguna = null): array
    {
        $pemeta = self::pemeta($kunci);
        if ($salah = $pemeta->periksaParameter($param)) {
            throw new AppException(422, $salah);
        }
        $rows = $this->baca($path);

        $kembar = $this->kembarDalamBerkas($pemeta, $rows);

        $siap = [];
        $lewati = $masalah = 0;
        foreach ($rows as $i => $baris) {
            $p = isset($kembar[$i])
                ? ['status' => 'masalah']
                : $pemeta->periksa($baris, $param);

            match ($p['status']) {
                'siap' => $siap[] = $baris,
                'lewati' => $lewati++,
                default => $masalah++,
            };
        }

        if ($siap === []) {
            throw new AppException(422, 'Tidak ada baris yang siap diimpor.');
        }

        /*
         * BATCH: satu nomor untuk seluruh baris yang lahir dari sekali impor.
         *
         * Dibuat DI DALAM transaksi bersama barisnya — kalau simpan() gagal di
         * tengah, catatan batchnya ikut hilang, dan tak ada nomor yatim yang
         * menunjuk baris yang tak pernah ada.
         *
         * Nomornya dititipkan lewat $param supaya kontrak Pemeta::simpan() tak
         * perlu berubah. Pemeta yang belum mendukung pembatalan tinggal
         * mengabaikannya — barisnya tetap tersimpan, hanya tak bisa dibatalkan
         * sekaligus.
         */
        [$batch, $tersimpan] = DB::transaction(function () use ($pemeta, $siap, $param, $kunci, $path, $idPengguna) {
            $batch = ImporBatch::create([
                'kunci' => $kunci,
                'nama_berkas' => basename($path),
                'dijalankan_oleh' => $idPengguna,
                'dijalankan_pada' => now(),
            ]);

            $hasil = $pemeta->simpan($siap, $param + ['id_batch' => $batch->id]);
            $batch->update(['ringkasan' => $hasil]);

            return [$batch, $hasil];
        });

        return [
            'tersimpan' => $tersimpan, 'siap' => count($siap),
            'lewati' => $lewati, 'masalah' => $masalah,
            'id_batch' => $batch->id,
        ];
    }

    /**
     * Alasan sebuah batch TIDAK bisa dibatalkan lagi.
     *
     * Membatalkan impor hanya sah selama belum ada apa pun yang menempel pada
     * barisnya. Begitu ada pembayaran, mutasi dompet, atau jadwal angsuran,
     * menghapus santrinya berarti membuang catatan uang yang benar-benar
     * terjadi — dan itu bukan pembatalan, itu kehilangan.
     *
     * Mengembalikan DAFTAR, bukan melempar pada temuan pertama: petugas berhak
     * melihat SEMUA yang menghalangi sekaligus, bukan menyingkirkannya satu per
     * satu lalu mencoba lagi.
     *
     * @return list<string>
     */
    public function halanganBatalBatch(ImporBatch $batch): array
    {
        if (! $batch->aktif()) {
            return ['Batch ini sudah dibatalkan pada '.$batch->dibatalkan_pada->format('d M Y H:i').'.'];
        }

        $idSantri = Santri::where('id_batch', $batch->id)->pluck('id');
        if ($idSantri->isEmpty()) {
            return ['Tidak ada baris yang bisa dibatalkan dari batch ini.'];
        }

        $idTagihan = TagihanSantri::whereIn('id_santri', $idSantri)->pluck('id');
        $halangan = [];

        if (($n = PembayaranSantri::whereIn('id_tagihan', $idTagihan)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} pembayaran atas tagihan santri batch ini.";
        }

        // Status yang bukan lagi `aktif` berarti santrinya sudah diproses lebih
        // jauh — naik tingkat, mengundurkan diri, atau lulus.
        $diproses = Santri::whereIn('id', $idSantri)->where('status', '!=', 'aktif')->count();
        if ($diproses > 0) {
            $halangan[] = "{$diproses} santri sudah berpindah status dari \"aktif\".";
        }

        // Rencana angsuran menggantung pada TAGIHAN, bukan langsung pada santri.
        if (($n = RencanaAngsuranUangPangkal::whereIn('id_tagihan', $idTagihan)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} rencana angsuran uang pangkal.";
        }

        if (($n = PrabayarSpp::whereIn('id_santri', $idSantri)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} pembayaran SPP di muka.";
        }

        $idDompet = DompetSantri::whereIn('id_santri', $idSantri)->pluck('id');
        if ($idDompet->isNotEmpty()
            && ($n = MutasiDompet::where('pemilik', 'santri')->whereIn('id_dompet', $idDompet)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} mutasi dompet santri.";
        }

        // Tagihan yang TIDAK bertanda batch ini = diterbitkan petugas kemudian.
        // Membuangnya bersama batch akan menghapus pekerjaan yang tak ada
        // hubungannya dengan berkas yang keliru.
        //
        // Dibedakan lewat PENANDA, bukan perbandingan waktu: catatan batch dibuat
        // sebelum barisnya disimpan, jadi tagihan dari impor itu sendiri selalu
        // bertanggal sesudahnya — dan penjagaan berbasis waktu menolak impor
        // membatalkan dirinya sendiri.
        $tambahan = TagihanSantri::whereIn('id_santri', $idSantri)
            ->where(fn ($q) => $q->whereNull('id_batch')->orWhere('id_batch', '!=', $batch->id))
            ->count();
        if ($tambahan > 0) {
            $halangan[] = "Ada {$tambahan} tagihan yang diterbitkan di luar impor ini.";
        }

        return $halangan;
    }

    /**
     * Batalkan seluruh baris sebuah batch impor.
     *
     * Yang dibuang: tagihan, riwayat NIS, riwayat tingkat, dompet & tabungan
     * yang masih kosong, santrinya sendiri, dan wali yang LAHIR dari impor ini
     * lagi tak menaungi siapa pun. Wali yang sudah ada sebelumnya tak disentuh.
     */
    public function batalkanBatch(int $idBatch, string $alasan, ?int $idPengguna = null): ImporBatch
    {
        $batch = ImporBatch::find($idBatch);
        if (! $batch) {
            throw new AppException(404, 'Batch impor tidak ditemukan.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan pembatalan wajib diisi.');
        }

        $halangan = $this->halanganBatalBatch($batch);
        if ($halangan !== []) {
            throw new AppException(422, 'Batch ini tidak bisa dibatalkan lagi. '.implode(' ', $halangan));
        }

        return DB::transaction(function () use ($batch, $alasan, $idPengguna) {
            $idSantri = Santri::where('id_batch', $batch->id)->pluck('id');

            TagihanSantri::whereIn('id_santri', $idSantri)->delete();
            NisSantri::whereIn('id_santri', $idSantri)->delete();
            RiwayatTingkat::whereIn('id_santri', $idSantri)->delete();
            DokumenSantri::whereIn('id_santri', $idSantri)->delete();
            DompetSantri::whereIn('id_santri', $idSantri)->delete();
            TabunganSantri::whereIn('id_santri', $idSantri)->delete();
            Santri::whereIn('id', $idSantri)->delete();

            // Wali dari batch ini yang kini tak menaungi santri mana pun. Yang
            // masih punya anak (mis. kakaknya dari angkatan lain) dibiarkan.
            Wali::where('id_batch', $batch->id)
                ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('santri')->whereColumn('santri.id_wali', 'wali.id'))
                ->delete();

            $batch->update([
                'dibatalkan_pada' => now(),
                'dibatalkan_oleh' => $idPengguna,
                'alasan_batal' => $alasan,
            ]);

            return $batch->refresh();
        });
    }

    /** Isi CSV template: baris judul + satu baris contoh. */
    public function template(string $kunci): string
    {
        $kolom = self::pemeta($kunci)->kolom();

        $f = fopen('php://memory', 'r+');
        fputcsv($f, array_keys($kolom), ',', '"', '\\');
        fputcsv($f, array_map(fn ($d) => $d['contoh'], $kolom), ',', '"', '\\');
        rewind($f);
        $isi = stream_get_contents($f);
        fclose($f);

        return "\xEF\xBB\xBF".$isi; // BOM supaya Excel membaca UTF-8 dengan benar
    }

    /**
     * Kekembaran DI DALAM satu berkas — yang tak mungkin terlihat dari
     * Pemeta::periksa(), karena ia hanya memegang satu baris pada satu waktu:
     * baris kedua yang ber-NIS sama selalu tampak sah bila dilihat sendirian.
     *
     * Yang ditandai bermasalah hanya kemunculan KEDUA dan seterusnya; yang
     * pertama tetap masuk. Membatalkan keduanya berarti menghukum baris yang
     * belum tentu keliru, padahal yang salah ketik biasanya cuma satu.
     *
     * Sel KOSONG tak pernah dihitung kembar: kolom seperti `nis` boleh kosong,
     * dan di database pun dua NULL dianggap berbeda oleh indeks uniknya.
     *
     * @param  list<array<string,string>>  $rows
     * @return array<int,string>  indeks baris => alasannya
     */
    private function kembarDalamBerkas(Pemeta $pemeta, array $rows): array
    {
        $masalah = [];

        foreach ($pemeta->kolomUnik() as $kolom) {
            $pertama = [];
            foreach ($rows as $i => $baris) {
                $nilai = trim((string) ($baris[$kolom] ?? ''));
                if ($nilai === '') {
                    continue;
                }

                if (isset($pertama[$nilai])) {
                    // Nomor baris pertamanya ikut disebut — tanpa itu petugas
                    // tahu ada yang kembar tapi tak tahu harus membandingkan
                    // dengan baris yang mana di antara ratusan baris.
                    $awal = $pertama[$nilai];
                    $masalah[$i] ??= sprintf(
                        'Kolom %s bernilai "%s" sudah dipakai baris %d (%s).',
                        $kolom, $nilai, $awal + 2, $this->ringkas($rows[$awal]),
                    );

                    continue;
                }

                $pertama[$nilai] = $i;
            }
        }

        return $masalah;
    }

    /** Cuplikan baris untuk ditampilkan di daftar masalah. */
    private function ringkas(array $baris): string
    {
        $ambil = array_slice(array_filter($baris, fn ($v) => $v !== ''), 0, 3);

        return implode(' · ', $ambil);
    }
}
