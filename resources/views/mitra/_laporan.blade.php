{{-- Isi laporan penjualan mitra — dipakai layar mitra (HP) dan layar staf. $lap dari MitraKantinService::laporan. --}}
<div class="grid grid-cols-2 gap-2 text-center sm:grid-cols-3">
    <div class="rounded-xl bg-white p-3 shadow-sm"><div class="text-xs text-gray-500">Penjualan kartu</div><div class="text-lg font-bold tabular-nums">@rp($lap['total'])</div></div>
    <div class="rounded-xl bg-white p-3 shadow-sm"><div class="text-xs text-gray-500">Transaksi</div><div class="text-lg font-bold">{{ $lap['jumlah'] }}</div></div>
    <div class="rounded-xl bg-white p-3 shadow-sm"><div class="text-xs text-gray-500">Dibatalkan</div><div class="text-lg font-bold">{{ $lap['batal'] }} <span class="text-xs font-normal text-gray-500">(@rp($lap['nilai_batal']))</span></div></div>
</div>

<div class="mt-3 rounded-xl bg-white p-3 shadow-sm">
    <div class="mb-2 text-sm font-semibold">Per hari</div>
    <table class="w-full text-sm">
        <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Tanggal</th><th class="py-1 text-right">Transaksi</th><th class="py-1 text-right">Nilai</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($lap['per_hari'] as $h)
                <tr><td class="py-1">{{ \Illuminate\Support\Carbon::parse($h->tanggal)->format('d/m/Y') }}</td><td class="py-1 text-right">{{ $h->jumlah }}</td><td class="py-1 text-right tabular-nums">@rp($h->total)</td></tr>
            @empty
                <tr><td colspan="3" class="py-3 text-center text-gray-400">Belum ada penjualan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-3 rounded-xl bg-white p-3 shadow-sm">
    <div class="mb-2 text-sm font-semibold">Per menu</div>
    <table class="w-full text-sm">
        <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Menu</th><th class="py-1 text-right">Terjual</th><th class="py-1 text-right">Nilai</th></tr></thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($lap['per_menu'] as $m)
                <tr><td class="py-1">{{ $m->nama }}</td><td class="py-1 text-right">{{ $m->jumlah }}</td><td class="py-1 text-right tabular-nums">@rp($m->nilai)</td></tr>
            @empty
                <tr><td colspan="3" class="py-3 text-center text-gray-400">Belum ada penjualan.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
