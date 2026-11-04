@extends('layouts.portal')

@section('title', $z->id_jadwal ? __('Perizinan Rutin') : __('Perizinan Insidental'))
@section('kembali', $z->id_jadwal ? route('portal.perizinan_rutin') : route('portal.perizinan', ['anak' => $z->id_santri]))

@section('content')
    @php $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('j M Y, H:i'); @endphp
    <div class="mt-2 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-2">
            <div><p class="text-lg font-bold text-slate-900">{{ __($z->tipe?->nama) }}</p>
                <p class="text-sm text-slate-500">{{ $z->santri?->nama }} · {{ $z->nomor }}</p></div>
            @include('portal._status-izin', ['z' => $z])
        </div>
        <dl class="mt-4 space-y-2 text-sm">
            @if ($z->keperluan)<div><dt class="text-xs text-slate-500">{{ __('Keperluan') }}</dt><dd class="text-slate-800">{{ $z->keperluan }}</dd></div>@endif
            <div class="grid grid-cols-2 gap-2">
                <div><dt class="text-xs text-slate-500">{{ __('Waktu keluar') }}</dt><dd class="text-slate-800">{{ $jam($z->rencana_keluar) }}</dd></div>
                <div><dt class="text-xs text-slate-500">{{ __('Waktu kembali') }}</dt><dd class="text-slate-800">{{ $jam($z->rencana_kembali) }}</dd></div>
            </div>
            @if ($z->penjemput_nama)<div><dt class="text-xs text-slate-500">{{ __('Penjemput') }}@if ($z->penjemput_jenis === 'wakil') · {{ __('Diwakilkan') }}@elseif ($z->penjemput_jenis === 'wali') · {{ __('Dijemput wali') }}@endif</dt>
                <dd class="text-slate-800">{{ $z->penjemput_nama }}{{ $z->penjemput_hubungan ? ' ('.__($z->penjemput_hubungan).')' : '' }}{{ $z->penjemput_hp ? ' · '.$z->penjemput_hp : '' }}
                    @if ($z->penjemput_foto_path)<a href="{{ route('portal.perizinan.foto_penjemput', $z->id) }}" target="_blank" class="block text-brand">{{ __('Foto KTP / identitas') }}</a>@endif</dd></div>@endif
            @if ($z->lampiran_path)<div><dt class="text-xs text-slate-500">{{ __('Lampiran') }}</dt><dd><a href="{{ route('portal.perizinan.lampiran', $z->id) }}" target="_blank" class="text-brand">{{ $z->lampiran_nama }}</a></dd></div>@endif
            @if ($z->status === 'ditolak')<div class="rounded-2xl bg-red-50 p-3 text-red-800"><dt class="text-xs">{{ __('Alasan ditolak') }}</dt><dd>{{ $z->alasan_tolak }}</dd></div>@endif
        </dl>

        {{-- Perjalanan izin --}}
        <ol class="mt-4 space-y-2 border-s-2 border-slate-200 ps-4 text-xs">
            @foreach ([['Diajukan', $z->created_at], ['Disetujui', in_array($z->status, ['disetujui', 'keluar', 'kembali'], true) ? ($z->tingkat2_pada ?? $z->tingkat1_pada ?? ($z->disetujui_otomatis ? $z->created_at : null)) : null],
                       ['Ditolak', $z->ditolak_pada], ['Keluar pondok', $z->keluar_pada], ['Kembali di pondok', $z->kembali_pada], ['Dibatalkan', $z->dibatalkan_pada]] as [$label, $waktu])
                @continue(! $waktu)
                <li class="relative"><span class="absolute -start-[1.4rem] top-0.5 h-2.5 w-2.5 rounded-full bg-teal-600 ring-2 ring-white"></span>
                    <span class="font-semibold text-slate-800">{{ __($label) }}</span> <span class="block text-slate-500">{{ $jam($waktu) }}</span></li>
            @endforeach
        </ol>
    </div>

    @if (in_array($z->status, ['menunggu_1', 'menunggu_2', 'disetujui'], true))
        <form method="POST" action="{{ route('portal.perizinan.batal', $z->id) }}" data-confirm="{{ __('Batalkan pengajuan izin ini?') }}" class="mt-4">
            @csrf
            <button class="w-full rounded-2xl bg-white py-3 text-sm font-bold text-red-700 ring-1 ring-red-200">{{ __('Batalkan pengajuan') }}</button>
        </form>
    @endif
@endsection
