@extends('layouts.app')

@section('title', 'Absensi Ujian')

@section('content')
    @php $s = $data['santri']; @endphp
    <div class="mx-auto max-w-md">
        @if ($galat)
            <div class="mb-4 rounded-xl bg-red-50 p-4 text-sm font-semibold text-red-700 ring-1 ring-red-200">{{ $galat }}</div>
        @elseif ($baru)
            <div class="mb-4 rounded-xl bg-emerald-50 p-4 text-center text-lg font-bold text-emerald-700 ring-1 ring-emerald-200">✓ HADIR — tercatat {{ $hadir->hadir_pada->format('H:i') }}</div>
        @else
            <div class="mb-4 rounded-xl bg-amber-50 p-4 text-center text-sm font-semibold text-amber-800 ring-1 ring-amber-200">
                Sudah tercatat hadir sebelumnya: {{ $hadir->hadir_pada->locale('id')->translatedFormat('j M Y, H:i') }} oleh {{ $hadir->pencatat?->nama ?? '—' }}
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="mx-auto mb-3 flex h-48 w-36 items-center justify-center overflow-hidden rounded-lg bg-gray-100 text-xs text-gray-400">
                @if ($data['foto'])<img src="{{ $data['foto'] }}" alt="" class="h-full w-full object-cover">@else belum ada foto @endif
            </div>
            <div class="text-xl font-bold text-gray-900">{{ $s->nama }}</div>
            <div class="font-mono text-gray-600">{{ $s->no_pendaftaran }}</div>
            <div class="mt-1 text-sm text-gray-600">{{ $data['jenjang'] }} · {{ $data['putri'] ? 'Putri' : 'Putra' }} · {{ $data['jalur'] }}</div>
            <div class="mt-1 text-sm text-gray-500">{{ $data['jadwal'] ? $data['jadwal']['tanggal']->locale('id')->translatedFormat('l, j F Y') : 'Jadwal belum ditetapkan' }}</div>
        </div>
        <a href="{{ route('ujian.daftar_hadir', ['ta' => $s->tahun_ajaran, 'jenjang' => $s->kode_jenjang]) }}" class="mt-4 block text-center text-sm font-semibold text-brand">Daftar hadir &rarr;</a>
    </div>
@endsection
