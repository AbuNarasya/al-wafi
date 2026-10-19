@extends('layouts.app')

@section('title', 'Pencairan Belanja Kartu')

@section('content')
    @php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Belanja kartu santri sudah menjadi pendapatan toko saat shift ditutup, tetapi uangnya masih di rekening penampung
        titipan uang saku. Tiap minggu, cairkan ke kas/bank toko — jurnalnya <b>D Kas Toko / K Rekening penampung</b>.
        Hanya struk dari shift yang sudah ditutup; retur kartu mengurangi pencairan.
    </p>

    <form method="GET" class="mb-3 flex items-end gap-2 text-sm">
        <div><label class="mb-1 block text-xs text-gray-500">Sampai tanggal</label>
            <input type="date" name="sampai" value="{{ $sampai }}" max="{{ now()->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></div>
        <button class="rounded-lg border border-gray-300 px-3 py-2 hover:bg-gray-50">Hitung</button>
    </form>

    <div class="mb-6 space-y-3">
        @forelse ($menunggu as $m)
            <form method="POST" action="{{ route('pencairan_kartu.store') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm"
                  onsubmit="return confirm('Cairkan {{ $rp($m['total']) }} untuk {{ addslashes($m['toko']->nama) }}?')">
                @csrf
                <input type="hidden" name="kode_lokasi" value="{{ $m['toko']->kode }}">
                <input type="hidden" name="sampai_tanggal" value="{{ $sampai }}">
                <div class="min-w-[12rem]">
                    <div class="font-semibold text-sky-900">{{ $m['toko']->nama }}</div>
                    <div class="text-xs text-sky-800">{{ $m['penjualan']->count() }} struk {{ $rp($m['belanja']) }}@if ((float) $m['kembali'] > 0) − retur {{ $rp($m['kembali']) }}@endif</div>
                    <div class="text-lg font-bold tabular-nums text-sky-900">{{ $rp($m['total']) }}</div>
                </div>
                @if (\App\Support\Akses::boleh('pencairan-kartu', 'buat') && (float) $m['total'] > 0)
                    <div class="min-w-[14rem] flex-1"><x-field name="kode_coa_sumber" label="Dari rekening penampung titipan" :options="['' => '— pilih —'] + $rekening" required /></div>
                    <div class="min-w-[14rem] flex-1"><x-field name="kode_coa_tujuan" label="Ke kas/bank toko" :options="['' => '— pilih —'] + $rekening" required /></div>
                    <button class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">Cairkan</button>
                @endif
            </form>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-400">Tidak ada belanja kartu yang menunggu dicairkan sampai tanggal ini.</div>
        @endforelse
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Riwayat pencairan</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Toko</th><th class="px-4 py-2">S.d. tanggal</th><th class="px-4 py-2 text-right">Belanja</th><th class="px-4 py-2 text-right">Retur</th><th class="px-4 py-2 text-right">Dicairkan</th><th class="px-4 py-2">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $r->nomor }}</td>
                        <td class="px-4 py-2">{{ $r->lokasi?->nama }}</td>
                        <td class="px-4 py-2">{{ $r->sampai_tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $rp($r->total_belanja) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $rp($r->total_retur) }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $rp($r->total) }}</td>
                        <td class="px-4 py-2">
                            @if ($r->status === 'batal')
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Batal</span>
                            @elseif (\App\Support\Akses::boleh('pencairan-kartu', 'hapus'))
                                <form method="POST" action="{{ route('pencairan_kartu.batal', $r->id_pencairan) }}" x-data="{ buka: false }" class="relative">
                                    @csrf
                                    <button type="button" @click="buka = !buka" class="text-xs font-medium text-red-600 hover:underline">Batalkan</button>
                                    <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                        <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Batalkan pencairan</button>
                                    </div>
                                </form>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Dicairkan</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada pencairan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
