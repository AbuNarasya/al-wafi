<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\SetoranPemakaian;
use App\Models\TagihanSantri;
use App\Models\TarifPemakaian;
use App\Models\TipeBiaya;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KELUARGA A — tagihan yang lahir dari PEMAKAIAN (laundry per kilogram).
 *
 * Alurnya: petugas mencatat tiap setoran → aplikasi menjumlahkan → di akhir
 * periode kelebihan di atas kuota dikalikan tarif satuan lalu diterbitkan.
 *
 * Yang TIDAK ada di sini, dan itu disengaja: penjadwalan. Tak ada angka yang
 * bisa diterbitkan sebelum ada yang menimbang, jadi penerbitannya selalu
 * ditekan orang. Yang bisa diotomatiskan cuma daftarnya, bukan uangnya.
 */
class PemakaianLainService
{
    /** Jenis biaya yang ditagih menurut pemakaian. */
    public function jenisPemakaian()
    {
        return JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('lain'))
            ->where('cara_tagih', 'pemakaian')
            ->orderBy('nama')->get();
    }

    public function jenis(string $kode): JenisBiaya
    {
        $jenis = JenisBiaya::find($kode);
        if (! $jenis || $jenis->cara_tagih !== 'pemakaian') {
            throw new AppException(404, 'Layanan tidak ditemukan, atau jenis biayanya tidak ditagih menurut pemakaian.');
        }

        return $jenis;
    }

    /**
     * Besarannya — dan ia TIDAK di master jenis biaya.
     *
     * Dipisahkan mengikuti pembagian yang sudah berlaku di sini: master itu
     * identitas akuntansi, besarannya tinggal di tabelnya sendiri. Selama
     * barisnya belum ada, mencatat timbangan pun ditolak: kuantitas yang tak
     * bisa dihargai hanya menumpuk jadi pekerjaan yang harus diulang.
     */
    public function tarif(JenisBiaya $jenis): TarifPemakaian
    {
        $tarif = TarifPemakaian::where('kode_jenis', $jenis->kode)->first();
        if (! $tarif || ! Money::gtZero(Money::of($tarif->tarif_satuan))) {
            throw new AppException(422, "\"{$jenis->nama}\" belum punya tarif per satuan, jadi pemakaiannya tak bisa dihitung. Isi dulu di Matriks Tarif Layanan.");
        }

        return $tarif;
    }

    /**
     * Matriks tarif layanan: satu baris per layanan bersatuan.
     *
     * Bukan matriks per jenjang seperti tarif kegiatan — jenis pemakaian sudah
     * berjenjang sendiri (Laundry SMP, Laundry SMA), jadi yang berbeda tiap
     * baris adalah satuan dan kuotanya, bukan jenjangnya.
     *
     * @return list<array<string,mixed>>
     */
    public function gridTarif(): array
    {
        $tarif = TarifPemakaian::get()->keyBy('kode_jenis');

        return $this->jenisPemakaian()->map(fn ($jb) => [
            'kode' => $jb->kode,
            'nama' => $jb->nama,
            'kode_jenjang' => $jb->kode_jenjang,
            'status' => $jb->status,
            'tarif_satuan' => $tarif[$jb->kode]->tarif_satuan ?? null,
            'nama_satuan' => $tarif[$jb->kode]->nama_satuan ?? null,
            'kuota_gratis' => $tarif[$jb->kode]->kuota_gratis ?? null,
        ])->all();
    }

    /**
     * Simpan seluruh matriks sekaligus.
     *
     * Tarif yang dikosongkan MENGHAPUS barisnya — bukan disimpan sebagai nol.
     * Nol berarti layanan gratis yang tetap dicatat pemakaiannya; tak ada baris
     * berarti besarannya belum diatur, dan pencatatan setorannya ditolak.
     *
     * @param  array<string,array<string,string|null>>  $baris
     * @return array{tersimpan:int,dihapus:int}
     */
    public function simpanGridTarif(array $baris): array
    {
        $sah = $this->jenisPemakaian()->pluck('kode')->flip();
        $tersimpan = $dihapus = 0;

        DB::transaction(function () use ($baris, $sah, &$tersimpan, &$dihapus) {
            foreach ($baris as $kode => $isi) {
                if (! isset($sah[$kode])) {
                    continue;
                }

                $tarif = trim((string) ($isi['tarif_satuan'] ?? ''));
                if ($tarif === '') {
                    $dihapus += TarifPemakaian::where('kode_jenis', $kode)->delete();

                    continue;
                }

                $kuota = trim((string) ($isi['kuota_gratis'] ?? ''));
                TarifPemakaian::updateOrCreate(['kode_jenis' => $kode], [
                    'tarif_satuan' => Money::of($tarif),
                    // Satuan tak boleh kosong bila tarifnya terisi — layar yang
                    // menulis "12,5" tanpa satuan tak memberi tahu apa pun.
                    'nama_satuan' => trim((string) ($isi['nama_satuan'] ?? '')) ?: 'satuan',
                    'kuota_gratis' => $kuota === '' ? null : Money::of($kuota),
                ]);
                $tersimpan++;
            }
        });

        return ['tersimpan' => $tersimpan, 'dihapus' => $dihapus];
    }

    /**
     * Catat satu setoran.
     *
     * Jenjang santri WAJIB cocok dengan jenjang layanannya: dua baris Laundry
     * (SMP dan SMA) punya kuota sendiri-sendiri, jadi mencatat santri SMA ke
     * baris SMP akan menggerus jatah yang bukan miliknya tanpa terlihat.
     */
    public function catat(array $data, int $idPengguna): SetoranPemakaian
    {
        $jenis = $this->jenis($data['kode_jenis']);
        $this->tarif($jenis);

        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }
        if ($santri->status !== 'aktif') {
            throw new AppException(422, "{$santri->nama} sudah tidak berstatus aktif.");
        }
        if ($jenis->kode_jenjang && $santri->kode_jenjang !== $jenis->kode_jenjang) {
            throw new AppException(422, "{$santri->nama} bukan santri jenjang layanan ini. Pakai baris layanan yang sesuai jenjangnya.");
        }

        $kuantitas = Money::of($data['kuantitas']);
        if (! Money::gtZero($kuantitas)) {
            throw new AppException(422, 'Kuantitas harus lebih dari nol.');
        }

        return SetoranPemakaian::create([
            'kode_jenis' => $jenis->kode,
            'id_santri' => $santri->id,
            'tanggal' => $data['tanggal'],
            'kuantitas' => $kuantitas,
            'catatan' => $data['catatan'] ?? null,
            'dicatat_oleh' => $idPengguna,
        ]);
    }

    public function hapusSetoran(int $id): SetoranPemakaian
    {
        $s = SetoranPemakaian::find($id);
        if (! $s) {
            throw new AppException(404, 'Setoran tidak ditemukan.');
        }
        if ($s->id_tagihan !== null) {
            throw new AppException(422, 'Setoran ini sudah ikut tertagih, jadi tak bisa dihapus. Koreksi lewat Koreksi Nominal Tagihan.');
        }
        $s->delete();

        return $s;
    }

    /**
     * Rekap berjalan: berapa yang sudah terpakai, berapa sisa kuotanya.
     *
     * Ditampilkan saat mencatat, bukan hanya saat menagih. Tanpa ini petugas
     * baru tahu ada santri yang melewati kuota pada akhir bulan — ketika tak ada
     * lagi yang bisa dilakukan selain menagih.
     *
     * @return list<array{santri:Santri,kuantitas:string,kena_tagih:string,nominal:string,sisa_kuota:string}>
     */
    public function rekap(string $kodeJenis, ?string $sampai = null): array
    {
        $jenis = $this->jenis($kodeJenis);
        $tarif = $this->tarif($jenis);
        $kuota = Money::of($tarif->kuota_gratis ?? '0');

        $baris = SetoranPemakaian::belumTertagih()
            ->where('kode_jenis', $kodeJenis)
            ->when($sampai, fn ($q) => $q->whereDate('tanggal', '<=', $sampai))
            ->with('santri:id,nis,nama,kode_jenjang,status')
            ->get()
            ->groupBy('id_santri');

        $hasil = [];
        foreach ($baris as $kumpulan) {
            $total = $kumpulan->reduce(fn ($t, $s) => Money::add($t, $s->kuantitas), '0');
            $kenaTagih = Money::gt($total, $kuota) ? Money::sub($total, $kuota) : '0';

            $hasil[] = [
                'santri' => $kumpulan->first()->santri,
                'kuantitas' => $total,
                'kena_tagih' => $kenaTagih,
                'nominal' => Money::mul($kenaTagih, $tarif->tarif_satuan),
                'sisa_kuota' => Money::gt($kuota, $total) ? Money::sub($kuota, $total) : '0',
                'jumlah_setoran' => $kumpulan->count(),
            ];
        }

        usort($hasil, fn ($a, $b) => strcmp((string) $a['santri']?->nama, (string) $b['santri']?->nama));

        return $hasil;
    }

    /**
     * Terbitkan tagihan atas pemakaian yang belum tertagih.
     *
     * Yang disapu adalah setoran BELUM BERTANDA sampai tanggal akhir periode —
     * bukan hanya yang bertanggal di dalam periodenya. Dengan begitu setoran
     * yang telat dicatat ikut terbawa, dan tak ada timbangan yang menguap hanya
     * karena petugasnya terlambat sehari.
     *
     * @return array<string,mixed>
     */
    public function terbitkan(array $data, int $idPengguna): array
    {
        $jenis = $this->jenis($data['kode_jenis']);
        $sampai = Carbon::parse($data['periode'].'-01')->endOfMonth()->toDateString();

        $rekap = $this->rekap($jenis->kode, $sampai);
        if ($rekap === []) {
            throw new AppException(422, 'Belum ada setoran yang bisa ditagih untuk periode itu.');
        }

        $nominal = [];
        $dibawahKuota = [];
        foreach ($rekap as $r) {
            if ($r['santri']?->status !== 'aktif') {
                continue;
            }
            if (! Money::gtZero($r['nominal'])) {
                // Masih di bawah kuota ⇒ TIDAK diterbitkan tagihan sama sekali.
                // Setorannya sengaja dibiarkan tak bertanda supaya ikut terhitung
                // pada periode berikutnya — kuota berlaku per periode penagihan,
                // dan yang belum pernah ditagih belum pernah memakai kuotanya.
                $dibawahKuota[] = $r['santri']?->nama;

                continue;
            }
            $nominal[$r['santri']->id] = $r['nominal'];
        }

        if ($nominal === []) {
            throw new AppException(422, 'Tidak ada yang melewati kuota pada periode ini, jadi tak ada tagihan yang perlu terbit.');
        }

        return DB::transaction(function () use ($jenis, $nominal, $data, $idPengguna, $sampai, $dibawahKuota) {
            $hasil = (new TagihanLainService)->terbitkanUntukPemakaian($jenis, $nominal, $data, $idPengguna);

            // Setoran yang barusan tertagih ditandai dengan tagihannya masing-
            // masing, supaya tak pernah tersapu dua kali.
            $tagihan = TagihanSantri::where('kode_jenis', $jenis->kode)
                ->where('periode', $data['periode'] ?? null)
                ->whereIn('id_santri', array_keys($nominal))->pluck('id', 'id_santri');

            foreach ($tagihan as $idSantri => $idTagihan) {
                SetoranPemakaian::belumTertagih()
                    ->where('kode_jenis', $jenis->kode)->where('id_santri', $idSantri)
                    ->whereDate('tanggal', '<=', $sampai)
                    ->update(['id_tagihan' => $idTagihan]);
            }

            return array_merge($hasil, ['di_bawah_kuota' => $dibawahKuota]);
        });
    }
}
