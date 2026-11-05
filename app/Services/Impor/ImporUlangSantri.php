<?php

namespace App\Services\Impor;

use App\Exceptions\AppException;
use App\Models\ImporBatch;
use App\Services\Impor\Pemeta\PemetaSantriLama;
use App\Support\Audit\Jejak;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KOSONGKAN SELURUH SANTRI & WALI LALU IMPOR ULANG — dalam SATU transaksi (keputusan user 5 Nov 2026).
 *
 * Yang dibuang: semua santri (aktif, calon, keluar) dan semua wali beserta SELURUH yang menempel —
 * tagihan, pembayaran, dompet/tabungan/prabayar, VA Midtrans, tarik tunai, belanja kartu, laundry,
 * perizinan, klinik, kartu RFID, PIN, akun portal wali (password, perangkat, langganan push,
 * verifikasi) — dan JURNAL yang lahir dari modul santri (SUMBER_JURNAL). Data itu masih uji coba;
 * user memutuskan dihapus, bukan dibalik.
 *
 * Yang DIPERTAHANKAN: Kebijakan Khusus. Barisnya disalin sebelum santri dihapus, lalu ditulis ulang
 * dengan ID YANG SAMA (lampiran surat menunjuk id itu) dan id_santri baru yang dicari lewat NIS.
 * Bila ada satu saja yang tak tersambung — santrinya tak ber-NIS atau NIS-nya tak ada di berkas —
 * SELURUH proses dibatalkan.
 *
 * Master (jenjang, kelas, tarif, COA, pengguna, …) tak disentuh. Berkas unggahan di disk (dokumen
 * santri, foto laundry/penjemput) tak ikut dihapus.
 *
 * Tanpa `$sungguhan` seluruh langkah tetap dijalankan lalu DIBATALKAN (rollback) — uji-kering yang
 * memperlihatkan angka sebenarnya, termasuk hasil impornya.
 */
final class ImporUlangSantri
{
    /** sumber_modul jurnal yang lahir dari transaksi santri/wali. */
    public const SUMBER_JURNAL = [
        'PembayaranSantri', 'MutasiDompet', 'TagihanSpp', 'TagihanLain', 'KoreksiTagihan',
        'SesiTarikTunai', 'DompetToko', 'PencairanToko',
    ];

    /**
     * Tabel yang dikosongkan SELURUHNYA, berurutan supaya kunci asing RESTRICT/NO ACTION tak menolak:
     * anak lebih dulu, santri, lalu wali beserta akun portalnya.
     */
    public const TABEL = [
        'transaksi_midtrans_tagihan', 'transaksi_midtrans',
        'pengajuan_tarik_tabungan', 'pengajuan_tarik_tunai', 'peserta_tarik_tunai', 'sesi_tarik_tunai',
        'transaksi_dompet_toko', 'rekonsiliasi_dompet_toko', 'pencairan_toko',
        'mutasi_dompet', 'pembayaran_santri',
        'catatan_penagihan', 'koreksi_tagihan', 'termin_uang_pangkal', 'rencana_angsuran_uang_pangkal',
        'potongan_uang_pangkal', 'tagihan_laundry', 'batch_tagihan_baris', 'batch_tagihan', 'peserta_tagihan_lain',
        'tagihan_santri',
        'prabayar_spp', 'tabungan_santri', 'dompet_santri', 'dompet_wali',
        'obat_kunjungan_klinik', 'kunjungan_klinik', 'laundry_item', 'laundry', 'perizinan', 'kehadiran_ujian',
        'kartu_santri', 'pin_santri', 'dokumen_pengajuan_keluar', 'pengajuan_keluar', 'jadwal_perubahan_santri',
        'pendaftaran', 'persetujuan_term', 'status_berkas_santri', 'dokumen_santri', 'nis_santri', 'riwayat_tingkat',
        'notifikasi_wali', 'kebijakan_khusus',
        'santri',
        'bahasa_wali', 'perangkat_wali', 'pin_wali', 'preferensi_menu_wali', 'preferensi_notifikasi_wali',
        'push_langganan_wali', 'sandi_wali', 'verifikasi_wali', 'otp_wali',
        'wali',
    ];

    public function __construct(private ImporSaldoAwal $impor) {}

