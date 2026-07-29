<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\JenisBiaya;
use App\Models\TagihanSantri;
use Illuminate\Support\Facades\DB;

/**
 * Master jenis biaya kesantrian. Akun COA disimpan sebagai string (validasi di
 * sini). Registrasi = cash basis (piutang null); SPP/uang pangkal = akrual.
 */
class JenisBiayaService
{
    /**
     * Perilaku yang tarifnya DICARI PROGRAM lewat JenisBiaya::berlaku(), jadi
     * kombinasi (perilaku, T.A, jenjang, jalur) wajib tunggal. Perilaku "lain"
     * tidak masuk: tagihannya dipilih manual, jadi boleh berganda.
     */
    private const PERILAKU_TUNGGAL = ['registrasi', 'uang_pangkal', 'perlengkapan', 'spp'];

    public function list()
    {
        return JenisBiaya::orderBy('tipe')->orderBy('kode')->get();
    }

    public function get(string $kode): JenisBiaya
    {
        $row = JenisBiaya::find($kode);
        if (! $row) {
            throw new AppException(404, 'Jenis biaya tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): JenisBiaya
    {
        $this->assertAkunAda($data['kode_coa_pendapatan'], $data['kode_coa_piutang'] ?? null);
        $this->assertUnitAda($data['kode_unit']);
        $this->assertBarisTunggal($data);

        return JenisBiaya::create($data);
    }

    public function update(string $kode, array $data): JenisBiaya
    {
        $lama = JenisBiaya::find($kode);
        if (! $lama) {
            throw new AppException(404, 'Jenis biaya tidak ditemukan.');
        }
        $gabungan = array_merge($lama->toArray(), $data);
        $this->assertAkunAda($gabungan['kode_coa_pendapatan'], $gabungan['kode_coa_piutang'] ?? null);
        $this->assertUnitAda($gabungan['kode_unit']);
        $this->assertBarisTunggal($gabungan, $kode);
        $lama->update($data);

        return $lama;
    }

    public function remove(string $kode): void
    {
        $dipakai = TagihanSantri::where('kode_jenis', $kode)->count();
        if ($dipakai > 0) {
            throw new AppException(422, "Jenis biaya ini sudah dipakai {$dipakai} tagihan santri. Nonaktifkan saja — menghapusnya akan memutus riwayat tagihan yang sudah terbit.");
        }
        JenisBiaya::destroy($kode);
    }

    /**
     * PRATINJAU duplikat master ke tahun ajaran lain — dipanggil sebelum menyalin
     * supaya petugas melihat dulu kode barunya dan mana yang tak bisa disalin.
     *
     * Kode adalah primary key, jadi tiap baris WAJIB dapat kode baru. Aturannya:
     * angka dua digit tahun T.A sumber di dalam kode ditukar dengan milik T.A
     * tujuan ("UP-SMP27-REG" → "UP-SMP28-REG"); bila polanya tak ditemukan,
     * kode diberi akhiran tahun tujuan. Nama ikut disesuaikan agar tak menyesatkan.
     *
     * @return list<array{kode:string,kode_baru:string,nama:string,nama_baru:string,tipe:string,cakupan:string,status:string,alasan:?string}>
     */
    public function pratinjauDuplikat(string $taSumber, string $taTujuan): array
    {
        $sumber = JenisBiaya::where('tahun_ajaran', $taSumber)->orderBy('tipe')->orderBy('kode')->get();
        $kodeTerpakai = JenisBiaya::pluck('kode')->flip();

        $hasil = [];
        foreach ($sumber as $j) {
            $kodeBaru = $this->kodeBaru($j->kode, $taSumber, $taTujuan);
            $namaBaru = $this->gantiTahun($j->nama, $taSumber, $taTujuan);

            [$status, $alasan] = match (true) {
                isset($kodeTerpakai[$kodeBaru]) => ['bentrok', "Kode \"{$kodeBaru}\" sudah dipakai."],
                $this->adaCakupanSama($j, $taTujuan) => ['bentrok', 'Cakupan (tipe, jenjang, jalur) itu sudah ada di T.A tujuan.'],
                default => ['siap', null],
            };

            $hasil[] = [
                'kode' => $j->kode, 'kode_baru' => $kodeBaru,
                'nama' => $j->nama, 'nama_baru' => $namaBaru,
                'tipe' => $j->tipe,
                'cakupan' => ($j->kode_jenjang ?: 'Semua jenjang').' · '.($j->kode_jalur ?: 'Semua jalur'),
                'status' => $status, 'alasan' => $alasan,
            ];
        }

        return $hasil;
    }

    /**
     * Salin baris master ke T.A tujuan. Hanya yang berstatus "siap" di pratinjau
     * yang disalin — baris bentrok DILEWATI, bukan menggagalkan seluruh proses,
     * supaya duplikasi bisa dijalankan ulang setelah bentroknya dibereskan.
     *
     * @return array{disalin:int,dilewati:int,kode:list<string>}
     */
    public function duplikat(string $taSumber, string $taTujuan): array
    {
        if ($taSumber === $taTujuan) {
            throw new AppException(422, 'Tahun ajaran sumber dan tujuan tidak boleh sama.');
        }
        $rencana = $this->pratinjauDuplikat($taSumber, $taTujuan);
        $siap = array_values(array_filter($rencana, fn ($r) => $r['status'] === 'siap'));
        if ($siap === []) {
            throw new AppException(422, 'Tidak ada baris yang bisa disalin — semuanya sudah ada di T.A tujuan.');
        }

        $asal = JenisBiaya::whereIn('kode', array_column($siap, 'kode'))->get()->keyBy('kode');

        $kode = DB::transaction(function () use ($siap, $asal, $taTujuan) {
            $dibuat = [];
            foreach ($siap as $r) {
                $lama = $asal[$r['kode']];
                JenisBiaya::create([
                    'kode' => $r['kode_baru'],
                    'nama' => $r['nama_baru'],
                    'tipe' => $lama->tipe,
                    'nominal' => $lama->nominal,
                    'kode_jenjang' => $lama->kode_jenjang,
                    'kode_jalur' => $lama->kode_jalur,
                    'kode_coa_pendapatan' => $lama->kode_coa_pendapatan,
                    'kode_coa_piutang' => $lama->kode_coa_piutang,
                    'kode_coa_diterima_dimuka' => $lama->kode_coa_diterima_dimuka,
                    'kode_unit' => $lama->kode_unit,
                    'berulang' => $lama->berulang,
                    'status' => $lama->status,
                    'tahun_ajaran' => $taTujuan,
                ]);
                $dibuat[] = $r['kode_baru'];
            }

            return $dibuat;
        });

        return ['disalin' => count($kode), 'dilewati' => count($rencana) - count($kode), 'kode' => $kode];
    }

    /** "UP-SMP27-REG" + 2027/2028 → 2028/2029 = "UP-SMP28-REG". */
    private function kodeBaru(string $kode, string $taSumber, string $taTujuan): string
    {
        $pendekSumber = $this->tahunPendek($taSumber);
        $pendekTujuan = $this->tahunPendek($taTujuan);

        if ($pendekSumber !== null && $pendekTujuan !== null) {
            $posisi = strrpos($kode, $pendekSumber);
            if ($posisi !== false) {
                return substr_replace($kode, $pendekTujuan, $posisi, strlen($pendekSumber));
            }
        }

        return $kode.'-'.($pendekTujuan ?? substr($taTujuan, 0, 4));
    }

    /** Tahun penuh & pendek pada nama ikut diganti ("Registrasi SMP 2027" → "… 2028"). */
    private function gantiTahun(string $teks, string $taSumber, string $taTujuan): string
    {
        $penuhSumber = substr($taSumber, 0, 4);
        $penuhTujuan = substr($taTujuan, 0, 4);
        $teks = str_replace([$taSumber, $penuhSumber], [$taTujuan, $penuhTujuan], $teks);

        $pendekSumber = $this->tahunPendek($taSumber);
        $pendekTujuan = $this->tahunPendek($taTujuan);
        if ($pendekSumber !== null && $pendekTujuan !== null) {
            $teks = preg_replace('/\b'.preg_quote($pendekSumber, '/').'\b/', $pendekTujuan, $teks);
        }

        return $teks;
    }

    private function tahunPendek(string $ta): ?string
    {
        return preg_match('/^(\d{4})/', $ta, $m) ? substr($m[1], 2) : null;
    }

    /** Cakupan (tipe, jenjang, jalur) sudah terisi di T.A tujuan? */
    private function adaCakupanSama(JenisBiaya $j, string $taTujuan): bool
    {
        if (! in_array(\App\Models\TipeBiaya::perilakuDari($j->tipe), self::PERILAKU_TUNGGAL, true) || $j->status !== 'aktif') {
            return false; // tipe "lain" & baris nonaktif memang boleh berganda
        }

        // Dibandingkan per PERILAKU: dua tipe berperilaku sama yang mencakup
        // jenjang & jalur yang sama tetap membuat JenisBiaya::berlaku() bimbang.
        return JenisBiaya::where('tahun_ajaran', $taTujuan)
            ->whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku((string) \App\Models\TipeBiaya::perilakuDari($j->tipe)))
            ->where('status', 'aktif')
            ->when($j->kode_jenjang, fn ($q) => $q->where('kode_jenjang', $j->kode_jenjang), fn ($q) => $q->whereNull('kode_jenjang'))
            ->when($j->kode_jalur, fn ($q) => $q->where('kode_jalur', $j->kode_jalur), fn ($q) => $q->whereNull('kode_jalur'))
            ->exists();
    }

    /**
     * Registrasi, uang pangkal, perlengkapan, & SPP dicari program lewat JenisBiaya::berlaku()
     * — jadi kombinasi (tipe, tahun ajaran, jenjang, jalur) HARUS tunggal di
     * antara baris aktif. Tanpa penjaga ini dua baris bersaing dan yang terpilih
     * cuma "urutan kode terkecil": itulah yang dulu membuat calon SMP reguler
     * ditagih tarif SMP OSS. Kolom kosong = cakupan UMUM, juga hanya boleh satu.
     * Tipe "lain" dikecualikan: tagihannya dipilih manual, jadi boleh banyak.
     */
    private function assertBarisTunggal(array $data, ?string $kecualiKode = null): void
    {
        $tipe = $data['tipe'] ?? null;
        if (! in_array(\App\Models\TipeBiaya::perilakuDari($tipe), self::PERILAKU_TUNGGAL, true) || ($data['status'] ?? 'aktif') !== 'aktif') {
            return;
        }

        $kodeJenjang = ($data['kode_jenjang'] ?? null) ?: null;
        $kodeJalur = ($data['kode_jalur'] ?? null) ?: null;
        $bentrok = JenisBiaya::whereIn('tipe', \App\Models\TipeBiaya::kodeBerperilaku((string) \App\Models\TipeBiaya::perilakuDari($tipe)))
            ->where('status', 'aktif')
            ->where('tahun_ajaran', $data['tahun_ajaran'])
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang), fn ($q) => $q->whereNull('kode_jenjang'))
            ->when($kodeJalur, fn ($q) => $q->where('kode_jalur', $kodeJalur), fn ($q) => $q->whereNull('kode_jalur'))
            ->when($kecualiKode, fn ($q) => $q->where('kode', '!=', $kecualiKode))
            ->first();

        if ($bentrok) {
            $label = $kodeJenjang ? "jenjang {$kodeJenjang}" : 'UMUM (semua jenjang)';
            $label .= $kodeJalur ? " jalur {$kodeJalur}" : ' semua jalur';
            $labelTipe = str_replace('_', ' ', $tipe);
            throw new AppException(409, "Tarif {$labelTipe} untuk {$label} pada T.A {$data['tahun_ajaran']} sudah ada di jenis biaya \"{$bentrok->kode}\". Sunting baris itu, nonaktifkan, atau bedakan Jalur-nya — kalau dua baris bercakupan sama, program tak bisa tahu mana tarif yang benar.");
        }
    }

    private function assertUnitAda(string $kodeUnit): void
    {
        $unit = BusinessUnit::find($kodeUnit);
        if (! $unit) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }
        if ($unit->status !== 'aktif') {
            throw new AppException(422, "Unit \"{$unit->nama_unit}\" berstatus nonaktif.");
        }
    }

    private function assertAkunAda(string $kodePendapatan, ?string $kodePiutang): void
    {
        foreach ([[$kodePendapatan, 'Akun pendapatan'], [$kodePiutang, 'Akun piutang']] as [$kode, $label]) {
            if (! $kode) {
                continue;
            }
            $akun = CoaDetail::find($kode);
            if (! $akun) {
                throw new AppException(400, "{$label} \"{$kode}\" tidak ada di Chart of Account.");
            }
            if ($akun->status !== 'aktif') {
                throw new AppException(422, "{$label} \"{$akun->nama_coa}\" berstatus nonaktif.");
            }
        }
    }
}
