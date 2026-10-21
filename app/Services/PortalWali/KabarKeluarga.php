<?php

namespace App\Services\PortalWali;

use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Support\Money;

/**
 * KABAR MILIK KELUARGA untuk portal & push wali (keputusan user 21 Okt 2026) — dikirim ke setiap orang di keluarga
 * yang bisa masuk portal ([[KabarWali::keKeluarga]]), kategorinya mengikuti NotifikasiWali::JENIS_KATEGORI.
 *
 *  • mutasi dompet uang saku anak (kategori dompet) — dipicu model MutasiDompet SESUDAH transaksinya tersimpan;
 *  • pembayaran tagihan diverifikasi keuangan (kategori tagihan);
 *  • tagihan baru terbit (kategori tagihan) — sapuan tiap 5 menit, dikelompokkan per anak; tagihan dari IMPOR
 *    (id_batch) tak dikabarkan: itu pemindahan data lama, bukan tagihan baru.
 */
class KabarKeluarga
{
    private const LABEL_DOMPET = [
        'topup' => 'Uang saku masuk', 'distribusi_masuk' => 'Pindah saldo masuk', 'distribusi_keluar' => 'Pindah saldo keluar',
        'tabung_keluar' => 'Dipindah ke tabungan', 'bayar_tagihan' => 'Bayar tagihan', 'tarik' => 'Penarikan tunai',
    ];

    public static function mutasiDompet(MutasiDompet $m): void
    {
        if ($m->pemilik !== 'santri' || $m->status !== 'terverifikasi') {
            return;
        }
        $santri = DompetSantri::with('santri.wali')->find($m->id_dompet)?->santri;
        if (! $santri) {
            return;
        }
        $nominal = (float) $m->nominal;
        $label = $m->jenis === 'jajan' ? ($nominal < 0 ? 'Belanja' : 'Pengembalian belanja') : (self::LABEL_DOMPET[$m->jenis] ?? 'Mutasi uang saku');
        $rp = fn ($v) => 'Rp '.number_format(abs((float) $v), 0, ',', '.');

        KabarWali::keKeluarga($santri->wali, [
            'judul' => "{$santri->nama}: {$label} ".($nominal < 0 ? '−' : '+').$rp($nominal),
            'pesan' => trim(($m->keterangan ? $m->keterangan.' · ' : '').'Sisa uang saku '.$rp($m->saldo_setelah)),
            'jenis' => 'mutasi_dompet', 'ref_jenis' => 'MutasiDompet', 'ref_id' => (string) $m->id, 'id_santri' => $santri->id,
        ]);
    }

    public static function pembayaranDiverifikasi(PembayaranSantri $p, TagihanSantri $t): void
    {
        $p->loadMissing('santri.wali');
        $t->loadMissing('jenis');
        $sisa = Money::of($t->sisa);
        KabarWali::keKeluarga($p->santri?->wali, [
            'judul' => 'Pembayaran diterima — '.($p->santri?->nama ?? 'santri'),
            'pesan' => ($t->jenis?->nama ?? 'Tagihan').' Rp '.number_format((float) $p->nominal, 0, ',', '.').' sudah diverifikasi keuangan. '
                .(Money::isZero($sisa) ? 'Tagihan ini LUNAS.' : 'Sisa tagihan Rp '.number_format((float) $sisa, 0, ',', '.').'.'),
            'jenis' => 'pembayaran_santri_diverifikasi', 'ref_jenis' => 'PembayaranSantri', 'ref_id' => (string) $p->id, 'id_santri' => $p->id_santri,
        ]);
    }

    /** Sapuan tagihan baru: satu kabar per anak (bukan per tagihan). @return int jumlah tagihan yang dikabarkan */
    public static function sapuTagihanBaru(): int
    {
        $baru = TagihanSantri::query()->berlaku()->whereNull('dikabarkan_pada')->whereNull('id_batch')
            ->with(['santri.wali', 'jenis'])->orderBy('id')->limit(1000)->get();
        if ($baru->isEmpty()) {
            return 0;
        }
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        // Yang lahir tanpa sisa (bebas / langsung lunas) tak perlu dikabarkan, tetapi tetap ditandai di bawah.
        foreach ($baru->filter(fn ($t) => Money::gtZero($t->sisa))->groupBy('id_santri') as $daftar) {
            $santri = $daftar->first()->santri;
            $nama = fn ($t) => trim(($t->jenis?->nama ?? 'Tagihan').($t->periode ? ' '.$t->periode : ''));
            $total = $daftar->reduce(fn ($s, $t) => Money::add($s, $t->sisa), '0');
            KabarWali::keKeluarga($santri?->wali, [
                'judul' => ($daftar->count() === 1 ? 'Tagihan baru' : $daftar->count().' tagihan baru').' — '.($santri?->nama ?? 'santri'),
                'pesan' => $daftar->count() === 1
                    ? $nama($daftar->first()).' '.$rp($daftar->first()->sisa).($daftar->first()->jatuh_tempo ? ', jatuh tempo '.\Illuminate\Support\Carbon::parse($daftar->first()->jatuh_tempo)->format('d/m/Y') : '').'.'
                    : $daftar->map($nama)->implode(', ').' — total '.$rp($total).'.',
                'jenis' => 'tagihan_terbit', 'ref_jenis' => 'TagihanSantri', 'ref_id' => (string) $daftar->first()->id, 'id_santri' => $santri?->id,
            ]);
        }
        TagihanSantri::whereIn('id', $baru->pluck('id'))->update(['dikabarkan_pada' => now()]);

        return $baru->count();
    }
}
