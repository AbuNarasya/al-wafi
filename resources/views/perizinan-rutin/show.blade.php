@extends('layouts.app')

@section('title', $j->tipe->nama.' — '.$j->mulai->format('d/m/Y'))

@php
    $wkt = fn ($t) => $t?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('D, d M Y H:i');
    $label = fn ($z) => match ($z->status) { 'menunggu_1' => 'Menunggu '.$p->label_tingkat1, 'menunggu_2' => 'Menunggu '.$p->label_tingkat2, default => $z->labelStatus() };
@endphp

@section('content')
    <a href="{{ route('perizinan_rutin.index') }}" class="text-sm text-brand hover:underline">&larr; Perizinan Rutin</a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <p class="text-base font-semibold text-gray-900">{{ $j->tipe->nama }}
                @if ($j->status !== 'aktif')<span class="ms-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">Ditiadakan</span>@endif</p>
            <p class="text-sm text-gray-600">{{ $wkt($j->mulai) }} → {{ $wkt($j->selesai) }}</p>
            <p class="text-xs text-gray-500">{{ $j->labelCakupan() }}@if ($b = $j->batasPengajuan()) · pengajuan ditutup {{ $wkt($b) }}@endif · persetujuan {{ $j->tipe->persetujuan === 'otomatis' ? 'otomatis' : 'oleh petugas' }}</p>
            @if ($j->status !== 'aktif')<p class="mt-1 text-sm text-red-700">Alasan: {{ $j->alasan_ditiadakan }}</p>@endif
        </div>
        @if ($menungguSaya > 0)
            <form method="POST" action="{{ route('perizinan_rutin.setujui_semua', $j->id) }}" data-confirm="Setujui {{ $menungguSaya }} pengajuan yang menunggu keputusan Anda?">
                @csrf
                <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Setujui semua ({{ $menungguSaya }})</button>
            </form>
        @endif
    </div>

    @php
        $tab = ['' => 'Semua santri ('.$jumlah['santri'].')', 'belum' => 'Belum mengajukan',
            'menunggu' => 'Menunggu ('.(($jumlah['menunggu_1'] ?? 0) + ($jumlah['menunggu_2'] ?? 0)).')', 'disetujui' => 'Disetujui ('.($jumlah['disetujui'] ?? 0).')',
            'keluar' => 'Di luar ('.($jumlah['keluar'] ?? 0).')', 'kembali' => 'Kembali ('.($jumlah['kembali'] ?? 0).')', 'ditolak' => 'Ditolak ('.($jumlah['ditolak'] ?? 0).')'];
    @endphp
    <form method="GET" class="mt-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach ($tab as $k => $l)
            <a href="{{ route('perizinan_rutin.show', ['jadwal' => $j->id, 'status' => $k, 'q' => $cari]) }}" @class(['rounded-full px-3 py-1', 'bg-brand text-white' => $status === $k, 'bg-white ring-1 ring-gray-200' => $status !== $k])>{{ $l }}</a>
        @endforeach
        <input type="hidden" name="status" value="{{ $status }}">
        <input type="search" name="q" value="{{ $cari }}" placeholder="Cari nama / NIS" class="ms-auto rounded-lg border-gray-300 text-sm">
    </form>

    <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Santri</th><th class="px-4 py-3">Kelas</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Penjemput</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($baris as $b)
                    @php $s = $b['santri']; $z = $b['izin']; @endphp
                    <tr>
                        <td class="px-4 py-2"><span class="font-medium text-gray-900">{{ $s->nama }}</span> <span class="text-xs text-gray-500">{{ $s->nis }}</span></td>
                        <td class="px-4 py-2 text-gray-600">{{ $s->jenjang?->nama }}{{ $s->kelas ? ' · '.$s->kelas->nama : '' }} · {{ $s->jenis_kelamin === 'L' ? 'Ikhwan' : 'Akhwat' }}</td>
                        <td class="px-4 py-2">
                            @if ($z)
                                <a href="{{ route('perizinan.show', $z->id) }}" @class(['font-semibold hover:underline', 'text-amber-700' => in_array($z->status, \App\Models\Perizinan::MENUNGGU, true), 'text-red-700' => $z->status === 'ditolak' || $z->terlambat(), 'text-gray-800' => ! in_array($z->status, ['menunggu_1', 'menunggu_2', 'ditolak'], true) && ! $z->terlambat()])>{{ $label($z) }}{{ $z->terlambat() ? ' · terlambat' : '' }}</a>
                            @else
                                <span class="text-gray-400">Belum mengajukan</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $z?->penjemput_nama }}{{ $z?->penjemput_jenis === 'wakil' ? ' (diwakilkan)' : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">Tidak ada santri.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
