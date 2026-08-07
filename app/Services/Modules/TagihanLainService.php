<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * TAGIHAN LAIN — biaya di luar registrasi/uang pangkal/SPP (seragam, study tour,
 * denda). Diterbitkan untuk BEBERAPA santri sekaligus. Akrual bila jenisnya
 * punya akun piutang (jurnal Piutang D / Pendapatan K), selain itu cash basis.
 */
class TagihanLainService
{
    /**
     * Penerbitan MANUAL: santri dipilih satu per satu, satu nominal untuk semua.
     */
    public function terbitkan(array $data, int $idPengguna): array
    {
        $jenis = $this->jenisSah($data['kode_jenis']);

        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, 'Nominal tagihan harus lebih dari nol.');
        }

        return $this->terbitkanUntuk($jenis, array_fill_keys($data['id_santri'], $nominal), $data, $idPengguna);
    }

    /**
     * Penerbitan dari DAFTAR PESERTA (keluarga B — ekskul, kegiatan, umroh).
     *
     * Tak ada santri yang dipilih di layar dan tak ada nominal yang diketik:
     * keduanya sudah ditetapkan sebagai kepesertaan dan matriks tarif per
     * jenjang, sehingga penerbitan tinggal menjalankannya.
     */
    public function terbitkanPeserta(array $data, int $idPengguna): array
    {
        $kepesertaan = new KepesertaanLainService;
        $jenis = $kepesertaan->jenis($data['kode_jenis']);
        $this->pastikanBisaDitagih($jenis);

        ['nominal' => $peta, 'gugur' => $gugur] = $kepesertaan->nominalPeserta($data['kode_jenis']);
        if ($peta === []) {
            throw new AppException(422, 'Tidak ada peserta yang bisa ditagih.'
                .($gugur === [] ? ' Daftar pesertanya masih kosong.' : ' '.implode('; ', $gugur).'.'));
        }

        return $this->terbitkanUntuk($jenis, $peta, $data, $idPengguna, $gugur);
    }

    /**
     * Penerbitan dari PEMAKAIAN (keluarga A — laundry).
     *
     * Nominal per santri sudah dihitung PemakaianLainService dari kuantitas ×
     * tarif satuan dikurangi kuota; yang tersisa di sini hanyalah menuliskannya
     * jadi tagihan dan jurnal, sama seperti dua jalur lainnya.
     *
     * @param  array<int,string>  $nominalPerSantri
     */
    public function terbitkanUntukPemakaian(JenisBiaya $jenis, array $nominalPerSantri, array $data, int $idPengguna): array
    {
        $this->pastikanBisaDitagih($jenis);

        return $this->terbitkanUntuk($jenis, $nominalPerSantri, $data, $idPengguna);
    }

    private function jenisSah(string $kode): JenisBiaya
    {
        $jenis = JenisBiaya::find($kode);
        if (! $jenis) {
            throw new AppException(400, 'Jenis biaya tidak ditemukan.');
        }
        $this->pastikanBisaDitagih($jenis);

        return $jenis;
    }

    private function pastikanBisaDitagih(JenisBiaya $jenis): void
    {
        if (\App\Models\TipeBiaya::perilakuDari($jenis->tipe) !== 'lain') {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" bukan bertipe Lain-lain. Registrasi, uang pangkal, dan SPP punya jalurnya sendiri.");
        }
        if ($jenis->status !== 'aktif') {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" nonaktif.");
        }
    }

    /**
     * Inti penerbitan, dipakai kedua jalur di atas.
     *
     * Nominalnya PER SANTRI, bukan satu angka untuk semua: keluarga B menagih
     * menurut tarif jenjang masing-masing, dan sebagian peserta bisa memperoleh
     * keringanan. Totalnya karena itu dijumlahkan, bukan dikalikan.
     *
     * @param  array<int,string>  $nominalPerSantri  [id santri => nominal]
     * @param  list<string>  $gugur  yang sudah tersaring sebelum sampai ke sini
     */
    private function terbitkanUntuk(JenisBiaya $jenis, array $nominalPerSantri, array $data, int $idPengguna, array $gugur = []): array
    {
        $santri = Santri::whereIn('id', array_keys($nominalPerSantri))->where('status', 'aktif')
            ->get(['id', 'nama', 'kode_jenjang', 'tahun_ajaran']);
        // Yang dipilih tapi tak terjaring query di atas = sudah tidak berstatus
        // aktif. Dihitung di sini karena sesudah ini mereka tak terlihat lagi.
        $tidakAktif = count($nominalPerSantri) - $santri->count();
        if ($santri->isEmpty()) {
            throw new AppException(422, 'Tidak ada santri aktif yang dipilih.');
        }

        $sudahAda = TagihanSantri::where('kode_jenis', $jenis->kode)
            ->where('periode', $data['periode'] ?? null)
            ->whereIn('id_santri', $santri->pluck('id'))->pluck('id_santri')->all();
        $target = $santri->reject(fn ($s) => in_array($s->id, $sudahAda, true))->values();
        // Namanya, bukan cuma jumlahnya: "3 santri dilewati" tak menolong siapa
        // pun yang harus memutuskan apakah itu wajar atau salah pilih.
        $namaDilewati = $santri->filter(fn ($s) => in_array($s->id, $sudahAda, true))->pluck('nama')->all();
        if ($target->isEmpty()) {
            throw new AppException(422, 'Seluruh santri yang dipilih sudah punya tagihan ini.');
        }

        // Dulu: `(bool) $jenis->kode_coa_piutang`. Sifat akrual kini DINYATAKAN
        // di master, bukan disimpulkan dari terisinya sebuah akun.
        $akrual = $jenis->pengakuan === 'akrual';
        if ($akrual && ! $jenis->kode_coa_piutang) {
            throw new AppException(422, "Jenis biaya \"{$jenis->nama}\" berpengakuan akrual tetapi belum punya akun piutang, sehingga jurnalnya tak punya alamat. Lengkapi dulu di master Jenis Biaya.");
        }
        // Dijumlahkan, bukan dikalikan: tiap santri bisa berbeda nominalnya.
        $total = $target->reduce(fn ($t, $s) => Money::add($t, $nominalPerSantri[$s->id]), '0');

        $hasil = DB::transaction(function () use ($data, $jenis, $nominalPerSantri, $target, $akrual, $total, $idPengguna, $namaDilewati, $tidakAktif, $gugur) {
            $referensi = null;
            if ($akrual) {
                $base = DocNumber::docBase('TGL', $data['tanggal']);
                $last = \App\Models\JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
                $referensi = DocNumber::nextDocNumber($base, $last);
                PostingService::postJournal([
                    'referensi' => $referensi, 'tanggal' => $data['tanggal'], 'kode_unit' => $jenis->kode_unit,
                    'sumber_modul' => 'TagihanLain', 'id_pengguna' => $idPengguna,
                    'keterangan' => "{$jenis->nama}".(! empty($data['periode']) ? " {$data['periode']}" : '')." — {$target->count()} santri",
                    'lines' => [
                        ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $total, 'kredit' => '0'],
                        ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $total],
                    ],
                ]);
            }
            $now = now();
            TagihanSantri::insert($target->map(fn ($s) => [
                'id_santri' => $s->id, 'kode_jenis' => $jenis->kode, 'periode' => $data['periode'] ?? null,
                // Perilaku "lain" sengaja TIDAK kena indeks unik anti tagih-ganda:
                // satu santri boleh kena beberapa tagihan insidental di tahun sama.
                'perilaku' => 'lain', 'kode_jenjang' => $s->kode_jenjang, 'tahun_ajaran' => $s->tahun_ajaran,
                'nominal' => $nominalPerSantri[$s->id], 'sisa' => $nominalPerSantri[$s->id],
                'sudah_akrual' => $akrual, 'status' => 'belum_bayar',
                'jatuh_tempo' => $data['jatuh_tempo'] ?? null, 'keterangan' => $data['keterangan'] ?? $jenis->nama,
                'created_at' => $now, 'updated_at' => $now,
            ])->all());

            return [
                'terbit' => $target->count(),
                'dilewati' => count($namaDilewati),
                'dilewati_nama' => $namaDilewati,
                'tidak_aktif' => $tidakAktif,
                // Peserta yang gugur SEBELUM sampai ke sini (berhenti, jenjang
                // tanpa tarif) — hanya terisi pada penerbitan dari daftar peserta.
                'gugur' => $gugur,
                'total' => $total,
                'akrual' => $akrual,
                'referensi' => $referensi,
            ];
        });

        $autoDebet = (new AutoDebetService)->jalankan($idPengguna, $data['tanggal']);

        return array_merge($hasil, ['auto_debet' => $autoDebet]);
    }

    /**
     * Susun hasil penerbitan jadi satu kalimat.
     *
     * Semua angkanya sudah dihitung sejak dulu — berapa terbit, berapa dilewati
     * beserta namanya, totalnya, nomor jurnalnya — lalu seluruhnya dibuang dan
     * diganti "Tagihan lain berhasil diterbitkan". Petugas jadi tak pernah tahu
     * ada santri yang tak kebagian tagihan sampai ada yang menagih.
     *
     * Ada di service, bukan di controller, karena DUA jalur penerbitan memakainya
     * — manual dan dari daftar peserta.
     *
     * @param  array<string,mixed>  $hasil
     */
    public function ringkasanTerbit(array $hasil): string
    {
        $rupiah = 'Rp '.number_format((float) $hasil['total'], 0, ',', '.');
        $pesan = "{$hasil['terbit']} tagihan terbit — total {$rupiah}.";

        if ($hasil['akrual'] && $hasil['referensi']) {
            $pesan .= " Jurnal akrual {$hasil['referensi']}.";
        }

        if ($hasil['dilewati'] > 0) {
            // Daftar nama dipenggal: satu kali terbit bisa melewati puluhan
            // santri, dan pesan sepanjang layar tak lagi terbaca siapa pun.
            $nama = $hasil['dilewati_nama'];
            $tampil = array_slice($nama, 0, 8);
            $sisa = count($nama) - count($tampil);
            $daftar = implode(', ', $tampil).($sisa > 0 ? " dan {$sisa} lainnya" : '');
            $pesan .= " {$hasil['dilewati']} dilewati karena sudah punya tagihan ini: {$daftar}.";
        }

        if ($hasil['tidak_aktif'] > 0) {
            $pesan .= " {$hasil['tidak_aktif']} dilewati karena sudah tidak berstatus aktif.";
        }

        // Penerbitan dari daftar peserta menyaring lebih dulu — sebabnya sudah
        // berupa kalimat, jadi disebut apa adanya.
        if (($hasil['gugur'] ?? []) !== []) {
            $pesan .= ' Tidak ditagih: '.implode('; ', $hasil['gugur']).'.';
        }

        return $pesan;
    }

    public function batalkan(int $id): TagihanSantri
    {
        $t = TagihanSantri::with(['jenis', 'pembayaran' => fn ($q) => $q->where('status', '!=', 'ditolak')])->find($id);
        if (! $t) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if (\App\Models\TipeBiaya::perilakuDari($t->jenis->tipe) !== 'lain') {
            throw new AppException(422, 'Hanya tagihan lain-lain yang bisa dibatalkan di sini.');
        }
        // Dua pesan di bawah dulu menyuruh petugas "ke modul keuangan" — tempat
        // yang saat itu belum ada wujudnya, sehingga jalan buntu yang terdengar
        // seperti petunjuk. Sejak Koreksi Nominal Tagihan ada, tempatnya nyata
        // dan disebut namanya.
        if ($t->pembayaran->isNotEmpty()) {
            throw new AppException(422, 'Tagihan ini sudah punya pembayaran, jadi tidak bisa dibatalkan. Pakai Koreksi Nominal Tagihan — kelebihan bayarnya akan dipindahkan ke Dompet Wali sebagai titipan.');
        }
        if ($t->sudah_akrual) {
            throw new AppException(422, 'Tagihan ini sudah diakrualkan ke buku besar, jadi tidak bisa dihapus begitu saja. Pakai Koreksi Nominal Tagihan — jurnal penyesuaiannya terbit bersamaan, sehingga buku besar dan tagihan santri bergerak bersama.');
        }
        $t->update(['status' => 'batal', 'sisa' => '0']);

        return $t;
    }
}
