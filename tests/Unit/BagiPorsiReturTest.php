<?php

namespace Tests\Unit;

use App\Services\Modules\KasirService;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT 22 Okt 2026 — porsi diskon struk per baris dibagi dengan metode sisa terbesar: jumlahnya selalu sama
 * dengan yang dibayar, sehingga retur seluruh struk mengembalikan uang persis (dulu bisa meleset Rp 0,01).
 */
class BagiPorsiReturTest extends TestCase
{
    private function bagi(array $nilai, string $dibayar): array
    {
        $m = new \ReflectionMethod(KasirService::class, 'bagiPorsi');

        return $m->invoke(null, $nilai, $dibayar);
    }

    public function test_jumlah_porsi_sama_persis_dengan_yang_dibayar(): void
    {
        // 3 baris @ 10.000 diskon struk 1.000 → 29.000 / 3 = 9.666,666…
        $hasil = $this->bagi([11 => '10000.00', 12 => '10000.00', 13 => '10000.00'], '29000.00');
        $this->assertSame('29000.00', array_reduce($hasil, fn ($t, $v) => bcadd($t, $v, 2), '0'));
        $this->assertSame(['11' => '9666.67', '12' => '9666.67', '13' => '9666.66'], array_map('strval', $hasil) + []);
    }

    public function test_tanpa_diskon_struk_porsi_sama_dengan_nilai_baris(): void
    {
        $this->assertSame([1 => '1250.50', 2 => '3749.50'], $this->bagi([1 => '1250.50', 2 => '3749.50'], '5000.00'));
    }

    public function test_struk_bernilai_nol(): void
    {
        $this->assertSame([1 => '0.00'], $this->bagi([1 => '0.00'], '0.00'));
    }
}
