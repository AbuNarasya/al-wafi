<?php

namespace App\Http\Controllers;

use App\Http\Requests\BagianRequest;
use App\Models\Bagian;
use App\Models\Budget;
use App\Models\JournalLine;
use App\Models\PengajuanPembayaran;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CRUD Bagian (struktur organisasi Yayasan → Bidang → Bagian, maks 3 tingkat).
 * Level dihitung dari induk. Hapus dijaga: dibedakan penghalang STRUKTURAL
 * (sub-bagian/pengguna, bisa dibereskan) vs FINANSIAL (jurnal/anggaran/pengajuan,
 * harus dinonaktifkan, bukan dihapus). Port dari bagian.service.ts.
 */
class BagianController extends Controller
{
    private const MAX_LEVEL = 3;

    public function index(): View
    {
        // Susun tree (DFS urut kode) untuk tampilan collapsable: tiap node dapat
        // depth, has_children, dan daftar ancestors (untuk sembunyi saat induk ditutup).
        $all = Bagian::orderBy('kode_bagian')->get();
        $byKode = $all->keyBy('kode_bagian');
        $children = [];
        $roots = [];
        foreach ($all as $b) {
            if ($b->kode_induk && $byKode->has($b->kode_induk)) {
                $children[$b->kode_induk][] = $b;
            } else {
                $roots[] = $b; // induk null / yatim → perlakukan sebagai root
            }
        }

        $nodes = [];
        $walk = function ($list, int $depth, array $ancestors) use (&$walk, $children, &$nodes) {
            foreach ($list as $b) {
                $b->depth = $depth;
                $b->has_children = ! empty($children[$b->kode_bagian]);
                $b->ancestors = $ancestors;
                $nodes[] = $b;
                if ($b->has_children) {
                    $walk($children[$b->kode_bagian], $depth + 1, array_merge($ancestors, [$b->kode_bagian]));
                }
            }
        };
        $walk($roots, 0, []);

        return view('bagian.index', ['nodes' => $nodes]);
    }

    public function create(): View
    {
        return view('bagian.form', [
            'bagian' => new Bagian(['status' => 'aktif']),
            'indukOptions' => $this->indukOptions(),
        ]);
    }

    public function store(BagianRequest $request): RedirectResponse
    {
        $data = $request->safe()->only(['kode_bagian', 'nama_bagian', 'kode_induk', 'status', 'keterangan']);

        $level = $this->resolveLevel($data['kode_induk'] ?? null);
        if ($level === null) {
            return back()->withInput()->with('error', $this->pesanLevel);
        }

        Bagian::create([...$data, 'level' => $level]);

        return redirect()->route('bagian.index')->with('status', 'Bagian berhasil ditambahkan.');
    }

    public function edit(Bagian $bagian): View
    {
        return view('bagian.form', [
            'bagian' => $bagian,
            'indukOptions' => $this->indukOptions($bagian->kode_bagian),
        ]);
    }

    public function update(BagianRequest $request, Bagian $bagian): RedirectResponse
    {
        $data = $request->safe()->only(['nama_bagian', 'kode_induk', 'status', 'keterangan']);
        $indukBaru = $data['kode_induk'] ?? null;

        // Bila induk diganti: cek daun & anti self-parent, lalu hitung ulang level.
        if ($indukBaru !== ($bagian->kode_induk ?? null)) {
            if ($indukBaru === $bagian->kode_bagian) {
                return back()->withInput()->with('error', 'Bagian tidak boleh menjadi induk dirinya sendiri.');
            }
            if (Bagian::where('kode_induk', $bagian->kode_bagian)->exists()) {
                return back()->withInput()->with('error', 'Bagian ini sudah memiliki sub-bagian; induknya tidak dapat dipindah.');
            }
            $level = $this->resolveLevel($indukBaru);
            if ($level === null) {
                return back()->withInput()->with('error', $this->pesanLevel);
            }
            $data['level'] = $level;
        }

        $bagian->update($data);

        return redirect()->route('bagian.index')->with('status', 'Bagian berhasil diperbarui.');
    }

