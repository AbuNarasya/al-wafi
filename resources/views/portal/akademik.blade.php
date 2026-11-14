@extends('layouts.portal')

{{-- Menu Akademik · Absensi · Prestasi · Umpan Balik Guru · Kegiatan — dibaca langsung dari ISE (PortalAkademikController). --}}
@section('title', __($judul))
@section('kembali', route('portal.beranda'))

@section('content')
    @php
        $warnaLencana = ['hadir' => 'bg-emerald-100 text-emerald-800', 'terlambat' => 'bg-amber-100 text-amber-800', 'tidak_hadir' => 'bg-red-100 text-red-700',
            'izin' => 'bg-sky-100 text-sky-800', 'uzur' => 'bg-violet-100 text-violet-800', 'lainnya' => 'bg-slate-100 text-slate-600',
            'libur' => 'bg-rose-100 text-rose-700', 'kosong' => 'bg-amber-100 text-amber-800'];
        $rute = 'portal.'.$menu;
        $q = fn (array $x = []) => route($rute, array_filter($x + ['anak' => $pilih?->id, 'tab' => $tab, 'bulan' => $bulan['kode'] ?? null]));
    @endphp

    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white shadow-sm" style="background-color: {{ $warna }}"><x-portal.ikon :nama="$ikon" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __($judul) }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $pilih?->nama ?? __('Belum ada anak berstatus santri aktif.') }}</p>
        </div>
    </div>

    @if ($anak->count() > 1)
        <div class="mb-3 flex gap-2 overflow-x-auto text-sm" data-pilih-anak>
            @foreach ($anak as $a)
                <a href="{{ route($rute, array_filter(['anak' => $a->id, 'tab' => $tab, 'bulan' => $bulan['kode'] ?? null])) }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => $pilih?->id === $a->id, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih?->id !== $a->id])>{{ \Illuminate\Support\Str::of($a->nama)->before(' ')->title() }}</a>
            @endforeach
        </div>
    @endif

    @if (count($tabs) > 1)
        <div class="mb-4 grid gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm" style="grid-template-columns: repeat({{ count($tabs) }}, minmax(0, 1fr))" data-tab-akademik>
            @foreach ($tabs as $kunci => $t)
                <a href="{{ route($rute, array_filter(['anak' => $pilih?->id, 'tab' => $kunci, 'bulan' => $bulan['kode'] ?? null])) }}"
                   @class(['truncate rounded-xl px-1 py-2', 'bg-white font-semibold text-slate-900 shadow-sm' => $tab === $kunci, 'text-slate-600' => $tab !== $kunci])>{{ __($t[0]) }}</a>
            @endforeach
        </div>
    @endif

    @if ($bulan && $pilih)
        {{-- Pilihan bulan & tahun (permintaan user 14 Nov 2026): seluruh catatan bulan terpilih ditampilkan. --}}
        <form method="GET" action="{{ route($rute) }}" class="mb-4 flex items-center gap-2" data-pilih-bulan>
            <input type="hidden" name="anak" value="{{ $pilih->id }}">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($bulan['sebelum'])
                <a href="{{ $q(['bulan' => $bulan['sebelum']]) }}" class="shrink-0 rounded-full bg-white px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200" aria-label="{{ __('Bulan sebelumnya') }}"><span class="inline-block rtl:-scale-x-100">&larr;</span></a>
            @else
                <span class="w-10 shrink-0"></span>
            @endif
            <select name="bln" onchange="this.form.submit()" aria-label="{{ __('Bulan') }}" class="min-w-0 flex-1 rounded-2xl border border-slate-300 bg-white px-3 py-2 text-sm">
                @foreach (range(1, 12) as $m)
                    <option value="{{ $m }}" @selected((int) $bulan['awal']->format('n') === $m)>{{ \Illuminate\Support\Carbon::create(2000, $m, 1)->locale(app()->getLocale())->translatedFormat('F') }}</option>
                @endforeach
            </select>
            <select name="thn" onchange="this.form.submit()" aria-label="{{ __('Tahun') }}" class="w-24 shrink-0 rounded-2xl border border-slate-300 bg-white px-3 py-2 text-sm">
                @foreach ($bulan['tahun'] as $y)
                    <option value="{{ $y }}" @selected((int) $bulan['awal']->format('Y') === $y)>{{ $y }}</option>
                @endforeach
            </select>
            @if ($bulan['sesudah'])
                <a href="{{ $q(['bulan' => $bulan['sesudah']]) }}" class="shrink-0 rounded-full bg-white px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200" aria-label="{{ __('Bulan berikutnya') }}"><span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
            @else
                <span class="w-10 shrink-0"></span>
            @endif
            <noscript><button class="rounded-2xl bg-brand px-3 py-2 text-sm font-semibold text-white">{{ __('Tampilkan') }}</button></noscript>
        </form>
    @endif

    @if (! $pilih)
        <p class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Menu ini hanya untuk santri aktif.') }}</p>
    @elseif ($gagal)
        <div class="rounded-3xl bg-amber-50 p-5 text-center text-sm text-amber-900 ring-1 ring-amber-200" data-ise-gagal>
            <p class="font-semibold">{{ __('Data dari sistem sekolah sedang tidak bisa dimuat.') }}</p>
            <p class="mt-1">{{ __('Silakan coba lagi beberapa saat lagi.') }}</p>
            <a href="{{ $q(['hal' => request('hal')]) }}" class="mt-3 inline-block rounded-full bg-white px-4 py-1.5 font-semibold text-amber-900 ring-1 ring-amber-300">{{ __('Coba lagi') }}</a>
        </div>
    @else
        @if ($kamar)
            <section class="mb-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200" x-data="{ buka: false }" data-kamar>
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0 text-sm">
                        <p class="text-xs text-slate-500">{{ __('Kamar sekarang') }}</p>
                        <p class="truncate font-semibold text-slate-900">{{ $kamar['kini']['nama'] ?? '—' }}</p>
                    </div>
                    @if (count($kamar['riwayat']) > 1)
                        <button type="button" @click="buka = ! buka" class="shrink-0 text-xs font-semibold text-brand">{{ __('Riwayat kamar') }}</button>
                    @endif
                </div>
                <ul x-show="buka" x-cloak class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-600">
                    @foreach ($kamar['riwayat'] as $k)
                        <li class="flex justify-between gap-2"><span class="truncate">{{ $k['nama'] }}</span>
                            <span class="shrink-0">{{ $k['mulai']?->translatedFormat('j M Y') ?? '?' }} – {{ $k['selesai']?->translatedFormat('j M Y') ?? __('sekarang') }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (! $hasil['dikenal'])
            <p class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Data santri ini belum tersedia di sistem sekolah.') }}</p>
        @elseif ($hari !== null)
            {{-- Absensi per hari (permintaan user 14 Nov 2026): satu kartu per tanggal, sesi berurutan menurut jam. --}}
            <div class="space-y-3" data-per-hari>
                @forelse ($hari as $h)
                    {{-- Hari libur diberi blok warna sendiri (permintaan user 15 Nov 2026) supaya beda dari hari sekolah. --}}
                    @php $libur = ($h['keterangan_hari'][1] ?? null) === 'libur'; @endphp
                    <section @class(['rounded-3xl shadow-sm ring-1', 'bg-white ring-slate-200' => ! $libur, 'bg-rose-50 ring-rose-200' => $libur]) @if ($libur) data-hari-libur @endif>
                        <h2 @class(['border-b px-4 py-2.5 text-sm font-bold', 'border-slate-100 text-slate-800' => ! $libur, 'border-rose-100 text-rose-700' => $libur])>{{ $h['tanggal']?->translatedFormat('l, j M Y') ?? __('Tanpa tanggal') }}</h2>
                        <div class="divide-y divide-slate-100">
                            @if (! empty($h['keterangan_hari']))
                                <div class="px-4 py-2.5 text-sm" data-keterangan-hari="{{ $h['keterangan_hari'][1] }}">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warnaLencana[$h['keterangan_hari'][1]] ?? $warnaLencana['lainnya'] }}">{{ $h['keterangan_hari'][0] }}</span>
                                </div>
                            @endif
                            @foreach ($h['baris'] as $b)
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <div class="min-w-0">
                                        @if ($tab !== 'harian')<p class="truncate font-medium text-slate-900">{{ $b['judul'] }}</p>@endif
                                        @if ($b['keterangan'] && $tab !== 'asrama')<p class="truncate text-xs text-slate-500">{{ $b['keterangan'] }}</p>@endif
                                        @if ($b['lencana'] && $tab === 'harian')
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ($b['lencana'] as [$teks, $kategori])
                                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warnaLencana[$kategori] ?? $warnaLencana['lainnya'] }}">{{ $teks }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    @if ($tab !== 'harian')
                                        <div class="flex shrink-0 items-center gap-2">
                                            @if ($b['waktu'] && ! $b['hanya_tanggal'])<span class="text-xs tabular-nums text-slate-500">{{ $b['waktu']->format('H:i') }}</span>@endif
                                            @foreach ($b['lencana'] as [$teks, $kategori])
                                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warnaLencana[$kategori] ?? $warnaLencana['lainnya'] }}">{{ $teks }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <p class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ $bulan ? __('Tidak ada catatan pada bulan ini.') : __('Belum ada data.') }}</p>
                @endforelse
            </div>

            @if (! $bulan && $hasil['halaman_akhir'] > 1)
                <div class="mt-3 flex items-center justify-between text-sm">
                    @if ($hasil['halaman'] > 1)
                        <a href="{{ $q(['hal' => $hasil['halaman'] - 1]) }}" class="rounded-full bg-white px-4 py-1.5 text-slate-700 ring-1 ring-slate-200"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Sebelumnya') }}</a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-xs text-slate-500">{{ __('Halaman :a dari :b', ['a' => $hasil['halaman'], 'b' => $hasil['halaman_akhir']]) }}</span>
                    @if ($hasil['halaman'] < $hasil['halaman_akhir'])
                        <a href="{{ $q(['hal' => $hasil['halaman'] + 1]) }}" class="rounded-full bg-white px-4 py-1.5 text-slate-700 ring-1 ring-slate-200">{{ __('Berikutnya') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
                    @else
                        <span></span>
                    @endif
                </div>
            @endif
        @else
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200" data-daftar-ise>
                @forelse ($hasil['baris'] as $b)
                    <div class="px-4 py-3 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ $b['judul'] }}</p>
                                @if ($b['keterangan'])<p class="text-xs text-slate-500">{{ $b['keterangan'] }}</p>@endif
                                @if ($b['waktu'])
                                    <p class="text-xs text-slate-500">{{ $b['hanya_tanggal'] ? $b['waktu']->translatedFormat('l, j M Y') : $b['waktu']->translatedFormat('j M Y · H:i') }}</p>
                                @endif
                            </div>
                            @if ($b['nilai'] !== null)
                                <span class="shrink-0 rounded-xl bg-slate-100 px-2.5 py-1 text-sm font-bold tabular-nums text-slate-800">{{ $b['nilai'] }}</span>
                            @endif
                        </div>
                        @if ($b['lencana'])
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach ($b['lencana'] as [$teks, $kategori])
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warnaLencana[$kategori] ?? $warnaLencana['lainnya'] }}">{{ $teks }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if ($b['isi'])
                            <p class="mt-2 whitespace-pre-line text-slate-700">{{ $b['isi'] }}</p>
                        @endif
                    </div>
                @empty
                    <p class="p-6 text-center text-sm text-slate-500">{{ __('Belum ada data.') }}</p>
                @endforelse
            </div>

            @if (in_array($tab, ['rapor', 'sertifikat'], true) && $hasil['baris'])
                <p class="mt-2 px-2 text-xs text-slate-500">{{ __('Berkas PDF belum bisa dibuka dari aplikasi ini. Fitur unduh menyusul.') }}</p>
            @endif

            @if ($hasil['halaman_akhir'] > 1)
                <div class="mt-3 flex items-center justify-between text-sm" data-halaman-ise>
                    @if ($hasil['halaman'] > 1)
                        <a href="{{ $q(['hal' => $hasil['halaman'] - 1]) }}" class="rounded-full bg-white px-4 py-1.5 text-slate-700 ring-1 ring-slate-200"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Sebelumnya') }}</a>
                    @else
                        <span></span>
                    @endif
                    <span class="text-xs text-slate-500">{{ __('Halaman :a dari :b', ['a' => $hasil['halaman'], 'b' => $hasil['halaman_akhir']]) }}</span>
                    @if ($hasil['halaman'] < $hasil['halaman_akhir'])
                        <a href="{{ $q(['hal' => $hasil['halaman'] + 1]) }}" class="rounded-full bg-white px-4 py-1.5 text-slate-700 ring-1 ring-slate-200">{{ __('Berikutnya') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
                    @else
                        <span></span>
                    @endif
                </div>
            @endif
        @endif
    @endif
@endsection
