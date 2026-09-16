@extends('layouts.app')

@section('title', 'Pengajuan Belum Dibayar — Saldo Awal')

@section('content')
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-800">Pengajuan Belum Dibayar (Saldo Awal)</h1>
        <p class="mt-1 text-sm text-gray-500">
            Hutang yang sudah disetujui di pembukuan lama tetapi belum dicairkan saat pindah sistem.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- Akibat yang paling penting disebutkan PALING ATAS, bukan disembunyikan di
         bawah tombol: dokumen yang lahir dari sini melompati seluruh rantai
         persetujuan dan langsung bisa jadi uang. --}}
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-relaxed text-amber-900">
        <b>Baca dulu.</b> Dokumen yang dicatat di sini langsung berstatus <b>Diposting</b> — melompati pemohon,
        verifikasi, dan seluruh rantai persetujuan — sehingga <b>bisa langsung dicairkan lewat Kas Keluar</b>.
        Pakai hanya untuk memindahkan hutang yang memang sudah disetujui di sistem lama.
        <br>
        Tidak ada jurnal yang terbit di sini; nilainya masuk buku besar lewat <b>baris turunan</b> di menu
        <a href="{{ route('opening_balance.index') }}" class="font-semibold underline">Saldo Awal</a>, yang menghitung ulang sendiri.
    </div>

    <form method="POST" action="{{ route('pengajuan_saldo_awal.store') }}" class="mb-5 space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        @csrf
        <div class="grid gap-4 sm:grid-cols-3">
            <x-field name="nomor" label="Nomor dokumen lama" :value="old('nomor')" required
                     hint="Nomor dari sistem sebelumnya, supaya bisa ditelusuri balik." />
            <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal')" required />
            <x-field name="nominal" label="Nominal" type="number" :value="old('nominal')" required />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="kode_coa_hutang" label="Akun Hutang" :value="old('kode_coa_hutang')" :options="$coaOptions" required
                     hint="Sisi kredit — inilah yang muncul sebagai baris turunan di Saldo Awal." />
            <x-field name="kode_coa_beban" label="Akun Beban / Lawannya" :value="old('kode_coa_beban')" :options="$coaOptions" required
                     hint="Dicatat pada rincian dokumen. Tidak dijurnal: bebannya milik periode lalu." />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="kode_bagian" label="Bagian" :value="old('kode_bagian')" :options="$bagianOptions" required />
            <x-field name="kode_unit" label="Unit Bisnis" :value="old('kode_unit')" :options="$unitOptions" required />
        </div>

        <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" required
                 hint="Mis. sisa termin renovasi asrama — vendor CV Amanah." />

        <div class="flex justify-end border-t border-gray-100 pt-4">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Catat Hutang Saldo Awal</button>
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Sisa</th>
                    <th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-xs">{{ $r->nomor }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->tanggal?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->sisa_hutang)</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $r->status === 'lunas' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst($r->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if (($halangan[$r->id] ?? []) === [])
                                {{-- @rp() TIDAK dipakai di dalam atribut — ia mengeluarkan
                                     <span class="rp"> dan kutipnya menutup atributnya lebih awal. --}}
                                <form method="POST" action="{{ route('pengajuan_saldo_awal.destroy', $r->id) }}" class="inline"
                                      data-confirm="Hapus hutang saldo awal {{ $r->nomor }} sebesar Rp {{ number_format((float) $r->nominal, 0, ',', '.') }}? Belum ada yang dicairkan, jadi tak ada jurnal yang dibalik.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50">Hapus</button>
                                </form>
                            @else
                                <span class="text-xs text-gray-400" title="{{ implode(' ', $halangan[$r->id]) }}">terkunci</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Belum ada hutang saldo awal yang dicatat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
