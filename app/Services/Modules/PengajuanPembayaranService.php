<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use App\Services\Ledger\AnggaranPolicy;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PeringkatPengajuan;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Pengajuan Pembayaran (§4). Dokumen pertama yang memakai mesin approval.
 * Alur pembayaran (accrual): create → rantai approval → verifikasi keuangan
 * (setAkunHutang + applyApproved posting §4.g Beban(D)/Hutang(K)) → dibayar Kas
 * Keluar (applyPayment, §4.h mendebit Hutang Pengajuan, mencegah biaya dobel).
 *
 * CATATAN: jalur `penyelesaian_uang_muka` (butuh OperationalAdvance) belum
 * dikonversi di sini — ditandai lanjutan.
 */
class PengajuanPembayaranService
{
    public const SUMBER = 'PengajuanPembayaran';

    private const PREFIX_JENIS = [
        'pembayaran' => 'PB',
        'uang_muka' => 'UM',
        'penyelesaian_uang_muka' => 'PUM',
    ];

    /** Kelompok akun via prefix kode_coa. */
    private static function kelompok(string $kodeCoa): string
    {
        return $kodeCoa[0] ?? '';
    }

    /**
     * Komposisi unit sebuah pengajuan: total nominal per unit (urutan stabil).
     * Satu sumber untuk baris hutang saat posting & baris kas saat Kas Keluar.
     *
     * @param  iterable  $details  objek/array dengan kode_unit & nominal
     * @return array<int,array{0:string,1:string}>  [[kode_unit, nominal], ...]
     */
    public static function ringkasPerUnit(iterable $details): array
    {
        $per = [];
        foreach ($details as $d) {
            $unit = is_array($d) ? $d['kode_unit'] : $d->kode_unit;
            $nominal = is_array($d) ? $d['nominal'] : $d->nominal;
            $per[$unit] = Money::add($per[$unit] ?? '0', $nominal);
        }

        return array_map(fn ($k, $v) => [$k, $v], array_keys($per), array_values($per));
    }

    private function approval(): ApprovalService
    {
        return new ApprovalService;
    }

    /**
     * Validasi baris rincian + nilai anggarannya (dipakai create).
     * @return array{baris:array,total:string,tahun:int,bulan:int,overbudget:bool,belum_dianggarkan:bool}
     */
    private function siapkanBaris(array $details, string $kodeBagian, string $tanggal, string $jenis): array
    {
        $baris = [];
        $dipakai = [];
        foreach ($details as $d) {
            $kunci = "{$d['kode_coa']}|{$d['kode_unit']}";
            if (in_array($kunci, $dipakai, true)) {
                throw new AppException(409, "Akun {$d['kode_coa']} untuk unit {$d['kode_unit']} dipakai lebih dari sekali; gabungkan jadi satu baris.");
            }
            $dipakai[] = $kunci;

            $akun = CoaDetail::find($d['kode_coa']);
            if (! $akun) {
                throw new AppException(400, "Akun {$d['kode_coa']} tidak ditemukan.");
            }
            if ($akun->status !== 'aktif') {
                throw new AppException(400, "Akun {$d['kode_coa']} berstatus nonaktif.");
            }
            if ($jenis === 'uang_muka' && self::kelompok($d['kode_coa']) !== '1') {
                throw new AppException(422, "Akun {$d['kode_coa']} ({$akun->nama_coa}) bukan Aset. Uang muka harus akun kelompok 1 (Aset).");
            }

            $unit = BusinessUnit::find($d['kode_unit']);
            if (! $unit) {
                throw new AppException(400, "Unit bisnis {$d['kode_unit']} tidak ditemukan.");
            }
            if ($unit->status !== 'aktif') {
                throw new AppException(422, "Unit bisnis \"{$unit->nama_unit}\" berstatus nonaktif.");
            }

            $baris[] = [
                'kode_coa' => $d['kode_coa'],
                'nama_coa' => $akun->nama_coa,
                'kode_unit' => $d['kode_unit'],
                'nominal' => Money::of($d['nominal']),
                'keterangan' => $d['keterangan'] ?? null,
            ];
        }

        $total = array_reduce($baris, fn ($s, $b) => Money::add($s, $b['nominal']), '0');
        $c = Carbon::parse($tanggal);
        $tahun = (int) $c->year;
        $bulan = (int) $c->month;

        $overbudget = false;
        $belum = false;
        if ($jenis !== 'uang_muka') {
            foreach ($baris as $b) {
                $ev = AnggaranPolicy::evaluasiAnggaran([
                    'tahun' => $tahun, 'bulan' => $bulan, 'kode_coa' => $b['kode_coa'],
                    'kode_bagian' => $kodeBagian, 'nominal' => $b['nominal'],
                ]);
                $overbudget = $overbudget || $ev['overbudget'];
                $belum = $belum || $ev['belum_dianggarkan'];
            }
        }

        return [
            'baris' => $baris, 'total' => $total, 'tahun' => $tahun, 'bulan' => $bulan,
            'overbudget' => $overbudget, 'belum_dianggarkan' => $belum,
        ];
    }

    /** Pratinjau nomor dokumen berikutnya untuk sebuah jenis (indikatif; final saat diajukan). */
    public function previewNomor(string $jenis): string
    {
        $base = DocNumber::docBase(self::PREFIX_JENIS[$jenis] ?? 'PB', now());
        $last = PengajuanPembayaran::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

        return DocNumber::nextDocNumber($base, $last);
    }

