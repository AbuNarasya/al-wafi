@extends('layouts.app')

@section('title', 'Neraca Saldo')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex flex-wrap items-end gap-2">
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
        @include('reports._download', ['type' => 'neraca-saldo'])
    </div>

    @php
        $seimbang = $data['seimbang_awal'] && $data['seimbang_mutasi'] && $data['seimbang_akhir'];
    @endphp

    <div class="mb-4 space-y-3">
        @if ($data['disaring_unit'])
            {{-- Laporan per unit memang tak seimbang, dan itu bukan kerusakan:
                 saldo awal tak berdimensi unit, dan baris tanpa unit tak ikut
                 ke unit mana pun. Dikatakan lebih dulu supaya spanduk merah di
                 bawah tidak dibaca sebagai pembukuan yang rusak. --}}
            <div class="rounded-lg border border-brand/30 bg-brand-soft/50 px-3 py-2 text-sm">
                Menampilkan <b>unit {{ $unitOptions[$unit] ?? $unit }}</b> saja. Saldo awal dari menu Saldo Awal
                <b>tidak ikut</b> (barisnya tak berunit), dan baris jurnal yang unitnya kosong juga tidak terhitung —
                karena itu neraca saldo per unit <b>wajar bila tidak seimbang</b>. Untuk menguji keseimbangan buku,
                pilih <b>Semua unit</b>.
            </div>
        @elseif ($seimbang)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                <b>Seimbang.</b> Total debet sama dengan total kredit pada saldo awal, mutasi, dan saldo akhir.
            </div>
        @else
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                <b>TIDAK SEIMBANG.</b> Ada selisih pada:
                @if (! $data['seimbang_awal']) <span class="font-semibold">saldo awal</span>@endif
                @if (! $data['seimbang_mutasi']) <span class="font-semibold">mutasi periode</span>@endif
                @if (! $data['seimbang_akhir']) <span class="font-semibold">saldo akhir</span>@endif.
                Telusuri lewat <a href="{{ route('reports.jurnal', ['from' => $from, 'to' => $to]) }}" class="underline">Jurnal Mentah</a>
                atau <a href="{{ route('reports.buku_besar', ['from' => $from, 'to' => $to]) }}" class="underline">Buku Besar</a>.
            </div>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5" rowspan="2">Akun</th>
                    <th class="px-3 py-2.5" rowspan="2">Kelompok</th>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-center" colspan="2">Saldo Awal</th>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-center" colspan="2">Mutasi Periode</th>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-center" colspan="2">Saldo Akhir</th>
                </tr>
                <tr>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-right">Debet</th>
                    <th class="px-3 py-1.5 text-right">Kredit</th>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-right">Debet</th>
                    <th class="px-3 py-1.5 text-right">Kredit</th>
                    <th class="border-l border-gray-200 px-3 py-1.5 text-right">Debet</th>
                    <th class="px-3 py-1.5 text-right">Kredit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['rows'] as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            <a href="{{ route('reports.buku_besar', ['kode_coa' => $r['kode_coa'], 'from' => $from, 'to' => $to, 'kode_unit' => $unit]) }}"
                               class="text-brand hover:underline">{{ $r['kode_coa'] }}</a>
                            <span class="text-gray-700">— {{ $r['nama_coa'] }}</span>
                        </td>
                        <td class="px-3 py-1.5 text-gray-500">{{ $r['kelompok'] }}</td>
                        <td class="border-l border-gray-100 px-3 py-1.5 text-right tabular-nums">@rp($r['awal_debet'])</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">@rp($r['awal_kredit'])</td>
                        <td class="border-l border-gray-100 px-3 py-1.5 text-right tabular-nums">@rp($r['mutasi_debet'])</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">@rp($r['mutasi_kredit'])</td>
                        <td class="border-l border-gray-100 px-3 py-1.5 text-right font-medium tabular-nums">@rp($r['akhir_debet'])</td>
                        <td class="px-3 py-1.5 text-right font-medium tabular-nums">@rp($r['akhir_kredit'])</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Tidak ada akun bersaldo atau bermutasi pada periode ini.</td></tr>
                @endforelse
            </tbody>
            @if (! empty($data['rows']))
                <tfoot class="bg-gray-50 font-semibold text-gray-900">
                    <tr>
                        <td class="px-3 py-2.5" colspan="2">TOTAL</td>
                        <td class="border-l border-gray-200 px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_awal'] ? '' : 'text-red-700' }}">@rp($data['total']['awal_debet'])</td>
                        <td class="px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_awal'] ? '' : 'text-red-700' }}">@rp($data['total']['awal_kredit'])</td>
                        <td class="border-l border-gray-200 px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_mutasi'] ? '' : 'text-red-700' }}">@rp($data['total']['mutasi_debet'])</td>
                        <td class="px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_mutasi'] ? '' : 'text-red-700' }}">@rp($data['total']['mutasi_kredit'])</td>
                        <td class="border-l border-gray-200 px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_akhir'] ? '' : 'text-red-700' }}">@rp($data['total']['akhir_debet'])</td>
                        <td class="px-3 py-2.5 text-right tabular-nums {{ $data['seimbang_akhir'] ? '' : 'text-red-700' }}">@rp($data['total']['akhir_kredit'])</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Saldo awal = saldo pembuka + seluruh mutasi sebelum {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}.
        Akun yang tidak bersaldo dan tidak bermutasi tidak ditampilkan. Klik kode akun untuk membuka buku besarnya.
    </p>
@endsection
