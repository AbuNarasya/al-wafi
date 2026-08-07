@extends('layouts.app')

@section('title', 'Impor Data Awal')

@section('content')
    <div class="mb-3">
        <h2 class="text-xl font-semibold text-gray-900">Impor Data Awal</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Memasukkan keadaan yang sudah berjalan <b>sebelum</b> aplikasi ini dipakai — bukan alat pemasukan harian.
            Dokumen yang dibuat di sini sengaja <b>tidak menerbitkan jurnal</b>; total saldonya masuk sekali lewat
            <a href="{{ route('opening_balance.index') }}" class="text-brand hover:underline">Saldo Awal</a>.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- Pilihan jenis berkas --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($daftar as $kunci => $label)
            <a href="{{ route('impor_data_awal.index', ['jenis' => $kunci]) }}"
               class="rounded-lg border px-3 py-1.5 text-sm {{ $kunci === $jenis ? 'border-brand bg-brand-soft font-semibold text-brand' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            {{-- Langkah 1 & 2 --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-800">{{ $judul }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $penjelasan }}</p>

                <form method="POST" action="{{ route('impor_data_awal.pratinjau') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="jenis" value="{{ $jenis }}">

                    @foreach ($parameter as $nama => $def)
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">{{ $def['label'] }}</label>
                            @if (($def['tipe'] ?? 'pilih') === 'tanggal')
                                <input type="date" name="param[{{ $nama }}]" value="{{ $param[$nama] ?? '' }}"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                            @else
                                <select name="param[{{ $nama }}]"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                                    <option value="">— belum dipilih —</option>
                                    @foreach ($def['opsi'] as $k => $v)
                                        <option value="{{ $k }}" @selected(($param[$nama] ?? '') === $k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                                @if (count($def['opsi']) === 0)
                                    <p class="mt-1 text-xs text-amber-700">Belum ada pilihan — masternya perlu diisi lebih dulu.</p>
                                @endif
                            @endif
                            @if ($def['ket'])<p class="mt-1 text-xs text-gray-400">{{ $def['ket'] }}</p>@endif
                        </div>
                    @endforeach

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Berkas CSV</label>
                        <input type="file" name="berkas" accept=".csv,text/csv" required
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-gray-100 file:px-3 file:py-1 file:text-sm">
                        <p class="mt-1 text-xs text-gray-400">Pemisah koma maupun titik koma sama-sama diterima. Maksimal 8 MB.</p>
                        @error('berkas')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex items-center gap-2">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Periksa Berkas</button>
                        <a href="{{ route('impor_data_awal.template', $jenis) }}"
                           class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Unduh Template CSV</a>
                    </div>
                </form>
            </div>

            {{-- Langkah 3: hasil pratinjau --}}
            @if ($hasil)
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-800">Hasil Pemeriksaan — {{ $namaBerkas }}</h3>

                    @if ($hasil['kolom_hilang'])
                        <div class="mt-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">
                            Kolom wajib tidak ada di berkas: <b>{{ implode(', ', $hasil['kolom_hilang']) }}</b>.
                            Unduh template lalu salin datanya ke sana.
                        </div>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach ([
                                ['Dibaca', $hasil['jumlah'], 'bg-gray-50 text-gray-700'],
                                ['Siap diimpor', $hasil['siap'], 'bg-emerald-50 text-emerald-700'],
                                ['Dilewati', $hasil['lewati'], 'bg-slate-50 text-slate-600'],
                                ['Bermasalah', $hasil['masalah'], 'bg-red-50 text-red-700'],
                            ] as [$label, $angka, $warna])
                                <div class="rounded-lg px-3 py-2 {{ $warna }}">
                                    <div class="text-xs">{{ $label }}</div>
                                    <div class="text-lg font-semibold tabular-nums">{{ number_format($angka, 0, ',', '.') }}</div>
                                </div>
                            @endforeach
                        </div>

                        @if ($hasil['lewati'] > 0)
                            <p class="mt-3 text-xs text-gray-500">
                                Baris "dilewati" sudah pernah masuk sebelumnya — berkas yang sama memang boleh diunggah ulang.
                            </p>
                        @endif

                        @if ($hasil['baris_masalah'])
                            <div class="mt-4">
                                <div class="mb-1 text-sm font-medium text-gray-700">Baris yang perlu diperbaiki</div>
                                <div class="max-h-80 overflow-y-auto rounded-lg border border-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="sticky top-0 bg-gray-50 text-left uppercase text-gray-500">
                                            <tr><th class="px-3 py-2 w-16">Baris</th><th class="px-3 py-2">Isi</th><th class="px-3 py-2">Sebabnya</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($hasil['baris_masalah'] as $m)
                                                <tr>
                                                    <td class="px-3 py-1.5 tabular-nums text-gray-500">{{ $m['nomor'] }}</td>
                                                    <td class="px-3 py-1.5 text-gray-700">{{ $m['ringkas'] }}</td>
                                                    <td class="px-3 py-1.5 text-red-700">{{ $m['alasan'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-1 text-xs text-gray-400">Nomor baris mengikuti penomoran Excel (baris 1 = judul kolom).</p>
                            </div>
                        @endif

                        @if ($hasil['siap'] > 0)
                            <form method="POST" action="{{ route('impor_data_awal.jalankan') }}" class="mt-4 border-t border-gray-100 pt-4"
                                  onsubmit="return confirm('Impor {{ $hasil['siap'] }} baris? Baris bermasalah tidak ikut dan bisa diunggah ulang setelah diperbaiki.')">
                                @csrf
                                <input type="hidden" name="jenis" value="{{ $jenis }}">
                                <input type="hidden" name="berkas_path" value="{{ $berkasPath }}">
                                @foreach ($param as $nama => $nilai)
                                    <input type="hidden" name="param[{{ $nama }}]" value="{{ $nilai }}">
                                @endforeach
                                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                                    Impor {{ number_format($hasil['siap'], 0, ',', '.') }} Baris
                                </button>
                            </form>
                        @else
                            <p class="mt-4 border-t border-gray-100 pt-4 text-sm text-gray-500">
                                Tidak ada baris yang siap diimpor. Perbaiki dulu berkasnya lalu periksa lagi.
                            </p>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        {{-- Daftar kolom --}}
        <div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold text-gray-800">Kolom yang diminta</h3>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($kolom as $nama => $def)
                            <tr>
                                <td class="py-1.5 pr-2 align-top">
                                    <span class="font-mono text-[11px] text-gray-800">{{ $nama }}</span>
                                    @if ($def['wajib'])<span class="text-red-500">*</span>@endif
                                </td>
                                <td class="py-1.5 align-top text-gray-500">{{ $def['ket'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-400"><span class="text-red-500">*</span> wajib diisi. Urutan kolom bebas; yang dicocokkan namanya.</p>
            </div>
        </div>

        {{-- RIWAYAT IMPOR — dan jalan keluar bila berkasnya ternyata keliru.
             Selama belum ada yang menempel, seluruh barisnya bisa dibuang
             sekaligus; sesudah itu alasannya disebutkan apa adanya, supaya
             petugas tahu apa yang menghalangi, bukan sekadar tak bisa. --}}
        @if ($batch)
            <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700">Riwayat Impor</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2">Waktu</th>
                                <th class="px-4 py-2">Berkas</th>
                                <th class="px-4 py-2">Hasil</th>
                                <th class="px-4 py-2">Oleh</th>
                                <th class="px-4 py-2 text-right">Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($batch as $b)
                                @php $r = $b['rec']; @endphp
                                <tr class="{{ $r->aktif() ? '' : 'bg-gray-50 text-gray-400' }}">
                                    <td class="whitespace-nowrap px-4 py-2">{{ $r->dijalankan_pada->format('d M Y H:i') }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $r->nama_berkas ?? '—' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        @foreach ($r->ringkasan ?? [] as $k => $n)
                                            <span class="mr-2">{{ $k }}: <b>{{ number_format($n, 0, ',', '.') }}</b></span>
                                        @endforeach
                                    </td>
                                    <td class="px-4 py-2 text-xs">{{ $r->pelaksana?->nama ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">
                                        @if (! $r->aktif())
                                            <span class="text-xs">Dibatalkan {{ $r->dibatalkan_pada->format('d M Y') }} — {{ $r->alasan_batal }}</span>
                                        @elseif ($b['halangan'])
                                            <span class="text-xs text-gray-500" title="{{ implode(' ', $b['halangan']) }}">
                                                Tak bisa dibatalkan: {{ $b['halangan'][0] }}
                                            </span>
                                        @elseif (\App\Support\Akses::boleh('impor-data-awal', 'hapus'))
                                            <div x-data="{ open: false }" class="relative inline-block">
                                                <button @click="open = ! open"
                                                        class="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50">Batalkan Impor</button>
                                                <form x-show="open" x-cloak @click.outside="open = false" method="POST"
                                                      action="{{ route('impor_data_awal.batalkan_batch', $r->id) }}"
                                                      class="absolute right-0 z-10 mt-1 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                                    @csrf @method('DELETE')
                                                    <p class="text-xs text-gray-600">Seluruh baris impor ini dihapus. Wali yang sudah ada sebelumnya tidak ikut terhapus.</p>
                                                    <input type="text" name="alasan" required maxlength="255" placeholder="Alasan pembatalan"
                                                           class="w-full rounded border-gray-300 text-xs">
                                                    <button class="w-full rounded bg-red-600 px-2 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Batalkan</button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