    /** Input pengajuan (§4.a) → langsung diajukan ke rantai. Belum ada jurnal. */
    public function create(array $input, int $idPengguna): PengajuanPembayaran
    {
        $pemohon = User::find($idPengguna);
        if (! $pemohon) {
            throw new AppException(400, 'Pemohon tidak ditemukan.');
        }
        // Pagar KERAS: hanya Staff (peringkat 4) yang boleh mengajukan.
        if ($pemohon->peringkat_pengajuan !== PeringkatPengajuan::STAFF) {
            throw new AppException(403, 'Hanya Staff yang boleh membuat pengajuan pembayaran. Atasan menyetujui, bukan mengajukan.');
        }
        if (! $pemohon->kode_bagian) {
            throw new AppException(422, 'Profil Anda belum ditempatkan di bagian mana pun. Minta administrator mengisi Bagian pada akun Anda.');
        }
        $kodeBagian = $pemohon->kode_bagian;
        $bagian = \App\Models\Bagian::find($kodeBagian);
        if (! $bagian) {
            throw new AppException(400, 'Bagian pada profil Anda tidak ditemukan.');
        }
        if ($bagian->status !== 'aktif') {
            throw new AppException(422, "Bagian \"{$bagian->nama_bagian}\" berstatus nonaktif.");
        }

        $jenis = $input['jenis'] ?? 'pembayaran';
        $idUangMuka = null;
        if ($jenis === 'penyelesaian_uang_muka') {
            if (empty($input['id_uang_muka'])) {
                throw new AppException(422, 'Pilih uang muka yang akan diselesaikan.');
            }
            $adv = OperationalAdvance::find($input['id_uang_muka']);
            if (! $adv || $adv->status === 'void') {
                throw new AppException(400, 'Uang muka tidak ditemukan / sudah dibatalkan.');
            }
            if ($adv->id_pengguna !== $idPengguna) {
                throw new AppException(403, 'Anda hanya dapat menyelesaikan uang muka yang Anda ajukan sendiri.');
            }
            if (Money::lte($adv->sisa, '0')) {
                throw new AppException(409, 'Uang muka ini sudah selesai (tidak ada sisa).');
            }
            $idUangMuka = $adv->id;
        }

        $prep = $this->siapkanBaris($input['details'], $kodeBagian, $input['tanggal'], $jenis);

        $rec = DB::transaction(function () use ($input, $jenis, $kodeBagian, $idPengguna, $prep, $idUangMuka) {
            $base = DocNumber::docBase(self::PREFIX_JENIS[$jenis] ?? 'PB', $input['tanggal']);
            $last = PengajuanPembayaran::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
            $doc = PengajuanPembayaran::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'tanggal' => $input['tanggal'],
                'jenis' => $jenis,
                'kode_bagian' => $kodeBagian,
                'kode_coa_hutang' => null, // diisi keuangan saat verifikasi
                'id_uang_muka' => $idUangMuka,
                'kode_rekening' => null,
                'nominal' => $prep['total'],
                'sisa_hutang' => '0',
                'keterangan' => $input['keterangan'],
                'referensi' => $input['referensi'] ?? null,
                'bank_tujuan' => $input['bank_tujuan'] ?? null,
                'no_rekening_tujuan' => $input['no_rekening_tujuan'] ?? null,
                'atas_nama_tujuan' => $input['atas_nama_tujuan'] ?? null,
                'status' => 'diajukan',
                'id_pengguna' => $idPengguna,
            ]);
            foreach ($prep['baris'] as $b) {
                $doc->details()->create($b);
            }

            return $doc;
        });

        $this->approval()->submit([
            'jenis_dokumen' => self::SUMBER,
            'id_dokumen' => (string) $rec->id,
            'kode_bagian' => $rec->kode_bagian,
            'tahun' => $prep['tahun'],
            'bulan' => $prep['bulan'],
            'nominal' => (string) $rec->nominal,
            'evaluasi' => ['overbudget' => $prep['overbudget'], 'belum_dianggarkan' => $prep['belum_dianggarkan']],
            'id_pemohon' => $idPengguna,
        ]);

        return $rec->refresh()->load('details');
    }

    /**
     * Perbaiki pengajuan yang DITOLAK. Hanya pemohonnya, hanya saat ditolak.
     * Status TETAP 'ditolak' (memperbaiki ≠ mengajukan); nomor & riwayat tetap.
     * Baris lama dibuang lalu ditulis ulang (dokumen belum berjurnal).
     */
    public function update(int $id, array $input, int $idPengguna): PengajuanPembayaran
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->id_pengguna !== $idPengguna) {
            throw new AppException(403, 'Hanya pemohon yang bisa memperbaiki pengajuannya.');
        }
        if ($rec->status !== 'ditolak') {
            throw new AppException(409, "Hanya pengajuan yang ditolak bisa diperbaiki; pengajuan ini berstatus {$rec->status}.");
        }

        $jenis = $input['jenis'] ?? $rec->jenis;
        $prep = $this->siapkanBaris($input['details'], $rec->kode_bagian, $input['tanggal'], $jenis);

        DB::transaction(function () use ($rec, $input, $jenis, $prep) {
            $rec->details()->delete();
            $rec->update([
                'tanggal' => $input['tanggal'],
                'jenis' => $jenis,
                'keterangan' => $input['keterangan'],
                'referensi' => $input['referensi'] ?? null,
                'bank_tujuan' => $input['bank_tujuan'] ?? null,
                'no_rekening_tujuan' => $input['no_rekening_tujuan'] ?? null,
                'atas_nama_tujuan' => $input['atas_nama_tujuan'] ?? null,
                'nominal' => $prep['total'],
            ]);
            foreach ($prep['baris'] as $b) {
                $rec->details()->create($b);
            }
        });

        return $rec->refresh()->load('details');
    }

    /**
     * Ajukan ULANG pengajuan yang ditolak (setelah diperbaiki atau apa adanya).
     * Anggaran dinilai ULANG dari isi dokumen sekarang. Rantai dijalankan lebih
     * dulu; status baru diubah ke 'diajukan' bila rantainya berhasil berjalan.
     */
    public function ajukanUlang(int $id, int $idPengguna): PengajuanPembayaran
    {
        $rec = PengajuanPembayaran::with('details')->find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->id_pengguna !== $idPengguna) {
            throw new AppException(403, 'Hanya pemohon yang bisa mengajukan ulang pengajuannya.');
        }
        if ($rec->status !== 'ditolak') {
            throw new AppException(409, "Hanya pengajuan yang ditolak bisa diajukan ulang; pengajuan ini berstatus {$rec->status}.");
        }

        $prep = $this->siapkanBaris(
            $rec->details->map(fn ($d) => [
                'kode_coa' => $d->kode_coa, 'kode_unit' => $d->kode_unit,
                'nominal' => (string) $d->nominal, 'keterangan' => $d->keterangan,
            ])->all(),
            $rec->kode_bagian,
            $rec->tanggal instanceof \Illuminate\Support\Carbon ? $rec->tanggal->toDateString() : (string) $rec->tanggal,
            $rec->jenis,
        );

        $this->approval()->ajukanUlang([
            'jenis_dokumen' => self::SUMBER,
            'id_dokumen' => (string) $rec->id,
            'kode_bagian' => $rec->kode_bagian,
            'tahun' => $prep['tahun'],
            'bulan' => $prep['bulan'],
            'nominal' => (string) $rec->nominal,
            'evaluasi' => ['overbudget' => $prep['overbudget'], 'belum_dianggarkan' => $prep['belum_dianggarkan']],
            'id_pemohon' => $idPengguna,
        ]);

        $rec->update(['status' => 'diajukan']);

        return $rec->refresh()->load('details');
    }

    /** Dipanggil mesin approval saat pengajuan DITOLAK (penolakan handler). */
    public function applyRejected(string $idDokumen): void
    {
        $rec = PengajuanPembayaran::find((int) $idDokumen);
        if (! $rec || $rec->status !== 'diajukan') {
            return;
        }
        $rec->update(['status' => 'ditolak']);
    }

    /** Keuangan menetapkan akun hutang (kelompok 2). */
    public function setAkunHutang(int $id, string $kodeCoaHutang, int $idPengguna): PengajuanPembayaran
    {
        $rec = PengajuanPembayaran::with('details')->find($id);
        $user = User::find($idPengguna);
        $akun = CoaDetail::find($kodeCoaHutang);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if (! $user) {
            throw new AppException(400, 'Pengguna tidak ditemukan.');
        }
        if (! $user->tim_keuangan) {
            throw new AppException(403, 'Hanya tim keuangan yang menentukan akun hutang pengajuan.');
        }
        if ($rec->status !== 'diajukan') {
            throw new AppException(409, "Pengajuan berstatus {$rec->status}; akun hutang tidak dapat diubah.");
        }
        if (! $akun) {
            throw new AppException(400, "Akun {$kodeCoaHutang} tidak ditemukan.");
        }
        if (self::kelompok($kodeCoaHutang) !== '2') {
            throw new AppException(422, "Akun {$kodeCoaHutang} ({$akun->nama_coa}) bukan Liabilitas. Akun penampung harus kelompok 2.");
        }
        if ($rec->details->contains('kode_coa', $kodeCoaHutang)) {
            throw new AppException(422, 'Akun hutang tidak boleh sama dengan akun yang diajukan.');
        }
        $rec->update(['kode_coa_hutang' => $kodeCoaHutang]);

        return $rec;
    }

    /** §4.g: posting saat disetujui — akun diajukan (D) / Hutang Pengajuan per unit (K). */
    public function applyApproved(string $idDokumen, ?int $idPengguna): void
    {
        $rec = PengajuanPembayaran::with('details')->find((int) $idDokumen);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if (in_array($rec->status, ['diposting', 'lunas'], true)) {
            throw new AppException(409, 'Pengajuan ini sudah diposting.');
        }
        if (in_array($rec->status, ['ditolak', 'void'], true)) {
            throw new AppException(409, "Pengajuan ini sudah {$rec->status}.");
        }
        if (! $rec->kode_coa_hutang) {
            throw new AppException(422, 'Bagian keuangan belum menentukan akun hutang pengajuan.');
        }
        $akunHutang = CoaDetail::find($rec->kode_coa_hutang);

        DB::transaction(function () use ($rec, $idPengguna, $akunHutang) {
            $lines = [];
            foreach ($rec->details as $d) {
                $lines[] = [
                    'kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa,
                    'debet' => $d->nominal, 'kredit' => '0',
                    'keterangan' => $d->keterangan ?? $rec->keterangan,
                    'kode_bagian' => $rec->kode_bagian, 'kode_unit' => $d->kode_unit,
                ];
            }
            // SATU baris hutang, tidak dipecah per unit.
            //
            // Dulu ia dipecah mengikuti komposisi unit rinciannya, dan justru
            // itulah yang membuat pelunasan SEBAGIAN mustahil: porsi tiap unit
            // harus diprorata, lengkap dengan sisa pembulatan yang tak punya
            // rumah. Padahal tak ada laporan yang membacanya — `neraca()` tak
            // menerima parameter unit sama sekali.
            //
            // Unitnya sengaja tidak ditentukan di sini: PostingService yang
            // menaruhnya di unit penampung neraca (lihat konteksNeraca).
            $lines[] = [
                'kode_coa' => $rec->kode_coa_hutang, 'nama_coa' => $akunHutang?->nama_coa,
                'debet' => '0', 'kredit' => $rec->nominal,
                'keterangan' => "Hutang atas pengajuan {$rec->nomor}",
            ];

            $entry = PostingService::postJournal([
                'referensi' => $rec->nomor, 'tanggal' => $rec->tanggal,
                'keterangan' => "Pengajuan disetujui — {$rec->keterangan}",
                'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $rec->id,
                'id_pengguna' => $idPengguna, 'lines' => $lines,
            ]);

            $rec->update([
                'status' => 'diposting',
                'journal_entry_id' => $entry->id,
                'sisa_hutang' => $rec->nominal,
            ]);
        });
    }

    /**
     * §4.f — Koreksi AKUN per baris oleh tim keuangan (nominal & unit TETAP).
     * Tanpa approval ulang: pemohon & penyetuju bagian diberi tahu, beserta status
     * anggaran akun barunya. Hanya selama pengajuan 'diajukan' & belum diposting.
     *
     * @param  list<array{id_detail:int,kode_coa:string}>  $koreksi
     */
    public function koreksiAkun(int $id, array $koreksi, int $idPengguna, ?string $catatan = null): PengajuanPembayaran
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(400, 'Pengguna tidak ditemukan.');
        }
        if (! $user->tim_keuangan) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh mengoreksi akun pengajuan.');
        }

        $rec = PengajuanPembayaran::with('details')->find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->status !== 'diajukan') {
            throw new AppException(409, "Akun hanya bisa dikoreksi selama pengajuan masih berjalan; pengajuan ini berstatus {$rec->status}.");
        }

        // Kumpulkan perubahan yang benar-benar berbeda + validasi akun barunya.
        $perubahan = [];
        foreach ($koreksi as $k) {
            $baris = $rec->details->firstWhere('id', (int) $k['id_detail']);
            if (! $baris) {
                throw new AppException(400, "Baris {$k['id_detail']} bukan milik pengajuan ini.");
            }
            if ($baris->kode_coa === $k['kode_coa']) {
                continue; // tak berubah — bukan kesalahan
            }
            $akun = CoaDetail::find($k['kode_coa']);
            if (! $akun) {
                throw new AppException(400, "Akun {$k['kode_coa']} tidak ditemukan.");
            }
            if ($akun->status !== 'aktif') {
                throw new AppException(400, "Akun {$k['kode_coa']} berstatus nonaktif.");
            }
            $perubahan[] = ['baris' => $baris, 'lama' => $baris->kode_coa, 'kode_coa_baru' => $k['kode_coa'], 'nama_coa_baru' => $akun->nama_coa];
        }
        if ($perubahan === []) {
            throw new AppException(422, 'Tidak ada akun yang berubah.');
        }

        // Keunikan akun+unit tetap dijaga sesudah dikoreksi.
        $kunci = [];
        foreach ($rec->details as $d) {
            $kunci[$d->id] = "{$d->kode_coa}|{$d->kode_unit}";
        }
        foreach ($perubahan as $p) {
            $kunci[$p['baris']->id] = "{$p['kode_coa_baru']}|{$p['baris']->kode_unit}";
        }
        $dipakai = [];
        foreach ($kunci as $val) {
            if (in_array($val, $dipakai, true)) {
                [$coa, $unit] = explode('|', $val);
                throw new AppException(409, "Koreksi ini membuat akun {$coa} untuk unit {$unit} muncul dua kali; gabungkan barisnya lebih dulu.");
            }
            $dipakai[] = $val;
        }

        $tahun = (int) $rec->tanggal->year;
        $bulan = (int) $rec->tanggal->month;

        return DB::transaction(function () use ($rec, $perubahan, $idPengguna, $user, $catatan, $tahun, $bulan, $id) {
            $inst = \App\Models\ApprovalInstance::where('jenis_dokumen', self::SUMBER)->where('id_dokumen', (string) $id)->first();
            if (! $inst) {
                throw new AppException(409, 'Pengajuan ini tidak punya rantai persetujuan.');
            }
            if ($inst->posted) {
                throw new AppException(409, 'Pengajuan sudah diposting; akun tidak dapat diubah.');
            }
            // "disetujui" ikut diterima: keuangan bekerja SESUDAH rantai selesai.
            if (! in_array($inst->status, ['berjalan', 'disetujui'], true)) {
                throw new AppException(409, "Pengajuan ini sudah {$inst->status}.");
            }

            $ringkas = [];
            foreach ($perubahan as $p) {
                $p['baris']->update(['kode_coa' => $p['kode_coa_baru'], 'nama_coa' => $p['nama_coa_baru']]);

                // Status anggaran akun BARU atas nominal BARIS itu (bukan total dokumen).
                $ev = AnggaranPolicy::evaluasiAnggaran([
                    'tahun' => $tahun, 'bulan' => $bulan, 'kode_coa' => $p['kode_coa_baru'],
                    'kode_bagian' => $rec->kode_bagian, 'nominal' => (string) $p['baris']->nominal,
                ]);
                $info = $ev['belum_dianggarkan']
                    ? ' ⚠️ akun baru BELUM DIANGGARKAN'
                    : ($ev['overbudget']
                        ? " ⚠️ akun baru OVERBUDGET (proyeksi {$ev['proyeksi']} dari anggaran {$ev['anggaran']})"
                        : " (anggaran cukup, sisa {$ev['sisa']})");
                $ringkas[] = "{$p['lama']} → {$p['kode_coa_baru']} ({$p['nama_coa_baru']}){$info}";
            }

            \App\Models\ApprovalLog::create([
                'id_instance' => $inst->id, 'urutan' => $inst->tahap_sekarang, 'id_pengguna' => $idPengguna,
                'nama_pengguna' => $user->nama, 'aksi' => 'edit',
                'catatan' => 'Akun dikoreksi keuangan (nominal tetap): '.implode('; ', $ringkas).'.'.($catatan ? " Catatan: {$catatan}" : ''),
                'waktu' => now(),
            ]);

            // Beri tahu pemohon + penyetuju bagiannya (peringkat di atas Staff).
            $penerima = collect([$rec->id_pengguna])->merge(
                User::where('kode_bagian', $rec->kode_bagian)->where('status', 'aktif')
                    ->whereNotNull('peringkat_pengajuan')
                    ->where('peringkat_pengajuan', '<', PeringkatPengajuan::STAFF)->pluck('id_pengguna'),
            )->unique()->values();

            app(\App\Services\Modules\NotificationService::class)->kirim($penerima->map(fn ($uid) => [
                'id_pengguna' => $uid,
                'judul' => 'Akun pengajuan dikoreksi keuangan',
                'pesan' => "{$rec->nomor}: ".implode('; ', $ringkas).'. Nominal tidak berubah.',
                'jenis' => 'approval_edit',
                'ref_jenis' => self::SUMBER,
                'ref_id' => (string) $id,
            ])->all());

            return $rec->refresh()->load('details');
        });
    }

    /**
     * Keuangan menyunting REKENING TUJUAN saat verifikasi (§ rekening penerima).
     *
     * Mengganti rekening penerima setelah dokumen disetujui adalah modus
     * penipuan pembayaran yang paling umum, jadi perubahannya tidak pernah
     * senyap: alasannya WAJIB, nilai lama & barunya disimpan di
     * pengajuan_rekening_riwayat, ikut masuk riwayat persetujuan, dan pemohon
     * diberi tahu — dialah satu-satunya orang yang tahu rekening mana yang
     * seharusnya.
     *
     * $baru = [bank_tujuan, no_rekening_tujuan, atas_nama_tujuan]; ketiganya
     * kosong berarti rekening tujuan dihapus.
     */
    public function ubahRekeningTujuan(int $id, array $baru, int $idPengguna, string $alasan): PengajuanPembayaran
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(400, 'Pengguna tidak ditemukan.');
        }
        if (! $user->tim_keuangan) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh mengubah rekening tujuan pembayaran.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan perubahan rekening tujuan wajib diisi.');
        }

        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        // Sesudah diposting/dibayar, dokumen sudah jadi dasar transfer — kalau
        // rekeningnya keliru, jalurnya membatalkan dokumen, bukan menyuntingnya.
        if ($rec->status !== 'diajukan') {
            throw new AppException(409, "Rekening tujuan hanya bisa disunting selama pengajuan belum diposting; pengajuan ini berstatus {$rec->status}.");
        }

        $kolom = ['bank_tujuan', 'no_rekening_tujuan', 'atas_nama_tujuan'];
        $nilaiBaru = [];
        foreach ($kolom as $k) {
            $v = trim((string) ($baru[$k] ?? ''));
            $nilaiBaru[$k] = $v === '' ? null : $v;
        }

        // Setengah terisi ditolak di sini juga — service tak boleh bergantung
        // pada satu-satunya layar yang kebetulan memvalidasinya.
        $terisi = array_filter($nilaiBaru, fn ($v) => $v !== null);
        if ($terisi !== [] && count($terisi) !== 3) {
            throw new AppException(422, 'Rekening tujuan harus lengkap: nama bank, nomor rekening, dan atas nama pemilik rekening.');
        }

        $lama = ['bank_tujuan' => $rec->bank_tujuan, 'no_rekening_tujuan' => $rec->no_rekening_tujuan, 'atas_nama_tujuan' => $rec->atas_nama_tujuan];
        if ($lama == $nilaiBaru) {
            throw new AppException(422, 'Rekening tujuan tidak berubah.');
        }

        return DB::transaction(function () use ($rec, $lama, $nilaiBaru, $idPengguna, $user, $alasan, $id) {
            $rec->update($nilaiBaru);

            \App\Models\PengajuanRekeningRiwayat::create([
                'id_pengajuan' => $rec->id,
                'bank_lama' => $lama['bank_tujuan'],
                'no_rekening_lama' => $lama['no_rekening_tujuan'],
                'atas_nama_lama' => $lama['atas_nama_tujuan'],
                'bank_baru' => $nilaiBaru['bank_tujuan'],
                'no_rekening_baru' => $nilaiBaru['no_rekening_tujuan'],
                'atas_nama_baru' => $nilaiBaru['atas_nama_tujuan'],
                'alasan' => $alasan,
                'id_pengguna' => $idPengguna,
            ]);

            $sebut = fn (array $v) => $v['bank_tujuan']
                ? "{$v['bank_tujuan']} {$v['no_rekening_tujuan']} a.n. {$v['atas_nama_tujuan']}"
                : '(kosong)';
            $ringkas = $sebut($lama).' → '.$sebut($nilaiBaru);

            $inst = \App\Models\ApprovalInstance::where('jenis_dokumen', self::SUMBER)->where('id_dokumen', (string) $id)->first();
            if ($inst) {
                \App\Models\ApprovalLog::create([
                    'id_instance' => $inst->id, 'urutan' => $inst->tahap_sekarang, 'id_pengguna' => $idPengguna,
                    'nama_pengguna' => $user->nama, 'aksi' => 'edit',
                    'catatan' => "Rekening tujuan diubah keuangan: {$ringkas}. Alasan: {$alasan}",
                    'waktu' => now(),
                ]);
            }

            app(\App\Services\Modules\NotificationService::class)->kirim([[
                'id_pengguna' => $rec->id_pengguna,
                'judul' => 'Rekening tujuan pengajuan diubah keuangan',
                'pesan' => "{$rec->nomor}: {$ringkas}. Alasan: {$alasan}. Periksa — bila Anda tidak tahu perubahan ini, laporkan sebelum uang dikirim.",
                'jenis' => 'approval_edit',
                'ref_jenis' => self::SUMBER,
                'ref_id' => (string) $id,
            ]]);

            return $rec->refresh();
        });
    }

    /** Verifikasi keuangan (pembayaran: setAkunHutang + posting; uang_muka: tandai siap; penyelesaian: posting settlement). */
    public function verifikasi(int $id, ?string $kodeCoaHutang, int $idPengguna, ?string $catatan = null, ?string $kodeRekening = null): PengajuanPembayaran
    {
        $user = User::find($idPengguna);
        if (! $user) {
            throw new AppException(400, 'Pengguna tidak ditemukan.');
        }
        if (! $user->tim_keuangan) {
            throw new AppException(403, 'Hanya tim keuangan yang boleh memverifikasi pengajuan.');
        }

        $inst = \App\Models\ApprovalInstance::where('jenis_dokumen', self::SUMBER)->where('id_dokumen', (string) $id)->first();
        if (! $inst) {
            throw new AppException(404, 'Pengajuan ini tidak punya rantai persetujuan.');
        }
        if ($inst->status !== 'disetujui') {
            throw new AppException(409, $inst->status === 'berjalan'
                ? 'Pengajuan ini belum disetujui seluruh approver; verifikasi keuangan menunggu rantai selesai.'
                : "Pengajuan ini sudah {$inst->status}.");
        }
        if ($inst->posted) {
            throw new AppException(409, 'Pengajuan ini sudah diproses.');
        }

        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->status !== 'diajukan') {
            throw new AppException(409, "Pengajuan berstatus {$rec->status}; tidak dapat diverifikasi.");
        }

        $tandaiPosted = true;
        if ($rec->jenis === 'uang_muka') {
            // Cash basis: tidak memposting — Kas Keluar yang memposting nanti.
            $rec->update(['status' => 'diverifikasi']);
            $tandaiPosted = false;
        } elseif ($rec->jenis === 'penyelesaian_uang_muka') {
            // Isian yang diminta bergantung ARAH selisihnya, dan arah itu
            // dihitung di sini — bukan disimpulkan dari isian mana yang terkirim.
            //   realisasi > uang muka → kekurangan ditahan di akun HUTANG,
            //                            kas tak tersentuh, dibayar Kas Keluar;
            //   uang muka > realisasi → kelebihan kembali sekarang, kas diakui.
            $adv = OperationalAdvance::find($rec->id_uang_muka);
            if (! $adv) {
                throw new AppException(400, 'Uang muka tidak valid / sudah dibatalkan.');
            }
            $selisih = Money::sub($rec->nominal, Money::sub($adv->nominal, $adv->nominal_diselesaikan));

            if (Money::gtZero($selisih)) {
                if (! $kodeCoaHutang) {
                    throw new AppException(422, 'Realisasi melampaui uang muka — tentukan akun hutang penampung kekurangannya.');
                }
                if (! CoaDetail::find($kodeCoaHutang)) {
                    throw new AppException(400, 'Akun hutang tidak ditemukan.');
                }
                $rec->update(['kode_coa_hutang' => $kodeCoaHutang]);
            } elseif (Money::isNegative($selisih)) {
                if (! $kodeRekening) {
                    throw new AppException(422, 'Uang muka melebihi realisasi — tentukan kas/rekening penerima pengembaliannya.');
                }
                if (! BankAccount::find($kodeRekening)) {
                    throw new AppException(400, 'Kas/Rekening tidak ditemukan.');
                }
                $rec->update(['kode_rekening' => $kodeRekening]);
            }
            $this->applyPenyelesaian($id, $idPengguna);
        } else {
            if (! $kodeCoaHutang) {
                throw new AppException(422, 'Akun hutang pengajuan wajib untuk jenis pembayaran.');
            }
            $this->setAkunHutang($id, $kodeCoaHutang, $idPengguna);
            $this->applyApproved((string) $id, $idPengguna);
        }

        \App\Models\ApprovalLog::create([
            'id_instance' => $inst->id, 'urutan' => $inst->tahap_sekarang, 'id_pengguna' => $idPengguna,
            'nama_pengguna' => $user->nama, 'aksi' => 'verifikasi',
            'catatan' => $catatan ?? 'Diverifikasi keuangan.', 'waktu' => now(),
        ]);
        if ($tandaiPosted) {
            $inst->update(['posted' => true]);
        }

        return $rec->refresh();
    }

    /**
     * Dipanggil Kas Keluar saat pengajuan dibayar (§4.h). BOLEH SEBAGIAN.
     *
     * Cermin persis dari reversePayment(): sisa berkurang, dan statusnya `lunas`
     * hanya ketika sisanya benar-benar nol. Selama masih bersisa dokumen tetap
     * `diposting` — tak ada status "sebagian" tersendiri, karena yang menentukan
     * boleh-tidaknya dibayar lagi memang sisanya, bukan namanya.
     */
    public function applyPayment(int $id, string $nominal): void
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(400, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->status !== 'diposting') {
            throw new AppException(422, "Pengajuan {$rec->nomor} berstatus {$rec->status}; belum bisa dibayar.");
        }
        $sisa = Money::of($rec->sisa_hutang);
        if (! Money::gtZero($nominal)) {
            throw new AppException(422, "Nominal pembayaran pengajuan {$rec->nomor} harus lebih dari nol.");
        }
        if (Money::gt($nominal, $sisa)) {
            throw new AppException(422, "Nominal melebihi sisa hutang pengajuan {$rec->nomor} sebesar {$sisa}.");
        }

        $baru = Money::sub($sisa, $nominal);
        $rec->update(['sisa_hutang' => $baru, 'status' => Money::isZero($baru) ? 'lunas' : 'diposting']);
    }

    /**
     * Dipanggil Kas Keluar saat KEKURANGAN penyelesaian uang muka dibayar.
     *
     * Jurnalnya (Hutang D / Kas K) milik Kas Keluar; di sini hanya kewajibannya
     * yang ditutup. `sisa_hutang` sengaja TAK disentuh — pada dokumen ini ia
     * memikul nominal uang muka yang diselesaikan, dan dibaca saat pembatalan.
     */
    public function applyKurangBayar(int $id, string $nominal): void
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            throw new AppException(400, 'Pengajuan tidak ditemukan.');
        }
        $sisa = Money::of($rec->sisa_kurang_bayar);
        if (! Money::eq($nominal, $sisa)) {
            throw new AppException(422, "Kekurangan penyelesaian {$rec->nomor} harus dilunasi PENUH sebesar {$sisa}.");
        }
        $rec->update(['sisa_kurang_bayar' => '0', 'status' => 'selesai']);
    }

    /** Kebalikannya: vouchernya di-void, kekurangannya berutang lagi. */
    public function reverseKurangBayar(int $id, string $nominal): void
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            return;
        }
        $rec->update([
            'sisa_kurang_bayar' => Money::add($rec->sisa_kurang_bayar, $nominal),
            'status' => 'diposting',
        ]);
    }

    /** Dipanggil Kas Keluar saat vouchernya di-void: kembalikan sisa hutang. */
    public function reversePayment(int $id, string $nominal): void
    {
        $rec = PengajuanPembayaran::find($id);
        if (! $rec) {
            return;
        }
        $sisa = Money::add($rec->sisa_hutang, $nominal);
        if (Money::gt($sisa, $rec->nominal)) {
            $sisa = Money::of($rec->nominal);
        }
        $rec->update(['sisa_hutang' => $sisa, 'status' => Money::isZero($sisa) ? 'lunas' : 'diposting']);
    }

    /** Uang muka outstanding MILIK pemohon (untuk dropdown penyelesaian). */
    public function uangMukaSaya(int $idPengguna)
    {
        // Nama unit dibawa dari sini, bukan dicari di Blade: layar & dropdown
        // menyebut NAMA unit, sedangkan barisnya hanya menyimpan kodenya.
        $namaUnit = \App\Models\BusinessUnit::pluck('nama_unit', 'kode_unit');

        return OperationalAdvance::where('id_pengguna', $idPengguna)->where('status', 'outstanding')->orderByDesc('id')->get()
            ->filter(fn ($r) => Money::gtZero($r->sisa))
            ->map(fn ($r) => [
                'id' => $r->id, 'nomor_ref' => $r->nomor_ref, 'keterangan' => $r->keterangan,
                'kode_coa_uang_muka' => $r->kode_coa_uang_muka, 'nama_coa_uang_muka' => $r->nama_coa_uang_muka,
                'kode_unit' => $r->kode_unit, 'nama_unit' => $namaUnit[$r->kode_unit] ?? $r->kode_unit,
                'sisa' => $r->sisa,
            ])->values();
    }

    /**
     * Posting PENYELESAIAN uang muka (dipanggil verifikasi): Kredit Uang Muka,
     * Debit realisasi tiap baris, selisih via Kas — lalu kurangi outstanding pool.
     * Menyelesaikan SISA penuh uang muka terpilih.
     */
    public function applyPenyelesaian(int $id, ?int $idPengguna): void
    {
        $rec = PengajuanPembayaran::with('details')->find($id);
        if (! $rec) {
            throw new AppException(404, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->status !== 'diajukan') {
            throw new AppException(409, "Pengajuan berstatus {$rec->status}; tidak dapat diproses.");
        }
        if (! $rec->id_uang_muka) {
            throw new AppException(422, 'Data penyelesaian tidak lengkap (uang muka belum ditunjuk).');
        }
        $adv = OperationalAdvance::find($rec->id_uang_muka);
        if (! $adv || $adv->status === 'void') {
            throw new AppException(400, 'Uang muka tidak valid / sudah dibatalkan.');
        }
        $sisaUM = Money::sub($adv->nominal, $adv->nominal_diselesaikan);
        if (Money::lte($sisaUM, '0')) {
            throw new AppException(409, 'Uang muka ini sudah selesai.');
        }
        $umN = $sisaUM;
        $realN = Money::of($rec->nominal);
        $diff = Money::sub($realN, $umN);
        $uUnit = $adv->kode_unit ?: null;
        $kurang = Money::gtZero($diff);   // realisasi > uang muka → masih harus dibayar
        $lebih = Money::isNegative($diff); // uang muka > realisasi → uang kembali

        // KURANG BAYAR tidak menyentuh kas sama sekali. Uangnya belum keluar,
        // jadi mengkreditkan kas di sini akan membuat saldo & "dana bisa
        // dipakai" lebih rendah daripada kenyataan, sementara kewajiban kepada
        // pemohon tak tercatat di mana pun. Selisihnya ditahan di akun hutang
        // pilihan keuangan, lalu dilunasi lewat Kas Keluar.
        if ($kurang && ! $rec->kode_coa_hutang) {
            throw new AppException(422, 'Realisasi melampaui uang muka — akun hutang penampung kekurangan belum ditentukan keuangan.');
        }
        $rek = null;
        if ($lebih) {
            // KELEBIHAN: uang benar-benar kembali sekarang, jadi kas diakui langsung.
            $rek = BankAccount::with('coa')->find($rec->kode_rekening);
            if (! $rek) {
                throw new AppException(422, 'Uang muka melebihi realisasi — kas/rekening penerima pengembalian belum ditentukan.');
            }
        }

        $lines = [
            ['kode_coa' => $adv->kode_coa_uang_muka, 'nama_coa' => $adv->nama_coa_uang_muka, 'debet' => '0', 'kredit' => $umN, 'keterangan' => "Penyelesaian uang muka {$adv->nomor_ref}", 'kode_unit' => $uUnit],
        ];
        foreach ($rec->details as $d) {
            $lines[] = ['kode_coa' => $d->kode_coa, 'nama_coa' => $d->nama_coa, 'debet' => $d->nominal, 'kredit' => '0', 'keterangan' => $d->keterangan ?? $rec->keterangan, 'kode_bagian' => $rec->kode_bagian, 'kode_unit' => $d->kode_unit];
        }
        if ($kurang) {
            $hutang = CoaDetail::find($rec->kode_coa_hutang);
            $lines[] = ['kode_coa' => $rec->kode_coa_hutang, 'nama_coa' => $hutang?->nama_coa, 'debet' => '0', 'kredit' => $diff, 'keterangan' => "Kekurangan penyelesaian {$rec->nomor} — menunggu Kas Keluar", 'kode_bagian' => $rec->kode_bagian, 'kode_unit' => $uUnit];
        } elseif ($lebih) {
            $lines[] = ['kode_coa' => $rec->kode_rekening, 'nama_coa' => $rek->coa->nama_coa, 'debet' => Money::sub('0', $diff), 'kredit' => '0', 'keterangan' => "Pengembalian sisa uang muka {$rec->nomor}", 'kode_unit' => $uUnit];
        }

        // Kurang bayar berhenti di `diposting` — kewajibannya belum lunas dan
        // harus terlihat di Perintah Pembayaran maupun Kas Keluar. Selebihnya
        // memang sudah tuntas saat ini juga.
        $statusAkhir = $kurang ? 'diposting' : 'selesai';
        $kurangN = $kurang ? $diff : '0';

        DB::transaction(function () use ($rec, $id, $idPengguna, $lines, $umN, $statusAkhir, $kurangN) {
            $entry = PostingService::postJournal([
                'referensi' => $rec->nomor, 'tanggal' => $rec->tanggal,
                'keterangan' => "Penyelesaian uang muka — {$rec->keterangan}",
                'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $rec->id, 'id_pengguna' => $idPengguna, 'lines' => $lines,
            ]);
            // sisa_hutang MENYIMPAN nominal uang muka yang diselesaikan (dipakai saat void)
            // — BUKAN hutang. Kekurangan yang benar-benar masih harus dibayar
            // ada di `sisa_kurang_bayar`, kolomnya sendiri.
            $rec->update([
                'status' => $statusAkhir, 'journal_entry_id' => $entry->id,
                'sisa_hutang' => $umN, 'sisa_kurang_bayar' => $kurangN,
            ]);
            (new OperationalAdvanceService)->applySettlement($rec->id_uang_muka, $umN);
        });
    }

    /**
     * Dipanggil Kas Keluar saat pengajuan UANG MUKA dibayar. Jurnalnya (Uang Muka
     * D / Kas K) milik Kas Keluar. Di sini: tandai lunas + daftarkan tiap baris
     * sebagai OperationalAdvance outstanding di pool bersama (id_pengguna = pemohon).
     */
    public function applyUangMukaPayment(int $id, array $ctx): void
    {
        $rec = PengajuanPembayaran::with('details')->find($id);
        if (! $rec) {
            throw new AppException(400, 'Pengajuan tidak ditemukan.');
        }
        if ($rec->jenis !== 'uang_muka') {
            throw new AppException(422, 'Bukan pengajuan uang muka.');
        }
        if ($rec->status !== 'diverifikasi') {
            throw new AppException(422, "Pengajuan {$rec->nomor} berstatus {$rec->status}; belum siap dibayar.");
        }
        $advSvc = new OperationalAdvanceService;
        foreach ($rec->details as $d) {
            $advSvc->registerOutstanding([
                'tanggal' => $ctx['tanggal'], 'kode_unit' => $d->kode_unit, 'kode_rekening' => $ctx['kode_rekening'],
                'kode_coa_uang_muka' => $d->kode_coa, 'nama_coa_uang_muka' => $d->nama_coa,
                'keterangan' => $d->keterangan ?? $rec->keterangan, 'nominal' => (string) $d->nominal,
                'id_pengguna' => $rec->id_pengguna, 'id_pengajuan_sumber' => $rec->id,
            ]);
        }
        $rec->update(['status' => 'lunas', 'sisa_hutang' => '0']);
    }

    /** Balik pembayaran uang muka saat Kas Keluar-nya di-void. */
    public function reverseUangMukaPayment(int $id): void
    {
        $advances = OperationalAdvance::where('id_pengajuan_sumber', $id)->where('status', '!=', 'void')->get();
        foreach ($advances as $adv) {
            if (Money::gtZero($adv->nominal_diselesaikan)) {
                throw new AppException(409, "Uang muka {$adv->nomor_ref} sudah (sebagian) diselesaikan; batalkan penyelesaiannya dulu sebelum void pembayaran uang muka.");
            }
        }
        OperationalAdvance::where('id_pengajuan_sumber', $id)->where('status', '!=', 'void')->update([
            'status' => 'void', 'void_reason' => 'Void pembayaran uang muka (Kas Keluar dibatalkan)', 'void_at' => now(),
        ]);
        PengajuanPembayaran::where('id', $id)->update(['status' => 'diverifikasi', 'sisa_hutang' => '0']);
    }

    /** Batalkan pengajuan (jalur pembayaran). */
    public function void(int $id, string $alasan, int $idPengguna): PengajuanPembayaran
    {
        return DB::transaction(function () use ($id, $alasan, $idPengguna) {
            $rec = PengajuanPembayaran::find($id);
            if (! $rec) {
                throw new AppException(404, 'Pengajuan tidak ditemukan.');
            }
            if ($rec->status === 'void') {
                throw new AppException(409, 'Pengajuan ini sudah void.');
            }
            if ($rec->status === 'lunas') {
                throw new AppException(409, "Pengajuan {$rec->nomor} sudah dibayar penuh. Void dulu voucher Kas Keluar yang melunasinya.");
            }
            $pelaku = User::find($idPengguna);
            if (! $pelaku) {
                throw new AppException(400, 'Pengguna tidak ditemukan.');
            }

            $entry = JournalEntry::where('sumber_modul', self::SUMBER)->where('id_sumber', (string) $id)->where('status', 'aktif')->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, ['id_pengguna' => $idPengguna, 'keteranganPrefix' => "Void pengajuan ({$alasan}) — "]);
            }
            // Penyelesaian yang di-void: kembalikan outstanding uang muka.
            if ($rec->jenis === 'penyelesaian_uang_muka' && $rec->id_uang_muka) {
                (new OperationalAdvanceService)->reverseSettlement($rec->id_uang_muka, (string) $rec->sisa_hutang);
            }

            $inst = \App\Models\ApprovalInstance::where('jenis_dokumen', self::SUMBER)->where('id_dokumen', (string) $id)->first();
            if ($inst) {
                $inst->update(['status' => 'dibatalkan']);
                \App\Models\ApprovalLog::create([
                    'id_instance' => $inst->id, 'urutan' => $inst->tahap_sekarang, 'id_pengguna' => $idPengguna,
                    'nama_pengguna' => $pelaku->nama, 'aksi' => 'void',
                    'catatan' => 'Dibatalkan (void)'.($entry ? ' — jurnalnya dibalik' : ' — belum berjurnal').". Alasan: {$alasan}", 'waktu' => now(),
                ]);
            }

            $rec->update([
                'status' => 'void', 'sisa_hutang' => '0',
                'void_reason' => $alasan, 'void_by' => $pelaku->nama, 'void_at' => now(),
            ]);

            return $rec;
        });
    }
}
