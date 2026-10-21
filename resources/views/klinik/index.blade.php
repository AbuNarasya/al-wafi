@extends('layouts.app')

@section('title', 'Klinik')

@section('content')
    @php $warna = ['dirawat' => 'bg-amber-50 text-amber-700', 'dirujuk' => 'bg-red-50 text-red-700', 'dijemput' => 'bg-red-50 text-red-700']; @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex items-center gap-2 text-sm">
            <input type="date" name="tanggal" value="{{ $tanggal->toDateString() }}" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
            <a href="{{ route('klinik.rekap') }}" class="text-brand hover:underline">Rekap</a>
        </form>
        @if (\App\Support\Akses::boleh('klinik', 'buat'))
            <a href="{{ route('klinik.create') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">+ Catat kunjungan</a>
        @endif
    </div>

    @if ($dirawat->isNotEmpty())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm">
            <p class="mb-1 font-semibold text-amber-800">Sedang dirawat di klinik ({{ $dirawat->count() }})</p>
            @foreach ($dirawat as $k)
                <a href="{{ route('klinik.show', $k->id) }}" class="mr-3 inline-block text-amber-900 hover:underline">{{ $k->santri?->nama }} <span class="text-xs">sejak {{ $k->datang_pada->timezone('Asia/Jakarta')->format('d/m H:i') }}</span></a>
            @endforeach
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Jam</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Keluhan</th><th class="px-4 py-2">Suhu</th><th class="px-4 py-2">Tindak lanjut</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($kunjungan as $k)
                    <tr>
                        <td class="px-4 py-2 tabular-nums"><a href="{{ route('klinik.show', $k->id) }}" class="text-brand hover:underline">{{ $k->datang_pada->timezone('Asia/Jakarta')->format('H:i') }}</a></td>
                        <td class="px-4 py-2">{{ $k->santri?->nama }}<div class="text-xs text-gray-500">{{ $k->santri?->nis }}{{ $k->santri?->kelas ? ' · '.$k->santri->kelas->nama : '' }}</div></td>
                        <td class="px-4 py-2">{{ \Illuminate\Support\Str::limit($k->keluhan, 60) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ $k->suhu ? $k->suhu.'°' : '—' }}</td>
                        <td class="px-4 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $warna[$k->tindak_lanjut] ?? 'bg-gray-100 text-gray-600' }}">{{ $k->labelTindakLanjut() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada kunjungan pada {{ $tanggal->locale('id')->translatedFormat('j F Y') }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