    public function destroy(Bagian $bagian): RedirectResponse
    {
        $anak = Bagian::where('kode_induk', $bagian->kode_bagian)->count();
        $users = User::where('kode_bagian', $bagian->kode_bagian)->count();
        $budgets = Budget::where('kode_bagian', $bagian->kode_bagian)->count();
        $jurnal = JournalLine::where('kode_bagian', $bagian->kode_bagian)->count();
        $pengajuan = PengajuanPembayaran::where('kode_bagian', $bagian->kode_bagian)->count();

        $struktural = [];
        if ($anak) $struktural[] = "{$anak} sub-bagian";
        if ($users) $struktural[] = "{$users} pengguna";

        $finansial = [];
        if ($budgets) $finansial[] = "{$budgets} baris anggaran";
        if ($jurnal) $finansial[] = "{$jurnal} baris jurnal";
        if ($pengajuan) $finansial[] = "{$pengajuan} pengajuan pembayaran";

        // Finansial menang: selama jejak keuangan menempel, hapus tidak boleh.
        if ($finansial !== []) {
            $juga = $struktural !== [] ? ' (juga: ' . implode(', ', $struktural) . ')' : '';

            return redirect()->route('bagian.index')->with('error',
                "Bagian \"{$bagian->nama_bagian}\" sudah dipakai transaksi: " . implode(', ', $finansial) . $juga .
                '. Riwayat keuangan tidak boleh berubah diam-diam — ubah statusnya menjadi Nonaktif agar tidak muncul lagi di pilihan.');
        }

        if ($struktural !== []) {
            $cara = [];
            if ($anak) $cara[] = 'hapus dulu sub-bagiannya (mulai dari yang paling bawah)';
            if ($users) $cara[] = 'pindahkan dulu penggunanya ke bagian lain di menu Pengguna';

            return redirect()->route('bagian.index')->with('error',
                "Bagian \"{$bagian->nama_bagian}\" masih menaungi: " . implode(', ', $struktural) .
                '. Belum ada transaksi yang memakainya, jadi masih bisa dihapus — ' . implode(', dan ', $cara) . '.');
        }

        $bagian->delete();

        return redirect()->route('bagian.index')->with('status', 'Bagian berhasil dihapus.');
    }

    private string $pesanLevel = '';

    /**
     * Level dari induk: root = 1, selain itu induk.level + 1. Mengembalikan null
     * bila induk tak ada atau sudah level maksimal (pesan di $this->pesanLevel).
     */
    private function resolveLevel(?string $kodeInduk): ?int
    {
        if (! $kodeInduk) {
            return 1;
        }
        $parent = Bagian::find($kodeInduk);
        if (! $parent) {
            $this->pesanLevel = 'Bagian induk tidak ditemukan.';

            return null;
        }
        if ($parent->level >= self::MAX_LEVEL) {
            $this->pesanLevel = 'Bagian induk sudah level ' . self::MAX_LEVEL .
                '. Struktur organisasi maksimal ' . self::MAX_LEVEL . ' tingkat (Yayasan → Bidang → Bagian).';

            return null;
        }

        return $parent->level + 1;
    }

    /**
     * Opsi induk: hanya bagian level < MAX (boleh punya anak), kecuali diri
     * sendiri saat edit.
     *
     * @return array<string,string>
     */
    private function indukOptions(?string $kecuali = null): array
    {
        return Bagian::query()
            ->where('level', '<', self::MAX_LEVEL)
            ->when($kecuali, fn ($q) => $q->where('kode_bagian', '!=', $kecuali))
            ->orderBy('kode_bagian')
            ->get()
            ->mapWithKeys(fn ($b) => [$b->kode_bagian => "{$b->kode_bagian} — {$b->nama_bagian}"])
            ->all();
    }
}
