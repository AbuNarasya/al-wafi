@extends('layouts.app')

@section('title', 'Pencairan ke Wafi Mart')

@section('content')
    @php $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'); @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Belanja kartu santri di Wafi Mart menjadi <b>hutang ke Wafi Mart</b> lewat jurnal malam. Tiap minggu, lunasi hutang itu —
        jurnalnya <b>D Hutang ke Wafi Mart / K Bank</b> — lalu transfer uangnya. Wafi Mart mencatat penerimaannya dengan nomor yang sama;
        sesudah itu pencairan tak bisa dibatalkan di sini.
    </p>

    <form method="GET" class="mb-3 flex items-end gap-2 text-sm">
        <div><label class="mb-1 block text-xs text-gray-500">Sampai tanggal</label>
            <input type="date" name="sampai" value="{{ $sampai }}" max="{{ now()->toDateString() }}" class="rounded-lg border-gray-300 text-sm"></div>
        <button class="rounded-lg border border-gray-300 px-3 py-2 hover:bg-gray-50">Hitung</button>
    </form>

    @if ($menunggu['belum_dijurnal'] > 0)
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span><b>{{ $menunggu['belum_dijurnal'] }}</b> transaksi belum dijurnal malam — belum menjadi hutang, jadi belum bisa dicairkan.
                Transaksi hari ini baru dijurnal besok.</span>
            @if (\App\Support\Akses::boleh('pencairan-toko', 'buat'))
                <form method="POST" action="{{ route('pencairan_toko.jurnal') }}">@csrf
                    <button class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold hover:bg-amber-100">Jalankan jurnal malam sekarang</button>
                </form>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('pencairan_toko.store') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm"
          onsubmit="return confirm('Cairkan {{ $rp($menunggu['total']) }} ke Wafi Mart?')">
        @csrf
        <input type="hidden" name="sampai_tanggal" value="{{ $sampai }}">
        <div class="min-w-[12rem]">
            <div class="font-semibold text-sky-900">Menunggu dicairkan s.d. {{ \Illuminate\Support\Carbon::parse($sampai)->format('d/m/Y') }}</div>
            <div class="text-xs text-sky-800">{{ $menunggu['transaksi']->count() }} transaksi (belanja − pengembalian)</div>
            <div class="text-lg font-bold tabular-nums text-sky-900">{{ $rp($menunggu['total']) }}</div>
        </div>
        @if (\App\Support\Akses::boleh('pencairan-toko', 'buat') && (float) $menunggu['total'] > 0)
            <div class="min-w-[14rem] flex-1"><x-field name="kode_coa_bank" label="Dibayar dari kas/bank" :options="['' => '— pilih —'] + $rekening" required /></div>
            <button class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-700">Cairkan</button>
        @endif
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">Riwayat pencairan</div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">S.d. tanggal</th><th class="px-4 py-2 text-right">Transaksi</th><th class="px-4 py-2 text-right">Dicairkan</th><th class="px-4 py-2">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr>
                        <td class="px-4 py-2 font-mono">{{ $r->nomor }}</td>
                        <td class="px-4 py-2">{{ $r->created_at->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $r->sampai_tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r->jumlah_transaksi }}</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $rp($r->total) }}</td>
                        <td class="px-4 py-2">
                            @if ($r->status === 'batal')
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500" title="{{ $r->alasan_batal }}">Batal</span>
                            @elseif ($r->diterima_toko_pada)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Diterima Wafi Mart {{ $r->diterima_toko_pada->format('d/m') }}</span>
                            @else
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-xs text-sky-700">Menunggu diterima</span>
                                @if (\App\Support\Akses::boleh('pencairan-toko', 'hapus'))
                                    <form method="POST" action="{{ route('pencairan_toko.batal', $r->id) }}" x-data="{ buka: false }" class="relative mt-1">
                                        @csrf
                                        <button type="button" @click="buka = !buka" class="text-xs font-medium text-red-600 hover:underline">Batalkan</button>
                                        <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                                            <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                                            <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Batalkan pencairan</button>
                                        </div>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Belum ada pencairan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
