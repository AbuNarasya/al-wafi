<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\DompetSantri;
use App\Models\JenisBiaya;
use App\Models\MutasiDompet;
use App\Models\NisSantri;
use App\Models\PembayaranSantri;
use App\Models\PrabayarSpp;
use App\Models\RiwayatTingkat;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Models\Wali;
use App\Services\Ledger\DocNumber;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * INPUT MANUAL SANTRI AKTIF — versi satuan dari impor Santri Lama.
 *
 * Jalur pendaftaran biasa (SantriService::create) melahirkan santri berstatus
 * `calon` beserta baris Pendaftaran dan tagihan registrasi — itu memang alur
 * PPSB. Santri yang SUDAH bersekolah sebelum aplikasi ini dipakai tak boleh
 * lewat sana: ia tak pernah mendaftar di sini, dan menagihnya registrasi adalah
 * kekeliruan. Satu-satunya jalan selama ini adalah berkas impor, yang menolak
 * NIS yang sudah terpakai dan merepotkan untuk satu-dua orang susulan.
 *
 * Yang dibuat di sini sama persis dengan yang dibuat PemetaSantriLama: santri
 * `aktif` bernomor LAMA-xxxx, riwayat NIS, riwayat tingkat — TANPA Pendaftaran,
 * TANPA tagihan registrasi, TANPA jurnal.
 *
 * ── Perlakuan posting tagihan ─────────────────────────────────────────────────
 *
 * Tiap baris tagihan memilih sendiri bagaimana ia masuk pembukuan. Ketiganya
 * sudah lama didukung model datanya; yang baru hanyalah kemampuan MEMILIHNYA:
 *
 *   `saldo_awal`  — kewajibannya sudah diakui di pembukuan lama. Tak ada jurnal;
 *                   nilainya masuk neraca lewat baris turunan di menu Saldo Awal.
 *                   Pembayarannya kelak mengkredit PIUTANG.
 *   `akrual`      — kewajibannya diakui HARI INI. Jurnal D Piutang / K Pendapatan
 *                   terbit sekarang, jadi ia menjadi pendapatan periode berjalan.
 *   `kas`         — belum diakui sama sekali. Tak ada jurnal; pendapatannya baru
 *                   diakui saat uang diterima. Perlakuan yang dipakai uang pangkal
 *                   & perlengkapan PPSB.
 *
 * Salah memilih di antara ketiganya TIDAK membuat aplikasi gagal — ia membuat
 * laporan laba rugi salah, dan itu baru ketahuan saat rekonsiliasi. Karena itu
 * layarnya menjelaskan akibat tiap pilihan di tempat, bukan sekadar menawarkan
 * tiga pilihan bernama.
 */
class SantriManualService
{
    /** Perlakuan posting yang boleh dipilih per baris tagihan. */
    public const POSTING = ['saldo_awal', 'akrual', 'kas'];

