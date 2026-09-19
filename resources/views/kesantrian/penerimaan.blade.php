@extends('layouts.app')

@section('title', 'Penerimaan Kesantrian')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Penerimaan Kesantrian per Periode</h1>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">
                Setoran yang <b>sudah diverifikasi</b>, dikelompokkan per jenis biaya dan per bulan.
                Ini uang yang masuk — bukan tagihan yang terbit.
            </p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Jenjang</label>
                <select name="kode_jenjang" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua jenjang</option>
                    @foreach ($jenjangOptions as $kode => $nama)
                        <option value="{{ $kode }}" @selected($jenjang === $kode)>{{ $nama }}</option>
                    @endforeach
                </select></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Perilaku</label>
                <select name="perilaku" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua</option>
                    @foreach ($perilakuOptions as $kode => $nama)
                        <option value="{{ $kode }}" @selected($perilaku === $kode)>{{ $nama }}</option>
                    @endforeach
                </select></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
    </div>

    @php $p = $data['pembanding']; @endphp
    @if (! $p['dimatikan'])
        @if ($p['cocok'])
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800">
                <b>Cocok dengan buku besar.</b> Jumlah setoran sama besar dengan jurnal Pembayaran Santri pada rentang ini.
            </div>
        @else
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
                <b>Selisih terhadap buku besar: @rp($p['selisih']).</b>
                Setoran tercatat @rp($data['total']), jurnal Pembayaran Santri @rp($p['buku_besar']).
                Ada setoran yang tak berjurnal, atau jurnal yang tak berpasangan setoran.
            </div>
        @endif
    @else
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-600">
            Laporan sedang disaring, jadi perbandingan dengan buku besar dimatikan — buku besar tidak mengenal
            jenjang maupun perilaku. Hapus penyaringnya untuk menguji kecocokan.
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5">Jenjang</th>
                    <th class="px-3 py-2.5">Jenis Biaya</th>
                    @foreach ($data['bulan'] as $bl)
                        <th class="px-3 py-2.5 text-right">{{ $bl['label'] }}</th>
                    @endforeach
                    <th class="border-l border-gray-200 px-3 py-2.5 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($data['baris'] as $b)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-1.5 whitespace-nowrap text-gray-500">{{ $b['nama_jenjang'] }}</td>
                        <td class="px-3 py-1.5 text-gray-800">
                            {{ $b['nama_jenis'] }}
                            @if ($b['kode_jenis'] === null)
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">tak terkait tagihan</span>
                            @endif
                        </td>
                        @foreach ($data['bulan'] as $bl)
                            <td class="px-3 py-1.5 text-right tabular-nums {{ (float) $b['per_bulan'][$bl['kunci']] == 0 ? 'text-gray-300' : '' }}">
                                @rp($b['per_bulan'][$bl['kunci']])
                            </td>
                        @endforeach
                        <td class="border-l border-gray-100 px-3 py-1.5 text-right font-semibold tabular-nums">@rp($b['total'])</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($data['bulan']) + 3 }}" class="px-4 py-10 text-center text-gray-400">
                        Belum ada setoran terverifikasi pada rentang ini.
                    </td></tr>
                @endforelse
            </tbody>
            @if (! empty($data['baris']))
                <tfoot class="bg-gray-50 font-semibold text-gray-900">
                    <tr>
                        <td class="px-3 py-2.5" colspan="2">TOTAL</td>
                        @foreach ($data['bulan'] as $bl)
                            <td class="px-3 py-2.5 text-right tabular-nums">@rp($data['total_per_bulan'][$bl['kunci']])</td>
                        @endforeach
                        <td class="border-l border-gray-200 px-3 py-2.5 text-right tabular-nums">@rp($data['total'])</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    <div class="mt-3 flex items-center justify-between gap-3">
        <p class="text-xs text-gray-500">
            Jenjang &amp; jenis biaya diambil dari <b>tagihannya</b> (snapshot saat terbit), bukan dari master yang berlaku sekarang.
        </p>
        <x-unduh :url="route('penerimaan_kesantrian.unduh').'?'.http_build_query(request()->query())" />
    </div>
@endsection
