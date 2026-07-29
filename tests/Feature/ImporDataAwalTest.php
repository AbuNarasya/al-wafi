<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\HakAksesModul;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\JournalEntry;
use App\Models\Karyawan;
use App\Models\Level;
use App\Models\Pendaftaran;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TahunAjaran;
use App\Models\TipeBiaya;
use App\Models\User;
use App\Models\Wali;
use App\Services\Impor\ImporSaldoAwal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Impor Data Awal — pemeta "Santri Lama".
 *
 * Yang dijaga test ini bukan sekadar "barisnya masuk", melainkan bahwa impor
 * TIDAK mengarang angka: tanpa tagihan registrasi, tanpa jurnal apa pun, dan
 * tunggakannya bertanda sudah diakrualkan supaya pembayaran nanti mengkredit
 * piutang, bukan pendapatan untuk kedua kalinya.
 */
class ImporDataAwalTest extends TestCase
{
    use RefreshDatabase;

    private const TA = '2026/2027';
    private const GRP = 'ZZIM';

    private User $admin;
    private string $jenisTunggakan = 'TUNGGAKAN-SPP';

    protected function setUp(): void
    {
        parent::setUp();
        TipeBiaya::lupakan();

        Level::create(['kode_level' => 'L1', 'nama_level' => 'L1', 'max_transaksi' => null]);
        $this->admin = User::create([
            'username' => 'zzim_admin', 'nama' => 'Admin Impor', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => true, 'status' => 'aktif',
        ]);

        Jenjang::create(['kode' => 'SMP', 'nama' => 'SMP']);
        TahunAjaran::create(['kode' => self::TA, 'nama' => 'TA Uji']);
        JalurPendaftaran::create(['kode' => 'LAMA', 'nama' => 'Santri Lama', 'status' => 'aktif']);

        BusinessUnit::create(['kode_unit' => 'ZZUNIT', 'nama_unit' => 'Unit']);
        CoaGroup::create(['kode_grup' => self::GRP, 'nama_grup' => 'Impor Uji']);
        CoaDetail::create(['kode_coa' => '4.ZZIM.1', 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '1.ZZIM.1', 'nama_coa' => 'Piutang Santri', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);

        // Tipe "lain" sudah ditanam migrasi sebagai baris bawaan — jangan dibuat ulang.
        TipeBiaya::firstOrCreate(
            ['kode' => 'lain'],
            ['nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4, 'bawaan' => true, 'status' => 'aktif'],
        );
        JenisBiaya::create([
            'kode' => $this->jenisTunggakan, 'nama' => 'Tunggakan SPP (saldo awal)', 'tipe' => 'lain',
            'tahun_ajaran' => self::TA, 'nominal' => 0, 'kode_coa_pendapatan' => '4.ZZIM.1',
            'kode_coa_piutang' => '1.ZZIM.1', 'kode_unit' => 'ZZUNIT', 'status' => 'aktif',
        ]);
    }

    private function csv(array $baris): string
    {
        $kolom = ['nis', 'nama', 'jenis_kelamin', 'kode_jenjang', 'tahun_ajaran', 'jalur', 'wali_nama', 'wali_telepon', 'tunggakan_spp', 'ket_tunggakan_spp'];
        $isi = implode(',', $kolom)."\n";
        foreach ($baris as $b) {
            $isi .= implode(',', array_map(fn ($k) => $b[$k] ?? '', $kolom))."\n";
        }

        return $isi;
    }

    private function berkas(array $baris): string
    {
        $path = sys_get_temp_dir().'/impor-uji-'.uniqid().'.csv';
        file_put_contents($path, $this->csv($baris));

        return $path;
    }

    private function param(): array
    {
        return ['jenis_tunggakan_spp' => $this->jenisTunggakan, 'jenis_tunggakan_uang_pangkal' => ''];
    }

    private function barisSah(array $ganti = []): array
    {
        return array_merge([
            'nis' => '230015', 'nama' => 'Ahmad Fauzi', 'jenis_kelamin' => 'L',
            'kode_jenjang' => 'SMP', 'tahun_ajaran' => self::TA, 'jalur' => 'LAMA',
            'wali_nama' => 'Bapak Fauzi', 'wali_telepon' => '08123456789',
        ], $ganti);
    }

    public function test_santri_lama_masuk_sebagai_aktif_tanpa_registrasi_dan_tanpa_jurnal(): void
    {
        $path = $this->berkas([$this->barisSah()]);
        $hasil = app(ImporSaldoAwal::class)->jalankan('santri-lama', $path, $this->param());

        $this->assertSame(['santri' => 1, 'wali' => 1, 'tagihan' => 0], $hasil['tersimpan']);

        $s = Santri::firstOrFail();
        $this->assertSame('aktif', $s->status);
        $this->assertSame('230015', $s->nis);            // NIS asli dipertahankan
        $this->assertSame('LAMA-0001', $s->no_pendaftaran);
        $this->assertNull($s->gelombang);                 // tak pernah kena potongan gelombang

        // Tak ada jejak jalur PPSB, dan buku besar tetap kosong.
        $this->assertSame(0, Pendaftaran::count());
        $this->assertSame(0, TagihanSantri::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_kakak_beradik_menempel_ke_satu_wali(): void
    {
        $path = $this->berkas([
            $this->barisSah(),
            $this->barisSah(['nis' => '230016', 'nama' => 'Aisyah Fauziah', 'jenis_kelamin' => 'P']),
        ]);
        $hasil = app(ImporSaldoAwal::class)->jalankan('santri-lama', $path, $this->param());

        $this->assertSame(2, $hasil['tersimpan']['santri']);
        $this->assertSame(1, $hasil['tersimpan']['wali']);
        $this->assertSame(1, Wali::count());
        $this->assertSame(1, Santri::distinct('id_wali')->count('id_wali'));
        $this->assertSame(['LAMA-0001', 'LAMA-0002'], Santri::orderBy('id')->pluck('no_pendaftaran')->all());
    }

    public function test_tunggakan_bertanda_sudah_akrual_dan_tidak_menjurnal(): void
    {
        $path = $this->berkas([$this->barisSah(['tunggakan_spp' => '1500000', 'ket_tunggakan_spp' => 'Tunggakan Jan-Jun'])]);
        app(ImporSaldoAwal::class)->jalankan('santri-lama', $path, $this->param());

        $t = TagihanSantri::firstOrFail();
        $this->assertSame(1500000.0, (float) $t->nominal);
        $this->assertSame(1500000.0, (float) $t->sisa);
        $this->assertTrue((bool) $t->sudah_akrual, 'tunggakan lama WAJIB sudah_akrual agar pembayaran mengkredit piutang');
        $this->assertSame('Tunggakan Jan-Jun', $t->keterangan);

        // Nilainya sudah diakui di catatan lama → tak boleh ada jurnal dari sini.
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_pratinjau_menandai_masalah_dan_tidak_menulis_apa_pun(): void
    {
        $path = $this->berkas([
            $this->barisSah(),                                            // siap
            $this->barisSah(['nis' => '230017', 'kode_jenjang' => 'SMA']), // jenjang tak ada
            $this->barisSah(['nis' => '230018', 'jenis_kelamin' => 'X']),  // kelamin salah
            $this->barisSah(['nis' => '', 'nama' => 'Tanpa NIS']),         // NIS kosong
            $this->barisSah(['nis' => '230019', 'jalur' => 'NGAWUR']),     // jalur tak dikenal
            $this->barisSah(['nis' => '230020', 'tunggakan_spp' => 'abc']),// bukan angka
        ]);

        $hasil = app(ImporSaldoAwal::class)->pratinjau('santri-lama', $path, $this->param());

        $this->assertSame(6, $hasil['jumlah']);
        $this->assertSame(1, $hasil['siap']);
        $this->assertSame(5, $hasil['masalah']);
        $this->assertSame(0, Santri::count(), 'pratinjau tidak boleh menulis apa pun');

        // Nomor baris mengikuti Excel: baris 1 judul, jadi data pertama = 2.
        $this->assertSame(3, $hasil['baris_masalah'][0]['nomor']);
        $this->assertStringContainsString('Jenjang', $hasil['baris_masalah'][0]['alasan']);
        $this->assertStringContainsString('Jalur "NGAWUR"', collect($hasil['baris_masalah'])->pluck('alasan')->join(' | '));
    }

    public function test_berkas_sama_boleh_diunggah_ulang_baris_lama_dilewati(): void
    {
        $path = $this->berkas([$this->barisSah(), $this->barisSah(['nis' => '230016', 'nama' => 'Dua'])]);
        app(ImporSaldoAwal::class)->jalankan('santri-lama', $path, $this->param());

        // Berkas yang SAMA dijalankan lagi: tak ada yang dobel.
        $ulang = app(ImporSaldoAwal::class)->pratinjau('santri-lama', $path, $this->param());
        $this->assertSame(0, $ulang['siap']);
        $this->assertSame(2, $ulang['lewati']);
        $this->assertSame(2, Santri::count());
    }

    public function test_tunggakan_ditolak_bila_jenis_biayanya_belum_dipilih(): void
    {
        $path = $this->berkas([$this->barisSah(['tunggakan_spp' => '500000'])]);
        $hasil = app(ImporSaldoAwal::class)->pratinjau('santri-lama', $path, ['jenis_tunggakan_spp' => '', 'jenis_tunggakan_uang_pangkal' => '']);

        $this->assertSame(1, $hasil['masalah']);
        $this->assertStringContainsString('belum dipilih', $hasil['baris_masalah'][0]['alasan']);
    }

    public function test_pemisah_titik_koma_ikut_terbaca(): void
    {
        $path = sys_get_temp_dir().'/impor-uji-'.uniqid().'.csv';
        file_put_contents($path, "\xEF\xBB\xBFnis;nama;jenis_kelamin;kode_jenjang;tahun_ajaran;jalur;wali_nama;wali_telepon\n"
            ."230021;Excel Indonesia;L;SMP;".self::TA.";LAMA;Wali Excel;08999\n");

        $hasil = app(ImporSaldoAwal::class)->pratinjau('santri-lama', $path, $this->param());
        $this->assertSame(1, $hasil['siap'], 'berkas Excel berpemisah titik koma harus tetap terbaca');
    }

    public function test_alur_http_periksa_lalu_impor(): void
    {
        HakAksesModul::create([
            'id_pengguna' => $this->admin->id_pengguna, 'kode_modul' => 'impor-data-awal',
            'lihat' => true, 'buat' => true, 'ubah' => false, 'hapus' => false, 'menu' => true,
        ]);

        $this->actingAs($this->admin)->get('/impor-data-awal')->assertOk()->assertSee('Santri Lama');

        $berkas = UploadedFile::fake()->createWithContent('santri.csv', $this->csv([$this->barisSah()]));
        $pratinjau = $this->actingAs($this->admin)->post('/impor-data-awal/pratinjau', [
            'jenis' => 'santri-lama', 'berkas' => $berkas, 'param' => $this->param(),
        ])->assertOk();

        $pratinjau->assertSee('Siap diimpor');
        $this->assertSame(0, Santri::count(), 'memeriksa berkas belum boleh menulis');

        // Ambil penunjuk berkas dari halaman pratinjau, lalu jalankan.
        preg_match('/name="berkas_path" value="([^"]+)"/', $pratinjau->getContent(), $m);
        $this->assertNotEmpty($m, 'halaman pratinjau harus membawa penunjuk berkas');

        $this->actingAs($this->admin)->post('/impor-data-awal/jalankan', [
            'jenis' => 'santri-lama', 'berkas_path' => $m[1], 'param' => $this->param(),
        ])->assertRedirect();

        $this->assertSame(1, Santri::count());
        $this->assertSame('aktif', Santri::firstOrFail()->status);
    }

    // ---------------- Hutang vendor ----------------

    private function siapkanVendor(): array
    {
        // COA dulu — bank_accounts.kode_coa punya kunci asing ke coa_detail.
        CoaDetail::create(['kode_coa' => '1.ZZIM.9', 'nama_coa' => 'Bank Uji', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        \App\Models\BankAccount::create([
            'kode_coa' => '1.ZZIM.9', 'nama_rekening' => 'Bank Uji', 'jenis_rekening' => 'bank', 'status' => 'aktif',
        ]);
        CoaDetail::create(['kode_coa' => '2.ZZIM.1', 'nama_coa' => 'Hutang Usaha', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '3.ZZIM.1', 'nama_coa' => 'Saldo Awal', 'kode_grup' => self::GRP, 'jenis_saldo' => 'kredit']);
        CoaDetail::create(['kode_coa' => '5.ZZIM.1', 'nama_coa' => 'Beban ATK', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
        \App\Models\VendorType::create(['kode_jenis_vendor' => 'ZZJV', 'nama' => 'Umum']);
        \App\Models\Vendor::create(['kode_vendor' => 'V001', 'nama_vendor' => 'PT Uji', 'kode_jenis_vendor' => 'ZZJV']);

        return [
            'akun_perantara' => '3.ZZIM.1', 'kode_coa_hutang' => '2.ZZIM.1',
            'kode_unit' => 'ZZUNIT', 'tanggal_cutoff' => '2026-09-01',
        ];
    }

    private function berkasIsi(string $isi): string
    {
        $path = sys_get_temp_dir().'/impor-uji-'.uniqid().'.csv';
        file_put_contents($path, $isi);

        return $path;
    }

    public function test_hutang_vendor_menjadi_invoice_berjurnal_saldo_awal(): void
    {
        $param = $this->siapkanVendor();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "kode_vendor,nomor_invoice,tanggal_jatuh_tempo,sisa_hutang,keterangan\n"
            ."V001,INV/2026/0451,2026-06-30,12500000,ATK Juni\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('invoice-vendor', $path, $param);
        $this->assertSame(['invoice' => 1], $hasil['tersimpan']);

        $inv = \App\Models\Invoice::firstOrFail();
        $this->assertSame(12500000.0, (float) $inv->sisa_hutang);
        // Tanggal invoice = cut-off supaya jurnalnya rapi; jatuh tempo tetap asli
        // karena laporan umur hutang memakai jatuh tempo.
        $this->assertSame('2026-09-01', $inv->tanggal_invoice->toDateString());
        $this->assertSame('2026-06-30', $inv->tanggal_jatuh_tempo->toDateString());

        // Jurnalnya HARUS Debit Saldo Awal / Kredit Hutang — bukan mendebit beban.
        $entry = JournalEntry::with('lines')->where('sumber_modul', 'Invoice')->firstOrFail();
        $this->assertSame(12500000.0, (float) $entry->lines->firstWhere('kode_coa', '3.ZZIM.1')->debet);
        $this->assertSame(12500000.0, (float) $entry->lines->firstWhere('kode_coa', '2.ZZIM.1')->kredit);
        $this->assertNull($entry->lines->firstWhere('kode_coa', '5.ZZIM.1'));
    }

    public function test_akun_perantara_beban_ditolak_sekali_bukan_per_baris(): void
    {
        $param = $this->siapkanVendor();
        $param['akun_perantara'] = '5.ZZIM.1'; // akun Beban

        $path = $this->berkasIsi(
            "kode_vendor,nomor_invoice,tanggal_jatuh_tempo,sisa_hutang\n"
            ."V001,INV/1,2026-06-30,1000\nV001,INV/2,2026-06-30,2000\n"
        );

        $this->expectException(\App\Exceptions\AppException::class);
        $this->expectExceptionMessageMatches('/tidak boleh akun Beban/');
        app(ImporSaldoAwal::class)->pratinjau('invoice-vendor', $path, $param);
    }

    public function test_invoice_yang_sudah_ada_dilewati(): void
    {
        $param = $this->siapkanVendor();
        $this->actingAs($this->admin);
        $path = $this->berkasIsi(
            "kode_vendor,nomor_invoice,tanggal_jatuh_tempo,sisa_hutang\n"
            ."V001,INV/2026/0451,2026-06-30,12500000\n"
        );

        app(ImporSaldoAwal::class)->jalankan('invoice-vendor', $path, $param);
        $ulang = app(ImporSaldoAwal::class)->pratinjau('invoice-vendor', $path, $param);

        $this->assertSame(0, $ulang['siap']);
        $this->assertSame(1, $ulang['lewati']);
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    // ---------------- Pembiayaan bank ----------------

    public function test_pembiayaan_bank_masuk_tanpa_jurnal_pencairan(): void
    {
        $param = $this->siapkanVendor();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "nama_bank,nomor_kontrak,sisa_pokok,tanggal_mulai,tanggal_jatuh_tempo,tenor_bulan\n"
            ."BSI,AKAD/2024/0012,250000000,2024-03-01,2029-03-01,60\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('pinjaman-bank', $path, [
            'kode_coa_hutang' => '2.ZZIM.1', 'kode_rekening' => '1.ZZIM.9',
        ]);

        $this->assertSame(['pembiayaan' => 1], $hasil['tersimpan']);
        $loan = \App\Models\BankLoan::firstOrFail();
        $this->assertSame(250000000.0, (float) $loan->pokok_awal); // diisi SISA pokok
        $this->assertSame('aktif', $loan->status);

        // Uangnya cair bertahun lalu — tak boleh ada jurnal pencairan hari ini.
        $this->assertSame(0, JournalEntry::where('sumber_modul', 'BankLoan')->count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_pembiayaan_tanpa_nomor_kontrak_ditolak(): void
    {
        $this->siapkanVendor();
        $path = $this->berkasIsi(
            "nama_bank,nomor_kontrak,sisa_pokok,tanggal_mulai\n"
            ."BSI,,250000000,2024-03-01\n"
        );
        $hasil = app(ImporSaldoAwal::class)->pratinjau('pinjaman-bank', $path, [
            'kode_coa_hutang' => '2.ZZIM.1', 'kode_rekening' => '1.ZZIM.9',
        ]);

        $this->assertSame(1, $hasil['masalah']);
        $this->assertStringContainsString('Nomor kontrak kosong', $hasil['baris_masalah'][0]['alasan']);
    }

    // ---------------- Tahap 2: uang muka, accrue, aset ----------------

    public function test_uang_muka_operasional_masuk_tanpa_jurnal(): void
    {
        $this->siapkanVendor();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "nomor_bukti,tanggal,penerima,sisa_uang_muka,keterangan\n"
            ."UM/2026/007,2026-07-15,Ust. Ahmad,2500000,Perjalanan dinas\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('uang-muka-operasional', $path, [
            'kode_coa_uang_muka' => '1.ZZIM.1', 'kode_rekening' => '1.ZZIM.9', 'kode_unit' => 'ZZUNIT',
        ]);

        $this->assertSame(['uang_muka' => 1], $hasil['tersimpan']);
        $um = \App\Models\OperationalAdvance::firstOrFail();
        $this->assertSame(2500000.0, (float) $um->nominal);
        $this->assertSame('outstanding', $um->status);
        $this->assertStringStartsWith('UM/2026/007', $um->keterangan); // penanda idempoten
        $this->assertSame(0, JournalEntry::count(), 'uangnya keluar sebelum pindah sistem — tak boleh ada jurnal baru');

        // Berkas yang sama diunggah lagi tidak menggandakan.
        $ulang = app(ImporSaldoAwal::class)->pratinjau('uang-muka-operasional', $path, [
            'kode_coa_uang_muka' => '1.ZZIM.1', 'kode_rekening' => '1.ZZIM.9', 'kode_unit' => '',
        ]);
        $this->assertSame(1, $ulang['lewati']);
    }

    public function test_accrue_prepaid_memakai_akun_asli_tanpa_jurnal(): void
    {
        $this->siapkanVendor();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "nomor_bukti,tanggal,kode_coa_debet,kode_coa_kredit,nominal,periode,keterangan\n"
            ."ACR/2026/003,2026-06-30,5.ZZIM.1,2.ZZIM.1,6000000,2026-06,Sewa dibayar dimuka\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('accrue-prepaid', $path, ['kode_unit' => 'ZZUNIT']);

        $this->assertSame(['accrue' => 1], $hasil['tersimpan']);
        $acc = \App\Models\Accrue::firstOrFail();
        // Pasangan akun ASLI dipertahankan supaya pembalikannya nanti benar —
        // bukan diarahkan ke akun perantara seperti invoice vendor.
        $this->assertSame('5.ZZIM.1', $acc->kode_coa_debet);
        $this->assertSame('2.ZZIM.1', $acc->kode_coa_kredit);
        $this->assertSame(6000000.0, (float) $acc->nominal);
        $this->assertSame(0, JournalEntry::count(), 'nilainya masuk lewat jurnal pembuka, bukan dari sini');
    }

    public function test_accrue_menolak_akun_sama_dan_akun_tak_dikenal(): void
    {
        $this->siapkanVendor();
        $path = $this->berkasIsi(
            "nomor_bukti,tanggal,kode_coa_debet,kode_coa_kredit,nominal\n"
            ."A/1,2026-06-30,5.ZZIM.1,5.ZZIM.1,1000\n"
            ."A/2,2026-06-30,9.NGAWUR,2.ZZIM.1,1000\n"
        );
        $hasil = app(ImporSaldoAwal::class)->pratinjau('accrue-prepaid', $path, ['kode_unit' => '']);

        $this->assertSame(2, $hasil['masalah']);
        $alasan = collect($hasil['baris_masalah'])->pluck('alasan')->join(' | ');
        $this->assertStringContainsString('tidak boleh sama', $alasan);
        $this->assertStringContainsString('9.NGAWUR', $alasan);
    }

    public function test_aset_tetap_melanjutkan_penyusutan_dari_nilai_buku(): void
    {
        $this->siapkanVendor();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "kode_aset,nama_aset,harga_perolehan,tanggal_perolehan,umur_manfaat,akumulasi_depresiasi\n"
            ."AST-0001,Mobil Operasional,240000000,2023-05-01,60,96000000\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('aset-tetap', $path, []);

        $this->assertSame(['aset' => 1], $hasil['tersimpan']);
        $aset = \App\Models\Asset::findOrFail('AST-0001');
        $this->assertSame(240000000.0, (float) $aset->harga_perolehan);
        $this->assertSame(96000000.0, (float) $aset->akumulasi_depresiasi);
        $this->assertSame('garis_lurus', $aset->metode_depresiasi);
        $this->assertSame(0, JournalEntry::count());

        // Inti fiturnya: nilai buku sudah berkurang, jadi penyusutan berlanjut
        // dari sisa umur — bukan dihitung ulang dari nol.
        $svc = new \App\Services\Modules\AssetService;
        $this->assertSame(144000000.0, (float) $svc->bookValue($aset));
    }

    public function test_aset_tanpa_akumulasi_ditolak_dengan_alasan_jelas(): void
    {
        $this->siapkanVendor();
        $path = $this->berkasIsi(
            "kode_aset,nama_aset,harga_perolehan,tanggal_perolehan,umur_manfaat,akumulasi_depresiasi\n"
            ."AST-0002,Meja,5000000,2024-01-01,48,\n"
            ."AST-0003,Kursi,5000000,2024-01-01,48,9000000\n"
        );
        $hasil = app(ImporSaldoAwal::class)->pratinjau('aset-tetap', $path, []);

        $this->assertSame(2, $hasil['masalah']);
        $alasan = collect($hasil['baris_masalah'])->pluck('alasan')->join(' | ');
        $this->assertStringContainsString('disusutkan ulang dari nol', $alasan);
        $this->assertStringContainsString('melebihi harga perolehan', $alasan);
    }

    public function test_pengajuan_belum_dibayar_bisa_langsung_dilunasi_kas_keluar(): void
    {
        $this->siapkanVendor();
        \App\Models\Bagian::create(['kode_bagian' => 'ZZBAG', 'nama_bagian' => 'Bagian Uji', 'level' => 3]);
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "nomor,tanggal,kode_bagian,sisa_hutang,kode_coa,kode_unit,keterangan\n"
            ."PB/2026/0088,2026-08-20,ZZBAG,7500000,5.ZZIM.1,ZZUNIT,Pembelian ATK Agustus\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('pengajuan-belum-dibayar', $path, [
            'kode_coa_hutang' => '2.ZZIM.1',
        ]);

        $this->assertSame(['pengajuan' => 1], $hasil['tersimpan']);
        $p = \App\Models\PengajuanPembayaran::firstOrFail();
        $this->assertSame(7500000.0, (float) $p->sisa_hutang);
        // "diposting" adalah satu-satunya status yang diterima applyPayment().
        $this->assertSame('diposting', $p->status);
        $this->assertCount(1, $p->details);
        $this->assertSame(0, JournalEntry::count(), 'bebannya sudah diakui di sistem lama');

        // Buktinya benar-benar bisa dibayar lewat jalur normal.
        (new \App\Services\Modules\PengajuanPembayaranService)->applyPayment($p->id, '7500000');
        $this->assertSame('lunas', $p->refresh()->status);
        $this->assertSame(0.0, (float) $p->sisa_hutang);
    }

    public function test_pengajuan_menolak_bagian_dan_unit_tak_dikenal(): void
    {
        $this->siapkanVendor();
        $path = $this->berkasIsi(
            "nomor,tanggal,kode_bagian,sisa_hutang,kode_coa,kode_unit,keterangan\n"
            ."PB/1,2026-08-20,NGAWUR,1000,5.ZZIM.1,ZZUNIT,uji\n"
            ."PB/2,2026-08-20,ZZBAG2,1000,5.ZZIM.1,NGAWUR,uji\n"
        );
        \App\Models\Bagian::create(['kode_bagian' => 'ZZBAG2', 'nama_bagian' => 'Bagian Uji 2', 'level' => 3]);

        $hasil = app(ImporSaldoAwal::class)->pratinjau('pengajuan-belum-dibayar', $path, [
            'kode_coa_hutang' => '2.ZZIM.1',
        ]);

        $this->assertSame(2, $hasil['masalah']);
        $alasan = collect($hasil['baris_masalah'])->pluck('alasan')->join(' | ');
        $this->assertStringContainsString('Bagian "NGAWUR"', $alasan);
        $this->assertStringContainsString('Unit "NGAWUR"', $alasan);
    }

    // ---------------- Tahap 3: pinjaman karyawan ----------------

    /** Master karyawan seadanya + akun piutangnya. */
    private function siapkanKaryawan(): void
    {
        \App\Models\Bagian::create(['kode_bagian' => 'ZZKRY', 'nama_bagian' => 'Bagian Karyawan', 'level' => 3]);
        Karyawan::create(['kode' => 'KRY-001', 'nama' => 'Ust. Salim', 'kode_bagian' => 'ZZKRY', 'status' => 'aktif']);
        Karyawan::create(['kode' => 'KRY-002', 'nama' => 'Mantan Pegawai', 'kode_bagian' => 'ZZKRY', 'status' => 'nonaktif']);
        CoaDetail::create(['kode_coa' => '1.ZZIM.5', 'nama_coa' => 'Piutang Karyawan', 'kode_grup' => self::GRP, 'jenis_saldo' => 'debet']);
    }

    public function test_pinjaman_karyawan_masuk_tanpa_jurnal_dan_bisa_dicicil(): void
    {
        $this->siapkanVendor();
        $this->siapkanKaryawan();
        $this->actingAs($this->admin);

        $path = $this->berkasIsi(
            "nomor_bukti,kode_karyawan,tanggal,sisa_pokok,sisa_termin,jatuh_tempo_pertama,keterangan\n"
            ."PJK/2025/014,KRY-001,2025-06-10,3000000,3,2026-09-25,Renovasi rumah\n"
        );
        $hasil = app(ImporSaldoAwal::class)->jalankan('pinjaman-karyawan', $path, [
            'kode_coa_piutang' => '1.ZZIM.5',
        ]);

        $this->assertSame(['pinjaman' => 1], $hasil['tersimpan']);
        $pinjaman = \App\Models\PinjamanKaryawan::firstOrFail();
        $this->assertSame(3000000.0, (float) $pinjaman->pokok);   // diisi SISA pokok
        $this->assertSame(0.0, (float) $pinjaman->terbayar);      // cicilan lama bukan urusan sistem ini
        $this->assertSame('aktif', $pinjaman->status);
        $this->assertStringStartsWith('PJK/2025/014', $pinjaman->keterangan); // penanda idempoten

        // Uangnya diserahkan sebelum pindah sistem — tak boleh ada jurnal pencairan.
        $this->assertNull($pinjaman->journal_entry_id);
        $this->assertSame(0, JournalEntry::count());

        // Jadwal sisa cicilan: bulanan, dan Σ termin persis sama dengan pokok.
        $termin = $pinjaman->termin()->orderBy('urutan')->get();
        $this->assertCount(3, $termin);
        $this->assertSame(3000000.0, (float) $termin->sum('nominal'));
        $this->assertSame('2026-11-25', $termin->last()->jatuh_tempo->toDateString());

        // Yang penting: dokumennya benar-benar bisa dicicil lewat jalur normal,
        // dan cicilannya mengkredit akun piutang yang dipilih saat impor.
        (new \App\Services\Modules\PinjamanKaryawanService)->bayar($pinjaman->id, [
            'tanggal' => '2026-09-25', 'nominal' => '1000000', 'cara' => 'tunai', 'kode_rekening' => '1.ZZIM.9',
        ], $this->admin->id_pengguna);

        $this->assertSame(2000000.0, (float) $pinjaman->refresh()->sisa);
        $this->assertSame(1000000.0, (float) \App\Models\JournalLine::where('kode_coa', '1.ZZIM.5')->sum('kredit'));
    }

    public function test_pinjaman_karyawan_menolak_karyawan_dan_jadwal_yang_tak_sah(): void
    {
        $this->siapkanVendor();
        $this->siapkanKaryawan();

        $path = $this->berkasIsi(
            "nomor_bukti,kode_karyawan,tanggal,sisa_pokok,sisa_termin,jatuh_tempo_pertama\n"
            ."P/1,NGAWUR,2025-06-10,1000000,,\n"
            ."P/2,KRY-002,2025-06-10,1000000,,\n"
            ."P/3,KRY-001,2025-06-10,1000000,6,\n"
            ."P/4,KRY-001,2025-06-10,0,,\n"
        );
        $hasil = app(ImporSaldoAwal::class)->pratinjau('pinjaman-karyawan', $path, [
            'kode_coa_piutang' => '1.ZZIM.5',
        ]);

        $this->assertSame(4, $hasil['masalah']);
        $this->assertSame(0, \App\Models\PinjamanKaryawan::count());
        $alasan = collect($hasil['baris_masalah'])->pluck('alasan')->join(' | ');
        $this->assertStringContainsString('"NGAWUR" tidak ada di master Karyawan', $alasan);
        $this->assertStringContainsString('nonaktif', $alasan);
        $this->assertStringContainsString('jatuh tempo pertama wajib diisi', $alasan);
        $this->assertStringContainsString('lebih dari nol', $alasan);
    }

    public function test_pinjaman_karyawan_menolak_akun_laba_rugi_sekali_bukan_per_baris(): void
    {
        $this->siapkanVendor();
        $this->siapkanKaryawan();

        $path = $this->berkasIsi(
            "nomor_bukti,kode_karyawan,tanggal,sisa_pokok\n"
            ."P/1,KRY-001,2025-06-10,1000000\n"
            ."P/2,KRY-001,2025-06-11,2000000\n"
        );

        // Akun beban akan membuat cicilan nanti mengkredit laba rugi.
        try {
            app(ImporSaldoAwal::class)->pratinjau('pinjaman-karyawan', $path, ['kode_coa_piutang' => '5.ZZIM.1']);
            $this->fail('Akun laba rugi seharusnya ditolak.');
        } catch (\App\Exceptions\AppException $e) {
            $this->assertStringContainsString('akun laba rugi', $e->getMessage());
        }

        // Berkas yang sama, tetapi diimpor dua kali: baris lama dilewati.
        $param = ['kode_coa_piutang' => '1.ZZIM.5'];
        $this->actingAs($this->admin);
        app(ImporSaldoAwal::class)->jalankan('pinjaman-karyawan', $path, $param);
        $ulang = app(ImporSaldoAwal::class)->pratinjau('pinjaman-karyawan', $path, $param);

        $this->assertSame(0, $ulang['siap']);
        $this->assertSame(2, $ulang['lewati']);
        $this->assertSame(2, \App\Models\PinjamanKaryawan::count());
    }

    public function test_semua_jenis_impor_terdaftar(): void
    {
        $this->assertSame(
            [
                'santri-lama', 'invoice-vendor', 'pinjaman-bank', 'pinjaman-karyawan',
                'uang-muka-operasional', 'accrue-prepaid', 'aset-tetap', 'pengajuan-belum-dibayar',
            ],
            array_keys(ImporSaldoAwal::daftar()),
        );
    }

    public function test_tanpa_hak_modul_ditolak(): void
    {
        $orang = User::create([
            'username' => 'zzim_biasa', 'nama' => 'Tanpa Hak', 'password_hash' => 'x',
            'kode_level' => 'L1', 'is_admin' => false, 'status' => 'aktif',
        ]);

        $this->actingAs($orang)->get('/impor-data-awal')->assertForbidden();
    }
}
