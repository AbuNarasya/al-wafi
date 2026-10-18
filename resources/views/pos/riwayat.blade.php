@extends('layouts.pos')

@section('title', 'Riwayat Struk')
@section('kepala', 'Riwayat Struk')
@section('subkepala', $shift ? 'Shift '.$shift->nomor.' — '.$shift->lokasi->nama : 'Tidak ada shift terbuka')

@section('aksi')
    <a href="{{ route('pos.index') }}" class="rounded bg-white px-2 py-1 text-xs font-semibold text-brand-dark hover:bg-white/90">&larr; Kembali ke Kasir</a>
@endsection

@section('content')
    <div class="mx-auto max-w-3xl" x-data>
        @if (! $shift)
            <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500">Tidak ada shift terbuka.</div>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Jam</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2">Status</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->nomor }}</td>
                                <td class="px-4 py-2">{{ $r->waktu->format('H:i') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">@rp($r->total)</td>
                                <td class="px-4 py-2">
                                    @if ($r->status === 'batal')
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">Batal</span>
                                    @else
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Selesai</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="text-xs font-medium text-brand hover:underline"
                                            data-alamat="{{ route('pos.struk', ['penjualan' => $r->id_penjualan, 'cetak' => 1]) }}"
                                            @click="$refs.cetakan.src = $el.dataset.alamat">Cetak ulang</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada struk di shift ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $rows->links() }}</div>
            <p class="mt-3 text-xs text-gray-500">Salah transaksi? Minta supervisor membatalkan struknya — kasir tidak membatalkan struknya sendiri.</p>
        @endif
        <iframe x-ref="cetakan" class="hidden" title="Struk"></iframe>
    </div>
@endsection
