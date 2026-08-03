@extends('layouts.app')

@php
    // $judul & $rute datang dari controller — satu sumber untuk keempat daftar
    // (Calon Santri, Santri, Alumni, Santri Keluar).
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
    // Kolom SPP hanya ada di daftar santri aktif (Kependidikan) — calon belum
    // membayar SPP, alumni & yang keluar sudah tak ditagih lagi. Tanggal lulus
    // sebaliknya: hanya bermakna di daftar Alumni. Jumlah kolom ikut berubah,
    // jadi colspan dihitung, bukan dipaku.
    $adaKolomSpp = $lingkup === 'aktif';
    $adaKolomLulus = $lingkup === 'alumni';
    // Kolom centang hanya di daftar Siap Aktivasi — satu-satunya daftar yang
    // punya tindakan massal.
    $adaKolomPilih = $lingkup === 'siap_aktivasi' && \App\Support\Akses::boleh('santri', 'ubah');
    $jumlahKolom = 10 + (int) $adaKolomSpp + (int) $adaKolomLulus + (int) $adaKolomPilih;
@endphp

@if ($adaKolomPilih)
    {{-- Kerangka form aktivasi massal, SENGAJA di luar form filter: form tak boleh
         bersarang. Centang & tombolnya menempel ke sini lewat atribut `form`,
         sehingga keduanya tetap bisa berada di dalam tabel yang dibungkus form GET. --}}
    <form id="aktivasiMassal" method="POST" action="{{ route('santri.aktivasi_massal') }}"
          data-confirm="Aktifkan santri yang dicentang sekarang juga? Jurnal akrual uang pangkal &amp; perlengkapan akan terbit untuk masing-masing.">@csrf</form>
