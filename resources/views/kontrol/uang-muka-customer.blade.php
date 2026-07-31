@extends('layouts.app')

@section('title', 'Uang Muka Customer')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">Pendapatan diterima dimuka (Kas Masuk jenis uang muka) yang belum diakui penuh + pengakuan pendapatan (parsial/penuh).</p>
        <div class="flex items-center gap-3">
            @include('kontrol._download', ['type' => 'uang-muka-customer'])
            <a href="{{ route('kontrol.ringkasan') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Ringkasan</a>
        </div>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">No. Voucher</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Akun</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Diakui</th>
                    <th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php $diakui = (float) ($r['nominal_diakui'] ?? 0); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ $r['nomor_transaksi'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r['tanggal'])->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['nama_customer'] ?? $r['kode_customer'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['kode_coa'] }} — {{ $r['nama_coa'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r['nominal'])</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">@rp($r['nominal_diakui'] ?? 0)</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r['sisa'])</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $diakui > 0 ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $diakui > 0 ? 'Sebagian' : 'Belum Diakui' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($bolehAkui)
                                <div x-data="{ open: false }" class="relative inline-block">
                                    <button type="button" @click="open = !open" class="text-xs font-medium text-brand hover:underline">Akui Pendapatan</button>
                                    <form x-show="open" x-cloak @click.outside="open = false" method="POST"
                                          action="{{ route('cash_in.akui', $r['kode_transaksi']) }}"
                                          class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                        @csrf
                                        <input type="hidden" name="detail_id" value="{{ $r['detail_id'] }}">
                                        <p class="text-xs text-gray-500">Reklasifikasi: Debit <b>{{ $r['nama_coa'] }}</b>, Kredit akun Pendapatan. Sisa: <b>@rp($r['sisa'])</b>.</p>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-gray-600">Akun Pendapatan</label>
                                            <select name="kode_coa_pendapatan" required class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm">
                                                @foreach ($coaOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-gray-600">Nominal Diakui</label>
                                            <x-input-rupiah name="nominal" :value="$r['sisa']"
                                                            class="rounded border border-gray-300 px-2 py-1.5 text-right" />
                                            <p class="mt-1 text-[11px] text-gray-400">Kosongkan/penuh = akui seluruh sisa.</p>
                                        </div>
                                        <button class="w-full rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark">Proses Pengakuan</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-xs text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Belum ada uang muka customer outstanding.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
