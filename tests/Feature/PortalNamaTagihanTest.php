<?php

namespace Tests\Feature;

use App\Http\Controllers\PortalKeluargaController as P;
use App\Models\JenisBiaya;
use App\Models\TagihanSantri;
use Tests\TestCase;

/**
 * Nama & keterangan tagihan di Portal Wali (laporan user 14 Okt 2026): keterangan
 * yang diketik petugas ("Tunggakan SPP 3 bulan") harus tampil, periode tampil untuk
 * semua jenis tagihan, catatan pembukuan sistem tetap tersembunyi.
 */
class PortalNamaTagihanTest extends TestCase
{
    private function tagihan(string $jenis, array $kolom): TagihanSantri
    {
        return (new TagihanSantri($kolom))->setRelation('jenis', new JenisBiaya(['nama' => $jenis]));
    }

    public function test_keterangan_petugas_tampil(): void
    {
        $t = $this->tagihan('SPP SMP', ['perilaku' => 'spp', 'keterangan' => 'Tunggakan SPP 3 BUlan', 'saldo_awal' => true]);

        $this->assertSame('SPP SMP', P::namaTagihan($t));
        $this->assertSame('Tunggakan SPP 3 BUlan', P::keteranganTagihan($t));
    }

    public function test_catatan_pembukuan_dan_keterangan_bawaan_disembunyikan(): void
    {
        foreach ([
            ['SPP SMP', 'SPP SMP 2026-11 · akrual SPP-2610-0002', '2026-11'],
            ['Uang Pangkal SMP', 'Uang Pangkal SMP T.A 2026/2027 tingkat 7 · akrual JU-0001', null],
            ['Registrasi SMP', 'Registrasi SMP', null],
            ['Excul Robotic', 'Excul Robotic', '2026-10'],
            ['SPP SMP', null, '2026-09'],
        ] as [$jenis, $ket, $periode]) {
            $this->assertNull(P::keteranganTagihan($this->tagihan($jenis, ['keterangan' => $ket, 'periode' => $periode])), (string) $ket);
        }
    }

    public function test_koreksi_dibuang_tapi_keterangan_aslinya_tetap(): void
    {
        $t = $this->tagihan('SPP SMP', ['keterangan' => 'Tunggakan Juli · koreksi KR-0001: salah ketik']);

        $this->assertSame('Tunggakan Juli', P::keteranganTagihan($t));
    }

    public function test_periode_tampil_untuk_semua_perilaku(): void
    {
        $lain = $this->tagihan('Excul Robotic', ['perilaku' => 'lain', 'keterangan' => 'Excul Robotic', 'periode' => '2026-10']);
        $this->assertSame('Excul Robotic · Oktober 2026', P::namaTagihan($lain));

        $teks = $this->tagihan('Study Tour', ['perilaku' => 'lain', 'keterangan' => 'Bus ke Bali', 'periode' => 'Semester Ganjil']);
        $this->assertSame('Study Tour · Semester Ganjil', P::namaTagihan($teks));
        $this->assertSame('Bus ke Bali', P::keteranganTagihan($teks));
    }
}