    /**
     * @param  array<string,string>  $param  parameter impor santri lama (tahun_ajaran_bawaan, jalur_bawaan, …)
     * @return array{sungguhan:bool,dihapus:array<string,int>,jurnal:array<string,int>,impor:array,kebijakan:int}
     */
    public function jalankan(string $path, array $param, bool $sungguhan, ?int $idPengguna = null, string $alasan = ''): array
    {
        $kunci = PemetaSantriLama::kunci();

        DB::beginTransaction();
        try {
            // 1. Salin Kebijakan Khusus beserta NIS santrinya — sebelum cascade menghapusnya.
            $kebijakan = DB::table('kebijakan_khusus as k')->leftJoin('santri as s', 's.id', '=', 'k.id_santri')
                ->select('k.*', 's.nis as nis_santri', 's.nama as nama_santri')->orderBy('k.id')->get();

            // 2. Hitung lalu hapus jurnal modul santri (baris jurnal ikut lewat cascade).
            $jurnal = DB::table('journal_entries')->whereIn('sumber_modul', self::SUMBER_JURNAL)
                ->selectRaw('sumber_modul, count(*) as n')->groupBy('sumber_modul')->pluck('n', 'sumber_modul')
                ->map(fn ($n) => (int) $n)->all();
            DB::table('journal_entries')->whereIn('sumber_modul', self::SUMBER_JURNAL)->delete();

            // 3. Kosongkan tabel santri & wali.
            $dihapus = [];
            foreach (self::TABEL as $t) {
                if (Schema::hasTable($t)) {
                    $dihapus[$t] = DB::table($t)->delete();
                }
            }

            // 4. Batch impor santri lama yang lama kini tak menunjuk apa pun — tandai dibatalkan.
            ImporBatch::where('kunci', $kunci)->whereNull('dibatalkan_pada')->update([
                'dibatalkan_pada' => now(), 'dibatalkan_oleh' => $idPengguna,
                'alasan_batal' => 'Dikosongkan untuk impor ulang'.($alasan !== '' ? ": {$alasan}" : '.'),
            ]);

            // 5. Berkasnya harus bersih seluruhnya: baris yang ditolak berarti santri yang hilang.
            $pratinjau = $this->impor->pratinjau($kunci, $path, $param);
            if ($pratinjau['kolom_hilang'] !== []) {
                throw new AppException(422, 'Kolom wajib tak ada di berkas: '.implode(', ', $pratinjau['kolom_hilang']).'.');
            }
            if ($pratinjau['masalah'] > 0 || $pratinjau['lewati'] > 0) {
                $rinci = collect($pratinjau['baris_masalah'])->map(fn ($m) => "  baris {$m['nomor']}: {$m['alasan']} ({$m['ringkas']})")->implode("\n");
                throw new AppException(422, "Berkas belum bersih: {$pratinjau['masalah']} baris bermasalah, {$pratinjau['lewati']} dilewati.\n{$rinci}");
            }
            $hasil = $this->impor->jalankan($kunci, $path, $param, $idPengguna);

            // 6. Sambungkan kembali Kebijakan Khusus lewat NIS, dengan id lamanya.
            $idPerNis = DB::table('santri')->whereNotNull('nis')->pluck('id', 'nis')->all();
            $yatim = $kebijakan->filter(fn ($k) => ! $k->nis_santri || ! isset($idPerNis[$k->nis_santri]));
            if ($yatim->isNotEmpty()) {
                $rinci = $yatim->map(fn ($k) => "  #{$k->id} {$k->nama_santri} (NIS ".($k->nis_santri ?: 'kosong').") — {$k->jenis} {$k->perilaku}, {$k->status}")->implode("\n");
                throw new AppException(422, "{$yatim->count()} Kebijakan Khusus tak bisa disambungkan ke santri di berkas:\n{$rinci}");
            }
            foreach ($kebijakan as $k) {
                $baris = (array) $k;
                unset($baris['nis_santri'], $baris['nama_santri']);
                $baris['id_santri'] = $idPerNis[$k->nis_santri];
                DB::table('kebijakan_khusus')->insert($baris);
            }

            $laporan = ['sungguhan' => $sungguhan, 'dihapus' => $dihapus, 'jurnal' => $jurnal,
                'impor' => $hasil, 'kebijakan' => $kebijakan->count()];

            if (! $sungguhan) {
                DB::rollBack();

                return $laporan;
            }

            Jejak::catat('impor_ulang_santri', ['modul' => 'impor-data-awal', 'id_pengguna' => $idPengguna,
                'ref_jenis' => 'impor_batch', 'ref_id' => $hasil['id_batch'],
                'detail' => ['alasan' => $alasan, 'berkas' => basename($path), 'santri' => $hasil['tersimpan']['santri'] ?? null,
                    'wali' => $hasil['tersimpan']['wali'] ?? null, 'kebijakan_disambung' => $kebijakan->count(),
                    'dihapus' => array_filter($dihapus), 'jurnal_dihapus' => $jurnal]]);
            DB::commit();

            return $laporan;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