    /**
     * Buat satu santri aktif beserta tagihan outstanding-nya.
     *
     * @param  array<string,mixed>  $data
     * @param  list<array{kode_jenis:string,nominal:string|int|float,posting:string,jatuh_tempo?:?string,keterangan?:?string,tahun_ajaran?:?string}>  $tagihan
     */
    public function buat(array $data, array $tagihan, int $idPengguna): Santri
    {
        $wali = $this->waliTerpakai($data);

        if (Santri::where('nis', $data['nis'])->exists()) {
            throw new AppException(422, "NIS \"{$data['nis']}\" sudah dipakai santri lain.");
        }

        // Diperiksa SEBELUM transaksi dibuka: kalau barisnya baru ditolak di
        // tengah penyimpanan, santrinya sudah terlanjur dibuat dan petugas
        // harus membereskan setengah jadi.
        $siap = $this->periksaTagihan($tagihan);

        return DB::transaction(function () use ($data, $wali, $siap, $idPengguna) {
            $santri = Santri::create([
                // Awalan sendiri supaya santri lama langsung terbedakan dari
                // pendaftar PPSB dan tak mengacaukan penomoran PSB.
                'no_pendaftaran' => $this->nomorBerikutnya(),
                'nis' => $data['nis'],
                'nama' => $data['nama'],
                'jenis_kelamin' => $data['jenis_kelamin'],
                'tempat_lahir' => $data['tempat_lahir'] ?? null,
                'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
                'nisn' => $data['nisn'] ?? null,
                'kode_jenjang' => $data['kode_jenjang'],
                'tingkat' => $data['tingkat'],
                'tahun_ajaran' => $data['tahun_ajaran'],
                // Santri lama masuk langsung sebagai aktif, jadi tahun yang sedang
                // DIJALANI sama dengan tahun masuknya. Tanpa ini, pencarian tarif
                // SPP-nya buntu karena kolomnya kosong.
                'tahun_ajaran_berjalan' => $data['tahun_ajaran_berjalan'] ?? $data['tahun_ajaran'],
                'jalur' => $data['jalur'],
                // Tanpa gelombang: santri lama tak boleh kena hitungan potongan.
                'gelombang' => null,
                'status' => 'aktif',
                'id_wali' => $wali->id,
            ]);

            // Bentuk barisnya disamakan persis dengan PemetaSantriLama. NIS
            // bawaannya WAJIB ikut tercatat sebagai riwayat pertama — tanpa itu
            // layar Generate NIS mengira santri ini belum pernah bernomor lalu
            // menawarkan nomor baru.
            NisSantri::create([
                'id_santri' => $santri->id, 'nis' => $santri->nis,
                'kode_jenjang' => $santri->kode_jenjang, 'tingkat' => $santri->tingkat,
                'tahun_ajaran' => $santri->tahun_ajaran, 'berlaku' => true,
                'diterbitkan_pada' => now()->toDateString(),
            ]);
            RiwayatTingkat::create([
                'id_santri' => $santri->id, 'tahun_ajaran' => $santri->tahun_ajaran,
                'kode_jenjang' => $santri->kode_jenjang, 'tingkat' => $santri->tingkat,
                'catatan' => 'Input manual santri aktif.',
            ]);

            foreach ($siap as $b) {
                $this->terbitkanTagihan($santri, $b, $idPengguna);
            }

            return $santri->refresh();
        });
    }

    /**
     * Alasan sebuah santri TIDAK boleh dihapus lewat pintu ini.
     *
     * Kosong = boleh. Dipakai service untuk menolak dan dipakai layar untuk
     * memutuskan apakah tombolnya ditampilkan — satu sumber, supaya tombol yang
     * terlihat dan tindakan yang diterima tak pernah berbeda pendapat.
     *
     * @return list<string>
     */
    public static function halangan(Santri $santri): array
    {
        // Hanya yang LAHIR DI SINI. Santri hasil impor bertanda batch, dan
        // membuangnya satu per satu dari sini akan menggerogoti batch itu
        // diam-diam sehingga pembatalannya tak lagi utuh. Santri PPSB bernomor
        // PSB- dan punya riwayat pendaftaran yang tak boleh lenyap.
        if ($santri->id_batch !== null || ! str_starts_with((string) $santri->no_pendaftaran, 'LAMA-')) {
            return ['Santri ini tidak dibuat lewat Input Manual, jadi tak bisa dihapus dari sini. '
                .'Santri hasil impor dibatalkan lewat pembatalan batch; santri PPSB lewat pengunduran diri.'];
        }

        $halangan = [];
        $idTagihan = TagihanSantri::where('id_santri', $santri->id)->pluck('id');

        if (($n = PembayaranSantri::whereIn('id_tagihan', $idTagihan)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} pembayaran atas tagihan santri ini.";
        }
        // Uang yang masuk lewat auto-debet dompet & SPP prabayar TIDAK
        // meninggalkan baris pembayaran; selisih nominal−sisa yang menangkapnya.
        if (TagihanSantri::where('id_santri', $santri->id)->whereColumn('sisa', '!=', 'nominal')->exists()) {
            $halangan[] = 'Sebagian tagihannya sudah terbayar.';
        }
        if (($n = PrabayarSpp::where('id_santri', $santri->id)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} pembayaran SPP di muka.";
        }
        $idDompet = DompetSantri::where('id_santri', $santri->id)->pluck('id');
        if ($idDompet->isNotEmpty()
            && ($n = MutasiDompet::where('pemilik', 'santri')->whereIn('id_dompet', $idDompet)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} mutasi dompet santri.";
        }
        if ($santri->status !== 'aktif') {
            $halangan[] = 'Santri ini sudah berpindah status dari "aktif".';
        }
        // Tagihan berjurnal tak boleh ikut hilang begitu saja: piutangnya sudah
        // di buku besar, dan menghapus barisnya meninggalkannya tanpa lawan.
        if (TagihanSantri::where('id_santri', $santri->id)->where('sudah_akrual', true)
            ->where('saldo_awal', false)->exists()) {
            $halangan[] = 'Ada tagihan yang sudah diakrualkan dengan jurnal. Koreksi nominalnya ke Rp 0 lebih dulu '
                .'agar jurnal penyesuaiannya terbit, baru santrinya bisa dihapus.';
        }

        return $halangan;
    }

