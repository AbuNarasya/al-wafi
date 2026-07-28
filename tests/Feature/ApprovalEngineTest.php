<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalInstance;
use App\Models\Bagian;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\User;
use App\Services\Modules\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Mesin approval bertingkat: rantai, eskalasi overbudget, tolak & ajukan ulang, dispatch. */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $svc;

    private int $staff;
    private int $mudir;
    private int $keuangan;

    protected function setUp(): void
    {
        parent::setUp();
        ApprovalService::resetRegistry();
        $this->svc = new ApprovalService;

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        LevelPengajuan::create(['peringkat' => 1, 'nama' => 'Ketua Yayasan']);
        LevelPengajuan::create(['peringkat' => 3, 'nama' => 'Mudir Bagian']);
        LevelPengajuan::create(['peringkat' => 4, 'nama' => 'Staff']);
        Bagian::create(['kode_bagian' => 'B1', 'nama_bagian' => 'Bagian 1', 'level' => 3]);

        $this->staff = User::create(['username' => 'staff', 'nama' => 'Staff', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 4])->id_pengguna;
        $this->mudir = User::create(['username' => 'mudir', 'nama' => 'Mudir', 'password_hash' => 'x', 'kode_level' => 'L1', 'kode_bagian' => 'B1', 'peringkat_pengajuan' => 3])->id_pengguna;
        $this->keuangan = User::create(['username' => 'keu', 'nama' => 'Keuangan', 'password_hash' => 'x', 'kode_level' => 'L1', 'tim_keuangan' => true])->id_pengguna;

        // Rantai: Mudir Bagian → [Ketua Yayasan bila overbudget] → Keuangan.
        $flow = ApprovalFlow::create(['kode_flow' => 'FTEST', 'nama_flow' => 'Test', 'jenis_dokumen' => 'TestDoc']);
        $flow->steps()->create(['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'scope' => 'bagian']);
        $flow->steps()->create(['urutan' => 2, 'nama_tahap' => 'Ketua Yayasan', 'peringkat' => 1, 'scope' => 'yayasan', 'syarat' => 'overbudget']);
        $flow->steps()->create(['urutan' => 3, 'nama_tahap' => 'Keuangan', 'fungsi' => 'keuangan', 'scope' => 'yayasan']);
    }

    private function submit(string $idDok, bool $overbudget = false): ApprovalInstance
    {
        return $this->svc->submit([
            'jenis_dokumen' => 'TestDoc', 'id_dokumen' => $idDok, 'id_pemohon' => $this->staff,
            'kode_bagian' => 'B1', 'nominal' => '1000000',
            'evaluasi' => ['overbudget' => $overbudget, 'belum_dianggarkan' => false],
        ]);
    }

    public function test_rantai_normal_lewati_tahap_overbudget(): void
    {
        $inst = $this->submit('D1');
        $this->assertSame(1, $inst->tahap_sekarang); // mulai di Mudir Bagian

        // Staff (peringkat 4) tak berwenang di tahap Mudir Bagian.
        try {
            $this->svc->approve($inst->id, $this->staff);
            $this->fail('harus 403');
        } catch (AppException $e) {
            $this->assertSame(403, $e->status);
        }

        // Mudir menyetujui → lompat tahap Ketua Yayasan (overbudget=false) ke Keuangan.
        $this->svc->approve($inst->id, $this->mudir);
        $this->assertSame(3, $inst->refresh()->tahap_sekarang);

        // Keuangan menyetujui → selesai (tanpa handler → tetap disetujui, posted false).
        $this->svc->approve($inst->id, $this->keuangan);
        $inst->refresh();
        $this->assertSame('disetujui', $inst->status);
        $this->assertFalse($inst->posted);
    }

    public function test_overbudget_menyertakan_tahap_ketua_yayasan(): void
    {
        $inst = $this->submit('D2', overbudget: true);
        $this->svc->approve($inst->id, $this->mudir);
        // overbudget → tahap Ketua Yayasan (urutan 2) TIDAK dilewati.
        $this->assertSame(2, $inst->refresh()->tahap_sekarang);
    }

    public function test_handler_dijalankan_dan_posted_true(): void
    {
        $dispatched = [];
        ApprovalService::daftarHandler('TestDoc', function ($idDok, $idUser) use (&$dispatched) {
            $dispatched[] = $idDok;
        });

        $inst = $this->submit('D3');
        $this->svc->approve($inst->id, $this->mudir);
        $inst = $this->svc->approve($inst->id, $this->keuangan);

        $this->assertSame(['D3'], $dispatched);
        $this->assertTrue($inst->posted);
    }

    public function test_tolak_lalu_ajukan_ulang(): void
    {
        $inst = $this->submit('D4');
        $this->svc->reject($inst->id, $this->mudir, 'kurang bukti');
        $this->assertSame('ditolak', $inst->refresh()->status);

        // Hanya pemohon (staff) yang boleh ajukan ulang.
        $out = $this->svc->ajukanUlang([
            'jenis_dokumen' => 'TestDoc', 'id_dokumen' => 'D4', 'id_pemohon' => $this->staff,
            'kode_bagian' => 'B1', 'nominal' => '1000000',
            'evaluasi' => ['overbudget' => false, 'belum_dianggarkan' => false],
        ]);
        $this->assertSame('berjalan', $out->status);
        $this->assertSame(1, $out->tahap_sekarang);
    }
}
