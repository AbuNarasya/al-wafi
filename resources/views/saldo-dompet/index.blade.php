@extends('layouts.app')

@section('title', 'Saldo Dompet')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Saldo titipan <b>seluruh</b> wali dan santri dalam satu layar. Angka total di bawah inilah yang
        harus sama dengan saldo akun titipannya di buku besar &mdash; kalau berselisih, daftar ini yang
        memperlihatkan selisihnya ada pada siapa.
    </p>
    <p class="mb-4 text-xs text-gray-400">
        Halaman ini <b>baca saja</b>. Top-up, pemindahan, dan penarikan tetap di
        <a href="{{ route('dompet.index') }}" class="font-medium text-brand hover:underline">Dompet &amp; Tabungan</a>.
        Perbandingannya terhadap buku besar ada di
        <a href="{{ route('kontrol.rekonsiliasi') }}" class="font-medium text-brand hover:underline">Rekonsiliasi Buku Pembantu</a>.
    </p>

    {{-- Saringan dipakai BERSAMA oleh kedua daftar: satu kotak pencarian yang
         menyaring dua tabel sekaligus jauh lebih mudah dipahami daripada dua
         kotak yang tampak sama tapi bekerja sendiri-sendiri. --}}
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="min-w-[14rem] flex-1">
            <label class="mb-1 block text-xs font-medium text-gray-600">Cari nama, NIS, atau telepon</label>
            <input type="search" name="cari" value="{{ $f['cari'] }}"
                   class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Urutkan</label>
            <select name="urut" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                <option value="saldo" @selected($f['urut'] === 'saldo')>Saldo</option>
                <option value="nama" @selected($f['urut'] === 'nama')>Nama</option>
                <option value="nis" @selected($f['urut'] === 'nis')>NIS</option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Arah</label>
            <select name="arah" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                <option value="desc" @selected($f['arah'] === 'desc')>Terbesar dulu</option>
                <option value="asc" @selected($f['arah'] === 'asc')>Terkecil dulu</option>
            </select>
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <input type="checkbox" name="bersaldo" value="1" @checked($f['bersaldo'])
                   class="rounded border-gray-300 text-brand focus:ring-brand">
            Hanya yang bersaldo
        </label>

        <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
            Terapkan
        </button>

        @if ($f['cari'] !== '' || $f['bersaldo'])
            <a href="{{ route('saldo_dompet.index') }}" class="pb-2 text-sm text-gray-500 hover:underline">Bersihkan</a>
        @endif
    </form>

    {{-- ══ DOMPET WALI ══ --}}
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-800">Dompet Wali</h2>
            <p class="text-xs text-gray-500">
                Akun buku besar <code class="rounded bg-gray-100 px-1">{{ $coa['wali'] }}</code> ·
                {{ number_format($wali['bersaldo'], 0, ',', '.') }} dari
                {{ number_format($wali['jumlah'], 0, ',', '.') }} wali bersaldo
            </p>
        </div>
        <div class="flex items-end gap-4">
            <div class="text-right">
                <div class="text-xs text-gray-500">Total seluruh wali</div>
                <div class="text-lg font-semibold text-gray-800">@rp($wali['total'])</div>
            </div>
            <x-unduh :url="route('saldo_dompet.unduh', ['lingkup' => 'wali'] + request()->query())" />
        </div>
    </div>

    <div class="mb-8 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Wali</th>
                    <th class="px-4 py-3">Telepon</th>
                    <th class="px-4 py-3 text-right">Santri</th>
                    <th class="px-4 py-3 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($wali['baris'] as $w)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('dompet.index', ['id_wali' => $w->id]) }}"
                               class="font-medium text-brand hover:underline">{{ $w->nama }}</a>
                            @if ($w->status !== 'aktif')
                                <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">{{ $w->status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $w->telepon }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-600">{{ $w->santri_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $w->saldo != 0 ? 'font-medium text-gray-800' : 'text-gray-300' }}">
                            @rp($w->saldo)
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">Tak ada wali yang cocok dengan saringan itu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($wali['baris']->hasPages())
        <div class="-mt-6 mb-8">{{ $wali['baris']->links() }}</div>
    @endif

    {{-- ══ DOMPET & TABUNGAN SANTRI ══ --}}
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-800">Dompet &amp; Tabungan Santri</h2>
            <p class="text-xs text-gray-500">
                Akun <code class="rounded bg-gray-100 px-1">{{ $coa['santri'] }}</code> (dompet) dan
                <code class="rounded bg-gray-100 px-1">{{ $coa['tabungan'] }}</code> (tabungan) ·
                {{ number_format($santri['bersaldo'], 0, ',', '.') }} dari
                {{ number_format($santri['jumlah'], 0, ',', '.') }} santri bersaldo
            </p>
        </div>
        <div class="flex items-end gap-4">
            <div class="text-right">
                <div class="text-xs text-gray-500">Dompet</div>
                <div class="text-lg font-semibold text-gray-800">@rp($santri['total_dompet'])</div>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">Tabungan</div>
                <div class="text-lg font-semibold text-gray-800">@rp($santri['total_tabungan'])</div>
            </div>
            <x-unduh :url="route('saldo_dompet.unduh', ['lingkup' => 'santri'] + request()->query())" />
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang</th>
                    <th class="px-4 py-3">Wali</th>
                    <th class="px-4 py-3 text-right">Dompet</th>
                    <th class="px-4 py-3 text-right">Tabungan</th>
                    <th class="px-4 py-3 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($santri['baris'] as $s)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800">{{ $s->nama }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $s->nis ?: 'belum ber-NIS' }}
                                @if ($s->status !== 'aktif')
                                    · <span class="text-gray-400">{{ $s->status }}</span>
                                @endif
                                @if ($s->kunci_tarik)
                                    · <span class="text-amber-700">penarikan dikunci</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $s->jenjang }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $s->nama_wali ?: '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $s->saldo != 0 ? 'text-gray-800' : 'text-gray-300' }}">@rp($s->saldo)</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $s->tabungan != 0 ? 'text-gray-800' : 'text-gray-300' }}">@rp($s->tabungan)</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $s->jumlah != 0 ? 'font-medium text-gray-800' : 'text-gray-300' }}">@rp($s->jumlah)</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">Tak ada santri yang cocok dengan saringan itu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($santri['baris']->hasPages())
        <div class="mt-4">{{ $santri['baris']->links() }}</div>
    @endif
@endsection
