<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\TerminFilterSetting;
use App\Services\Modules\TerminFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Setting filter termin jatuh tempo: parse pilihan, simpan, resolusi dalamHari. */
class TerminFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_tanpa_baris_db(): void
    {
        $svc = new TerminFilterService;
        $this->assertSame([7 => '≤ 7 hari + lewat', 14 => '≤ 14 hari + lewat', 30 => '≤ 30 hari + lewat', 0 => 'hanya yang lewat'], $svc->opsi());
        $this->assertSame(7, $svc->dalamHari(null));
    }

    public function test_simpan_normalisasi_dan_dipakai_opsi(): void
    {
        $svc = new TerminFilterService;
        $svc->simpan(['pilihan_hari' => ' 30, 3 ,3, 60 ', 'default_hari' => 60]);

        $s = TerminFilterSetting::find(1);
        $this->assertSame('3,30,60', $s->pilihan_hari);
        $this->assertSame(60, $s->default_hari);
        $this->assertSame([3, 30, 60, 0], array_keys($svc->opsi()));

        // Query valid dipakai; tak valid (tak ada di pilihan) jatuh ke default.
        $this->assertSame(3, $svc->dalamHari('3'));
        $this->assertSame(0, $svc->dalamHari('0'));
        $this->assertSame(60, $svc->dalamHari('14'));
        $this->assertSame(60, $svc->dalamHari('abc'));
    }

    public function test_default_harus_anggota_pilihan(): void
    {
        $svc = new TerminFilterService;
        $svc->simpan(['pilihan_hari' => '7,14', 'default_hari' => 0]); // 0 selalu sah

        $this->expectException(AppException::class);
        $svc->simpan(['pilihan_hari' => '7,14', 'default_hari' => 30]);
    }
}
