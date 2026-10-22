<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\DompetWali;
use App\Models\Wali;
use App\Support\SaldoAtomik;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AUDIT 22 Okt 2026 — saldo dompet diubah atomik di database (bukan baca-hitung-tulis),
 * pengurangan yang melampaui saldo ditolak, dan CHECK saldo >= 0 menjaga jalur lain.
 */
class SaldoAtomikTest extends TestCase
{
    use RefreshDatabase;

    private function dompet(string $saldo): DompetWali
    {
        $w = Wali::create(['kontak_utama' => 'ayah', 'nama_ayah' => 'Ayah', 'telepon_ayah' => '081200000001', 'nama' => 'Ayah', 'telepon' => '081200000001', 'status' => 'aktif']);

        return DompetWali::create(['id_wali' => $w->id, 'saldo' => $saldo]);
    }

    public function test_menggeser_dari_saldo_database_bukan_salinan_model_yang_basi(): void
    {
        $d = $this->dompet('100000');
        $basi = DompetWali::find($d->id);          // dibaca sebelum mutasi lain
        SaldoAtomik::kurangi($d, '30000');          // "transaksi lain"

        $this->assertSame('20000.00', SaldoAtomik::kurangi($basi, '50000'));
        $this->assertSame('20000.00', (string) DompetWali::find($d->id)->saldo, 'tak ada potongan yang hilang');
    }

    public function test_pengurangan_melampaui_saldo_ditolak_tanpa_mengubah_apa_pun(): void
    {
        $d = $this->dompet('10000');
        try {
            SaldoAtomik::kurangi($d, '10000.01', 'Saldo Dompet Wali');
            $this->fail('seharusnya ditolak');
        } catch (AppException $e) {
            $this->assertStringContainsString('Saldo Dompet Wali tidak cukup', $e->getMessage());
        }
        $this->assertSame('10000.00', (string) DompetWali::find($d->id)->saldo);
    }

    public function test_database_menolak_saldo_minus_dari_jalur_mana_pun(): void
    {
        $d = $this->dompet('5000');
        $this->expectException(QueryException::class);
        DB::table('dompet_wali')->where('id', $d->id)->update(['saldo' => -1]);
    }
}
