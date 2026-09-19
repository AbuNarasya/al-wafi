@extends('layouts.app')

@section('title', 'Laporan Perubahan Aset Neto')

@php
    // Angka pengurang ditulis dalam kurung — lazim di laporan keuangan, dan
    // jauh lebih terbaca daripada tanda minus yang mudah terlewat saat dicetak.
    $kurung = fn ($v) => '(Rp '.number_format((float) $v, 0, ',', '.').')';
@endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
    </div>

    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900">Laporan Perubahan Aset Neto</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Format entitas nirlaba (ISAK 35). Memisahkan aset neto <b>tanpa pembatasan</b> — yang boleh dipakai bebas —
            dari yang <b>dengan pembatasan</b>, dan memperlihatkan perpindahan di antara keduanya.
        </p>
    </div>

    @unless ($data['ada_pembatasan'])
        {{-- Tanpa satu pun akun berpembatasan, laporan ini hanya berisi satu
             kolom dan tak lebih berguna dari Laba Rugi biasa. Dikatakan, bukan
             dibiarkan pembacanya menyimpulkan sendiri. --}}
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Belum ada satu pun akun yang ditandai <b>dengan pembatasan</b>, jadi kolom kanan masih kosong dan laporan ini
            belum berbeda dari Laba Rugi. Tandai akun pendapatan &amp; aset neto yang terikat lewat
            <a href="{{ route('coa_detail.index') }}" class="underline">Chart of Account</a>.
        </div>
    @endunless

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">Keterangan</th>
                    <th class="px-4 py-3 text-right">Tanpa Pembatasan</th>
                    <th class="px-4 py-3 text-right">Dengan Pembatasan</th>
                    <th class="border-l border-gray-200 px-4 py-3 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr class="bg-gray-50/60">
                    <td class="px-4 py-2.5 font-semibold text-gray-800">Aset Neto Awal Periode</td>
                    <td class="px-4 py-2.5 text-right font-medium tabular-nums">@rp($data['awal']['tanpa'])</td>
                    <td class="px-4 py-2.5 text-right font-medium tabular-nums">@rp($data['awal']['dengan'])</td>
                    <td class="border-l border-gray-100 px-4 py-2.5 text-right font-semibold tabular-nums">@rp($data['awal']['jumlah'])</td>
                </tr>

                <tr><td class="px-4 pt-3 text-xs font-semibold uppercase tracking-wide text-gray-500" colspan="4">Penghasilan Periode Berjalan</td></tr>

                <tr>
                    <td class="px-4 py-2 pl-8 text-gray-700">Pendapatan</td>
                    <td class="px-4 py-2 text-right tabular-nums text-emerald-700">@rp($data['pendapatan']['tanpa'])</td>
                    <td class="px-4 py-2 text-right tabular-nums text-emerald-700">@rp($data['pendapatan']['dengan'])</td>
                    <td class="border-l border-gray-100 px-4 py-2 text-right tabular-nums">@rp($data['pendapatan']['jumlah'])</td>
                </tr>
                <tr>
                    <td class="px-4 py-2 pl-8 text-gray-700">
                        Beban
                        <span class="ml-1 text-xs text-gray-400">seluruhnya ditanggung kolom tanpa pembatasan</span>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums text-red-700">{{ $kurung($data['beban']) }}</td>
                    <td class="px-4 py-2 text-right tabular-nums text-gray-300">—</td>
                    <td class="border-l border-gray-100 px-4 py-2 text-right tabular-nums text-red-700">{{ $kurung($data['beban']) }}</td>
                </tr>

                <tr>
                    <td class="px-4 py-2 pl-8 text-gray-700">
                        Pelepasan pembatasan
                        <div class="text-xs text-gray-400">Belanja dana terikat pada periode ini — pembatasannya gugur saat dananya terpakai sesuai peruntukan.</div>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums text-emerald-700">@rp($data['pelepasan'])</td>
                    <td class="px-4 py-2 text-right tabular-nums text-red-700">{{ $kurung($data['pelepasan']) }}</td>
                    <td class="border-l border-gray-100 px-4 py-2 text-right tabular-nums text-gray-400">—</td>
                </tr>

                <tr class="border-t-2 border-gray-200">
                    <td class="px-4 py-2.5 font-semibold text-gray-800">Kenaikan/(Penurunan) Aset Neto</td>
                    <td class="px-4 py-2.5 text-right font-medium tabular-nums {{ (float) $data['kenaikan']['tanpa'] < 0 ? 'text-red-700' : '' }}">@rp($data['kenaikan']['tanpa'])</td>
                    <td class="px-4 py-2.5 text-right font-medium tabular-nums {{ (float) $data['kenaikan']['dengan'] < 0 ? 'text-red-700' : '' }}">@rp($data['kenaikan']['dengan'])</td>
                    <td class="border-l border-gray-100 px-4 py-2.5 text-right font-semibold tabular-nums">@rp($data['kenaikan']['jumlah'])</td>
                </tr>
            </tbody>
            <tfoot class="bg-gray-50 text-gray-900">
                <tr>
                    <td class="px-4 py-3 text-base font-bold">Aset Neto Akhir Periode</td>
                    <td class="px-4 py-3 text-right font-bold tabular-nums">@rp($data['akhir']['tanpa'])</td>
                    <td class="px-4 py-3 text-right font-bold tabular-nums">@rp($data['akhir']['dengan'])</td>
                    <td class="border-l border-gray-200 px-4 py-3 text-right text-base font-bold tabular-nums">@rp($data['akhir']['jumlah'])</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        @foreach ([['Rincian Pendapatan Tanpa Pembatasan', $data['rincian_pendapatan']['tanpa']], ['Rincian Pendapatan Dengan Pembatasan', $data['rincian_pendapatan']['dengan']]] as [$judul, $baris])
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">{{ $judul }}</div>
                <table class="min-w-full text-xs">
                    <tbody class="divide-y divide-gray-50">
                        @forelse ($baris as $b)
                            <tr>
                                <td class="px-4 py-1.5 text-gray-700">{{ $b['kode_coa'] }} — {{ $b['nama_coa'] }}</td>
                                <td class="px-4 py-1.5 text-right tabular-nums">@rp($b['nilai'])</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-4 text-center text-gray-400">Tidak ada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
@endsection
