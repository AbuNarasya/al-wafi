<?php

namespace App\Http\Controllers;

use App\Models\Santri;
use App\Services\Modules\AbsensiAsramaService;
use App\Services\PortalWali\HistoriIse;
use App\Services\PortalWali\HistoriIseGagal;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * MENU AKADEMIK PORTAL WALI dari ISE (keputusan user 14 Nov 2026): Akademik · Absensi · Prestasi · Umpan Balik Guru ·
 * Kegiatan. Dibaca langsung dari API Histori ISE, tidak disimpan. Hanya santri AKTIF milik keluarga yang masuk.
 *
 * Setiap baris ISE dirapikan di sini menjadi bentuk seragam (judul, keterangan, waktu, lencana, nilai, isi) supaya
 * satu tampilan melayani semua area. Nama yang belum dikirim ISE (mis. dormitory_name) jatuh ke "Asrama #15" dan
 * otomatis berganti nama begitu ISE menyertakannya. Laporan & umpan balik berstatus `draft` TIDAK ditampilkan.
 */
class PortalAkademikController extends Controller
{
    /** menu => [judul, ikon, warna, [tab => [label, area, saringan|null, perHalaman]]] */
    public const MENU = [
        'akademik' => ['Akademik', 'akademik', '#1d4ed8', [
            'rapor' => ['Rapor', 'sertifikat', 'rapor', 100],
            'semester' => ['Nilai semester', 'nilai-semester', null, 20],
            'penilaian' => ['Penilaian', 'penilaian', null, 20],
            'ekskul' => ['Nilai ekskul', 'ekstrakurikuler-nilai', null, 20],
        ]],
        'absensi' => ['Absensi', 'absensi', '#15803d', [
            'harian' => ['Harian', 'harian', null, 20],
            'asrama' => ['Asrama', 'asrama', null, 30],
            'halaqah' => ['Halaqah', 'halaqah', null, 20],
            'ekskul' => ['Ekskul', 'ekstrakurikuler-absen', null, 20],
        ]],
        'prestasi' => ['Prestasi', 'prestasi', '#b45309', [
            'sertifikat' => ['Sertifikat & prestasi', 'sertifikat', 'prestasi', 100],
        ]],
        'umpan_balik' => ['Umpan Balik Guru', 'umpan_balik', '#7c3aed', [
            'guru' => ['Guru', 'feedback', 'final', 30],
            'musyrif' => ['Musyrif', 'laporan-asrama', 'final', 30],
        ]],
        'kegiatan' => ['Kegiatan', 'kegiatan', '#0e7490', [
            'kegiatan' => ['Kegiatan', 'event', null, 20],
        ]],
    ];

    /** Arti kategori/jenis umpan balik dari ISE → label wali. Nilai lain ditampilkan apa adanya. */
    private const JENIS_UMPAN_BALIK = ['positive' => 'Apresiasi', 'negative' => 'Perlu perhatian', 'neutral' => 'Catatan'];

    private const KATEGORI_UMPAN_BALIK = ['behavior' => 'Perilaku', 'academic' => 'Akademik', 'attendance' => 'Kehadiran',
        'discipline' => 'Kedisiplinan', 'social' => 'Sosial', 'health' => 'Kesehatan'];

    public function akademik(Request $r): View
    {
        return $this->tampil($r, 'akademik');
    }

    public function absensi(Request $r): View
    {
        return $this->tampil($r, 'absensi');
    }

    public function prestasi(Request $r): View
    {
        return $this->tampil($r, 'prestasi');
    }

    public function umpanBalik(Request $r): View
    {
        return $this->tampil($r, 'umpan_balik');
    }

    public function kegiatan(Request $r): View
    {
        return $this->tampil($r, 'kegiatan');
    }

