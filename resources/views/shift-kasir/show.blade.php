@extends('layouts.app')

@section('title', 'Shift '.$shift->nomor)

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
        $bolehUbah = \App\Support\Akses::boleh('shift-kasir', 'ubah');
    @endphp
    <div class="mx-auto max-w-5xl space-y-4">
        <div class="flex items-center justify-between">
            <a href="{{ route('shift_kasir.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($shift->status !== 'buka')
                <a href="{{ route('pos.laporan', $shift->id_shift) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Laporan Tutup Shift</a>
            @endif
        </div>

        <div class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 text-sm shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Shift</div><div class="font-mono font-semibold">{{ $shift->nomor }}</div></div>
            <div><div class="text-xs text-gray-400">Toko · Kasir</div><div>{{ $shift->lokasi?->nama }} · {{ $shift->kasir?->nama }}</div></div>
            <div><div class="text-xs text-gray-400">Dibuka / Ditutup</div><div>{{ $shift->dibuka_pada->format('d/m H:i') }} — {{ $shift->ditutup_pada?->format('d/m H:i') ?? 'masih buka' }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div><div class="font-medium">{{ \App\Models\ShiftKasir::STATUS[$shift->status] }}</div></div>
            <div><div class="text-xs text-gray-400">Modal awal</div><div class="tabular-nums">{{ $rp($shift->modal_awal) }}</div></div>
            @if ($shift->status !== 'buka')
                <div><div class="text-xs text-gray-400">Penjualan tunai</div><div class="tabular-nums">{{ $rp($shift->total_tunai) }}</div></div>
                <div><div class="text-xs text-gray-400">Uang dihitung</div><div class="tabular-nums">{{ $rp($shift->uang_dihitung) }}</div></div>
                <div><div class="text-xs text-gray-400">Selisih</div><div class="font-semibold tabular-nums {{ (float) $shift->selisih < 0 ? 'text-red-600' : '' }}">{{ $rp($shift->selisih) }}</div></div>
            @endif
            @if ($shift->catatan_tutup)<div class="sm:col-span-4 text-xs text-gray-500">Catatan tutup: {{ $shift->catatan_tutup }}</div>@endif
            @if ($shift->status === 'diserahkan')
                <div class="sm:col-span-4 rounded bg-emerald-50 px-3 py-2 text-emerald-800">
                    Setoran {{ $rp($shift->setoran) }} diterima {{ $shift->penerima?->nama }} · {{ $shift->diterima_pada?->format('d/m/Y H:i') }}
                </div>
            @endif
        </div>

        @if ($bolehUbah && $shift->status === 'tutup')
            <form method="POST" action="{{ route('shift_kasir.serah', $shift->id_shift) }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"
                  onsubmit="return confirm('Konfirmasi: uang setoran sudah Anda terima dan hitung?')">
                @csrf
                <div>
                    <div class="font-semibold text-amber-900">Terima setoran {{ $rp(max(0, (float) $shift->uang_dihitung - (float) $shift->modal_awal)) }}</div>
                    <div class="text-xs text-amber-800">Uang dihitung dikurangi modal awal — modal tetap di laci toko.</div>
                </div>
                <div class="min-w-[16rem] flex-1">
                    <x-field name="kode_coa_kas_tujuan" label="Masuk ke kas" :options="['' => '— pilih kas —'] + $kasOptions" required />
                </div>
                <button class="rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white hover:bg-amber-700">Terima Setoran</button>
            </form>
        @endif

        @if ($bolehUbah && $shift->status === 'buka')
            <form method="POST" action="{{ route('shift_kasir.tutup', $shift->id_shift) }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm"
                  x-data="{ uang: '' }" onsubmit="return confirm('Tutup shift kasir ini atas nama pengawas?')">
                @csrf
                <div class="w-full font-semibold text-red-800">Tutup shift atas nama pengawas (kasir lupa menutup)</div>
                <div>
                    <label class="mb-1 block text-xs text-gray-600">Uang di laci (dihitung pengawas)</label>
                    <input type="text" inputmode="numeric" :value="fmtRupiah(uang)" @input="uang = ketikRupiah($event)" class="w-40 rounded border-gray-300 text-right text-sm tabular-nums">
                    <input type="hidden" name="uang_dihitung" :value="uang || 0">
                </div>
                <div class="min-w-[14rem] flex-1">
                    <label class="mb-1 block text-xs text-gray-600">Alasan</label>
                    <input type="text" name="catatan" required class="w-full rounded border-gray-300 text-sm" placeholder="mis. kasir pulang tanpa menutup shift">
                </div>
                <button class="rounded-lg bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-700">Tutup Shift</button>
            </form>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Struk ({{ $struk->count() }})</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Jam</th><th class="px-4 py-2">Barang</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2 text-right">HPP</th><th class="px-4 py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($struk as $j)
                        <tr class="{{ $j->status === 'batal' ? 'text-gray-400' : '' }}">
                            <td class="px-4 py-2 font-mono">{{ $j->nomor }}</td>
                            <td class="px-4 py-2">{{ $j->waktu->format('H:i') }}</td>
                            <td class="px-4 py-2 text-xs">
                                @foreach ($j->details as $d)
                                    <div>{{ $angka($d->kuantiti_input) }} {{ $d->satuan }} {{ $d->persediaan?->nama_persediaan }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $rp($j->total) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $rp($j->hpp) }}</td>
                            <td class="px-4 py-2">
                                @if ($j->status === 'batal')
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">Batal</span>
                                    <div class="text-[11px]">{{ $j->alasan_batal }}</div>
                                @elseif ($bolehUbah && $shift->status === 'buka')
                                    <form method="POST" action="{{ route('shift_kasir.batal_struk', $j->id_penjualan) }}" x-data="{ buka: false }" class="relative">
                                        @csrf
                                        <button type="button" @click="buka = !buka" class="text-xs font-medium text-red-600 hover:underline">Batalkan</button>
                                        <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                            <input type="text" name="alasan" required placeholder="Alasan (mis. salah scan)" class="w-full rounded border-gray-300 text-sm">
                                            <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Batalkan struk</button>
                                        </div>
                                    </form>
                                @else
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Selesai</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada struk.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
