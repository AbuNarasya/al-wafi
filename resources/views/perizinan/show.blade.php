@extends('layouts.app')

@section('title', 'Izin '.$z->nomor)

@php
    $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—';
    $s = $z->santri;
    $status = match ($z->status) {
        'menunggu_1' => 'Menunggu '.$p->label_tingkat1, 'menunggu_2' => 'Menunggu '.$p->label_tingkat2, default => $z->labelStatus(),
    };
@endphp

@section('content')
    <a href="{{ route('perizinan.index') }}" class="text-sm text-brand hover:underline">&larr; Perizinan Insidental</a>

    <div class="mt-3 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-base font-semibold text-gray-900">{{ $s?->nama }}</p>
                        <p class="text-gray-500">{{ $s?->nis }} · {{ $s?->jenjang?->nama }}{{ $s?->kelas ? ' · '.$s->kelas->nama : '' }}</p>
                    </div>
                    <span @class(['rounded-full px-3 py-1 text-xs font-semibold', 'bg-red-100 text-red-800' => $z->terlambat() || $z->status === 'ditolak',
                        'bg-amber-100 text-amber-800' => in_array($z->status, \App\Models\Perizinan::MENUNGGU, true), 'bg-gray-100 text-gray-700' => ! $z->terlambat() && ! in_array($z->status, ['ditolak', 'menunggu_1', 'menunggu_2'], true)])>{{ $status }}</span>
                </div>
                <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-500">Nomor</dt><dd class="font-mono">{{ $z->nomor }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Tipe izin</dt><dd>{{ $z->tipe?->nama }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Keperluan</dt><dd>{{ $z->keperluan ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Rencana keluar</dt><dd>{{ $jam($z->rencana_keluar) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Rencana kembali</dt><dd>{{ $jam($z->rencana_kembali) }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Penjemput</dt>
                        <dd>{{ $z->penjemput_nama ?: '—' }}{{ $z->penjemput_hubungan ? ' ('.$z->penjemput_hubungan.')' : '' }}{{ $z->penjemput_hp ? ' · '.$z->penjemput_hp : '' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Diajukan</dt>
                        <dd>{{ $z->diajukan_oleh ? 'Staf atas nama wali — '.($petugas[$z->diajukan_oleh] ?? '') : 'Wali ('.($z->diajukan_peran ?? '—').') lewat portal' }} · {{ $jam($z->created_at) }}</dd></div>
                    @if ($z->lampiran_path)
                        <div class="sm:col-span-2"><dt class="text-xs text-gray-500">Lampiran</dt><dd><a href="{{ route('perizinan.lampiran', $z->id) }}" target="_blank" class="text-brand hover:underline">{{ $z->lampiran_nama }}</a></dd></div>
                    @endif
                </dl>
            </div>

            @if ($bolehMemutus)
                <div class="grid gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ route('perizinan.setujui', $z->id) }}" data-confirm="Setujui izin {{ $z->nomor }}?" class="rounded-xl border border-emerald-200 bg-white p-4 text-sm shadow-sm">
                        @csrf
                        <p class="font-semibold text-gray-900">Setujui</p>
                        <input name="catatan" maxlength="1000" placeholder="Catatan (opsional)" class="mt-2 w-full rounded-lg border-gray-300 text-sm">
                        <button class="mt-2 rounded-lg bg-emerald-600 px-4 py-2 font-semibold text-white">Setujui</button>
                    </form>
                    <form method="POST" action="{{ route('perizinan.tolak', $z->id) }}" data-confirm="Tolak izin {{ $z->nomor }}?" class="rounded-xl border border-red-200 bg-white p-4 text-sm shadow-sm">
                        @csrf
                        <p class="font-semibold text-gray-900">Tolak</p>
                        <input name="alasan" required maxlength="1000" placeholder="Alasan penolakan *" class="mt-2 w-full rounded-lg border-gray-300 text-sm">
                        <button class="mt-2 rounded-lg border border-red-300 px-4 py-2 font-semibold text-red-700 hover:bg-red-50">Tolak</button>
                    </form>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
            <p class="mb-2 font-semibold text-gray-900">Perjalanan izin</p>
            <ol class="space-y-2">
                @foreach ([['Diajukan', $z->created_at, $z->diajukan_oleh, null],
                           [$p->label_tingkat1, $z->tingkat1_pada, $z->tingkat1_oleh, $z->tingkat1_catatan],
                           [$p->label_tingkat2, $z->tingkat2_pada, $z->tingkat2_oleh, $z->tingkat2_catatan],
                           ['Ditolak', $z->ditolak_pada, $z->ditolak_oleh, $z->alasan_tolak],
                           ['Keluar ('.($z->keluar_cara === 'kartu' ? 'tap kartu' : 'manual').')', $z->keluar_pada, $z->keluar_oleh, null],
                           ['Kembali ('.($z->kembali_cara === 'kartu' ? 'tap kartu' : 'manual').')', $z->kembali_pada, $z->kembali_oleh, null],
                           ['Dibatalkan wali', $z->dibatalkan_pada, null, null]] as [$label, $waktu, $oleh, $catatan])
                    @continue(! $waktu)
                    <li class="flex justify-between gap-2"><span>{{ $label }}@if ($catatan)<span class="block text-xs text-gray-500">{{ $catatan }}</span>@endif</span>
                        <span class="text-end text-xs text-gray-500">{{ $jam($waktu) }}<br>{{ $petugas[$oleh] ?? '' }}</span></li>
                @endforeach
            </ol>
            @if ($z->terlambat())<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-800">Terlambat kembali dari jadwal {{ $jam($z->rencana_kembali) }}.</p>@endif
        </div>
    </div>
@endsection
