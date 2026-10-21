@extends('layouts.app')

@section('title', 'Daftar Hadir Ujian')

@section('content')
    @php $jumlahHadir = $peserta->filter(fn ($s) => isset($hadir[$s->id]))->count(); @endphp
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        <select name="ta" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
            @foreach ($ta as $t)<option value="{{ $t }}" @selected($t === $pilih)>{{ $t }}</option>@endforeach
        </select>
        <select name="jenjang" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm">
            <option value="">Semua jenjang</option>
            @foreach ($jenjang as $k => $n)<option value="{{ $k }}" @selected($k === $jenjangPilih)>{{ $n }}</option>@endforeach
        </select>
        <span class="ml-auto text-gray-600"><b>{{ $jumlahHadir }}</b> hadir dari <b>{{ $peserta->count() }}</b> peserta</span>
    </form>
    <p class="mb-3 text-xs text-gray-500">Kehadiran tercatat saat panitia memindai QR di kartu peserta dengan kamera HP (login ERP, hak Absensi Ujian).</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">No. Pendaftaran</th><th class="px-4 py-2">Nama</th><th class="px-4 py-2">L/P</th><th class="px-4 py-2">Jenjang</th><th class="px-4 py-2">Kehadiran</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($peserta as $s)
                    @php $h = $hadir[$s->id] ?? null; @endphp
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs">{{ $s->no_pendaftaran }}</td>
                        <td class="px-4 py-2">{{ $s->nama }}</td>
                        <td class="px-4 py-2">{{ $s->jenis_kelamin }}</td>
                        <td class="px-4 py-2">{{ $s->jenjang?->nama ?? $s->kode_jenjang }}</td>
                        <td class="px-4 py-2">
                            @if ($h)<span class="font-semibold text-emerald-700">Hadir</span> <span class="text-xs text-gray-500">{{ $h->hadir_pada->format('d/m H:i') }} · {{ $h->pencatat?->nama }}</span>
                            @else<span class="text-amber-600">belum hadir</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Belum ada peserta ujian di pilihan ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
