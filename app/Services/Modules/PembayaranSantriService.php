<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\BayarDompet;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PEMBAYARAN SANTRI — dua tangan: PPSB mencatat (menunggu_verifikasi, belum
 * berjurnal) → Keuangan verifikasi (jurnal terbit, tagihan berkurang, santri maju).
 * Sisi KREDIT ditentukan `sudah_akrual`: belum = Pendapatan (cash basis), sudah
 * = Piutang (menghindari pendapatan dobel). Lingkup: ppsb | kesantrian.
 */
class PembayaranSantriService
{
    private const TIPE_LINGKUP = [
        'ppsb' => ['registrasi', 'uang_pangkal', 'perlengkapan'],
        'kesantrian' => ['spp', 'daftar_ulang', 'lain'],
    ];

    private const LABEL_LINGKUP = [
        'ppsb' => 'PPSB (registrasi, uang pangkal & perlengkapan)',
        // Label yang DIBACA pemakai — mengikuti nama menunya (kini Kependidikan).
        // Kunci lingkupnya sendiri tetap 'kesantrian': dipakai di URL & kode modul.
        'kesantrian' => 'Kependidikan (SPP, daftar ulang & tagihan lain)',
    ];

    /**
     * Perilaku yang harus DILUNASI SEKALIGUS — tak boleh dicicil dari mana pun.
     *
     * Hanya `lain`, mengikuti aturan "lunas sekaligus" yang dikunci rancangan
     * Tagihan Lain-lain v2.
     *
     * SPP SENGAJA TIDAK IKUT di sini. Ia memang penuh-atau-tidak pada AUTO-DEBET
     * (lihat AutoDebetService), tetapi pembayaran yang diketik petugas di loket
     * selama ini boleh sebagian — dan tak ada keputusan yang pernah mengubahnya.
     * Menyeretnya ke sini akan menolak setoran yang hari ini sah.
     *
     * Yang juga tidak masuk: uang pangkal (punya modul Angsuran tersendiri),
     * daftar ulang, perlengkapan, dan registrasi.
     */
    private const LUNAS_SEKALIGUS = ['lain'];

    /**
     * Cicilan ditolak di sini, bukan didiamkan lalu meninggalkan status
     * "sebagian" yang tak pernah direncanakan modul mana pun.
     *
     * Yang dibandingkan adalah RUANG, bukan `sisa`: kalau sudah ada setoran lain
     * yang menunggu verifikasi, pelunasan yang sah justru sebesar ruang tersisa.
     */
    private function assertLunasSekaligus(TagihanSantri $tagihan, string $nominal, string $ruang): void
    {
        $perilaku = TipeBiaya::perilakuDari($tagihan->jenis?->tipe);
        if (! in_array($perilaku, self::LUNAS_SEKALIGUS, true) || Money::eq($nominal, $ruang)) {
            return;
        }

        throw new AppException(422, __(
            'Tagihan lain-lain harus dilunasi sekaligus, tidak bisa dicicil. Yang harus dibayar :harus, bukan :nominal.',
            ['harus' => Money::of($ruang), 'nominal' => Money::of($nominal)],
        ));
    }

