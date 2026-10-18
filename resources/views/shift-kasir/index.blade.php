@extends('layouts.app')

@section('title', 'Shift Kasir & Setoran')

@section('content')
    @php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    @if ($menunggu || $terbuka)
        <div class="mb-4 flex flex-wrap gap-2 text-sm">
            @if ($menunggu)
                <a href="{{ route('shift_kasir.index', ['status' => 'tutup']) }}" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800 hover:bg-amber-100">
                    <b>{{ $menunggu }}</b> shift ditutup, setorannya belum diterima
                </a>
            @endif
            @if ($terbuka)
                <a href="{{ route('shift_kasir.index', ['status' => 'buka']) }}" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700 hover:bg-red-100">
                    <b>{{ $terbuka }}</b> shift dari hari sebelumnya masih terbuka — jurnal HPP-nya belum terbit
                </a>
            @endif
        </div>
    @endif

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        <div><label class="mb-1 block text-xs text-gray-500">Dari</label><input type="date" name="dari" value="{{ $filter['dari'] }}" class="rounded-lg border-gray-300 text-sm"></div>
        <div><label class="mb-1 block text-xs text-gray-500">Sampai</label><input type="date" name="sampai" value="{{ $filter['sampai'] }}" class="rounded-lg border-gray-300 text-sm"></div>
        <div><label class="mb-1 block text-xs text-gray-500">Toko</label>
            <select name="lokasi" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua toko</option>
                @foreach ($lokasiOptions as $k => $n)<option value="{{ $k }}" @selected($filter['lokasi'] === $k)>{{ $n }}</option>@endforeach
            </select></div>
        <div><label class="mb-1 block text-xs text-gray-500">Status</label>
            <select name="status" class="rounded-lg border-gray-300 text-sm">
                <option value="">Semua</option>
                @foreach (\App\Models\ShiftKasir::STATUS as $k => $l)<option value="{{ $k }}" @selected($filter['status'] === $k)>{{ $l }}</option>@endforeach
            </select></div>
        <button class="rounded-lg bg-brand px-3 py-2 font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
    </form>

    <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Rekap harian per toko</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Toko</th><th class="px-4 py-2 text-right">Shift</th><th class="px-4 py-2 text-right">Struk</th><th class="px-4 py-2 text-right">Omzet</th><th class="px-4 py-2 text-right">Laba Kotor</th><th class="px-4 py-2 text-right">Selisih Kas</th><th class="px-4 py-2">Setoran</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rekap as $r)
                    @php $s = $selisih[$r->tanggal.'|'.$r->kode_lokasi] ?? null; @endphp
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $lokasiOptions[$r->kode_lokasi] ?? $r->kode_lokasi }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->shift }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->struk }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->omzet) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->laba_kotor) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums {{ (float) ($s->selisih ?? 0) < 0 ? 'text-red-600' : '' }}">{{ $rp($s->selisih ?? 0) }}</td>
                        <td class="px-4 py-2">
                            @if (($s->belum_serah ?? 0) > 0)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ $s->belum_serah }} belum diterima</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Lengkap</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Belum ada shift pada rentang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Daftar shift</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Shift</th><th class="px-4 py-2">Toko</th><th class="px-4 py-2">Kasir</th><th class="px-4 py-2">Dibuka</th><th class="px-4 py-2 text-right">Penjualan</th><th class="px-4 py-2 text-right">Selisih</th><th class="px-4 py-2">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2"><a href="{{ route('shift_kasir.show', $r->id_shift) }}" class="font-mono font-medium text-brand hover:underline">{{ $r->nomor }}</a></td>
                        <td class="px-4 py-2">{{ $r->lokasi?->nama }}</td>
                        <td class="px-4 py-2">{{ $r->kasir?->nama }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $r->dibuka_pada->format('d/m H:i') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->status === 'buka' ? '—' : $rp($r->total_tunai) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums {{ (float) $r->selisih < 0 ? 'text-red-600' : '' }}">{{ $r->selisih === null ? '—' : $rp($r->selisih) }}</td>
                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ match ($r->status) { 'buka' => 'bg-blue-100 text-blue-700', 'tutup' => 'bg-amber-100 text-amber-800', default => 'bg-emerald-100 text-emerald-700' } }}">{{ \App\Models\ShiftKasir::STATUS[$r->status] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada shift.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
