@extends('layouts.app')

@php
    $rt = $lingkup === 'ppsb' ? 'pembayaran_ppsb' : 'pembayaran_kesantrian';
    $kode = $lingkup === 'ppsb' ? 'pembayaran-ppsb' : 'pembayaran-kesantrian';
    $judul = $lingkup === 'ppsb' ? 'Pembayaran Registrasi & Uang Pangkal' : 'Pembayaran SPP & Tagihan Lain';
    $labelStatus = ['menunggu_verifikasi' => 'bg-amber-100 text-amber-700', 'terverifikasi' => 'bg-emerald-100 text-emerald-700', 'ditolak' => 'bg-red-100 text-red-700', 'void' => 'bg-gray-100 text-gray-500'];
    $bolehVerif = auth()->user()->tim_keuangan || auth()->user()->is_admin;
    $bolehDompet = $bolehDompet ?? false;
    $tagihanDompet = $tagihanDompet ?? collect();
@endphp

@section('title', $judul)

@section('content')
    <div x-data="dompetBayar(@js($tagihanDompet->values()))">
        <form method="GET" id="filterBayar"></form>

        <p class="mb-3 text-sm text-gray-500">Catat pembayaran → tim keuangan memverifikasi (jurnal terbit). Bukti transfer dilampirkan agar keuangan bisa memeriksa dananya.</p>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-filter-server placeholder="Cari nomor / santri…" :total="$rows->total()"
                             :reset="route($rt . '.index')" :aktif="(bool) ($q !== '' || array_filter($filter))" form="filterBayar" />
            <div class="flex items-center gap-2">
                @if ($bolehDompet && \App\Support\Akses::boleh($kode, 'buat'))
                    <button type="button" @click="buka()" class="rounded-lg border border-brand px-3 py-1.5 text-sm font-semibold text-brand hover:bg-brand-soft">Bayar dari Dompet Wali</button>
                @endif
                @if (\App\Support\Akses::boleh($kode, 'buat'))
                    <a href="{{ route($rt . '.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Catat Pembayaran</a>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Santri</th><th class="px-4 py-3">Tagihan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Metode</th><th class="px-4 py-3">Bukti</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                    <tr class="bg-white">
                        <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                        <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                        <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterBayar" />
                        <x-scol type="blank" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $r->santri?->nama }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->tagihan?->jenis?->nama }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                            <td class="px-4 py-3 text-gray-500">{{ $r->metode ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($r->bukti_path)
                                    <a href="{{ route($rt . '.bukti', $r->id) }}" target="_blank" class="text-brand hover:underline">Lihat bukti</a>
                                @else
                                    <span class="text-gray-300">tanpa bukti</span>
                                @endif
                            </td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? '' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                            <td class="px-4 py-3 text-right">
                                @if ($r->status === 'menunggu_verifikasi' && $bolehVerif)
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="{{ route($rt . '.verifikasi', $r->id) }}" onsubmit="return confirm('Verifikasi pembayaran {{ $r->nomor }}? Jurnal akan terbit.')">
                                            @csrf<button class="text-brand hover:underline">Verifikasi</button>
                                        </form>
                                        <div x-data="{ open: false }" class="relative">
                                            <button @click="open=!open" class="text-red-600 hover:underline">Tolak</button>
                                            <form x-show="open" x-cloak @click.outside="open=false" method="POST" action="{{ route($rt . '.tolak', $r->id) }}"
                                                  class="absolute right-0 z-10 mt-2 w-56 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                                @csrf<input type="text" name="alasan" required placeholder="Alasan" class="w-full rounded border-gray-300 text-sm">
                                                <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                @elseif ($r->status === 'terverifikasi')
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route($rt . '.kuitansi', $r->id) }}" target="_blank" class="text-brand hover:underline">🖨 Kuitansi</a>
                                        <a href="{{ route('rekap_pembayaran.show', $r->id_santri) }}" class="text-gray-500 hover:underline">Rekap</a>
                                    </div>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">
                            {{ $q !== '' || array_filter($filter) ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada pembayaran.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>

        {{-- ===== Modal: Bayar dari Dompet Wali (Kesantrian) ===== --}}
        @if ($bolehDompet)
            <div x-show="show" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @keydown.escape.window="tutup()">
                <div class="w-full max-w-lg rounded-xl bg-white shadow-xl" @click.outside="tutup()">
                    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                        <h3 class="text-sm font-semibold text-gray-800">Bayar Tagihan dari Dompet Wali</h3>
                        <button type="button" @click="tutup()" class="text-gray-400 hover:text-gray-600">&times;</button>
                    </div>
                    <form method="POST" action="{{ route($rt . '.bayar_dompet') }}" class="space-y-3 px-5 py-4">
                        @csrf
                        <p class="rounded bg-gray-100 px-3 py-2 text-xs text-gray-600">Pembayaran ini <b>langsung berlaku</b> — tidak menunggu verifikasi keuangan, karena dananya sudah ada di Dompet Wali dan diperiksa saat top-up. Kas tidak bergerak: yang berkurang adalah titipan wali.</p>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Tagihan <span class="text-red-500">*</span></label>
                            <select name="id_tagihan" x-model.number="tagihanId" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                                <option value="">@{{ list.length ? '— pilih tagihan —' : '— tidak ada tagihan outstanding —' }}</option>
                                <template x-for="t in list" :key="t.id"><option :value="t.id" x-text="t.label"></option></template>
                            </select>
                            <p class="mt-1 text-xs text-brand" x-show="maxSisa > 0" x-cloak>Sisa tagihan: <span x-text="fmt(maxSisa)"></span>. Bila saldo dompet kurang, bayar sebagian lebih dulu.</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Tanggal <span class="text-red-500">*</span></label>
                                <input type="date" name="tanggal" x-model="tanggal" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Nominal <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" min="0" name="nominal" x-model="nominal" :max="maxSisa" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-right text-sm">
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Catatan</label>
                            <input type="text" name="catatan" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-3">
                            <button type="button" @click="tutup()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                            <button type="submit" :disabled="!tagihanId || !(parseFloat(nominal) > 0)"
                                    class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Bayar Sekarang</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <script>
        function dompetBayar(list) {
            return {
                list: list || [], show: false, tagihanId: '', nominal: '', tanggal: @js(now()->toDateString()),
                get maxSisa() { return (this.list.find(t => t.id === this.tagihanId)?.sisa) || 0; },
                buka() { this.show = true; },
                tutup() { this.show = false; },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
                init() { this.$watch('tagihanId', () => { if (this.maxSisa > 0) this.nominal = this.maxSisa; }); },
            };
        }
    </script>
@endsection
