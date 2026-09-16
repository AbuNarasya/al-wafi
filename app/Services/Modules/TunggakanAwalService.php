<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\PembayaranSantri;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * TUNGGAKAN AWAL — pintu manual per santri untuk saldo awal tunggakan.
 *
 * Pintu KEDUA, di samping Impor Data Awal. Keduanya menulis baris yang bentuknya
 * sama persis; yang membedakan hanya caranya masuk. Impor melayani pindahan
 * borongan, pintu ini melayani yang tak terjangkau impor: santri yang SUDAH ada
 * di aplikasi. `PemetaSantriLama` menciptakan santrinya sekaligus dan menolak NIS
 * yang sudah terpakai, jadi tanpa pintu ini tunggakan warisan seorang santri yang
 * telanjur masuk lewat PPSB tak punya jalan masuk sama sekali.
 *
 * TIDAK MENJURNAL — dan itu bukan kelalaian, melainkan aturan rumah yang berlaku
 * untuk seluruh jalur saldo awal (lihat routes/web.php pada Impor Data Awal):
 * buku besarnya masuk sekali lewat menu Saldo Awal, rinciannya lewat pintu-pintu
 * ini. Konsekuensinya wajib selalu disebutkan ke petugas: menambah di sini TIDAK
 * menaikkan piutang di neraca. Jurnal pembukanya harus disesuaikan sendiri; kalau
 * tidak, buku pembantu jadi lebih besar dari buku besar tanpa bersuara.
 *
 * `sudah_akrual = true` menjadikan pembayarannya kelak mengkredit PIUTANG, bukan
 * Pendapatan — kalau tidak, pendapatan lama akan diakui dua kali.
 *
 * SUNTING & HAPUS TANPA JURNAL, selama barisnya belum tersentuh apa pun. Itu sah
 * justru karena baris ini memang belum pernah menyentuh buku besar: tak ada yang
 * perlu dibalik. Begitu ada yang membayar, pintunya tertutup dan pembetulannya
 * kembali ke Koreksi Nominal Tagihan yang menerbitkan jurnal penyesuaian.
 */
class TunggakanAwalService
{
    /**
     * Tambahkan satu tunggakan warisan pada santri yang sudah ada.
     *
     * @param  array{kode_jenis:string,tahun_ajaran:string,nominal:string|int|float,keterangan:string,jatuh_tempo?:string|null}  $data
     */
    public function tambah(int $idSantri, array $data): TagihanSantri
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        $jenis = $this->jenisTerpakai($data['kode_jenis']);
        $perilaku = TipeBiaya::perilakuDari($jenis->tipe);
        $nominal = Money::of($data['nominal']);

