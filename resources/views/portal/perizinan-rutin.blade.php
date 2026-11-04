@extends('layouts.portal')

@section('title', __('Perizinan Rutin'))
@section('kembali', route('portal.beranda'))

@section('content')
    @php
        $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('D, j M Y');
        $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->format('H:i');
        $jamLengkap = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M Y, H:i');
    @endphp
    <div class="mb-4 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-700 text-white shadow-sm"><x-portal.ikon nama="kalender" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Perizinan Rutin') }}</h1>
            <p class="truncate text-sm text-slate-500">{{ __('Penjengukan & kepulangan sesuai jadwal pesantren') }}</p>
        </div>
    </div>

    @if ($anak->isEmpty())
        <p class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Belum ada anak berstatus santri aktif.') }}</p>
    @else
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Jadwal terdekat') }}</h2>
        <div class="space-y-3">
            @forelse ($jadwal as $j)
                @php
                    $batas = $j->batasPengajuan();
                    $buka = $j->masihBisaDiajukan();
                    $anakJadwal = $anak->filter(fn ($s) => $j->mencakup($s));
                    $bisaAjukan = false;
                @endphp
                <div @class(['rounded-3xl bg-white p-4 shadow-sm ring-1', 'ring-slate-200' => $j->status === 'aktif', 'ring-red-200 opacity-90' => $j->status !== 'aktif'])>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-wide text-indigo-700">{{ __($j->tipe->nama) }}</p>
                            <p class="font-semibold text-slate-900">{{ $tgl($j->mulai) }}@if (! $j->mulai->isSameDay($j->selesai)) – {{ $tgl($j->selesai) }}@endif</p>
                            <p class="text-sm text-slate-600">{{ $jam($j->mulai) }} → {{ $jam($j->selesai) }}</p>
                        </div>
                        @if ($j->status !== 'aktif')
                            <span class="shrink-0 rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">{{ __('Ditiadakan') }}</span>
                        @endif
                    </div>
                    @if ($j->status !== 'aktif')
                        <p class="mt-2 rounded-2xl bg-red-50 px-3 py-2 text-sm text-red-800">{{ $j->alasan_ditiadakan }}</p>
                    @else
                        @if ($j->catatan)<p class="mt-1 text-sm text-slate-600">{{ $j->catatan }}</p>@endif
                        <p class="mt-1 text-xs {{ $buka ? 'text-amber-700' : 'text-slate-500' }}">
                            @if ($buka)
                                {{ $batas ? __('Pengajuan ditutup :waktu', ['waktu' => $jamLengkap($batas)]) : __('Pengajuan dibuka sampai jadwal dimulai') }}
                            @else
                                {{ __('Pengajuan sudah ditutup') }}
                            @endif
                        </p>
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($anakJadwal as $s)
                                @php
                                    $z = $izin[$j->id.'|'.$s->id][0] ?? null;
                                    $tolak = \App\Services\Modules\TunggakanIzin::hambatan($j->tipe, $s, 'tolak');
                                    $tahan = \App\Services\Modules\TunggakanIzin::hambatan($j->tipe, $s, 'tahan_kembali');
                                    if (! $z && $buka && ! $tolak) { $bisaAjukan = true; }
                                @endphp
                                <li class="flex items-center justify-between gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                                    <span class="min-w-0 truncate font-medium text-slate-800">{{ \Illuminate\Support\Str::of($s->nama)->title() }}</span>
                                    @if ($z)
                                        <a href="{{ route('portal.perizinan.lihat', $z->id) }}">@include('portal._status-izin', ['z' => $z])</a>
                                    @elseif ($tolak)
                                        <span class="shrink-0 text-xs font-semibold text-red-700">{{ __('Tidak dapat diajukan: tunggakan') }}</span>
                                    @elseif ($buka)
                                        <span class="shrink-0 text-xs text-slate-500">{{ __('Belum diajukan') }}</span>
                                    @else
                                        <span class="shrink-0 text-xs text-slate-400">{{ __('Tidak diajukan') }}</span>
                                    @endif
                                </li>
                                @if ($tolak && ! $z)
                                    <li class="px-3 text-xs text-red-700">{{ __('Masih ada :tunggakan. Izin diberikan setelah tagihan diselesaikan.', ['tunggakan' => \App\Services\Modules\TunggakanIzin::teks($tolak)]) }}</li>
                                @elseif ($tahan && (! $z || in_array($z->status, \App\Models\Perizinan::AKTIF, true)))
                                    <li class="px-3 text-xs text-amber-700">{{ __('Perhatian: masih ada :tunggakan. Santri belum dapat kembali ke pondok sebelum tunggakan diselesaikan.', ['tunggakan' => \App\Services\Modules\TunggakanIzin::teks($tahan)]) }}</li>
                                @endif
                            @endforeach
                        </ul>
                        @if ($bisaAjukan)
                            <a href="{{ route('portal.perizinan_rutin.ajukan', $j->id) }}" class="mt-3 block rounded-2xl bg-brand py-2.5 text-center text-sm font-bold text-white">{{ __('Ajukan') }}</a>
                        @endif
                    @endif
                </div>
            @empty
                <p class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Belum ada jadwal dalam 60 hari ke depan.') }}</p>
            @endforelse
        </div>

        @if ($riwayat->isNotEmpty())
            <h2 class="mb-2 mt-6 text-sm font-semibold uppercase tracking-wider text-slate-500">{{ __('Riwayat') }}</h2>
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($riwayat as $z)
                    <a href="{{ route('portal.perizinan.lihat', $z->id) }}" class="flex items-start justify-between gap-2 px-4 py-3 text-sm hover:bg-slate-50">
                        <span class="min-w-0"><span class="block font-medium text-slate-900">{{ __($z->tipe?->nama) }} · {{ $anak->firstWhere('id', $z->id_santri)?->nama }}</span>
                            <span class="block text-xs text-slate-500">{{ $jamLengkap($z->rencana_keluar) }} – {{ $jamLengkap($z->rencana_kembali) }}</span></span>
                        @include('portal._status-izin', ['z' => $z])
                    </a>
                @endforeach
            </div>
        @endif
    @endif
@endsection
