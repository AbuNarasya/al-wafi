@extends('layouts.app')

@section('title', 'Kartu Stok — ' . $item->nama_persediaan)

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('inventory.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Persediaan</a>
            <h1 class="mt-1 text-lg font-semibold text-gray-900">
                Kartu Stok — {{ $item->nama_persediaan }}
                <span class="ml-1 font-mono text-sm font-normal text-gray-500">{{ $item->kode_persediaan }}</span>
            </h1>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            @if (count($lokasiOptions) > 1)
                <div><label class="mb-1 block text-xs font-medium text-gray-500">Lokasi</label>
                    <select name="lokasi" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                        <option value="">Semua lokasi</option>
                        @foreach ($lokasiOptions as $kodeLok => $namaLok)
                            <option value="{{ $kodeLok }}" @selected($lokasi === $kodeLok)>{{ $namaLok }}</option>
                        @endforeach
                    </select></div>
            @endif
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs text-gray-500">Stok saat ini</div>
            <div class="text-lg font-semibold tabular-nums text-gray-900">
                {{ rtrim(rtrim(number_format((float) $item->stok_masuk - (float) $item->stok_keluar, 4, '.', ''), '0'), '.') }}
                <span class="text-sm font-normal text-gray-500">{{ $item->satuan }}</span>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs text-gray-500">Nilai persediaan</div>
            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($item->nilai_persediaan)</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs text-gray-500">Harga rata-rata lapisan tersisa</div>
            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($item->harga_perolehan)</div>
        </div>
    </div>

    @if (count($lokasiOptions) > 1 && $stokLokasi)
        <div class="mb-4 flex flex-wrap gap-2 text-xs">
            @foreach ($stokLokasi as $kodeLok => $jml)
                <a href="{{ route('inventory.kartu', [$item->kode_persediaan, 'lokasi' => $kodeLok]) }}"
                   class="rounded-full border px-3 py-1 {{ $lokasi === $kodeLok ? 'border-brand bg-brand-soft text-brand' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
                    {{ $lokasiOptions[$kodeLok] ?? $kodeLok }}: <b class="tabular-nums">{{ rtrim(rtrim(number_format((float) $jml, 4, '.', ''), '0'), '.') }}</b> {{ $item->satuan }}
                </a>
            @endforeach
        </div>
    @endif

    @if ($item->satuanLain->isNotEmpty() || $item->barcode->isNotEmpty())
        <div class="mb-4 rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs text-gray-600 shadow-sm">
            @if ($item->satuanLain->isNotEmpty())
                <div><span class="font-medium text-gray-800">Satuan:</span>
                    @foreach ($item->satuanLain as $s)
                        1 {{ $s->satuan }} = {{ rtrim(rtrim((string) $s->isi, '0'), '.') }} {{ $item->satuan }}@if (! $loop->last), @endif
                    @endforeach
                </div>
            @endif
            @if ($item->barcode->isNotEmpty())
                <div class="mt-1"><span class="font-medium text-gray-800">Barcode:</span>
                    <span class="font-mono">{{ $item->barcode->map(fn ($b) => $b->barcode.($b->satuan ? " ({$b->satuan})" : ''))->join(', ') }}</span>
                </div>
            @endif
        </div>
    @endif

    {{-- Lapisan FIFO yang masih hidup: inilah yang akan tergerus pada
         pengeluaran berikutnya, dan urutan inilah yang menentukan harga pokoknya. --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">
            Lapisan FIFO tersisa
            <span class="ml-1 font-normal text-gray-500">— yang paling atas keluar lebih dulu</span>
        </div>
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Asal</th>
                    <th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2 text-right">Harga Satuan</th>
                    <th class="px-4 py-2 text-right">Nilai</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($lapisan as $l)
                    <tr>
                        <td class="px-4 py-1.5 whitespace-nowrap">{{ $l->tanggal->format('d/m/Y') }}</td>
                        <td class="px-4 py-1.5 text-gray-500">{{ $l->sumber_modul }}{{ $l->sumber_ref ? " · {$l->sumber_ref}" : '' }}</td>
                        <td class="px-4 py-1.5 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $l->kuantiti_sisa, 4, '.', ''), '0'), '.') }}</td>
                        <td class="px-4 py-1.5 text-right tabular-nums">@rp($l->harga_satuan)</td>
                        <td class="px-4 py-1.5 text-right tabular-nums">@rp($l->nilai_sisa)</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Stok habis — tidak ada lapisan tersisa.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-3 py-2.5">Tanggal</th>
                    <th class="px-3 py-2.5">Alasan</th>
                    <th class="px-3 py-2.5">Sumber</th>
                    <th class="px-3 py-2.5 text-right">Masuk</th>
                    <th class="px-3 py-2.5 text-right">Keluar</th>
                    <th class="px-3 py-2.5 text-right">Nilai</th>
                    <th class="border-l border-gray-200 px-3 py-2.5 text-right">{{ $lokasi ? 'Saldo Lokasi' : 'Saldo' }}</th>
                    @unless ($lokasi)
                        <th class="px-3 py-2.5 text-right">Nilai Saldo</th>
                    @endunless
                    <th class="px-3 py-2.5">Oleh</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $m)
                    @php $qty = rtrim(rtrim(number_format((float) $m->kuantiti, 4, '.', ''), '0'), '.'); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $m->tanggal->format('d/m/Y') }}</td>
                        <td class="px-3 py-1.5">
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-medium {{ $m->alasan === 'pembatalan' ? 'bg-red-100 text-red-700' : ($m->alasan === 'opname' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600') }}">
                                {{ $m->labelAlasan() }}
                            </span>
                        </td>
                        <td class="px-3 py-1.5 text-gray-500">
                            {{ $m->sumber_modul }}{{ $m->sumber_ref ? " · {$m->sumber_ref}" : '' }}
                            @if (! $lokasi && count($lokasiOptions) > 1)
                                <div class="text-[11px] text-gray-500">{{ $lokasiOptions[$m->kode_lokasi] ?? $m->kode_lokasi }}</div>
                            @endif
                            @if ($m->keterangan)
                                <div class="text-[11px] text-gray-400">{{ $m->keterangan }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-emerald-700">{{ $m->arah === 'masuk' ? $qty : '' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums text-red-700">{{ $m->arah === 'keluar' ? $qty : '' }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">@rp($m->nilai)</td>
                        <td class="border-l border-gray-100 px-3 py-1.5 text-right font-medium tabular-nums">{{ rtrim(rtrim(number_format((float) ($lokasi ? $m->saldo_kuantiti_lokasi : $m->saldo_kuantiti), 4, '.', ''), '0'), '.') }}</td>
                        @unless ($lokasi)
                            <td class="px-3 py-1.5 text-right font-medium tabular-nums">@rp($m->saldo_nilai)</td>
                        @endunless
                        <td class="px-3 py-1.5 text-gray-500">{{ $m->pengguna?->nama ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $lokasi ? 8 : 9 }}" class="px-4 py-10 text-center text-gray-400">Belum ada pergerakan untuk barang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
