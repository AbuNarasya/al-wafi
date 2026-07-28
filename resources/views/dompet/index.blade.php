@extends('layouts.app')

@section('title', 'Dompet & Tabungan')

@php
    $bolehVerif = auth()->user()->tim_keuangan || auth()->user()->is_admin;
    $bolehBuat = \App\Support\Akses::boleh('dompet', 'buat');
    $bolehUbah = \App\Support\Akses::boleh('dompet', 'ubah');
    $labelPemilik = ['wali' => 'Dompet Wali', 'santri' => 'Dompet Santri', 'tabungan' => 'Tabungan Santri'];
    $labelJenis = ['topup' => 'Top-up', 'distribusi_keluar' => 'Distribusi keluar', 'distribusi_masuk' => 'Distribusi masuk', 'tabung_keluar' => 'Ke tabungan', 'tabung_masuk' => 'Masuk tabungan', 'bayar_tagihan' => 'Bayar tagihan', 'jajan' => 'Jajan', 'tarik' => 'Tarik tunai'];
@endphp

@section('content')
    <div x-data="{ setor: false }">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-gray-900">Dompet &amp; Tabungan Santri</h2>
                <p class="mt-1 max-w-2xl text-sm text-gray-500">Seluruh saldo adalah <b>titipan (wadi'ah)</b> — kewajiban yayasan kepada penitip, bukan pendapatan. Pemindahan antar-dompet tidak menggerakkan kas.</p>
            </div>
            <div class="flex gap-2">
                @if ($bolehUbah)
                    <form method="POST" action="{{ route('dompet.auto_debet') }}" onsubmit="return confirm('Jalankan auto-debet? Memotong saldo Dompet Wali untuk melunasi tagihan — HANYA keluarga yang mengizinkan. Tunggakan tertua dulu.')">
                        @csrf<button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Jalankan Auto-Debet</button>
                    </form>
                @endif
                @if ($bolehBuat)
                    <button type="button" @click="setor = true" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Setor Tunai Santri</button>
                @endif
            </div>
        </div>

        {{-- Pilih wali --}}
        <form method="GET" action="{{ route('dompet.index') }}" class="mb-4 flex items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex-1"><label class="mb-1 block text-xs font-medium text-gray-500">Wali / Keluarga</label>
                <select name="id_wali" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                    <option value="">— pilih wali —</option>
                    @foreach ($waliOptions as $id => $label)<option value="{{ $id }}" @selected($idWali === $id)>{{ $label }}</option>@endforeach
                </select></div>
            <button class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-900">Lihat</button>
        </form>

        @if ($wali)
            @php
                $anak = $wali->santri;
                $santriKeluarga = $anak->mapWithKeys(fn ($s) => [$s->id => $s->nama])->all();
            @endphp

            <div class="grid gap-4 lg:grid-cols-3">
                {{-- Dompet Wali --}}
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-xs text-gray-400">Dompet Wali — {{ $wali->nama }}</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums text-gray-900">@rp($wali->dompet?->saldo ?? 0)</div>
                    @if ($bolehBuat)
                        <form method="POST" action="{{ route('dompet.topup') }}" class="mt-4 space-y-2 border-t border-gray-100 pt-3">
                            @csrf <input type="hidden" name="id_wali" value="{{ $wali->id }}"><input type="hidden" name="tanggal" value="{{ now()->toDateString() }}">
                            <div class="text-xs font-semibold text-gray-600">Top-up Dompet Wali</div>
                            <input type="number" name="nominal" step="0.01" min="0" required placeholder="Nominal" class="w-full rounded border-gray-300 text-sm">
                            <select name="kode_rekening" required class="w-full rounded border-gray-300 text-sm">@foreach ($rekeningOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                            <button class="w-full rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Catat Top-up</button>
                        </form>
                    @endif
                </div>

                {{-- Pindah dana --}}
                @if ($bolehUbah && ! empty($santriKeluarga))
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" x-data="{ dari: 'wali' }">
                        <div class="text-sm font-semibold text-gray-700">Pindah Dana</div>
                        <form method="POST" action="{{ route('dompet.pindah') }}" class="mt-3 space-y-2">
                            @csrf <input type="hidden" name="id_wali" value="{{ $wali->id }}"><input type="hidden" name="tanggal" value="{{ now()->toDateString() }}">
                            <label class="block text-xs text-gray-500">Dari</label>
                            <select name="dari" x-model="dari" class="w-full rounded border-gray-300 text-sm"><option value="wali">Dompet Wali</option><option value="santri">Dompet Santri</option></select>
                            <label class="block text-xs text-gray-500">Ke</label>
                            <select name="ke" class="w-full rounded border-gray-300 text-sm">
                                <option value="santri" x-show="dari === 'wali'">Dompet Santri (uang jajan)</option>
                                <option value="tabungan">Tabungan Santri</option>
                            </select>
                            <label class="block text-xs text-gray-500">Santri</label>
                            <select name="id_santri" required class="w-full rounded border-gray-300 text-sm">@foreach ($santriKeluarga as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach</select>
                            <input type="number" name="nominal" step="0.01" min="0" required placeholder="Nominal" class="w-full rounded border-gray-300 text-sm">
                            <button class="w-full rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">Pindahkan</button>
                        </form>
                    </div>
                @endif

                {{-- Info --}}
                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-xs text-gray-500">
                    Semua dompet adalah <strong>titipan (wadi'ah)</strong> — liabilitas yayasan. Top-up menambah Kas &amp; Titipan; pemindahan hanya menggeser antar akun titipan. Arah sah: Wali→Santri, Wali→Tabungan, Santri→Tabungan.
                </div>
            </div>

            {{-- Dompet per santri --}}
            <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Dompet &amp; Tabungan Santri</h3>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-4 py-3">Santri</th><th class="px-4 py-3 text-right">Dompet</th><th class="px-4 py-3 text-right">Tabungan</th><th class="px-4 py-3 text-center">Kunci Tarik</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($anak as $s)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $s->nama }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">@rp($s->dompet?->saldo ?? 0)</td>
                                <td class="px-4 py-3 text-right tabular-nums">@rp($s->tabungan?->saldo ?? 0)</td>
                                <td class="px-4 py-3 text-center">
                                    @if ($bolehUbah)
                                        <form method="POST" action="{{ route('dompet.kunci', $s->id) }}">
                                            @csrf<input type="hidden" name="kunci" value="{{ $s->dompet?->kunci_tarik ? '0' : '1' }}">
                                            <button class="rounded-full px-2 py-0.5 text-xs font-medium {{ $s->dompet?->kunci_tarik ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $s->dompet?->kunci_tarik ? '🔒 Terkunci' : '🔓 Terbuka' }}</button>
                                        </form>
                                    @else
                                        <span class="text-gray-400">{{ $s->dompet?->kunci_tarik ? 'Terkunci' : 'Terbuka' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Wali ini belum punya santri aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center text-sm text-gray-400">Pilih wali untuk melihat dompet keluarganya.</div>
        @endif

        {{-- ===== Buku Mutasi Dompet ===== --}}
        <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Buku Mutasi Dompet</h3>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Dompet</th><th class="px-4 py-3">Jenis</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Saldo Setelah</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($mutasi as $m)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $m->nomor }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($m->tanggal)->format('d/m/Y') }}</td>
                            <td class="px-4 py-2">{{ $labelPemilik[$m->pemilik] ?? $m->pemilik }}</td>
                            <td class="px-4 py-2 text-xs">{{ $labelJenis[$m->jenis] ?? $m->jenis }}</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ (float) $m->nominal < 0 ? 'text-red-600' : 'text-emerald-700' }}">@rp($m->nominal)</td>
                            <td class="px-4 py-2 text-right tabular-nums text-xs text-gray-500">@rp($m->saldo_setelah)</td>
                            <td class="px-4 py-2 text-xs text-gray-600">{{ $m->keterangan ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $m->status === 'terverifikasi' ? 'bg-emerald-100 text-emerald-700' : ($m->status === 'ditolak' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">{{ str_replace('_', ' ', $m->status) }}</span>
                                @if ($m->alasan_tolak)<span class="block text-xs text-rose-600">{{ $m->alasan_tolak }}</span>@endif
                            </td>
                            <td class="px-4 py-2">
                                @if ($m->status === 'menunggu_verifikasi' && $m->jenis === 'topup' && $bolehVerif)
                                    <div class="flex items-center gap-2">
                                        <form method="POST" action="{{ route('dompet.topup.verifikasi', $m->id) }}" onsubmit="return confirm('Verifikasi top-up {{ $m->nomor }}? Pastikan dananya sudah masuk.')">@csrf<button class="text-xs text-brand hover:underline">Verifikasi</button></form>
                                        <div x-data="{ open: false }" class="relative">
                                            <button @click="open=!open" class="text-xs text-red-600 hover:underline">Tolak</button>
                                            <form x-show="open" x-cloak @click.outside="open=false" method="POST" action="{{ route('dompet.topup.tolak', $m->id) }}" class="absolute right-0 z-10 mt-2 w-56 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                                @csrf<input type="text" name="alasan" required placeholder="Alasan" class="w-full rounded border-gray-300 text-sm"><button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                @elseif ($m->status === 'menunggu_verifikasi')
                                    <span class="text-xs text-gray-400">menunggu keuangan</span>
                                @else
                                    <span class="text-gray-300">—</span>
                                @endif
                                @if ($m->bukti_path)<a href="{{ route('dompet.mutasi.bukti', $m->id) }}" target="_blank" class="block text-xs text-brand hover:underline">Lihat bukti</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">Belum ada mutasi dompet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ===== Modal: Setor Tunai Santri ===== --}}
        <div x-show="setor" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="setor = false">
            <div class="my-10 w-full max-w-md rounded-xl bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">Setor Tunai ke Dompet Santri</h3>
                    <button type="button" @click="setor = false" class="text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                <form method="POST" action="{{ route('dompet.topup_santri') }}" class="space-y-3 p-5">
                    @csrf<input type="hidden" name="tanggal" value="{{ now()->toDateString() }}">
                    <p class="rounded bg-slate-50 px-3 py-2 text-xs text-gray-500">Jalur ini bisa dimatikan admin di Pengaturan Perusahaan. Menunggu verifikasi keuangan sebelum saldo bertambah.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Santri</label>
                        <select name="id_santri" required class="w-full rounded border-gray-300 px-3 py-2 text-sm">
                            <option value="">— pilih santri —</option>
                            @foreach ($santriOptions as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Nominal</label><input type="number" name="nominal" step="0.01" min="0" required class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Kas/Rekening</label>
                            <select name="kode_rekening" required class="w-full rounded border-gray-300 px-3 py-2 text-sm">@foreach ($rekeningOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    </div>
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Keterangan</label><input type="text" name="keterangan" class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" @click="setor = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan &amp; Kirim ke Keuangan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
