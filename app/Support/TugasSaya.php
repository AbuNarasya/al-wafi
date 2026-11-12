<?php

namespace App\Support;

use App\Models\ApprovalInstance;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\PengajuanPembayaran;
use App\Services\Modules\ApprovalService;
use App\Services\Modules\PengajuanPembayaranService;
use App\Services\Modules\ReminderTagihanService;
use Illuminate\Support\Facades\Auth;

/**
 * PEKERJAAN YANG MENUNGGU PENGGUNA LOGIN — sumber tunggal angka notifikasi
 * (badge sidebar, penanda judul halaman, dan bagian "Tugas" di lonceng).
 *
 * Dihitung dari KEADAAN NYATA tiap modul, bukan dari tabel `notifications`.
 * Bedanya penting: baris notifikasi adalah catatan PERISTIWA yang tak pernah
 * berubah — ia tetap berbunyi "menunggu verifikasi" walau dokumennya sudah
 * lama diverifikasi (di basis data ini pernah ada 10 baris seperti itu). Angka
 * yang dihitung dari keadaan akan sembuh sendiri: muncul selama pekerjaannya
 * ada, hilang begitu diproses siapa pun, tanpa perlu ditandai manual. Inilah
 * yang memenuhi syarat "tanda tetap muncul sebelum diproses".
 *
 * Tiap sumber DISARING hak akses, jadi angka yang tampil selalu berarti "ini
 * pekerjaan saya" — bukan pekerjaan orang lain yang kebetulan terlihat.
 *
 * Hasilnya di-memo per request: sidebar, judul, dan lonceng memakai angka yang
 * sama persis tanpa mengulang query.
 */
final class TugasSaya
{
    /** @var array<string,array{url:string,jumlah:int,label:string,rincian:list<array{jumlah:int,label:string}>}>|null */
    private static ?array $memo = null;

    /** @var list<array{url:string,jumlah:int,label:string,bidang:string,tautan:string}> */
    private static array $baris = [];

    /** Menu hub: angkanya = SELURUH pekerjaan yang punya pintasan di sana (lihat untukUrl). */
    public const HUB = '/approvals';

    /**
     * Semua pekerjaan menunggu, dikunci per URL menu (0 tidak ikut).
     *
     * @return array<string,array{url:string,jumlah:int,label:string,rincian:list<array{jumlah:int,label:string}>}>
     */
    public static function semua(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $user = Auth::user();
        if (! $user || $user->status !== 'aktif') {
            return self::$memo = [];
        }

        $hasil = [];
        self::$baris = [];
        foreach ([...self::sumber($user), ...TugasPersetujuan::sumber($user)] as $b) {
            [$url, $jumlah, $label] = $b;
            if ($jumlah <= 0) {
                continue;
            }
            // Baris mentah (tak digabung per URL) untuk pintasan di Persetujuan Saya.
            if (($b[3] ?? null) !== null) {
                self::$baris[] = ['url' => $url, 'jumlah' => $jumlah, 'label' => $label, 'bidang' => $b[3], 'tautan' => $b[4] ?? $url];
            }
            // Satu modul bisa punya dua macam pekerjaan sekaligus — mis. halaman
            // Pembayaran PPSB memuat setoran menunggu verifikasi DAN tagihan yang
            // jatuh tempo. Keduanya DIJUMLAHKAN, bukan saling menimpa.
            if (isset($hasil[$url])) {
                $hasil[$url]['jumlah'] += $jumlah;
                $hasil[$url]['rincian'][] = ['jumlah' => $jumlah, 'label' => $label];
                $hasil[$url]['label'] = implode(' & ', array_unique(array_column($hasil[$url]['rincian'], 'label')));

                continue;
            }
            $hasil[$url] = [
                'url' => $url, 'jumlah' => $jumlah, 'label' => $label,
                'rincian' => [['jumlah' => $jumlah, 'label' => $label]],
            ];
        }

        return self::$memo = $hasil;
    }

