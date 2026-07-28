@extends('layouts.app')

@php $draft = $data['status'] === 'draft'; $balance = (float) $data['selisih'] === 0.0; @endphp

@section('title', 'Rekonsiliasi ' . $data['nama_rekening'])

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="mb-4 flex items-center justify-between">
            <a href="{{ route('bank_reconciliation.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="flex items-center gap-2">
                <span class="rounded-full px-2 py-1 text-xs font-medium {{ $draft ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700' }}">{{ ucfirst($data['status']) }}</span>
                @if ($draft && \App\Support\Akses::boleh('bank-reconciliation', 'hapus'))
                    <form method="POST" action="{{ route('bank_reconciliation.destroy', $data['id']) }}" onsubmit="return confirm('Hapus draft rekonsiliasi ini?')">
                        @csrf @method('DELETE')
                        <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Hapus Draft</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Ringkasan saldo --}}
        <div class="mb-4 grid gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-400">Saldo Rekening Koran</div><div class="mt-1 text-lg font-semibold tabular-nums">@rp($data['saldo_bank'])</div></div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-400">Saldo Buku (GL)</div><div class="mt-1 text-lg font-semibold tabular-nums">@rp($data['saldo_buku'])</div></div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-400">Saldo Buku Efektif (cleared)</div><div class="mt-1 text-lg font-semibold tabular-nums">@rp($data['saldo_buku_efektif'])</div></div>
            <div class="rounded-xl border-2 p-4 shadow-sm {{ $balance ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
                <div class="text-xs {{ $balance ? 'text-brand' : 'text-amber-600' }}">Selisih</div>
                <div class="mt-1 text-lg font-bold tabular-nums {{ $balance ? 'text-emerald-800' : 'text-amber-800' }}">@rp($data['selisih'])</div>
            </div>
        </div>

        <div class="mb-3 text-xs text-gray-500">Rekening: <strong>{{ $data['nama_rekening'] }}</strong> · cut-off {{ \Illuminate\Support\Carbon::parse($data['tanggal'])->format('d M Y') }} · {{ $data['jumlah_cleared'] }} item cleared</div>

        {{-- Item transaksi --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-3 py-3 text-center">Cleared</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debit</th><th class="px-4 py-3 text-right">Kredit</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($data['items'] as $it)
                        <tr class="hover:bg-gray-50 {{ $it['cleared'] ? 'bg-emerald-50/40' : '' }}">
                            <td class="px-3 py-2 text-center">
                                <form method="POST" action="{{ route('bank_reconciliation.toggle', [$data['id'], $it['id']]) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="cleared" value="0">
                                    <input type="checkbox" name="cleared" value="1" @checked($it['cleared']) @disabled(! $draft)
                                           onchange="this.form.submit()" class="rounded border-gray-300 text-brand focus:ring-brand">
                                </form>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($it['tanggal'])->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 text-gray-600">
                                {{ $it['keterangan'] }}
                                @if ($it['is_adjustment'])<span class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">penyesuaian</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($it['debet'])</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($it['kredit'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Tidak ada transaksi buku besar pada rekening ini s/d tanggal cut-off.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($draft)
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                {{-- Penyesuaian --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-3 text-sm font-semibold text-gray-800">Tambah Penyesuaian</h3>
                    <form method="POST" action="{{ route('bank_reconciliation.adjustment', $data['id']) }}" class="space-y-3">
                        @csrf
                        <x-field name="kode_coa_lawan" label="Akun Lawan" :value="old('kode_coa_lawan')" :options="$coaOptions" required />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-field name="arah" label="Arah" :value="old('arah', 'tambah')"
                                     :options="['tambah' => 'Tambah kas bank (Debit)', 'kurang' => 'Kurangi kas bank (Kredit)']" required />
                            <x-field name="nominal" label="Nominal" type="number" :value="old('nominal')" required />
                        </div>
                        <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" />
                        <div class="flex justify-end">
                            <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-900">Posting Penyesuaian</button>
                        </div>
                    </form>
                </div>

                {{-- Finalize --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-3 text-sm font-semibold text-gray-800">Selesaikan Rekonsiliasi</h3>
                    <p class="mb-4 text-sm text-gray-500">Finalisasi hanya bisa dilakukan bila selisih = 0. Item yang di-cleared akan dikunci dan tidak dimuat lagi di rekonsiliasi berikutnya.</p>
                    @if (\App\Support\Akses::boleh('bank-reconciliation', 'ubah'))
                        <form method="POST" action="{{ route('bank_reconciliation.finalize', $data['id']) }}">
                            @csrf
                            <button {{ $balance ? '' : 'disabled' }}
                                    class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50">
                                {{ $balance ? 'Finalkan (selisih 0)' : 'Belum balance — selisih ' }}<span class="tabular-nums">@if (! $balance)@rp($data['selisih'])@endif</span>
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection
