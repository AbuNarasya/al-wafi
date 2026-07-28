@extends('layouts.app')

@section('title', 'Saldo Awal')

@section('content')
    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Masukkan saldo awal per akun (Debet/Kredit). Setelah total Debet = Kredit, <b>Finalisasi</b> membuat satu jurnal pembuka.
        Untuk merevisi setelah final, lakukan <b>Void</b> dulu.
    </p>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    @if ($summary['posted'])
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
            <div class="text-sm text-emerald-800">🔒 <b>Sudah difinalisasi.</b> Jurnal pembuka: <span class="font-mono">{{ $summary['journalRef'] ?? '—' }}</span>. Baris terkunci.</div>
            <form method="POST" action="{{ route('opening_balance.void') }}" onsubmit="return confirm('Void finalisasi saldo awal? Jurnal pembuka akan dibalik.')">
                @csrf<button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Void Finalisasi</button>
            </form>
        </div>
    @endif

    {{-- Form tambah baris (hanya bila belum final) --}}
    @unless ($summary['posted'])
        <form method="POST" action="{{ route('opening_balance.add') }}" class="mb-4 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-4">
            @csrf
            <div class="sm:col-span-2"><x-field name="kode_coa" label="Akun" :options="$coaOptions" required /></div>
            <x-field name="jenis_saldo" label="Sisi" :options="['debet' => 'Debet', 'kredit' => 'Kredit']" required />
            <div class="flex items-end gap-2">
                <div class="flex-1"><x-field name="saldo" label="Saldo" type="number" required /></div>
                <button class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah</button>
            </div>
        </form>
    @endunless

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Akun</th><th class="px-4 py-3 text-right">Debet</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><span class="font-mono text-xs text-gray-400">{{ $r->kode_coa }}</span> {{ $r->coa?->nama_coa }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $r->jenis_saldo === 'debet' ? '' : '' }}@if ($r->jenis_saldo === 'debet')@rp($r->saldo)@endif</td>
                        <td class="px-4 py-3 text-right tabular-nums">@if ($r->jenis_saldo === 'kredit')@rp($r->saldo)@endif</td>
                        <td class="px-4 py-3 text-right">
                            @unless ($summary['posted'])
                                <form method="POST" action="{{ route('opening_balance.remove', $r->id) }}" onsubmit="return confirm('Hapus baris ini?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                            @else
                                <span class="text-gray-300">—</span>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">Belum ada baris saldo awal.</td></tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50">
                <tr class="border-t-2 border-gray-200 font-semibold">
                    <td class="px-4 py-3 text-right">Total</td>
                    <td class="px-4 py-3 text-right tabular-nums">@rp($summary['totalDebet'])</td>
                    <td class="px-4 py-3 text-right tabular-nums">@rp($summary['totalKredit'])</td>
                    <td class="px-4 py-3 text-right">
                        @if ($summary['balanced'])
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">✓ Balance</span>
                        @else
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Selisih @rp($summary['selisih'])</span>
                        @endif
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless ($summary['posted'])
        <div class="mt-4 flex justify-end">
            <form method="POST" action="{{ route('opening_balance.post') }}" onsubmit="return confirm('Finalisasi saldo awal dan buat jurnal pembuka?')">
                @csrf
                <button @disabled(! $summary['balanced'] || $summary['count'] < 2)
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Finalisasi Saldo Awal</button>
            </form>
        </div>
    @endunless
@endsection