    /**
     * Baris ke-4 = BIDANG di Persetujuan Saya (null = tak dipintas di sana: kotak persetujuan keuangan
     * sendiri & tagihan jatuh tempo yang bukan keputusan). Baris ke-5 = tautan bila beda dari URL menu.
     * Sumber tambahan (pengunduran diri, persediaan, laundry, …) ada di TugasPersetujuan.
     *
     * @return list<array{0:string,1:int,2:string,3?:?string,4?:string}>
     */
    private static function sumber($user): array
    {
        $keuangan = $user->is_admin || $user->tim_keuangan;
        [$K, $S, $W] = [TugasPersetujuan::KEUANGAN, TugasPersetujuan::KESANTRIAN, TugasPersetujuan::PERSEDIAAN];

        return [
            // Menu "Persetujuan Saya" terbuka untuk semua; isinya sendiri yang
            // sudah menyaring — inbox() hanya memuat tahap yang jadi giliran user.
            [self::HUB, (new ApprovalService)->inbox($user->id_pengguna)->count(), 'menunggu persetujuan Anda', null],

            ['/ppsb/pembayaran', Akses::boleh('pembayaran-ppsb', 'ubah')
                ? self::pembayaranMenunggu(['registrasi', 'uang_pangkal', 'perlengkapan']) : 0, 'pembayaran PPSB menunggu verifikasi', $K],

            ['/kesantrian/pembayaran', Akses::boleh('pembayaran-kesantrian', 'ubah')
                ? self::pembayaranMenunggu(['spp', 'daftar_ulang', 'lain']) : 0, 'pembayaran SPP & lainnya menunggu verifikasi', $K],

            ['/pengajuan-pembayaran', $keuangan ? self::pengajuanMenungguKeuangan() : 0, 'pengajuan pembayaran menunggu verifikasi keuangan', $K],

            ['/kesantrian/dompet', $keuangan && Akses::boleh('dompet', 'ubah')
                ? MutasiDompet::where('status', 'menunggu_verifikasi')->count() : 0, 'top-up menunggu verifikasi', $K],

            // Penarikan tunai kegiatan: kesantrian memutus pengajuan; keuangan mengambil dana
            // (sesudah batas persetujuan) lalu membagikan & menutup sesi.
            ['/kesantrian/tarik-tunai', Akses::boleh('penarikan-tunai', 'ubah')
                ? \App\Models\PengajuanTarikTunai::where('status', 'diajukan')
                    ->whereHas('sesi', fn ($q) => $q->where('status', 'berjalan')->where('batas_persetujuan', '>=', now()))->count() : 0,
                'pengajuan uang saku kegiatan menunggu', $S],

            ['/tarik-tunai', Akses::boleh('sesi-tarik-tunai', 'ubah')
                ? \App\Models\SesiTarikTunai::where(fn ($q) => $q->where('status', 'dana_diambil')
                    ->orWhere(fn ($w) => $w->where('status', 'berjalan')->where('batas_persetujuan', '<', now())))->count() : 0,
                'sesi tarik tunai menunggu diambil/dibagikan', $K],

            // Perizinan Insidental yang menunggu keputusan tingkat pengguna ini.
            ['/kesantrian/perizinan', \App\Services\Modules\PerizinanService::jumlahMenungguSaya(), 'pengajuan izin menunggu persetujuan', $S],
            ['/kesantrian/perizinan-rutin', \App\Services\Modules\PerizinanService::jumlahMenungguSaya(rutin: true), 'pengajuan penjengukan/kepulangan menunggu', $S],
            ['/kesantrian/perizinan-rutin/jadwal', Akses::boleh('perizinan-jadwal', 'ubah')
                ? \App\Services\Modules\PerizinanRutinService::jadwalBulanDepanBelumAda() : 0, 'jenis perizinan rutin belum berjadwal bulan depan', $S],

            // Janji bayar wali yang sudah lewat sementara tagihannya belum lunas — saatnya menagih lagi.
            ['/kesantrian/penagihan-tunggakan', Akses::boleh('penagihan-tunggakan', 'buat')
                ? \App\Services\Modules\PenagihanTunggakanService::jumlahJanjiLewat() : 0, 'santri janji bayarnya sudah lewat', $K],

            // ---- Wafi Mart & pencairan antar-entitas (10 Nov 2026) ----
            // Akses::boleh sudah menyaring per instans: modulnya mati di instans yang tak memakainya.
            ['/mitra-kantin', Akses::boleh('mitra-kantin', 'ubah')
                ? \App\Models\PenarikanMitra::where('status', 'diajukan')->count() : 0, 'penarikan saldo mitra menunggu diproses', $W],
            ['/penerimaan-al-wafi', Akses::boleh('penerimaan-al-wafi', 'buat')
                ? count(\App\Services\Modules\KabarKantin::pencairanMenunggu()) : 0, 'pencairan dari Al Wafi belum dicatat diterima', $W],
            ['/rekonsiliasi-kartu', Akses::boleh('rekonsiliasi-kartu', 'ubah')
                ? \App\Models\RekonsiliasiDompetToko::where('status', 'perlu_diputus')->whereNull('ditangani_pada')->count() : 0,
                'tanggal rekonsiliasi kartu perlu diputus', $W],
            ...self::pencairanTerlambat(),

            ...self::jatuhTempo(),
        ];
    }

