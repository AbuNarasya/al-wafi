<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\Dana;
use App\Models\DanaAkun;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Master dana + laporan pertanggungjawabannya.
 *
 * LAPORAN PER DONATUR adalah alasan utama modul ini ada. "Dana wakaf
 * pembangunan yang Bapak titipkan: diterima sekian, terpakai sekian, sisa
 * sekian" — pertanyaan yang paling sering diajukan donatur dan paling sering
 * tak bisa dijawab, karena sebelumnya tak ada dimensi apa pun yang
 * memisahkannya dari uang lain.
 */
class DanaService
{
    public function daftar(?string $cari = null, ?string $jenis = null)
    {
        return Dana::query()
            ->when($cari, fn ($q) => $q->where(
                fn ($w) => $w->where('kode_dana', 'ilike', "%{$cari}%")
                    ->orWhere('nama_dana', 'ilike', "%{$cari}%")
                    ->orWhere('donatur', 'ilike', "%{$cari}%"),
            ))
            ->when($jenis, fn ($q) => $q->where('jenis', $jenis))
            ->withCount('akun')
            ->orderBy('urutan')->orderBy('kode_dana')
            ->get();
    }

    public function simpan(array $data, ?string $kodeLama = null): Dana
    {
        $kode = trim((string) ($data['kode_dana'] ?? $kodeLama));
        if ($kode === '') {
            throw new AppException(422, 'Kode dana wajib diisi.');
        }

        // Terikat tanpa peruntukan tertulis adalah dana yang pembatasannya
        // hanya hidup di kepala seseorang — dan itulah yang hendak dihapus
        // oleh modul ini.
        if (($data['jenis'] ?? 'tidak_terikat') !== 'tidak_terikat' && trim((string) ($data['peruntukan'] ?? '')) === '') {
            throw new AppException(422, 'Dana terikat wajib menyebutkan peruntukannya — itulah yang membedakannya dari dana biasa.');
        }

        $isi = [
            'nama_dana' => $data['nama_dana'],
            'jenis' => $data['jenis'] ?? 'tidak_terikat',
            'donatur' => $data['donatur'] ?? null,
            'peruntukan' => $data['peruntukan'] ?? null,
            'tanggal_mulai' => $data['tanggal_mulai'] ?? null,
            'tanggal_selesai' => $data['tanggal_selesai'] ?? null,
            'target_nominal' => Money::of($data['target_nominal'] ?? 0),
            'status' => $data['status'] ?? 'aktif',
            'urutan' => (int) ($data['urutan'] ?? 0),
        ];

        try {
            if ($kodeLama) {
                $dana = Dana::findOrFail($kodeLama);
                $dana->update($isi);

                return $dana;
            }

            return Dana::create(['kode_dana' => $kode] + $isi);
        } catch (QueryException) {
            throw new AppException(409, "Kode dana {$kode} sudah dipakai.");
        }
    }

    public function hapus(string $kode): void
    {
        $dana = Dana::find($kode);
        if (! $dana) {
            throw new AppException(404, 'Dana tidak ditemukan.');
        }

        // Dana yang sudah menempel di jurnal adalah bagian riwayat pembukuan.
        // Menghapusnya membuat baris-baris jurnal lama kehilangan penjelasan
        // asal-usulnya — nonaktifkan saja.
        if (DB::table('journal_lines')->where('kode_dana', $kode)->exists()) {
            throw new AppException(409,
                "Dana \"{$dana->nama_dana}\" sudah dipakai di jurnal dan menjadi bagian riwayat pembukuan. "
                .'Ubah statusnya menjadi nonaktif saja.');
        }

        $dana->delete();
    }

    /**
     * Setel daftar akun beban yang boleh dibebani dana ini.
     *
     * @param  list<string>  $kodeCoa  daftar kosong = tanpa pembatasan akun
     */
    public function aturAkun(string $kodeDana, array $kodeCoa): void
    {
        $dana = Dana::find($kodeDana);
        if (! $dana) {
            throw new AppException(404, 'Dana tidak ditemukan.');
        }

        DB::transaction(function () use ($kodeDana, $kodeCoa) {
            DanaAkun::where('kode_dana', $kodeDana)->delete();
            foreach (array_unique(array_filter($kodeCoa)) as $coa) {
                DanaAkun::create(['kode_dana' => $kodeDana, 'kode_coa' => $coa]);
            }
        });
    }