        // Nol tak punya arti sebagai tunggakan warisan: yang tak menunggak cukup
        // tidak dibuatkan barisnya. Ia juga akan menempati slot indeks unik dan
        // memblokir tagihan sungguhan di tahun yang sama.
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal tunggakan harus lebih besar dari nol.');
        }

        // Jenjang & tahun ajaran adalah SNAPSHOT: jenjang diambil dari santrinya
        // saat ini, tahun ajaran dipilih petugas — tunggakan SPP 2024/2025 dicap
        // 2024/2025 supaya umurnya jujur di aging piutang, bukan tampak baru.
        $ta = (string) $data['tahun_ajaran'];
        $this->tolakBilaBentrok($santri, $perilaku, $ta);

        return TagihanSantri::create([
            'id_santri' => $santri->id,
            'kode_jenis' => $jenis->kode,
            // Periode dibiarkan kosong, sama seperti pintu impor: tunggakan
            // warisan adalah satu sisa yang menggumpal, bukan tagihan bulan
            // tertentu. Itu pula yang membuatnya tak bertabrakan dengan SPP
            // bulanan yang berjalan — periodenya berbeda.
            'periode' => null,
            'perilaku' => $perilaku,
            'kode_jenjang' => $santri->kode_jenjang,
            'tahun_ajaran' => $ta,
            'nominal' => $nominal,
            'sisa' => $nominal,
            'status' => 'belum_bayar',
            'sudah_akrual' => true,
            'saldo_awal' => true,
            'jatuh_tempo' => $data['jatuh_tempo'] ?? null,
            'keterangan' => $data['keterangan'],
        ]);
    }

    /**
     * Betulkan salah ketik — nominal, keterangan, jatuh tempo.
     *
     * Jenis biaya & tahun ajarannya TIDAK ikut bisa diubah. Keduanya menentukan
     * slot indeks unik, sehingga mengubahnya berarti memeriksa ulang tabrakan
     * pada slot yang baru. Yang salah jenisnya lebih jujur dihapus lalu dibuat
     * ulang — dan itu memang tersedia di sini.
     *
     * @param  array{nominal:string|int|float,keterangan:string,jatuh_tempo?:string|null}  $data
     */
    public function ubah(int $idTagihan, array $data): TagihanSantri
    {
        $tagihan = $this->tagihanTersunting($idTagihan);
        $nominal = Money::of($data['nominal']);

        if (! Money::gtZero($nominal)) {
            throw new AppException(422, 'Nominal tunggakan harus lebih besar dari nol. Untuk meniadakannya, hapus barisnya.');
        }

        // `sisa` ikut disamakan, bukan digeser sebesar selisihnya: pintu ini
        // hanya terbuka selama belum sepeser pun terbayar, jadi sisa memang
        // selalu sama dengan nominal.
        $tagihan->update([
            'nominal' => $nominal,
            'sisa' => $nominal,
            'keterangan' => $data['keterangan'],
            'jatuh_tempo' => $data['jatuh_tempo'] ?? null,
        ]);

        return $tagihan->refresh();
    }

    /** Buang barisnya. Tak ada jurnal yang perlu dibalik — memang tak pernah ada. */
    public function hapus(int $idTagihan): void
    {
        $tagihan = $this->tagihanTersunting($idTagihan);

        DB::transaction(fn () => $tagihan->delete());
    }

    /**
     * Alasan-alasan sebuah baris TIDAK lagi boleh disunting dari pintu ini.
     *
     * Kosong = boleh. Dipakai service untuk menolak, dan dipakai layar untuk
     * memutuskan apakah tombolnya ditampilkan — satu sumber, supaya tombol yang
     * terlihat dan tindakan yang diterima tak pernah berbeda pendapat.
     *
     * @return list<string>
     */
    public static function halangan(TagihanSantri $tagihan): array
    {
        if (! $tagihan->saldo_awal) {
            return ['Tagihan ini bukan tunggakan awal — ia terbit lewat penagihan biasa dan sudah berjurnal. '
                .'Pembetulannya lewat Koreksi Nominal Tagihan, supaya jurnal penyesuaiannya ikut terbit.'];
        }

        if (! $tagihan->berlaku()) {
            return ['Tagihan ini sudah tidak berlaku lagi.'];
        }

        $halangan = [];

        // Dua penjaga yang berbeda, dan keduanya perlu. Baris pembayaran menangkap
        // setoran yang sudah dicatat — termasuk yang MASIH menunggu verifikasi dan
        // karena itu belum mengurangi `sisa`. Selisih nominal−sisa menangkap uang
        // yang masuk TANPA meninggalkan baris pembayaran sama sekali: auto-debet
        // dompet dan SPP prabayar. Mengandalkan salah satunya saja akan
        // melewatkan separuh keadaan.
        if (($n = PembayaranSantri::where('id_tagihan', $tagihan->id)->count()) > 0) {
            $halangan[] = "Sudah ada {$n} pembayaran atas tagihan ini.";
        }

        if (! Money::eq($tagihan->nominal, $tagihan->sisa)) {
            $halangan[] = 'Sebagian tagihan ini sudah terbayar, jadi buku besar sudah ikut bergerak.';
        }

        if (RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->exists()) {
            $halangan[] = 'Tagihan ini sudah punya jadwal angsuran yang berjalan.';
        }

        return $halangan;
    }

    /** Jenis biaya yang sah dipakai sebagai tunggakan warisan. */
    private function jenisTerpakai(string $kode): JenisBiaya
    {
        $jenis = JenisBiaya::find($kode);
        if (! $jenis || $jenis->status !== 'aktif') {
            throw new AppException(422, 'Jenis biaya tidak ditemukan atau sudah tidak aktif.');
        }

        // Ditolak DI MUKA, bukan saat dibayar. Karena barisnya berakrual,
        // pembayarannya kelak mencari akun piutang jenis ini; kalau kosong,
        // kekeliruan ini baru meledak berbulan-bulan kemudian di layar
        // pembayaran — jauh dari orang yang membuatnya.
        if (! $jenis->kode_coa_piutang) {
            throw new AppException(422, 'Jenis biaya "'.$jenis->nama.'" belum punya akun piutang. '
                .'Tunggakan warisan selalu dibayar dengan mengkredit piutang, jadi akunnya harus ada dulu — '
                .'lengkapi di master Jenis Biaya.');
        }

        return $jenis;
    }

    /** Baris yang masih boleh disunting dari pintu ini, atau penolakan beralasan. */
    private function tagihanTersunting(int $idTagihan): TagihanSantri
    {
        $tagihan = TagihanSantri::find($idTagihan);
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }

        $halangan = self::halangan($tagihan);
        if ($halangan !== []) {
            throw new AppException(422, implode(' ', $halangan));
        }

        return $tagihan;
    }

    /**
     * Tabrakan indeks unik dijelaskan, bukan dibiarkan jadi SQLSTATE[23505].
     *
     * Perilaku sekali-per-tahun hanya boleh punya satu tagihan berlaku per
     * (santri, jenjang, T.A, periode). Petugas yang menabraknya perlu tahu baris
     * mana yang menghalangi — bukan nama sebuah indeks.
     */
    private function tolakBilaBentrok(Santri $santri, ?string $perilaku, string $ta): void
    {
        if ($perilaku === null || ! in_array($perilaku, TagihanSantri::SEKALI_PER_TA, true)) {
            return;
        }

        // `query()->berlaku()`, bukan `TagihanSantri::berlaku()`: model ini punya
        // scope `berlaku` DAN method instance `berlaku()` bernama sama, sehingga
        // pemanggilan statis jatuh ke yang instance dan melempar galat.
        $bentrok = TagihanSantri::query()->berlaku()
            ->where('id_santri', $santri->id)
            ->where('perilaku', $perilaku)
            ->whereRaw("COALESCE(kode_jenjang, '-') = ?", [$santri->kode_jenjang ?? '-'])
            ->whereRaw("COALESCE(tahun_ajaran, '-') = ?", [$ta])
            ->whereNull('periode')
            ->first();

        if ($bentrok) {
            throw new AppException(422, 'Santri ini sudah punya tagihan berperilaku "'.$perilaku.'" '
                .'untuk tahun ajaran '.$ta.' ('.Money::of($bentrok->nominal).', '.$bentrok->kode_jenis.'). '
                .'Perilaku ini hanya boleh sekali per tahun ajaran — koreksi nominal tagihan yang sudah ada, '
                .'atau pakai jenis biaya berperilaku Lain-lain untuk tunggakan warisannya.');
        }
    }
}