    /** ERP Al Wafi: belanja kartu yang lewat batas hari belum dicairkan ke Wafi Mart — satu tugas. */
    private static function pencairanTerlambat(): array
    {
        if (! Akses::boleh('pencairan-toko', 'buat') || ! ($t = \App\Services\Modules\PencairanTokoService::terlambat())) {
            return [];
        }

        return [['/pencairan-toko', 1, 'belanja kartu Rp '.number_format((float) $t['total'], 0, ',', '.')
            .' belum dicairkan ke Wafi Mart sejak '.\Illuminate\Support\Carbon::parse($t['sejak'])->format('d/m/Y'), TugasPersetujuan::KEUANGAN]];
    }

    /**
     * Tagihan jatuh tempo, DIPECAH ke modul tempat pekerjaannya dikerjakan —
     * bukan ke halaman "Reminder Tagihan Jatuh Tempo".
     *
     * Halaman reminder itu SETTING (mengatur kapan & ke siapa pengingat dikirim);
     * menaruh penandanya di sana membuat orang mencari pekerjaan di menu setelan.
     * Angsuran yang jatuh tempo ditagih dari modul Angsuran Uang Pangkal, tagihan
     * santri dari modul pembayaran yang sesuai tipenya, invoice dari Invoice Vendor.
     *
     * Jendela hari & sumber yang dipantau tetap diambil dari pengaturan reminder
     * (satu tempat pengaturan), tetapi saklar "Aktifkan reminder OTOMATIS" sengaja
     * TIDAK dipakai di sini: mematikan pengiriman otomatis bukan berarti tagihannya
     * berhenti jatuh tempo.
     *
     * @return list<array{0:string,1:int,2:string}>
     */
    private static function jatuhTempo(): array
    {
        // Label menyebut "mendekati/lewat" karena hitungannya memang memuat yang
        // BELUM jatuh tempo: jendelanya H-n dari pengaturan reminder (mis. 7,3,1
        // → semua yang jatuh tempo ≤ 7 hari lagi ikut terhitung). Menyebutnya
        // "jatuh tempo" saja membuat orang menyangka ada yang sudah telat
        // padahal belum.
        //
        // Tujuannya BERLAPIS, dicoba berurutan sampai ada yang boleh dibuka
        // pengguna ini. Yang pertama selalu DAFTAR TUNGGAKAN — layar yang
        // menyebut siapa & berapa; yang kedua layar pencatatan pembayaran
        // seperti dulu.
        //
        // Berlapis, bukan sekadar diganti, karena `outstanding-lain` modul baru
        // yang belum dicentangi siapa pun: mengarahkan penanda ke sana tanpa
        // cadangan akan MENGHILANGKAN notifikasinya dari layar semua orang
        // sampai hak itu dibagikan — memperbaiki kebingungan dengan cara
        // membuat pekerjaannya tak terlihat sama sekali.
        $tujuan = [
            'angsuran' => [
                'label' => 'angsuran mendekati/lewat jatuh tempo',
                'lapis' => [['/ppsb/angsuran-uang-pangkal', 'angsuran-uang-pangkal']],
            ],
            'ppsb' => [
                'label' => 'tagihan mendekati/lewat jatuh tempo',
                'lapis' => [['/ppsb/pembayaran', 'pembayaran-ppsb']],
            ],
            'spp' => [
                'label' => 'tagihan SPP mendekati/lewat jatuh tempo',
                'lapis' => [
                    ['/kesantrian/outstanding-spp', 'outstanding-spp'],
                    ['/kesantrian/pembayaran', 'pembayaran-kesantrian'],
                ],
            ],
            'lain' => [
                'label' => 'tagihan lain-lain & daftar ulang mendekati/lewat jatuh tempo',
                'lapis' => [
                    ['/kesantrian/outstanding-lain', 'outstanding-lain'],
                    ['/kesantrian/pembayaran', 'pembayaran-kesantrian'],
                ],
            ],
            'invoice' => [
                'label' => 'invoice mendekati/lewat jatuh tempo',
                'lapis' => [['/invoices', 'invoices']],
            ],
        ];

        $jumlah = array_fill_keys(array_keys($tujuan), 0);

        foreach ((new ReminderTagihanService)->daftarMendekati() as $item) {
            $kunci = match ($item['sumber']) {
                'angsuran_uang_pangkal' => 'angsuran',
                'invoice_vendor' => 'invoice',
                // SPP dipisah dari lain-lain & daftar ulang: ketiganya ditangani
                // modul pembayaran yang sama, tetapi daftar tunggakannya dua layar
                // yang berbeda.
                'tagihan_santri' => match ($item['tipe'] ?? 'lain') {
                    'registrasi', 'uang_pangkal', 'perlengkapan' => 'ppsb',
                    'spp' => 'spp',
                    default => 'lain',
                },
                default => null,
            };
            if ($kunci !== null) {
                $jumlah[$kunci]++;
            }
        }

        $hasil = [];
        foreach ($tujuan as $kunci => $t) {
            foreach ($t['lapis'] as [$url, $modul]) {
                if (Akses::bolehMenu($modul)) {
                    $hasil[] = [$url, $jumlah[$kunci], $t['label']];

                    continue 2;
                }
            }
            // Tak satu pun tujuannya boleh dibuka → pekerjaan ini milik orang lain.
            $hasil[] = [$t['lapis'][0][0], 0, $t['label']];
        }

        return $hasil;
    }

