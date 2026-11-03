<?php

namespace App\Services\PortalWali;

use App\Models\DompetSantri;
use App\Models\MutasiDompet;
use App\Models\NotifikasiWali;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Support\Money;
use App\Support\Teks;
use Illuminate\Support\Carbon;

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

    /**
     * PENGINGAT JATUH TEMPO untuk wali (keputusan user 3 Nov 2026) — ikut jadwal & titik H-n di pengaturan
     * Reminder Tagihan (bawaan H-7, H-3, H-1), ditambah SATU kabar sesudah lewat jatuh tempo.
     * Satu kabar per anak; tiap (tagihan, titik) dikirim sekali — catatannya di ref_id kabar itu sendiri
     * ("12:h3,15:lewat"), jadi tanpa tabel baru. Tagihan yang dicicil lewat rencana angsuran dilewati:
     * jatuh temponya mengikuti termin.
     *
     * @param  list<int>  $hari  titik H-n
     * @return int jumlah kabar (per anak) yang dibuat
     */
    public static function pengingatJatuhTempo(array $hari): int
    {
        $hari = $hari === [] ? [7, 3, 1] : $hari;
        $kini = now()->startOfDay();
        $rows = TagihanSantri::query()->berlaku()
            ->whereIn('status', ['belum_bayar', 'sebagian'])->where('sisa', '>', 0)
            ->whereNotNull('jatuh_tempo')->whereDate('jatuh_tempo', '<=', $kini->copy()->addDays(max($hari)))
            ->whereDoesntHave('rencanaAngsuran', fn ($q) => $q->where('status', 'aktif'))
            ->with(['santri.wali', 'jenis'])->orderBy('jatuh_tempo')->get();

        $calon = collect();
        foreach ($rows as $t) {
            if (! $t->santri?->wali) {
                continue;
            }
            $sisaHari = (int) $kini->diffInDays(Carbon::parse($t->jatuh_tempo)->startOfDay(), false);
            // Titik terdekat yang sudah tercapai (H-3 hari ini → "h3"); lewat jatuh tempo → "lewat".
            $titik = $sisaHari < 0 ? 'lewat' : 'h'.min(array_filter($hari, fn ($h) => $sisaHari <= $h));
            $calon->push(['t' => $t, 'kunci' => "{$t->id}:{$titik}", 'sisa_hari' => $sisaHari]);
        }
        if ($calon->isEmpty()) {
            return 0;
        }

        // Kunci yang sudah pernah dikabarkan (ref_id kabar pengingat 120 hari terakhir).
        $sudah = [];
        NotifikasiWali::where('jenis', 'pengingat_tagihan')->where('created_at', '>=', now()->subDays(120))
            ->whereIn('id_wali', $calon->map(fn ($c) => $c['t']->santri->id_wali)->unique()->values())
            ->pluck('ref_id')->each(function ($ref) use (&$sudah) {
                foreach (explode(',', (string) $ref) as $k) {
                    $sudah[$k] = true;
                }
            });

        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $nama = fn ($t) => trim(($t->jenis?->nama ?? 'Tagihan').($t->periode ? ' '.$t->periode : ''));
        $dibuat = 0;
        // Per anak; potongan 15 supaya daftar kunci muat di ref_id (varchar 255).
        foreach ($calon->reject(fn ($c) => isset($sudah[$c['kunci']]))->groupBy(fn ($c) => $c['t']->id_santri) as $perAnak) {
            foreach ($perAnak->chunk(15) as $daftar) {
                $santri = $daftar->first()['t']->santri;
                $baris = $daftar->values()->map(fn ($c) => match (true) {
                    $c['sisa_hari'] < 0 => Teks::k(':tagihan :nominal, lewat jatuh tempo :tanggal', [
                        'tagihan' => $nama($c['t']), 'nominal' => $rp($c['t']->sisa), 'tanggal' => Carbon::parse($c['t']->jatuh_tempo)->format('d/m/Y'),
                    ]),
                    $c['sisa_hari'] === 0 => Teks::k(':tagihan :nominal, jatuh tempo hari ini', ['tagihan' => $nama($c['t']), 'nominal' => $rp($c['t']->sisa)]),
                    default => Teks::k(':tagihan :nominal, jatuh tempo :tanggal (:hari hari lagi)', [
                        'tagihan' => $nama($c['t']), 'nominal' => $rp($c['t']->sisa),
                        'tanggal' => Carbon::parse($c['t']->jatuh_tempo)->format('d/m/Y'), 'hari' => $c['sisa_hari'],
                    ]),
                });
                // Daftar sepanjang apa pun: kunci rakitan ":b0; :b1; …" tak ada di lang/*.json, jadi __() hanya
                // mengisi placeholder-nya — tiap baris (Teks) tetap diterjemahkan sendiri-sendiri.
                $pesan = Teks::k(
                    $baris->keys()->map(fn ($i) => ":b{$i}")->implode('; ').'.',
                    $baris->mapWithKeys(fn ($b, $i) => ["b{$i}" => $b])->all(),
                );
                KabarWali::keKeluarga($santri->wali, [
                    'judul' => $daftar->contains(fn ($c) => $c['sisa_hari'] < 0)
                        ? Teks::k('Tagihan lewat jatuh tempo — :nama', ['nama' => $santri->nama])
                        : Teks::k('Pengingat jatuh tempo tagihan — :nama', ['nama' => $santri->nama]),
                    'pesan' => $pesan,
                    'jenis' => 'pengingat_tagihan', 'ref_jenis' => 'PengingatTagihan',
                    'ref_id' => $daftar->pluck('kunci')->implode(','), 'id_santri' => $santri->id,
                ]);
                $dibuat++;
            }
        }

        return $dibuat;
    }
}
