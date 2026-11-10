@extends('layouts.app')

@section('title', $mitra->nama)

@section('content')
@php
    $bolehBuat = \App\Support\Akses::boleh('mitra-kantin', 'buat');
    $bolehUbah = \App\Support\Akses::boleh('mitra-kantin', 'ubah');
@endphp
<div class="mx-auto max-w-4xl space-y-4">
    <a href="{{ route('mitra_kantin.index') }}" class="inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Mitra Kantin</a>

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $mitra->nama }} <span class="text-sm font-normal text-gray-400">{{ $mitra->kode }}</span></h2>
                <div class="text-sm text-gray-600">{{ $mitra->pemilik ?? '—' }} · {{ $mitra->telepon ?? '—' }}</div>
                @if (! $mitra->aktif())<span class="text-xs font-semibold text-red-600">Nonaktif — kasir &amp; akun mitra tertutup</span>@endif
            </div>
            <div class="flex gap-2">
                <a href="{{ route('mitra_kantin.laporan', ['mitra' => $mitra->id]) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Laporan</a>
                @if ($bolehUbah)<a href="{{ route('mitra_kantin.edit', $mitra) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">Ubah data</a>@endif
            </div>
        </div>
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-gray-500">Rekening penarikan</dt><dd>{{ $mitra->bank_nama ?? '—' }} {{ $mitra->bank_rekening }}<div class="text-xs text-gray-500">{{ $mitra->bank_atas_nama }}</div></dd></div>
            <div><dt class="text-xs text-gray-500">Masa sewa</dt><dd>{{ $mitra->sewa_mulai?->format('d/m/Y') ?? '—' }} – {{ $mitra->sewa_sampai?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Keterangan sewa</dt><dd class="whitespace-pre-line">{{ $mitra->keterangan_sewa ?? '—' }}</dd></div>
        </dl>
    </div>

    {{-- Saldo mitra (Paket 3) --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-500">Saldo bisa ditarik</div><div class="text-lg font-bold tabular-nums">@rp($saldo['bisa_ditarik'])</div></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-500">Penjualan hari ini (belum bisa ditarik)</div><div class="text-lg font-bold tabular-nums">@rp($saldo['tertahan'])</div></div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"><div class="text-xs text-gray-500">Penarikan sedang diajukan</div><div class="text-lg font-bold tabular-nums">@rp($saldo['diajukan'])</div></div>
    </div>

    {{-- Mitra NONAKTIF (sewa selesai): akunnya tak bisa masuk, jadi petugas mengajukan pelunasan saldo akhirnya (10 Nov 2026). --}}
    @if (! $mitra->aktif() && (float) $saldo['bisa_ditarik'] > 0 && \App\Support\Akses::boleh('mitra-kantin', 'ubah'))
        <form method="POST" action="{{ route('mitra_kantin.pelunasan', $mitra) }}" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"
              data-confirm="Ajukan pelunasan saldo akhir {{ $mitra->nama }}? Transfernya diproses di daftar Penarikan Saldo Mitra.">
            @csrf
            <p class="font-semibold text-amber-900">Mitra ini nonaktif — sisa saldonya bisa dilunasi oleh petugas.</p>
            <p class="text-xs text-amber-800">Akun mitra tak bisa masuk lagi. Pelunasan masuk antrean penarikan biasa (transfer ke rekening di atas, biaya transfer dipotong).</p>
            <div class="mt-2 flex flex-wrap items-end gap-2">
                <label class="block"><span class="text-xs text-amber-900">Nominal</span>
                    <input type="number" name="nominal" min="1" step="1" value="{{ old('nominal', (int) $saldo['bisa_ditarik']) }}" max="{{ (int) $saldo['bisa_ditarik'] }}" class="block w-40 rounded-lg border-amber-300 text-sm"></label>
                <label class="block flex-1"><span class="text-xs text-amber-900">Catatan (tidak wajib)</span>
                    <input type="text" name="catatan" maxlength="200" value="{{ old('catatan') }}" class="block w-full rounded-lg border-amber-300 text-sm"></label>
                <button class="rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white">Ajukan pelunasan</button>
            </div>
        </form>
    @endif

    {{-- Menu --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-800">Daftar Menu</h3>
        <p class="text-xs text-gray-500">Diisi kepala Wafi Mart dari daftar yang disetorkan mitra. Perubahan harga berlaku untuk transaksi berikutnya dan tercatat di Jejak Audit.</p>
        <div class="mt-3 divide-y divide-gray-100">
            @forelse ($menu as $m)
                <div class="py-2" x-data="{ ubah: false }">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <div class="{{ $m->aktif ? '' : 'text-gray-400 line-through' }}">{{ $m->nama }}</div>
                        <div class="flex items-center gap-3">
                            <span class="tabular-nums">@rp($m->harga)</span>
                            @if ($bolehUbah)<button type="button" @click="ubah = !ubah" class="text-xs text-brand hover:underline">Ubah</button>@endif
                        </div>
                    </div>
                    @if ($bolehUbah)
                        <form x-show="ubah" x-cloak method="POST" action="{{ route('mitra_kantin.menu.update', [$mitra, $m]) }}" class="mt-2 grid items-end gap-2 sm:grid-cols-4">
                            @csrf @method('PUT')
                            <input type="text" name="nama" value="{{ $m->nama }}" required maxlength="100" class="rounded-lg border-gray-300 text-sm sm:col-span-2">
                            <x-input-rupiah name="harga" :value="(int) $m->harga" required />
                            <div class="flex items-center gap-2">
                                <input type="hidden" name="aktif" value="0">
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="aktif" value="1" @checked($m->aktif) class="rounded border-gray-300"> Aktif</label>
                                <button class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white">Simpan</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="py-3 text-sm text-gray-400">Belum ada menu.</p>
            @endforelse
        </div>
        @if ($bolehBuat)
            <form method="POST" action="{{ route('mitra_kantin.menu.store', $mitra) }}" class="mt-3 grid items-end gap-2 border-t border-gray-100 pt-3 sm:grid-cols-4">
                @csrf
                <div class="sm:col-span-2">
                    <label class="text-xs text-gray-500">Menu baru</label>
                    <input type="text" name="nama" required maxlength="100" value="{{ old('nama') }}" placeholder="mis. Nasi goreng" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div><label class="text-xs text-gray-500">Harga</label><x-input-rupiah name="harga" :value="old('harga')" required /></div>
                <button class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">+ Tambah menu</button>
            </form>
        @endif
    </div>

    {{-- Akun mitra --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-800">Akun Mitra</h3>
        <p class="text-xs text-gray-500">Mitra masuk di halaman login biasa dari HP-nya dan hanya melihat layar kasir, riwayat, laporan, dan saldo miliknya.</p>
        <div class="mt-3 divide-y divide-gray-100">
            @forelse ($akun as $a)
                <div class="py-2" x-data="{ ubah: false }">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <div><span class="font-mono">{{ $a->username }}</span>
                            <span class="ml-2 rounded-full px-2 py-0.5 text-xs {{ $a->status === 'aktif' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ $a->status }}</span></div>
                        @if ($bolehUbah)<button type="button" @click="ubah = !ubah" class="text-xs text-brand hover:underline">Ubah</button>@endif
                    </div>
                    @if ($bolehUbah)
                        <form x-show="ubah" x-cloak method="POST" action="{{ route('mitra_kantin.akun.update', [$mitra, $a]) }}" class="mt-2 flex flex-wrap items-end gap-2">
                            @csrf @method('PUT')
                            <input type="password" name="sandi" minlength="8" autocomplete="new-password" placeholder="Kata sandi baru (kosongkan bila tetap)" class="flex-1 rounded-lg border-gray-300 text-sm">
                            <select name="status" class="rounded-lg border-gray-300 text-sm">
                                <option value="aktif" @selected($a->status === 'aktif')>Aktif</option>
                                <option value="nonaktif" @selected($a->status === 'nonaktif')>Nonaktif</option>
                            </select>
                            <button class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">Simpan</button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="py-3 text-sm text-gray-400">Belum ada akun.</p>
            @endforelse
        </div>
        @if ($bolehBuat)
            <form method="POST" action="{{ route('mitra_kantin.akun.store', $mitra) }}" class="mt-3 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3">
                @csrf
                <div><label class="text-xs text-gray-500">Username</label><input type="text" name="username" required maxlength="50" value="{{ old('username') }}" autocomplete="off" class="mt-1 block rounded-lg border-gray-300 text-sm"></div>
                <div><label class="text-xs text-gray-500">Kata sandi awal (min. 8)</label><input type="password" name="sandi" required minlength="8" autocomplete="new-password" class="mt-1 block rounded-lg border-gray-300 text-sm"></div>
                <button class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">+ Buat akun</button>
            </form>
        @endif
    </div>
</div>
@endsection