    private function tampil(Request $r, string $menu): View
    {
        $wali = SesiWali::wali();
        abort_unless(HistoriIse::aktifUntuk($wali), 404);

        [$judul, $ikon, $warna, $tabs] = self::MENU[$menu];
        $tab = array_key_exists((string) $r->query('tab'), $tabs) ? (string) $r->query('tab') : array_key_first($tabs);
        [, $area, $saringan, $per] = $tabs[$tab];

        // Penjaga kepemilikan: hanya anak AKTIF keluarga ini; id dari alamat hanya memilih di antara mereka.
        $anak = Santri::where('id_wali', $wali->id)->where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'nis', 'id_wali', 'status']);
        $pilih = $anak->firstWhere('id', (int) $r->query('anak')) ?? $anak->first();
        $halaman = max(1, (int) $r->query('hal', 1));

        $hasil = null;
        $kamar = null;
        $gagal = false;
        if ($pilih) {
            $svc = new HistoriIse;
            try {
                $hasil = $svc->daftar($pilih, $area, $saringan ? 1 : $halaman, $per);
                $hasil['baris'] = $this->rapikan($area, $this->saring($hasil['baris'], $saringan), $pilih);
                if ($saringan) {
                    $hasil['halaman'] = $hasil['halaman_akhir'] = 1;
                }
                if ($area === 'asrama') {
                    $kamar = $this->kamar($svc->daftar($pilih, 'mutasi-asrama', 1, 10)['baris']);
                }
            } catch (HistoriIseGagal $e) {
                Log::warning('Histori ISE gagal dibaca', ['area' => $area, 'santri' => $pilih->id, 'pesan' => $e->getMessage()]);
                $gagal = true;
            }
        }

        return view('portal.akademik', compact('menu', 'judul', 'ikon', 'warna', 'tabs', 'tab', 'anak', 'pilih', 'hasil', 'kamar', 'gagal'));
    }

    /** @param list<array> $baris */
    private function saring(array $baris, ?string $saringan): array
    {
        $rapor = fn (array $b) => str_starts_with(mb_strtolower(trim((string) ($b['type_name'] ?? ''))), 'rapor');

        return array_values(match ($saringan) {
            'rapor' => array_filter($baris, $rapor),
            'prestasi' => array_filter($baris, fn ($b) => ! $rapor($b)),
            // Laporan musyrif & umpan balik guru yang masih draf belum untuk wali.
            'final' => array_filter($baris, fn ($b) => mb_strtolower((string) ($b['status'] ?? '')) !== 'draft'),
            default => $baris,
        });
    }

    /**
     * Bentuk seragam untuk tampilan.
     *
     * @return list<array{judul:string, keterangan:?string, waktu:?Carbon, hanya_tanggal:bool, lencana:list<array{0:string,1:string}>, nilai:?string, isi:?string}>
     */
    private function rapikan(string $area, array $baris, Santri $santri): array
    {
        $hasil = array_map(fn (array $b) => match ($area) {
            'harian' => [
                'judul' => __('Kehadiran sekolah'), 'keterangan' => null,
                'waktu' => $this->waktu($b['date'] ?? null), 'hanya_tanggal' => true,
                'lencana' => array_values(array_filter([
                    $this->lencana($b['morning_status'] ?? null, __('Pagi'), $b['morning_scanned_at'] ?? null),
                    $this->lencana($b['evening_status'] ?? null, __('Sore'), $b['evening_scanned_at'] ?? null),
                ])),
                'nilai' => null, 'isi' => null,
            ],
            'asrama' => [
                'judul' => $this->nama($b, 'attendance_type_name', 'attendance_type_id', __('Sesi')),
                'keterangan' => $this->nama($b, 'dormitory_name', 'dormitory_id', __('Asrama')),
                'waktu' => $this->waktu($this->gabung($b['attendance_date'] ?? null, $b['attendance_time'] ?? null)), 'hanya_tanggal' => false,
                'lencana' => array_values(array_filter([$this->lencana($b['status'] ?? null)])), 'nilai' => null, 'isi' => null,
            ],
            'ekstrakurikuler-absen' => [
                'judul' => $this->nama($b, 'extracurricular_name', 'extracurricular_id', __('Ekskul')), 'keterangan' => null,
                'waktu' => $this->waktu($this->gabung($b['attendance_date'] ?? null, $b['check_in_time'] ?? null)), 'hanya_tanggal' => false,
                'lencana' => array_values(array_filter([$this->lencana($b['status'] ?? null)])), 'nilai' => null, 'isi' => null,
            ],
            'ekstrakurikuler-nilai' => [
                'judul' => $this->nama($b, 'extracurricular_name', 'extracurricular_id', __('Ekskul')),
                'keterangan' => $this->nama($b, 'assessment_type_name', 'assessment_type_id', __('Penilaian')),
                'waktu' => $this->waktu($b['assessment_date'] ?? null), 'hanya_tanggal' => true, 'lencana' => [],
                'nilai' => $this->skor($b['score'] ?? null, $b['max_score'] ?? null), 'isi' => $this->teks($b['notes'] ?? null),
            ],
            'sertifikat' => [
                'judul' => (string) ($b['type_name'] ?? __('Sertifikat')), 'keterangan' => null,
                'waktu' => $this->waktu($b['issued_at'] ?? null), 'hanya_tanggal' => true, 'lencana' => [], 'nilai' => null, 'isi' => null,
            ],
            'feedback' => [
                'judul' => (string) ($b['title'] ?? __('Umpan balik')),
                'keterangan' => implode(' · ', array_filter([
                    filled($b['teacher_name'] ?? null) ? (string) $b['teacher_name'] : null,
                    filled($b['category'] ?? null) ? __(self::KATEGORI_UMPAN_BALIK[$b['category']] ?? ucfirst((string) $b['category'])) : null,
                ])) ?: null,
                'waktu' => $this->waktu($b['created_at'] ?? null), 'hanya_tanggal' => true,
                'lencana' => filled($b['type'] ?? null) ? [[__(self::JENIS_UMPAN_BALIK[$b['type']] ?? ucfirst((string) $b['type'])),
                    match ($b['type']) { 'positive' => 'hadir', 'negative' => 'terlambat', default => 'lainnya' }]] : [],
                'nilai' => null, 'isi' => $this->teks($b['content'] ?? null),
            ],
            'laporan-asrama' => [
                'judul' => __('Laporan asrama').(filled($b['semester'] ?? null) ? ' — '.$b['semester'] : ''),
                'keterangan' => $this->periode($b['date_from'] ?? null, $b['date_to'] ?? null),
                'waktu' => $this->waktu($b['report_date'] ?? null), 'hanya_tanggal' => true,
                'lencana' => filled($b['overall_grade_en'] ?? null) || filled($b['overall_grade_ar'] ?? null)
                    ? [[(string) (app()->getLocale() === 'ar' ? ($b['overall_grade_ar'] ?? $b['overall_grade_en']) : ($b['overall_grade_en'] ?? $b['overall_grade_ar'])), 'hadir']] : [],
                'nilai' => $this->skor($b['average_score'] ?? null, null), 'isi' => null,
            ],
            'event' => [
                'judul' => $this->nama($b, 'event_name', 'event_id', __('Kegiatan')),
                'keterangan' => filled($b['location'] ?? null) ? (string) $b['location'] : null,
                'waktu' => $this->waktu($b['check_in_at'] ?? $b['created_at'] ?? null), 'hanya_tanggal' => false,
                'lencana' => array_values(array_filter([$this->lencana($b['status'] ?? null)])), 'nilai' => null, 'isi' => null,
            ],
            // nilai-semester, penilaian, halaqah: belum ada contoh isi dari ISE (kosong pada santri yang dicoba) — bentuk umum.
            default => [
                'judul' => (string) ($b['subject_name'] ?? $b['subject'] ?? $b['name'] ?? $b['title'] ?? $b['halaqah_name'] ?? __('Catatan')),
                'keterangan' => filled($b['semester'] ?? null) ? (string) $b['semester'] : null,
                'waktu' => $this->waktu($b['attendance_date'] ?? $b['date'] ?? $b['assessment_date'] ?? $b['created_at'] ?? null),
                'hanya_tanggal' => true,
                'lencana' => array_values(array_filter([$this->lencana($b['status'] ?? null)])),
                'nilai' => $this->skor($b['final_score'] ?? $b['score'] ?? $b['grade'] ?? null, $b['max_score'] ?? null),
                'isi' => $this->teks($b['notes'] ?? $b['description'] ?? null),
            ],
        }, $baris);

        return $area === 'asrama' ? $this->terapkanIzin($hasil, $santri) : $hasil;
    }

    /** Tidak hadir di dalam Perizinan Insidental yang disetujui ERP → tampil "Izin" (sama seperti menu Kehadiran lama). */
    private function terapkanIzin(array $hasil, Santri $santri): array
    {
        $waktu = array_values(array_filter(array_column($hasil, 'waktu')));
        if ($waktu === []) {
            return $hasil;
        }
        $izin = AbsensiAsramaService::izinDalam($santri->id, min($waktu)->copy()->utc(), max($waktu)->copy()->utc());
        if ($izin->isEmpty()) {
            return $hasil;
        }

        return array_map(function (array $h) use ($izin) {
            if (! $h['waktu'] || ($h['lencana'][0][1] ?? null) !== 'tidak_hadir') {
                return $h;
            }
            $dalam = $izin->contains(fn ($z) => ($z->keluar_pada ?? $z->rencana_keluar)->lte($h['waktu'])
                && ($z->kembali_pada ?? $z->rencana_kembali)->gte($h['waktu']));
            if ($dalam) {
                $h['lencana'][0] = [__('Izin'), 'izin'];
            }

            return $h;
        }, $hasil);
    }

    /** Kamar sekarang = riwayat mutasi tanpa tanggal selesai. */
    private function kamar(array $mutasi): ?array
    {
        $riwayat = array_map(fn (array $m) => [
            'nama' => $this->nama($m, 'dormitory_name', 'dormitory_id', __('Asrama')),
            'mulai' => $this->waktu($m['start_date'] ?? null), 'selesai' => $this->waktu($m['end_date'] ?? null),
        ], $mutasi);
        if ($riwayat === []) {
            return null;
        }
        $kini = collect($riwayat)->first(fn ($k) => $k['selesai'] === null);

        return ['kini' => $kini, 'riwayat' => $riwayat];
    }

    /** [label, kategori] untuk status absen; kategori memakai pemetaan status ISE yang sama dengan menu Kehadiran lama. */
    private function lencana(?string $status, ?string $awalan = null, ?string $jam = null): ?array
    {
        if (! filled($status)) {
            return null;
        }
        $kategori = AbsensiAsramaService::kategori($status);
        $label = $kategori === 'lainnya' ? ucfirst((string) $status) : __(AbsensiAsramaService::LABEL_KATEGORI[$kategori]);
        $jamTeks = filled($jam) ? ' '.rescue(fn () => Carbon::parse($jam, 'Asia/Jakarta')->format('H:i'), '', false) : '';

        return [trim(($awalan ? $awalan.': ' : '').$label.$jamTeks), $kategori];
    }

    private function nama(array $b, string $kolomNama, string $kolomId, string $jenis): string
    {
        if (filled($b[$kolomNama] ?? null)) {
            return (string) $b[$kolomNama];
        }

        return filled($b[$kolomId] ?? null) ? $jenis.' #'.$b[$kolomId] : $jenis;
    }

    private function gabung(?string $tanggal, ?string $jam): ?string
    {
        return filled($tanggal) ? substr($tanggal, 0, 10).(filled($jam) ? ' '.$jam : '') : null;
    }

    /** Waktu ISE tersimpan sebagai waktu lokal (WIB) tanpa zona. */
    private function waktu(mixed $nilai): ?Carbon
    {
        return filled($nilai) ? rescue(fn () => Carbon::parse((string) $nilai, 'Asia/Jakarta'), null, false) : null;
    }

    private function periode(?string $dari, ?string $sampai): ?string
    {
        $a = $this->waktu($dari);
        $b = $this->waktu($sampai);

        return $a && $b ? $a->translatedFormat('j M Y').' – '.$b->translatedFormat('j M Y') : null;
    }

    private function skor(mixed $nilai, mixed $maks): ?string
    {
        if (! filled($nilai)) {
            return null;
        }
        $f = fn ($v) => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : (string) $v;

        return $f($nilai).(filled($maks) ? ' / '.$f($maks) : '');
    }

    private function teks(mixed $nilai): ?string
    {
        return filled($nilai) ? trim((string) $nilai) : null;
    }
}