    /** Buang santri beserta turunannya. Hanya yang lahir di sini & belum tersentuh. */
    public function hapus(int $idSantri): void
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        $halangan = self::halangan($santri);
        if ($halangan !== []) {
            throw new AppException(422, implode(' ', $halangan));
        }

        DB::transaction(function () use ($santri) {
            TagihanSantri::where('id_santri', $santri->id)->delete();
            NisSantri::where('id_santri', $santri->id)->delete();
            RiwayatTingkat::where('id_santri', $santri->id)->delete();
            DokumenSantri::where('id_santri', $santri->id)->delete();
            DompetSantri::where('id_santri', $santri->id)->delete();
            TabunganSantri::where('id_santri', $santri->id)->delete();
            $santri->delete();
        });
    }

    /** Wali yang dipakai: yang sudah ada, atau yang dibuat sekalian di sini. */
    private function waliTerpakai(array $data): Wali
    {
        if (! empty($data['id_wali'])) {
            $wali = Wali::find($data['id_wali']);
            if (! $wali) {
                throw new AppException(422, 'Wali tidak ditemukan.');
            }
            if ($wali->status !== 'aktif') {
                throw new AppException(422, "Wali \"{$wali->nama}\" berstatus nonaktif.");
            }

            return $wali;
        }

        // Dibuat lewat WaliService, bukan Wali::create langsung: di sanalah
        // penyalinan kontak utama dan penjagaan telepon-kembar tinggal.
        return (new WaliService)->create($data['wali_baru']);
    }

    /**
     * Sahkan seluruh baris tagihan SEBELUM apa pun disimpan.
     *
     * @param  list<array<string,mixed>>  $tagihan
     * @return list<array{jenis:JenisBiaya,perilaku:?string,nominal:string,posting:string,jatuh_tempo:?string,keterangan:?string,tahun_ajaran:?string}>
     */
    private function periksaTagihan(array $tagihan): array
    {
        $siap = [];
        foreach ($tagihan as $i => $b) {
            $nomor = $i + 1;
            $posting = (string) ($b['posting'] ?? '');
            if (! in_array($posting, self::POSTING, true)) {
                throw new AppException(422, "Baris tagihan {$nomor}: perlakuan posting tidak dikenali.");
            }

            $jenis = JenisBiaya::find($b['kode_jenis'] ?? '');
            if (! $jenis || $jenis->status !== 'aktif') {
                throw new AppException(422, "Baris tagihan {$nomor}: jenis biaya tidak ditemukan atau sudah tidak aktif.");
            }

            $nominal = Money::of($b['nominal'] ?? 0);
            if (! Money::gtZero($nominal)) {
                throw new AppException(422, "Baris tagihan {$nomor}: nominal harus lebih besar dari nol.");
            }

            // Dua perlakuan yang membuat pembayarannya kelak mengkredit PIUTANG
            // menuntut akunnya ada. Ditolak di muka, bukan berbulan-bulan
            // kemudian di layar pembayaran — jauh dari orang yang membuatnya.
            if ($posting !== 'kas' && ! $jenis->kode_coa_piutang) {
                throw new AppException(422, "Baris tagihan {$nomor}: jenis biaya \"{$jenis->nama}\" belum punya akun piutang. "
                    .'Perlakuan Saldo Awal & Akrual selalu dibayar dengan mengkredit piutang, jadi akunnya harus ada dulu.');
            }
            if ($posting === 'akrual' && ! $jenis->kode_coa_pendapatan) {
                throw new AppException(422, "Baris tagihan {$nomor}: jenis biaya \"{$jenis->nama}\" belum punya akun pendapatan, "
                    .'sehingga jurnal akrualnya tak punya alamat.');
            }

            $siap[] = [
                'jenis' => $jenis,
                'perilaku' => TipeBiaya::perilakuDari($jenis->tipe),
                'nominal' => $nominal,
                'posting' => $posting,
                'jatuh_tempo' => ($b['jatuh_tempo'] ?? '') !== '' ? $b['jatuh_tempo'] : null,
                'keterangan' => ($b['keterangan'] ?? '') !== '' ? $b['keterangan'] : null,
                'tahun_ajaran' => ($b['tahun_ajaran'] ?? '') !== '' ? $b['tahun_ajaran'] : null,
            ];
        }

        return $siap;
    }

    /** @param  array<string,mixed>  $b baris yang sudah lolos periksaTagihan() */
    private function terbitkanTagihan(Santri $santri, array $b, int $idPengguna): TagihanSantri
    {
        $jenis = $b['jenis'];
        $akrual = $b['posting'] !== 'kas';

        $tagihan = TagihanSantri::create([
            'id_santri' => $santri->id,
            'kode_jenis' => $jenis->kode,
            'periode' => null,
            'perilaku' => $b['perilaku'],
            'kode_jenjang' => $santri->kode_jenjang,
            'tahun_ajaran' => $b['tahun_ajaran'] ?: $santri->tahun_ajaran,
            'nominal' => $b['nominal'],
            'sisa' => $b['nominal'],
            'status' => 'belum_bayar',
            // `saldo_awal` ditulis dulu sebagai false untuk perlakuan `akrual`:
            // jurnalnya terbit di bawah, dan barisnya memang BUKAN saldo awal.
            'sudah_akrual' => $b['posting'] === 'saldo_awal',
            'saldo_awal' => $b['posting'] === 'saldo_awal',
            'jatuh_tempo' => $b['jatuh_tempo'],
            'keterangan' => $b['keterangan'] ?? $jenis->nama,
        ]);

        // Akrual sekarang: jurnalnya diterbitkan lewat service yang sama dengan
        // yang dipakai aktivasi santri baru — beserta penjagaannya (pembayaran
        // yang menggantung, akun piutang yang kosong) dan penandaan
        // `sudah_akrual`-nya. Ditiru ulang di sini hanya akan melahirkan
        // sepasang aturan yang lambat laun berbeda.
        if ($b['posting'] === 'akrual') {
            (new SantriService)->akrualkanTagihan(
                $santri,
                [$tagihan->load('jenis')],
                $idPengguna,
                'input manual santri aktif',
            );
        }

        return $tagihan->refresh();
    }

    private function nomorBerikutnya(): string
    {
        $last = Santri::where('no_pendaftaran', 'like', 'LAMA-%')
            ->orderByDesc('no_pendaftaran')->value('no_pendaftaran');

        return DocNumber::nextDocNumber('LAMA-', $last);
    }
}
