@extends('layouts.app')

@section('title', 'Rencana Angsuran — ' . $d['nama'])

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-3 flex items-center justify-between">
            <a href="{{ route('angsuran_uang_pangkal.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <a href="{{ route('angsuran_uang_pangkal.cetak_detail', $d['id_santri']) }}" target="_blank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">🖨 Cetak</a>
        </div>

        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Rencana Angsuran — {{ $d['nama'] }}</h2>
            <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">No. Pendaftaran</span><b class="font-mono">{{ $d['no_pendaftaran'] }}</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Wali</span><b>{{ $d['nama_wali'] ?? '—' }}</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Telepon wali</span><b>{{ $d['telepon_wali'] ?? '—' }}</b></div>
            </div>
            <p class="mt-3 text-xs text-gray-400">
                Uang pangkal dan biaya perlengkapan punya jadwal termin masing-masing; keduanya ditagihkan bersamaan
                tetapi dibayar dan dijadwalkan terpisah.
            </p>
        </div>

        {{-- Dua bagian berurutan: uang pangkal dulu, lalu perlengkapan. --}}
        <div class="space-y-4">
            @foreach ($d['komponen'] as $kunci => $bagian)
                @if ($bagian)
                    @include('angsuran-uang-pangkal._komponen', ['d' => $bagian])
                @endif
            @endforeach
        </div>
    </div>

    <script>
        function renegForm(init, total) {
            return {
                rows: init.length ? init : [{ nominal: '', jatuh_tempo: '', keterangan: '' }],
                total,
                get sum() { return this.rows.reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                tambah() { this.rows.push({ nominal: '', jatuh_tempo: '', keterangan: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                fmt(n) { return 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
