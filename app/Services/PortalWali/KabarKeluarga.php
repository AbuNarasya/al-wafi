<?php

namespace App\Services\PortalWali;

use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Support\Money;
use App\Support\Teks;

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
            'judul' => Teks::k(':nama: :label :nominal', ['nama' => $santri->nama, 'label' => Teks::k($label), 'nominal' => ($nominal < 0 ? '−' : '+').$rp($nominal)]),
            'pesan' => $m->keterangan
                ? Teks::k(':keterangan · Sisa uang saku :saldo', ['keterangan' => ltrim($m->keterangan), 'saldo' => $rp($m->saldo_setelah)])
                : Teks::k('Sisa uang saku :saldo', ['saldo' => $rp($m->saldo_setelah)]),
            'jenis' => 'mutasi_dompet', 'ref_jenis' => 'MutasiDompet', 'ref_id' => (string) $m->id, 'id_santri' => $santri->id,
        ]);
    }

    public static function pembayaranDiverifikasi(PembayaranSantri $p, TagihanSantri $t): void
    {
        $p->loadMissing('santri.wali');
        $t->loadMissing('jenis');
        $sisa = Money::of($t->sisa);
        KabarWali::keKeluarga($p->santri?->wali, [
            'judul' => Teks::k('Pembayaran diterima — :nama', ['nama' => $p->santri?->nama ?? Teks::k('santri')]),
            'pesan' => Teks::k(':tagihan :nominal sudah diverifikasi keuangan. :sisa', [
                'tagihan' => $t->jenis?->nama ?? Teks::k('Tagihan'),
                'nominal' => 'Rp '.number_format((float) $p->nominal, 0, ',', '.'),
                'sisa' => Money::isZero($sisa)
                    ? Teks::k('Tagihan ini LUNAS.')
                    : Teks::k('Sisa tagihan :sisa.', ['sisa' => 'Rp '.number_format((float) $sisa, 0, ',', '.')]),
            ]),
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
            $siapa = $santri?->nama ?? Teks::k('santri');
            $satu = $daftar->first();
            KabarWali::keKeluarga($santri?->wali, [
                'judul' => $daftar->count() === 1
                    ? Teks::k('Tagihan baru — :nama', ['nama' => $siapa])
                    : Teks::k(':jumlah tagihan baru — :nama', ['jumlah' => $daftar->count(), 'nama' => $siapa]),
                'pesan' => match (true) {
                    $daftar->count() > 1 => Teks::k(':daftar — total :total.', ['daftar' => $daftar->map($nama)->implode(', '), 'total' => $rp($total)]),
                    (bool) $satu->jatuh_tempo => Teks::k(':tagihan :nominal, jatuh tempo :tanggal.', [
                        'tagihan' => $nama($satu), 'nominal' => $rp($satu->sisa),
                        'tanggal' => \Illuminate\Support\Carbon::parse($satu->jatuh_tempo)->format('d/m/Y'),
                    ]),
                    default => Teks::k(':tagihan :nominal.', ['tagihan' => $nama($satu), 'nominal' => $rp($satu->sisa)]),
                },
                'jenis' => 'tagihan_terbit', 'ref_jenis' => 'TagihanSantri', 'ref_id' => (string) $daftar->first()->id, 'id_santri' => $santri?->id,
            ]);
        }
        TagihanSantri::whereIn('id', $baru->pluck('id'))->update(['dikabarkan_pada' => now()]);

        return $baru->count();
    }
}
