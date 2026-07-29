@extends('layouts.app')

@section('title', 'Laba Rugi')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Unit Bisnis</label>
                <select name="kode_unit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua unit</option>
                    @foreach ($unitOptions as $kode => $nama)
                        <option value="{{ $kode }}" @selected($unit === $kode)>{{ $nama }}</option>
                    @endforeach
                </select></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'laba-rugi'])
    </div>

    <div class="mx-auto max-w-3xl space-y-4">
        @if ($unit)
            <div class="rounded-lg border border-brand/30 bg-brand-soft/50 px-3 py-2 text-sm">
                Menampilkan <b>unit {{ $unitOptions[$unit] ?? $unit }}</b> saja.
            </div>
            @if ((float) $data['tanpa_unit'] > 0)
                {{-- Jujur soal yang tak terhitung: laba semua unit dijumlahkan
                     belum tentu sama dengan laba keseluruhan. --}}
                <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Ada mutasi pendapatan/beban pada periode ini yang <b>belum berunit</b>, senilai @rp($data['tanpa_unit'])
                    (jumlah debet + kredit). Nilai itu tidak masuk laporan unit mana pun, sehingga penjumlahan seluruh unit
                    tidak akan sama dengan laba rugi keseluruhan. Telusuri lewat Jurnal untuk melengkapi unitnya.
                </div>
            @endif
        @endif

        @include('reports._section', ['section' => $data['pendapatan'], 'detail' => ['from' => $from, 'to' => $to, 'kode_unit' => $unit]])
        @include('reports._section', ['section' => $data['beban'], 'detail' => ['from' => $from, 'to' => $to, 'kode_unit' => $unit]])

        <div class="rounded-xl border-2 px-4 py-4 flex items-center justify-between {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }}">
            <span class="text-sm font-semibold {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'text-emerald-800' : 'text-red-800' }}">
                Laba/Rugi Bersih
            </span>
            <span class="text-lg font-bold tabular-nums {{ (float) $data['laba_rugi_bersih'] >= 0 ? 'text-emerald-900' : 'text-red-900' }}">
                @rp($data['laba_rugi_bersih'])
            </span>
        </div>
    </div>
@endsection
