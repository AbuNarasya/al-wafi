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
        foreach (self::sumber($user) as [$url, $jumlah, $label]) {
            if ($jumlah <= 0) {
                continue;
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
     * @return list<array{0:string,1:int,2:string}>
     */
    private static function sumber($user): array
    {
        $keuangan = $user->is_admin || $user->tim_keuangan;

        return [
            // Menu "Persetujuan Saya" terbuka untuk semua; isinya sendiri yang
            // sudah menyaring — inbox() hanya memuat tahap yang jadi giliran user.
            ['/approvals', (new ApprovalService)->inbox($user->id_pengguna)->count(), 'menunggu persetujuan Anda'],

            ['/ppsb/pembayaran', Akses::boleh('pembayaran-ppsb', 'ubah')
                ? self::pembayaranMenunggu(['registrasi', 'uang_pangkal', 'perlengkapan']) : 0, 'menunggu verifikasi'],

            ['/kesantrian/pembayaran', Akses::boleh('pembayaran-kesantrian', 'ubah')
                ? self::pembayaranMenunggu(['spp', 'lain']) : 0, 'menunggu verifikasi'],

            ['/pengajuan-pembayaran', $keuangan ? self::pengajuanMenungguKeuangan() : 0, 'menunggu verifikasi keuangan'],

            ['/kesantrian/dompet', $keuangan && Akses::boleh('dompet', 'ubah')
                ? MutasiDompet::where('status', 'menunggu_verifikasi')->count() : 0, 'top-up menunggu verifikasi'],

            ...self::jatuhTempo(),
        ];
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
        $modul = [
            // Label menyebut "mendekati/lewat" karena hitungannya memang memuat
            // yang BELUM jatuh tempo: jendelanya H-n dari pengaturan reminder
            // (mis. 7,3,1 → semua yang jatuh tempo ≤ 7 hari lagi ikut terhitung).
            // Menyebutnya "jatuh tempo" saja membuat orang menyangka ada yang
            // sudah telat padahal belum.
            '/ppsb/angsuran-uang-pangkal' => ['modul' => 'angsuran-uang-pangkal', 'jumlah' => 0, 'label' => 'angsuran mendekati/lewat jatuh tempo'],
            '/ppsb/pembayaran' => ['modul' => 'pembayaran-ppsb', 'jumlah' => 0, 'label' => 'tagihan mendekati/lewat jatuh tempo'],
            '/kesantrian/pembayaran' => ['modul' => 'pembayaran-kesantrian', 'jumlah' => 0, 'label' => 'tagihan mendekati/lewat jatuh tempo'],
            '/invoices' => ['modul' => 'invoices', 'jumlah' => 0, 'label' => 'invoice mendekati/lewat jatuh tempo'],
        ];

        foreach ((new ReminderTagihanService)->daftarMendekati() as $item) {
            $url = match ($item['sumber']) {
                'angsuran_uang_pangkal' => '/ppsb/angsuran-uang-pangkal',
                'invoice_vendor' => '/invoices',
                'tagihan_santri' => in_array($item['tipe'] ?? 'lain', ['registrasi', 'uang_pangkal', 'perlengkapan'], true)
                    ? '/ppsb/pembayaran' : '/kesantrian/pembayaran',
                default => null,
            };
            if ($url !== null) {
                $modul[$url]['jumlah']++;
            }
        }

        $hasil = [];
        foreach ($modul as $url => $m) {
            $hasil[] = [$url, Akses::bolehMenu($m['modul']) ? $m['jumlah'] : 0, $m['label']];
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

    /** Jumlah pekerjaan menunggu pada satu menu (0 bila tak ada). */
    public static function untukUrl(string $url): int
    {
        return self::semua()[$url]['jumlah'] ?? 0;
    }

    /** Label pendek pekerjaan pada satu menu, mis. "menunggu verifikasi". */
    public static function labelUrl(string $url): ?string
    {
        return self::semua()[$url]['label'] ?? null;
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
    }
}