    /**
     * Langkah 1 — PPSB (atau wali lewat portal) mencatat setoran. Tidak ada jurnal.
     *
     * @param  array{id:int, peran:string}|null  $wali  pengirim dari Portal Wali (null = dicatat staf)
     */
    public function catat(array $data, ?int $idPengguna, string $lingkup, ?array $wali = null): PembayaranSantri
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($data['id_tagihan']);
        if (! $tagihan) {
            throw new AppException(404, __('Tagihan tidak ditemukan.'));
        }
        $this->assertLingkup($tagihan->jenis->tipe, $lingkup);
        if ($tagihan->id_santri !== $data['id_santri']) {
            throw new AppException(400, 'Tagihan itu bukan milik santri yang dipilih.');
        }
        if ($tagihan->status === 'lunas') {
            throw new AppException(422, __('Tagihan ini sudah lunas.'));
        }
        if (! $tagihan->berlaku()) {
            throw new AppException(422, $tagihan->status === 'dihapus'
                ? __('Tagihan ini sudah dihapus lewat koreksi nominal, jadi tak ada yang perlu dibayar.')
                : __('Tagihan ini sudah dibatalkan.'));
        }

        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, __('Nominal pembayaran harus lebih dari nol.'));
        }
        $ruang = $this->ruangTagihan($tagihan->id, $tagihan->sisa);
        if (Money::gt($nominal, $ruang)) {
            throw new AppException(422, __('Nominal melebihi sisa tagihan yang belum tercatat (:nominal).', ['nominal' => $ruang]));
        }
        $this->assertLunasSekaligus($tagihan, $nominal, $ruang);
        $this->assertRekeningAda($data['kode_rekening']);

        $pembayaran = DB::transaction(function () use ($data, $idPengguna, $nominal, $wali) {
            $base = DocNumber::docBase('BYR', $data['tanggal']);
            $last = PembayaranSantri::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            return PembayaranSantri::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'id_santri' => $data['id_santri'], 'id_tagihan' => $data['id_tagihan'],
                'tanggal' => $data['tanggal'], 'nominal' => $nominal, 'kode_rekening' => $data['kode_rekening'],
                'metode' => $data['metode'] ?? null, 'bukti_path' => $data['bukti_path'] ?? null,
                'catatan' => $data['catatan'] ?? null, 'sumber' => 'manual',
                'status' => 'menunggu_verifikasi', 'kelompok_bayar' => $data['kelompok_bayar'] ?? null,
            ] + \App\Services\PortalWali\Pencatat::kolom($idPengguna, $wali));
        });
        $this->tutupVaBasiSesudahnya($tagihan->id);

        // Anggota kelompok: pemanggilnya mengirim SATU kabar untuk seluruh kelompok.
        if (empty($data['kelompok_bayar'])) {
            $this->notifKeuangan("{$pembayaran->nomor} — {$tagihan->jenis->nama} atas nama {$tagihan->santri->nama}. Pastikan dananya sudah masuk sebelum menyetujui.", (string) $pembayaran->id);
        }

        return $pembayaran;
    }

    /** Langkah 2 — Keuangan verifikasi → jurnal terbit (satu transaksi). */
    public function verifikasi(int $id, int $idPengguna): PembayaranSantri
    {
        $this->assertTimKeuangan($idPengguna);
        $pembayaran = PembayaranSantri::find($id);
        if ($pembayaran?->kelompok_bayar) {
            throw new AppException(422, 'Pembayaran ini bagian dari transfer berkelompok (satu bukti untuk beberapa tagihan). Gunakan "Verifikasi kelompok".');
        }
        [$hasil, $tagihan] = $this->verifikasiSatu($id, $idPengguna);
        $this->evaluasiPotonganSesudahBayar($tagihan, $idPengguna);

        return $hasil;
    }

    /**
     * Transfer BERKELOMPOK (satu bukti, beberapa tagihan) — diverifikasi SEKALIGUS: satu
     * gagal = tak satu pun terverifikasi. Jurnal tetap satu per tagihan.
     *
     * @return Collection<int,PembayaranSantri>
     */
    public function verifikasiKelompok(string $kelompok, int $idPengguna): \Illuminate\Support\Collection
    {
        $this->assertTimKeuangan($idPengguna);
        $ids = PembayaranSantri::where('kelompok_bayar', $kelompok)->where('status', 'menunggu_verifikasi')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            throw new AppException(404, 'Tidak ada pembayaran kelompok ini yang menunggu verifikasi.');
        }

        $hasil = DB::transaction(fn () => $ids->map(fn ($id) => $this->verifikasiSatu($id, $idPengguna)));

        // Di LUAR transaksi: kegagalan evaluasi potongan tak boleh menggagalkan verifikasinya.
        foreach ($hasil as [, $tagihan]) {
            $this->evaluasiPotonganSesudahBayar($tagihan, $idPengguna);
        }

        return $hasil->map(fn ($h) => $h[0]);
    }

    /** Menolak transfer berkelompok — seluruh anggotanya, satu kabar ke pengirimnya. */
    public function tolakKelompok(string $kelompok, string $alasan, int $idPengguna): int
    {
        $this->assertTimKeuangan($idPengguna);
        $baris = DB::transaction(function () use ($kelompok, $alasan, $idPengguna) {
            // Dikunci dulu: anggota yang sudah terverifikasi oleh klik lain tak ikut "ditolak".
            $baris = PembayaranSantri::where('kelompok_bayar', $kelompok)->where('status', 'menunggu_verifikasi')
                ->orderBy('id')->lockForUpdate()->get();
            if ($baris->isEmpty()) {
                throw new AppException(404, 'Tidak ada pembayaran kelompok ini yang menunggu verifikasi.');
            }
            PembayaranSantri::whereIn('id', $baris->pluck('id'))->update([
                'status' => 'ditolak', 'alasan_tolak' => $alasan,
                'diverifikasi_oleh' => $idPengguna, 'diverifikasi_pada' => Carbon::now(),
            ]);

            return $baris;
        });

        $pertama = $baris->first();
        \App\Services\PortalWali\KabarWali::kePencatat($pertama, [
            'judul' => \App\Support\Teks::k('Pembayaran ditolak keuangan'), 'id_santri' => $pertama->id_santri,
            'pesan' => \App\Support\Teks::k(':awal s/d :akhir (:jumlah tagihan) ditolak: :alasan', [
                'awal' => $pertama->nomor, 'akhir' => $baris->last()->nomor, 'jumlah' => $baris->count(), 'alasan' => $alasan,
            ]),
            'jenis' => 'pembayaran_santri_ditolak', 'ref_jenis' => 'PembayaranSantri', 'ref_id' => (string) $pertama->id,
        ]);

        return $baris->count();
    }

    /** Kabar SATU kali untuk transfer berkelompok yang baru dikirim. */
    public function kabariKelompok(\Illuminate\Support\Collection $baris): void
    {
        $total = Money::of($baris->sum(fn ($p) => (float) $p->nominal));
        $this->notifKeuangan("{$baris->first()->nomor} s/d {$baris->last()->nomor} — {$baris->count()} tagihan, total {$total}, satu bukti transfer. "
            .'Cocokkan TOTALNYA dengan mutasi bank, lalu "Verifikasi kelompok".', (string) $baris->first()->id);
    }

    /**
     * Inti verifikasi tanpa langkah susulan (evaluasi potongan) — supaya beberapa
     * verifikasi bisa dibungkus SATU transaksi tanpa susulan yang ikut menggagalkannya.
     *
     * @return array{0:PembayaranSantri, 1:TagihanSantri}
     */
    private function verifikasiSatu(int $id, int $idPengguna): array
    {
        $tagihan = null;
        $hasil = DB::transaction(function () use ($id, $idPengguna, &$tagihan) {
            // Kunci pembayaran LALU tagihannya, dan periksa di bawah kunci: dua klik "Verifikasi"
            // serentak dulu sama-sama lolos → dua jurnal, sisa tagihan terpotong dua kali (audit 22 Okt 2026).
            $pembayaran = PembayaranSantri::whereKey($id)->lockForUpdate()->first();
            if (! $pembayaran) {
                throw new AppException(404, 'Pembayaran tidak ditemukan.');
            }
            if ($pembayaran->status !== 'menunggu_verifikasi') {
                throw new AppException(422, "Pembayaran ini sudah berstatus \"{$pembayaran->status}\".");
            }
            $tagihan = $pembayaran->id_tagihan
                ? TagihanSantri::with('jenis')->whereKey($pembayaran->id_tagihan)->lockForUpdate()->first()
                : null;
            if (! $tagihan) {
                throw new AppException(422, 'Pembayaran ini tidak terkait tagihan mana pun.');
            }
            if (! $tagihan->berlaku()) {
                throw new AppException(422, 'Tagihan pembayaran ini sudah dibatalkan atau dihapus — tolak pembayarannya.');
            }
            $pembayaran->load('santri');
            $nominal = Money::of($pembayaran->nominal);
            $sisaBaru = Money::sub($tagihan->sisa, $nominal);
            if (Money::isNegative($sisaBaru)) {
                throw new AppException(422, "Nominal {$nominal} melebihi sisa tagihan {$tagihan->sisa} — kemungkinan sudah ada pembayaran lain yang diverifikasi lebih dulu.");
            }

            return $this->bukukanVerifikasi($pembayaran, $tagihan, $nominal, $sisaBaru, $idPengguna);
        });

        return [$hasil, $tagihan];
    }

    private function bukukanVerifikasi(PembayaranSantri $pembayaran, TagihanSantri $tagihan, string $nominal, string $sisaBaru, int $idPengguna): PembayaranSantri
    {
        $jenis = $tagihan->jenis;
        $akunKredit = $this->akunKredit($tagihan);

        $entry = PostingService::postJournal([
            'referensi' => $pembayaran->nomor, 'tanggal' => $pembayaran->tanggal, 'kode_unit' => $jenis->kode_unit,
            'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $pembayaran->id, 'id_pengguna' => $idPengguna,
            'keterangan' => "{$jenis->nama} — {$pembayaran->santri->nama} ({$pembayaran->santri->no_pendaftaran})",
            'lines' => [
                ['kode_coa' => $pembayaran->kode_rekening, 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => $akunKredit, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);

        $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
        $this->majukanTahapBilaRegistrasiLunas($tagihan->refresh(), $pembayaran->santri);

        $pembayaran->update([
            'status' => 'terverifikasi', 'diverifikasi_oleh' => $idPengguna,
            'diverifikasi_pada' => Carbon::now(), 'journal_entry_id' => $entry->id,
        ]);

        // Kabar & push ke keluarga (kategori "tagihan") — sesudah transaksi tersimpan, tak pernah menggagalkannya.
        DB::afterCommit(function () use ($pembayaran, $tagihan) {
            try {
                \App\Services\PortalWali\KabarKeluarga::pembayaranDiverifikasi($pembayaran, $tagihan);
            } catch (\Throwable $e) {
                report($e);
            }
        });
        $this->tutupVaBasiSesudahnya($tagihan->id);

        return $pembayaran;
    }

    /**
     * Pembayaran lewat payment gateway (Midtrans) — LANGSUNG terverifikasi & berjurnal,
     * tanpa tangan kedua: lunasnya sudah dipastikan ke API Midtrans. Dipanggil dari
     * dalam transaksi PembayaranMidtrans, dengan tagihan yang SUDAH dikunci; nominal
     * di sini tak pernah melebihi sisa (kelebihannya dialihkan ke Dompet Wali di sana).
     * Sesudah transaksinya selesai, pemanggil wajib memanggil sesudahBayarGateway().
     *
     * @param  array{nominal:string, biaya:string, tanggal:string, kode_rekening:string, akun_biaya:string,
     *               pencatat:array{id:int,peran:string}, external_id:string, provider_ref:?string, metode:?string}  $d
     */
    public function bukukanGateway(TagihanSantri $tagihan, array $d): PembayaranSantri
    {
        $tagihan->loadMissing(['jenis', 'santri']);
        $nominal = Money::of($d['nominal']);
        $sisaBaru = Money::sub($tagihan->sisa, $nominal);
        if (Money::lte($nominal, '0') || Money::isNegative($sisaBaru)) {
            throw new AppException(422, "Nominal {$nominal} tidak sah untuk sisa tagihan {$tagihan->sisa}.");
        }
        $this->assertRekeningAda($d['kode_rekening']);

        return DB::transaction(function () use ($tagihan, $d, $nominal, $sisaBaru) {
            $jenis = $tagihan->jenis;
            $base = DocNumber::docBase('BYR', $d['tanggal']);
            $last = PembayaranSantri::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $pembayaran = PembayaranSantri::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'id_santri' => $tagihan->id_santri, 'id_tagihan' => $tagihan->id,
                'tanggal' => $d['tanggal'], 'nominal' => $nominal, 'kode_rekening' => $d['kode_rekening'],
                'metode' => $d['metode'] ?? null, 'sumber' => 'midtrans',
                'external_id' => $d['external_id'], 'provider_ref' => $d['provider_ref'] ?? null,
                'catatan' => 'Dibayar online lewat Midtrans (Virtual Account).',
                'status' => 'terverifikasi', 'diverifikasi_pada' => Carbon::now(),
                'kelompok_bayar' => $d['kelompok_bayar'] ?? null,
            ] + \App\Services\PortalWali\Pencatat::kolom(null, $d['pencatat']));

            [$entry] = PostingService::postJournals(\App\Services\Midtrans\JurnalGateway::susun([
                'referensi' => $pembayaran->nomor, 'tanggal' => $d['tanggal'], 'kode_unit' => $jenis->kode_unit,
                'sumber_modul' => 'PembayaranSantri', 'id_sumber' => (string) $pembayaran->id,
                'keterangan' => "{$jenis->nama} (Midtrans) — {$tagihan->santri->nama} ({$tagihan->santri->no_pendaftaran})",
            ], $d['kode_rekening'], $this->akunKredit($tagihan), $nominal, Money::of($d['biaya']), $d['akun_biaya']));

            $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);
            $this->majukanTahapBilaRegistrasiLunas($tagihan->refresh(), $tagihan->santri);
            $pembayaran->update(['journal_entry_id' => $entry->id]);

            return $pembayaran;
        });
    }

    /** Langkah susulan bayar gateway — di LUAR transaksi, sama seperti sesudah verifikasi(). */
    public function sesudahBayarGateway(TagihanSantri $tagihan): void
    {
        $this->evaluasiPotonganSesudahBayar($tagihan, null);
    }

    /** Sisi KREDIT pembayaran tagihan: Pendapatan (cash basis) atau Piutang bila sudah diakrualkan. */
    private function akunKredit(TagihanSantri $tagihan): string
    {
        $jenis = $tagihan->jenis;
        if (! $tagihan->sudah_akrual) {
            return $jenis->kode_coa_pendapatan;
        }
        if (! $jenis->kode_coa_piutang) {
            throw new AppException(422, "Tagihan \"{$jenis->nama}\" sudah diakrualkan tetapi jenis biayanya tidak punya akun piutang. Lengkapi dulu di master Jenis Biaya.");
        }

        return $jenis->kode_coa_piutang;
    }

    /**
     * Pembayaran uang pangkal bisa memenuhi syarat potongan gelombang — putuskan
     * sekarang, bukan menunggu jadwal malam. SESUDAH transaksi pembayaran
     * selesai, dan kegagalannya tak membatalkan pembayaran yang sudah sah:
     * evaluasi terjadwal akan mengulangnya.
     */
    private function evaluasiPotonganSesudahBayar(TagihanSantri $tagihan, ?int $idPengguna): void
    {
        if ($tagihan->perilaku !== 'uang_pangkal') {
            return;
        }
        try {
            (new AngsuranUangPangkalService)->evaluasiPotongan($tagihan->id, $idPengguna);
        } catch (\Throwable $e) {
            Log::warning('Evaluasi potongan sesudah pembayaran gagal; diulang oleh jadwal harian.', [
                'id_tagihan' => $tagihan->id, 'pesan' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bayar tagihan dari Dompet Wali (tanpa verifikasi — dana sudah diverifikasi saat top-up).
     *
     * Dipakai loket DAN Portal Wali, supaya gerbang tahap PSB (registrasi lunas →
     * calon maju) & evaluasi potongan gelombang berlaku sama di kedua pintu.
     *
     * @param  array{id:int, peran:string}|null  $wali  pembayar dari Portal Wali (null = dicatat staf)
     */
    public function bayarDariDompet(array $data, ?int $idPengguna, ?array $wali = null, bool $tundaSusulan = false): PembayaranSantri
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($data['id_tagihan']);
        if (! $tagihan) {
            throw new AppException(404, __('Tagihan tidak ditemukan.'));
        }
        if ($tagihan->status === 'lunas') {
            throw new AppException(422, __('Tagihan ini sudah lunas.'));
        }
        if (! $tagihan->berlaku()) {
            throw new AppException(422, $tagihan->status === 'dihapus'
                ? __('Tagihan ini sudah dihapus lewat koreksi nominal, jadi tak ada yang perlu dibayar.')
                : __('Tagihan ini sudah dibatalkan.'));
        }

        $dompet = DompetWali::where('id_wali', $tagihan->santri->id_wali)->first();
        if (! $dompet) {
            throw new AppException(422, __('Keluarga ini belum punya Dompet Wali. Lakukan top-up lebih dulu di menu Dompet & Tabungan.'));
        }
        $nominal = Money::of($data['nominal']);
        if (Money::lte($nominal, '0')) {
            throw new AppException(422, __('Nominal pembayaran harus lebih dari nol.'));
        }
        $ruang = $this->ruangTagihan($tagihan->id, $tagihan->sisa);
        if (Money::gt($nominal, $ruang)) {
            throw new AppException(422, __('Nominal melebihi sisa tagihan yang belum tercatat (:nominal).', ['nominal' => $ruang]));
        }
        $this->assertLunasSekaligus($tagihan, $nominal, $ruang);
        if (Money::lt($dompet->saldo, $nominal)) {
            throw new AppException(422, __('Saldo Dompet Wali tidak cukup (tersedia :saldo).', ['saldo' => $dompet->saldo]));
        }

        $hasil = DB::transaction(function () use ($tagihan, $dompet, $nominal, $data, $idPengguna, $wali) {
            $bayar = BayarDompet::bayarTagihanDariDompet($tagihan, [
                'id_dompet' => $dompet->id, 'nominal' => (string) $nominal, 'tanggal' => $data['tanggal'],
                'id_pengguna' => $idPengguna, 'namaSantri' => $tagihan->santri->nama, 'catatan' => $data['catatan'] ?? null,
                'wali' => $wali,
            ]);
            // Gerbang tahap yang SAMA dengan verifikasi di loket. Dulu jalur dompet
            // hanya melunasi tagihan: registrasi lanjutan santri aktif yang dibayar
            // dari dompet membuat siklusnya macet di "Calon" tanpa tombol apa pun.
            $this->majukanTahapBilaRegistrasiLunas($tagihan->refresh(), $tagihan->santri);
            if (! empty($data['kelompok_bayar'])) {
                $bayar->update(['kelompok_bayar' => $data['kelompok_bayar']]);
            }
            $this->tutupVaBasiSesudahnya($tagihan->id);

            return $bayar;
        });

        // Bayar berkelompok dibungkus SATU transaksi oleh pemanggilnya — susulan dijalankannya
        // sesudah semuanya selesai (sesudahBayarGateway), bukan di tengah transaksi itu.
        if (! $tundaSusulan) {
            $this->evaluasiPotonganSesudahBayar($tagihan, $idPengguna);
        }

        return $hasil;
    }

    /**
     * Registrasi LUNAS = gerbang tahap berikutnya. Satu tempat untuk kedua jalur
     * pembayaran (verifikasi loket & Dompet Wali), supaya keduanya tak pernah
     * berbeda perilaku lagi.
     *  • calon baru → `terbayar`;
     *  • santri AKTIF = pendaftaran ke jenjang lanjutan. Yang maju tahapnya
     *    PENDAFTARAN-nya, bukan santrinya: ia masih bersekolah di jenjang lama
     *    sampai kenaikan dieksekusi.
     */
    private function majukanTahapBilaRegistrasiLunas(TagihanSantri $tagihan, Santri $santri): void
    {
        if (TipeBiaya::perilakuDari($tagihan->jenis?->tipe) !== 'registrasi' || ! Money::isZero($tagihan->sisa)) {
            return;
        }
        if ($santri->status === 'calon') {
            Santri::where('id', $santri->id)->update(['status' => 'terbayar']);
        } elseif ($santri->status === 'aktif') {
            (new PendaftaranLanjutanService)->tandaiRegistrasiLunas(
                (int) $santri->id, $tagihan->kode_jenjang, $tagihan->tahun_ajaran,
            );
        }
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PembayaranSantri
    {
        $this->assertTimKeuangan($idPengguna);
        // Di bawah kunci: tolak & verifikasi serentak atas baris yang sama tak boleh dua-duanya lolos.
        $pembayaran = DB::transaction(function () use ($id, $alasan, $idPengguna) {
            $pembayaran = PembayaranSantri::whereKey($id)->lockForUpdate()->first();
            if (! $pembayaran) {
                throw new AppException(404, 'Pembayaran tidak ditemukan.');
            }
            if ($pembayaran->kelompok_bayar) {
                throw new AppException(422, 'Pembayaran ini bagian dari transfer berkelompok. Gunakan "Tolak kelompok".');
            }
            if ($pembayaran->status !== 'menunggu_verifikasi') {
                throw new AppException(422, "Pembayaran ini sudah berstatus \"{$pembayaran->status}\".");
            }
            $pembayaran->update([
                'status' => 'ditolak', 'alasan_tolak' => $alasan,
                'diverifikasi_oleh' => $idPengguna, 'diverifikasi_pada' => Carbon::now(),
            ]);

            return $pembayaran;
        });

        // Ke PENCATATNYA — staf atau wali yang mengirim dari portal.
        \App\Services\PortalWali\KabarWali::kePencatat($pembayaran, [
            'judul' => \App\Support\Teks::k('Pembayaran santri ditolak keuangan'), 'id_santri' => $pembayaran->id_santri,
            'pesan' => \App\Support\Teks::k(':nomor ditolak: :alasan', ['nomor' => $pembayaran->nomor, 'alasan' => $alasan]), 'jenis' => 'pembayaran_santri_ditolak',
            'ref_jenis' => 'PembayaranSantri', 'ref_id' => (string) $id,
        ]);

        return $pembayaran;
    }

    // ---- Helper ----

    private function assertLingkup(string $tipe, string $lingkup): void
    {
        // Dibandingkan lewat PERILAKU tipe: tipe buatan sendiri ikut masuk lingkup
        // yang benar tanpa perlu didaftarkan lagi di sini.
        if (! in_array(TipeBiaya::perilakuDari($tipe), self::TIPE_LINGKUP[$lingkup], true)) {
            $lain = $lingkup === 'ppsb' ? 'kesantrian' : 'ppsb';
            throw new AppException(422, "Tagihan berjenis \"{$tipe}\" bukan urusan modul ".self::LABEL_LINGKUP[$lingkup].'. Pakai modul '.self::LABEL_LINGKUP[$lain].'.');
        }
    }

    private function assertTimKeuangan(int $idPengguna): void
    {
        $user = User::find($idPengguna);
        if (! $user || (! $user->tim_keuangan && ! $user->is_admin)) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh memverifikasi pembayaran santri.');
        }
    }

    /**
     * Pembayaran di sini tak memperhitungkan VA wali yang belum dibayar (uang tunai di loket tak boleh
     * ditolak), jadi VA itu bisa jadi melebihi sisa → dibatalkan (PembayaranMidtrans::tutupVaBasi).
     * Sesudah transaksi tersimpan & tak pernah menggagalkannya.
     */
    private function tutupVaBasiSesudahnya(int $idTagihan): void
    {
        DB::afterCommit(function () use ($idTagihan) {
            try {
                (new \App\Services\Midtrans\PembayaranMidtrans)->tutupVaBasi($idTagihan);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /** Sisa yang belum tercatat = sisa - setoran menunggu verifikasi. */
    private function ruangTagihan(int $idTagihan, $sisa): string
    {
        $menunggu = PembayaranSantri::where('id_tagihan', $idTagihan)->where('status', 'menunggu_verifikasi')->sum('nominal');

        return Money::sub($sisa, $menunggu);
    }

    private function assertRekeningAda(string $kodeRekening): void
    {
        $rek = BankAccount::find($kodeRekening);
        if (! $rek) {
            throw new AppException(400, 'Kas/rekening penerima tidak ditemukan.');
        }
        if ($rek->status !== 'aktif') {
            throw new AppException(422, "Rekening \"{$rek->nama_rekening}\" berstatus nonaktif.");
        }
    }

    private function notifKeuangan(string $pesan, ?string $refId): void
    {
        $keuangan = User::where('tim_keuangan', true)->where('status', 'aktif')->get(['id_pengguna']);
        (new NotificationService)->kirim($keuangan->map(fn ($u) => [
            'id_pengguna' => $u->id_pengguna, 'judul' => 'Pembayaran santri menunggu verifikasi',
            'pesan' => $pesan, 'jenis' => 'pembayaran_santri_menunggu', 'ref_jenis' => 'PembayaranSantri', 'ref_id' => $refId,
        ])->all());
    }
}