    /** @param  list<string>  $tipe */
    /** @param  list<string>  $perilaku registrasi|uang_pangkal|spp|lain */
    private static function pembayaranMenunggu(array $perilaku): int
    {
        return PembayaranSantri::where('status', 'menunggu_verifikasi')
            ->whereHas('tagihan.jenis', fn ($q) => $q->whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku(...$perilaku)))
            ->count();
    }

    /**
     * Pengajuan yang rantai approval-nya SUDAH selesai tetapi belum diverifikasi
     * keuangan — aturannya sama dengan kolom "Menunggu di" pada daftar pengajuan.
     */
    private static function pengajuanMenungguKeuangan(): int
    {
        $siap = ApprovalInstance::where('jenis_dokumen', PengajuanPembayaranService::SUMBER)
            ->where('status', 'disetujui')->where('posted', false)
            ->pluck('id_dokumen')->all();

        if ($siap === []) {
            return 0;
        }

        return PengajuanPembayaran::whereIn('id', $siap)->where('status', 'diajukan')->count();
    }

    /**
     * Jumlah pekerjaan menunggu pada satu menu (0 bila tak ada). Menu hub (Persetujuan Saya) menjumlah
     * kotak persetujuannya + SEMUA pintasan di sana — sengaja TIDAK lewat semua(), supaya total() (lonceng,
     * ikon aplikasi) tak menghitung pekerjaan yang sama dua kali.
     */
    public static function untukUrl(string $url): int
    {
        if ($url === self::HUB) {
            return (self::semua()[self::HUB]['jumlah'] ?? 0) + array_sum(array_column(self::persetujuan(), 'jumlah'));
        }

        return self::semua()[$url]['jumlah'] ?? 0;
    }

    /** Label pendek pekerjaan pada satu menu, mis. "menunggu verifikasi". */
    public static function labelUrl(string $url): ?string
    {
        if ($url === self::HUB) {
            return self::untukUrl(self::HUB) > 0 ? 'menunggu tindakan Anda' : null;
        }

        return self::semua()[$url]['label'] ?? null;
    }

    /**
     * Pintasan di Persetujuan Saya: tiap pekerjaan (tak digabung per menu), urut bidang lalu terbanyak.
     *
     * @return list<array{url:string,jumlah:int,label:string,bidang:string,tautan:string}>
     */
    public static function persetujuan(): array
    {
        self::semua();
        $urutan = array_flip(TugasPersetujuan::URUTAN);
        $baris = self::$baris;
        usort($baris, fn ($a, $b) => [$urutan[$a['bidang']] ?? 99, $b['jumlah']] <=> [$urutan[$b['bidang']] ?? 99, $a['jumlah']]);

        return $baris;
    }

    public static function total(): int
    {
        return array_sum(array_column(self::semua(), 'jumlah'));
    }

    /**
     * Untuk lonceng: daftar pekerjaan + nama menunya, terbanyak dulu.
     *
     * @return list<array{url:string,jumlah:int,label:string,menu:string}>
     */
    public static function daftar(): array
    {
        $baris = array_map(
            fn ($t) => $t + ['menu' => Navigation::labelUrl($t['url']) ?? $t['url']],
            array_values(self::semua()),
        );
        usort($baris, fn ($a, $b) => $b['jumlah'] <=> $a['jumlah']);

        return $baris;
    }

    /** Buang memo (dipakai test yang mengubah data di tengah request). */
    public static function lupakan(): void
    {
        self::$memo = null;
        self::$baris = [];
    }
}
