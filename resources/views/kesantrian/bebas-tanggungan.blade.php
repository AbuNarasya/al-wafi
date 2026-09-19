@extends('layouts.app')

@section('title', 'Bebas Tanggungan')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold text-gray-900">Bebas Tanggungan</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">
                Diperiksa <b>dua arah</b>: yang santri masih hutang (tagihan bersisa) dan yang pesantren masih titip
                (saldo dompet &amp; tabungan). Yang kedua paling sering terlewat — titipan tak terasa seperti masalah,
                tetapi ia menggantung di neraca selamanya bila pemiliknya sudah tak ada.
            </p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Jenjang</label>
                <select name="jenjang" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua jenjang</option>
                    @foreach ($jenjangOptions as $kode => $nama)
                        <option value="{{ $kode }}" @selected($jenjang === $kode)>{{ $nama }}</option>
                    @endforeach
                </select></div>
            <label class="flex items-center gap-1.5 pb-2 text-xs text-gray-600">
                <input type="checkbox" name="semua" value="1" @checked($semua) class="rounded border-gray-300 text-brand focus:ring-brand">
                tampilkan yang sudah bersih juga
            </label>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang</th>
                    <th class="px-4 py-3 text-right">Tagihan Bersisa</th>
                    <th class="px-4 py-3 text-right">Titipan Belum Dikembalikan</th>
                    <th class="px-4 py-3">Keadaan</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $r['santri']->nama }}</div>
                            <div class="text-xs text-gray-400">{{ $r['santri']->nis }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ $jenjangOptions[$r['santri']->kode_jenjang] ?? $r['santri']->kode_jenjang }}
                            @if ($r['santri']->tingkat)<span class="text-xs text-gray-400"> · tingkat {{ $r['santri']->tingkat }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $r['tagihan'] > 0 ? 'text-red-700' : 'text-gray-300' }}">
                            @rp($r['tagihan'])
                            @if ($r['jumlah_tagihan'] > 0)
                                <div class="text-[11px] font-normal text-gray-400">{{ $r['jumlah_tagihan'] }} tagihan</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums {{ (float) $r['titipan'] > 0 ? 'text-amber-700' : 'text-gray-300' }}">@rp($r['titipan'])</td>
                        <td class="px-4 py-3">
                            @if ($r['bersih'])
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Bebas tanggungan</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Belum bebas</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('rekap_pembayaran.show', $r['santri']->id) }}" class="text-brand hover:underline">Rekap</a>
                            @if ((float) $r['titipan'] > 0)
                                <a href="{{ route('dompet.index', ['cari' => $r['santri']->nama]) }}" class="ml-2 text-brand hover:underline">Kembalikan titipan</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">
                        {{ $semua ? 'Tidak ada santri aktif pada saringan ini.' : 'Semua santri aktif sudah bebas tanggungan.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-gray-500">
        Dompet <b>wali</b> sengaja tidak ikut dihitung: ia milik keluarga, bukan milik santri yang lulus,
        dan adik-adiknya mungkin masih bersekolah.
    </p>
@endsection
