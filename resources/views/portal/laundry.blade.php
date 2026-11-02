@extends('layouts.portal')

@section('title', __('Laundry'))
@section('kembali', route('portal.beranda'))

@section('content')
    @php $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M, H:i'); @endphp
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-cyan-600 text-white shadow-sm"><x-portal.ikon nama="laundry" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Laundry') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ $pilih?->nama ?? __('Belum ada anak berstatus santri aktif.') }}</p>
        </div>
    </div>

    @if ($anak->count() > 1)
        <div class="mb-4 flex gap-2 overflow-x-auto text-sm">
            @foreach ($anak as $a)
                <a href="{{ route('portal.laundry', ['anak' => $a->id]) }}" @class(['shrink-0 rounded-full px-3 py-1.5', 'bg-brand text-white' => $pilih?->id === $a->id, 'bg-white text-slate-600 ring-1 ring-slate-200' => $pilih?->id !== $a->id])>{{ \Illuminate\Support\Str::of($a->nama)->before(' ')->title() }}</a>
            @endforeach
        </div>
    @endif

    @if ($pilih)
        {{-- Kuota bulan ini --}}
        @php
            $persen = (float) $kuota['kuota'] > 0 ? min(100, round((float) $kuota['terpakai'] / (float) $kuota['kuota'] * 100)) : 100;
            $lebih = (float) $kuota['lebih'] > 0;
        @endphp
        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-baseline justify-between">
                <p class="text-sm font-semibold text-slate-900">{{ __('Kuota :bulan', ['bulan' => now()->locale(app()->getLocale())->translatedFormat('F Y')]) }}</p>
                <p class="text-sm tabular-nums text-slate-600"><b>{{ number_format((float) $kuota['terpakai'], 1, ',', '.') }}</b> / {{ number_format((float) $kuota['kuota'], 1, ',', '.') }} kg</p>
            </div>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100">
                <div @class(['h-full rounded-full', 'bg-red-500' => $lebih, 'bg-amber-500' => ! $lebih && $persen >= 80, 'bg-cyan-600' => ! $lebih && $persen < 80]) style="width: {{ $persen }}%"></div>
            </div>
            @if ($lebih)
                <p class="mt-2 text-sm text-red-700">{{ __('Melebihi kuota :kg kg.', ['kg' => number_format((float) $kuota['lebih'], 1, ',', '.')]) }}
                    @if ($kuota['perkiraan'] !== null){{ __('Perkiraan tagihan awal bulan depan:') }} <span class="rp">Rp {{ number_format((float) $kuota['perkiraan'], 0, ',', '.') }}</span>@endif</p>
            @else
                <p class="mt-2 text-sm text-slate-600">{{ __('Sisa kuota :kg kg.', ['kg' => number_format((float) $kuota['sisa'], 1, ',', '.')]) }}</p>
            @endif
            <p class="mt-1 text-xs text-slate-400">{{ __('Kuota berlaku per bulan dan tidak terbawa ke bulan berikutnya.') }}
                @if ($kuota['tarif'] !== null){{ __('Kelebihan ditagih :tarif per kg.', ['tarif' => 'Rp '.number_format((float) $kuota['tarif'], 0, ',', '.')]) }}@endif</p>
        </div>

        {{-- Cucian yang sedang berjalan --}}
        <h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Sedang di laundry') }}</h2>
        @forelse ($berjalan as $l)
            @php $langkah = array_keys(\App\Models\Laundry::LANGKAH); $ke = array_search($l->status, $langkah, true); @endphp
            <div class="mb-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex items-start justify-between gap-2 text-sm">
                    <div>
                        <p class="font-semibold text-slate-900">{{ $l->nomor }}</p>
                        <p class="text-xs text-slate-500">{{ __(':berat kg · :potong potong', ['berat' => number_format((float) $l->berat, 1, ',', '.'), 'potong' => $l->jumlahPotong()]) }}</p>
                    </div>
                    @if ($l->status === 'selesai')<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">{{ __('Siap diambil') }}</span>@endif
                </div>
                <ol class="mt-3 grid grid-cols-4 gap-1 text-center text-[11px]">
                    @foreach (\App\Models\Laundry::LANGKAH_PORTAL as $s => $label)
                        @php $sudah = $loop->index <= $ke; @endphp
                        <li>
                            <span @class(['mx-auto block h-1.5 rounded-full', 'bg-cyan-600' => $sudah, 'bg-slate-200' => ! $sudah])></span>
                            <span @class(['mt-1 block', 'font-semibold text-slate-900' => $sudah, 'text-slate-400' => ! $sudah])>{{ __($label) }}</span>
                            <span class="block text-slate-400">{{ $tgl($l->{"{$s}_pada"}) }}</span>
                        </li>
                    @endforeach
                </ol>
                <details class="mt-2 text-xs text-slate-600">
                    <summary class="cursor-pointer text-brand">{{ __('Daftar pakaian') }}</summary>
                    <ul class="mt-1 space-y-0.5">@foreach ($l->item as $it)<li>{{ $it->nama_pakaian }} × {{ $it->jumlah }}</li>@endforeach</ul>
                </details>
            </div>
        @empty
            <p class="rounded-3xl bg-white p-4 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Tidak ada cucian yang sedang di laundry.') }}</p>
        @endforelse

        {{-- Tagihan kelebihan --}}
        @if ($tagihan->isNotEmpty())
            <h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Tagihan kelebihan kuota') }}</h2>
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($tagihan as $t)
                    <a href="{{ route('portal.tagihan') }}" class="flex items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-slate-50">
                        <span>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $t->periode.'-01')->locale(app()->getLocale())->translatedFormat('F Y') }}
                            <span class="block text-xs text-slate-500">{{ __(':lebih kg di atas kuota :kuota kg', ['lebih' => number_format((float) $t->kg_lebih, 1, ',', '.'), 'kuota' => number_format((float) $t->kuota_kg, 1, ',', '.')]) }}</span></span>
                        <span class="text-end"><span class="rp">Rp {{ number_format((float) $t->nominal, 0, ',', '.') }}</span>
                            <span @class(['block text-xs', 'text-emerald-700' => $t->tagihan?->status === 'lunas', 'text-amber-700' => $t->tagihan?->status !== 'lunas'])>{{ $t->tagihan?->status === 'lunas' ? __('Lunas') : __('Belum lunas') }}</span></span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Riwayat --}}
        <h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Riwayat') }}</h2>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($riwayat as $l)
                <details class="px-4 py-3 text-sm">
                    <summary class="flex cursor-pointer items-start justify-between gap-2">
                        <span><span class="font-medium text-slate-900">{{ $l->diterima_pada->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M Y') }}</span>
                            <span class="block text-xs text-slate-500">{{ __(':berat kg · :potong potong', ['berat' => number_format((float) $l->berat, 1, ',', '.'), 'potong' => $l->jumlahPotong()]) }}</span></span>
                        <span class="text-end text-xs">
                            @if ($l->status === 'dibatalkan')<span class="text-slate-400">{{ __('Dibatalkan') }}</span>
                            @elseif ($l->ada_kurang)<span class="font-semibold text-red-700">{{ __('Ada yang kurang') }}</span>
                            @else<span class="text-emerald-700">{{ __('Sudah diambil') }} {{ $tgl($l->diserahkan_pada) }}</span>@endif
                        </span>
                    </summary>
                    <ul class="mt-2 space-y-0.5 text-xs text-slate-600">
                        @foreach ($l->item as $it)
                            <li>{{ $it->nama_pakaian }} × {{ $it->jumlah }}@if ($it->kurang() > 0) <span class="font-semibold text-red-700">({{ __('kurang :n', ['n' => $it->kurang()]) }})</span>@endif</li>
                        @endforeach
                    </ul>
                    @if ($l->catatan_serah)<p class="mt-1 text-xs text-red-700">{{ __('Catatan petugas:') }} {{ $l->catatan_serah }}</p>@endif
                </details>
            @empty
                <p class="p-6 text-center text-sm text-slate-500">{{ __('Belum ada riwayat laundry.') }}</p>
            @endforelse
        </div>
        @if ($riwayat)<div class="mt-3">{{ $riwayat->links() }}</div>@endif
    @endif
@endsection