    /**
     * LAPORAN PERTANGGUNGJAWABAN DANA.
     *
     * Diterima = kredit bersih pada akun Pendapatan yang bertanda dana ini.
     * Terpakai = debet bersih pada akun Beban yang bertanda dana ini.
     * Sisa     = diterima − terpakai.
     *
     * Jurnal void dan pembaliknya saling meniadakan, jadi seluruh status ikut
     * dihitung — sama seperti seluruh laporan lain di aplikasi ini.
     *
     * @return array{baris:list<array>,total:array{diterima:string,terpakai:string,sisa:string}}
     */
    public function laporan(?string $from = null, ?string $to = null): array
    {
        $gerak = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->join('coa_detail as c', 'jl.kode_coa', '=', 'c.kode_coa')
            ->join('coa_groups as g', 'c.kode_grup', '=', 'g.kode_grup')
            ->whereNotNull('jl.kode_dana')
            ->when($from, fn ($q) => $q->where('je.tanggal', '>=', $from))
            ->when($to, fn ($q) => $q->where('je.tanggal', '<=', $to))
            ->groupBy('jl.kode_dana', 'c.kode_coa')
            ->selectRaw('jl.kode_dana as kode_dana, c.kode_coa as kode_coa,
                         SUM(jl.debet) as d, SUM(jl.kredit) as k')
            ->get();

        // Akar kelompok akun ditelusuri di PHP: hierarki grupnya berjenjang dan
        // tak bisa diandalkan dari nomor akun (tiap pesantren menomori sendiri).
        $akar = [];
        foreach ($gerak->pluck('kode_coa')->unique() as $kode) {
            $akar[$kode] = CoaDetail::akarKelompok(
                CoaDetail::find($kode)?->kode_grup
            );
        }

        $per = [];
        foreach ($gerak as $g) {
            $per[$g->kode_dana] ??= ['diterima' => '0', 'terpakai' => '0'];
            if (($akar[$g->kode_coa] ?? null) === '4') {
                $per[$g->kode_dana]['diterima'] = Money::add($per[$g->kode_dana]['diterima'], Money::sub($g->k, $g->d));
            } elseif (($akar[$g->kode_coa] ?? null) === '5') {
                $per[$g->kode_dana]['terpakai'] = Money::add($per[$g->kode_dana]['terpakai'], Money::sub($g->d, $g->k));
            }
        }

        $baris = [];
        $total = ['diterima' => '0', 'terpakai' => '0', 'sisa' => '0'];

        foreach (Dana::orderBy('urutan')->orderBy('kode_dana')->get() as $d) {
            $diterima = Money::of($per[$d->kode_dana]['diterima'] ?? '0');
            $terpakai = Money::of($per[$d->kode_dana]['terpakai'] ?? '0');
            $sisa = Money::sub($diterima, $terpakai);

            $baris[] = [
                'kode_dana' => $d->kode_dana,
                'nama_dana' => $d->nama_dana,
                'jenis' => $d->jenis,
                'label_jenis' => $d->labelJenis(),
                'donatur' => $d->donatur,
                'peruntukan' => $d->peruntukan,
                'target' => Money::of($d->target_nominal),
                'status' => $d->status,
                'diterima' => $diterima,
                'terpakai' => $terpakai,
                'sisa' => $sisa,
                // Terpakai melebihi yang diterima berarti dana itu menombok dari
                // uang lain — pelanggaran pembatasan yang paling sering luput,
                // karena kasnya bercampur di rekening yang sama.
                'defisit' => Money::isNegative($sisa),
            ];

            $total['diterima'] = Money::add($total['diterima'], $diterima);
            $total['terpakai'] = Money::add($total['terpakai'], $terpakai);
            $total['sisa'] = Money::add($total['sisa'], $sisa);
        }

        return ['from' => $from, 'to' => $to, 'baris' => $baris, 'total' => array_map(fn ($v) => Money::of($v), $total)];
    }
}
