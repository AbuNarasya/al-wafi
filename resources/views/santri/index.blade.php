@extends('layouts.app')

@php
    $judul = $lingkup === 'calon' ? 'Calon Santri' : 'Santri';
    $c = [
        'calon' => 'bg-gray-100 text-gray-600', 'terbayar' => 'bg-blue-100 text-blue-700',
        'terverifikasi' => 'bg-blue-100 text-blue-700', 'diseleksi' => 'bg-indigo-100 text-indigo-700',
        'diterima' => 'bg-emerald-100 text-emerald-700', 'lolos_kesehatan' => 'bg-emerald-100 text-emerald-700',
        'aktif' => 'bg-emerald-100 text-emerald-700', 'alumni' => 'bg-purple-100 text-purple-700',
        'tidak_lulus' => 'bg-red-100 text-red-700', 'gagal_medcheck' => 'bg-red-100 text-red-700',
        'mengundurkan_diri' => 'bg-gray-100 text-gray-500', 'keluar' => 'bg-gray-100 text-gray-500',
    ];

    // Warna label JALUR: satu warna tetap per jalur, dibagi mengikuti urutan
    // $jalurWarna (waktu dibuat — lihat SantriController) supaya jatah warna
    // jalur lama tak bergeser saat jalur baru ditambahkan. Jalur "reguler"
    // sengaja netral agar jalur khusus (pindahan, beasiswa, …) menonjol.
    // Kelas ditulis di Blade (bukan controller) agar tak terbuang saat build Tailwind.
    $palet = [
        'bg-amber-100 text-amber-800', 'bg-emerald-100 text-emerald-700', 'bg-violet-100 text-violet-700',
        'bg-sky-100 text-sky-700', 'bg-rose-100 text-rose-700', 'bg-teal-100 text-teal-700',
        'bg-orange-100 text-orange-700', 'bg-fuchsia-100 text-fuchsia-700',
    ];
    $warnaJalur = [];
    $iJalur = 0;
    foreach ($jalurWarna as $kodeJalur => $namaJalur) {
        $warnaJalur[$kodeJalur] = str_contains(mb_strtolower($kodeJalur.' '.$namaJalur), 'reguler')
            ? 'bg-slate-100 text-slate-600'
            : $palet[$iJalur++ % count($palet)];
    }
@endphp

@section('title', $judul)

@section('content')
@php
    $adaFilter = $q !== '' || array_filter($filter);
    $rute = $lingkup === 'calon' ? 'santri.calon' : 'santri.aktif';
@endphp

    {{-- Bilah filter meniru pola halaman master (cari + Reset + penghitung),
         tetapi seluruh penyaringan dikerjakan SERVER agar tetap sahih lintas
         halaman — daftar ini berpaginasi. Satu <form> membungkus bilah atas dan
         baris filter kolom di dalam tabel, jadi semuanya terkirim bersamaan. --}}
    <form method="GET" id="filterSantri">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nama / no. daftar / NISN / NIS…" :total="$rows->total()"
                         :reset="route($rute)" :aktif="(bool) $adaFilter" />
        @if ($lingkup === 'calon' && \App\Support\Akses::boleh('santri', 'buat'))
            <a href="{{ route('santri.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Daftarkan Calon</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">No. Daftar / NIS</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">L/P</th><th class="px-4 py-3">Jenjang</th><th class="px-4 py-3">Jalur</th><th class="px-4 py-3">Wali</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Pembayaran</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                {{-- Baris filter per kolom (server-side). --}}
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="jenjang" :options="collect($opsiJenjang)->keys()->mapWithKeys(fn ($k) => [$k => $k])->all()" :value="$filter['jenjang']" />
                    <x-scol name="jalur" :options="$opsiJalur" :value="$filter['jalur']" />
                    <x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" />
                    <x-scol name="bayar" :options="['lunas' => 'Lunas', 'menunggu' => 'Menunggu verifikasi', 'sebagian' => 'Sebagian', 'belum' => 'Belum bayar', 'tanpa_tagihan' => 'Belum ditagih']" :value="$filter['bayar']" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nis ?? $r->no_pendaftaran }}</td>
                        <td class="px-4 py-3">{{ $r->nama }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->jenis_kelamin }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->kode_jenjang ?? '—' }}</td>
                        {{-- Jalur pendaftaran: nama dari master (Pindahan, Anak Karyawan, …);
                             turun ke kode mentah bila baris masternya sudah dihapus. --}}
                        <td class="px-4 py-3">
                            @if ($r->jalur)
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warnaJalur[$r->jalur] ?? 'bg-gray-100 text-gray-600' }}">{{ $r->jalurPendaftaran?->nama ?? $r->jalur }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->wali?->nama ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $c[$r->status] ?? 'bg-gray-100 text-gray-500' }}">{{ \App\Services\Ppsb\Tahap::labelStatus($r->status) }}</span></td>
                        <td class="px-4 py-3"><x-status-bayar :info="$bayar[$r->id] ?? null" /></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('santri.show', $r->id) }}" class="text-brand hover:underline">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada ' . strtolower($judul) . '.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </form>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