@endif

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

        @if ($adaKolomPilih)
            <button type="submit" form="aktivasiMassal"
                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">
                Aktifkan yang Dicentang
            </button>
        @endif

        {{-- Unduhan mengikuti penyaring yang sedang aktif, bukan seluruh daftar. --}}
        @include('santri._unduh', ['lingkup' => $lingkup])
    </div>

    @if ($lingkup === 'siap_aktivasi')
        <p class="mb-4 rounded-lg border-2 border-indigo-500 bg-white px-4 py-2.5 text-sm text-indigo-950 shadow-sm">
            <b class="uppercase tracking-wide text-indigo-700">Menunggu tahun ajarannya.</b>
            Berkas mereka sudah tuntas dan keputusannya final &mdash; aktivasinya <b>menyala sendiri</b>
            saat tahun ajaran masuk masing-masing dimulai, berikut jurnal akrual uang pangkal &amp;
            perlengkapannya. Sampai saat itu mereka <b>belum</b> ditagih SPP dan belum ikut kenaikan tingkat.
            Yang masuk di tengah tahun ajaran bisa diaktifkan sekarang lewat tombol di atas.
        </p>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>@if ($adaKolomPilih)<th class="px-4 py-3"><input type="checkbox" x-data @change="document.querySelectorAll('[data-pilih-santri]').forEach(c => c.checked = $event.target.checked)" class="rounded border-gray-300"></th>@endif {{-- spasi WAJIB sesudah @endif --}}
                    <th class="px-4 py-3">No. Daftar / NIS</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">L/P</th><th class="px-4 py-3">Jenjang</th><th class="px-4 py-3">{{ $adaKolomLulus ? 'Tingkat Akhir' : 'Tingkat' }}</th><th class="px-4 py-3">Jalur</th><th class="px-4 py-3">Wali</th>@if ($adaKolomSpp)<th class="px-4 py-3 text-right">SPP / bulan</th>@endif {{-- spasi WAJIB: Blade tak mengenali direktif yang menempel langsung sesudah huruf, jadi "@endif@if" membuat @if kedua tak terkompilasi & @endif-nya jadi yatim --}}
                    @if ($adaKolomLulus)<th class="px-4 py-3">Tanggal Lulus</th>@endif
                    <th class="px-4 py-3">Status</th><th class="px-4 py-3">Pembayaran</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                {{-- Baris filter per kolom (server-side). --}}
                <tr class="bg-white">
                    @if ($adaKolomPilih)<x-scol type="blank" />@endif {{-- spasi WAJIB sesudah @endif --}}
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    {{-- Opsinya NAMA jenjang; nilai yang dikirim tetap kodenya. --}}
                    <x-scol name="jenjang" :options="$opsiJenjang" :value="$filter['jenjang']" />
                    <x-scol name="tingkat" :options="$opsiTingkat" :value="$filter['tingkat']" />
                    <x-scol name="jalur" :options="$opsiJalur" :value="$filter['jalur']" />
                    <x-scol type="blank" />
                    @if ($adaKolomSpp)<x-scol type="blank" />@endif
                    @if ($adaKolomLulus)<x-scol type="blank" />@endif
                    {{-- Daftar berstatus TUNGGAL (Alumni, Santri Keluar) tak punya
                         penyaring status — opsinya dikirim kosong oleh controller. --}}
                    @if ($opsiStatus)
                        <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" />
                    @else
                        <x-scol type="blank" />
                    @endif
                    <x-scol name="bayar" :options="['lunas' => 'Lunas', 'menunggu' => 'Menunggu verifikasi', 'sebagian' => 'Sebagian', 'belum' => 'Belum bayar', 'tanpa_tagihan' => 'Belum ditagih']" :value="$filter['bayar']" />
                    <x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        @if ($adaKolomPilih)
                            <td class="px-4 py-3">
                                <input type="checkbox" name="id_santri[]" value="{{ $r->id }}" form="aktivasiMassal"
                                       data-pilih-santri class="rounded border-gray-300">
                            </td>
                        @endif

                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nis ?? $r->no_pendaftaran }}</td>
                        <td class="px-4 py-3">{{ $r->nama }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->jenis_kelamin }}</td>
                        {{-- Nama jenjangnya; kodenya baru dipakai bila baris masternya
                             sudah dihapus, supaya barisnya tak jadi sel kosong. --}}
                        <td class="px-4 py-3 text-gray-500">{{ $r->jenjang?->nama ?? $r->kode_jenjang ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->tingkat ? 'Tingkat '.$r->tingkat : '—' }}</td>
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
                        @if ($adaKolomSpp)
                            {{-- Nominal SPP yang BENAR-BENAR berlaku bagi santri ini, beserta
                                 asalnya: khusus (beasiswa/keringanan) menang atas tarif jenjang.
                                 Judul (tooltip) memuat kalimat asalnya + alasan keringanannya. --}}
                            @php $s = $spp[$r->id] ?? null; @endphp
                            <td class="px-4 py-3 text-right">
                                @if ($s === null)
                                    <span class="text-gray-300" title="Jenjang atau tahun ajaran berjalan santri ini belum terisi.">—</span>
                                @elseif ($s['status'] === 'khusus')
                                    <span class="tabular-nums font-medium text-gray-900" title="{{ $s['label'] }}@if ($s['keterangan']) — {{ $s['keterangan'] }}@endif">@rp($s['nominal'])</span>
                                    <span class="ml-1 rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium text-violet-700">khusus</span>
                                @elseif ($s['status'] === 'bebas')
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title="{{ $s['label'] }}">Bebas</span>
                                @elseif ($s['status'] === 'kosong')
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700" title="{{ $s['label'] }}">belum diisi</span>
                                @else
                                    <span class="tabular-nums text-gray-600" title="{{ $s['label'] }}">@rp($s['nominal'])</span>
                                @endif
                            </td>
                        @endif
                        @if ($adaKolomLulus)
                            <td class="px-4 py-3 text-gray-600">{{ $r->tanggal_lulus ? $r->tanggal_lulus->format('d/m/Y') : '—' }}</td>
                        @endif
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $c[$r->status] ?? 'bg-gray-100 text-gray-500' }}">{{ \App\Services\Ppsb\Tahap::labelStatus($r->status) }}</span></td>
                        <td class="px-4 py-3"><x-status-bayar :info="$bayar[$r->id] ?? null" /></td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('santri.show', $r->id) }}" class="text-brand hover:underline">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $jumlahKolom }}" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada ' . strtolower($judul) . '.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    </form>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
