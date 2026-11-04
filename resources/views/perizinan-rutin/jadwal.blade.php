@extends('layouts.app')

@section('title', 'Jadwal Perizinan Rutin')

@php
    $ubah = \App\Support\Akses::boleh('perizinan-jadwal', 'ubah');
    $wkt = fn ($t) => $t?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('D, d M Y H:i');
    $hari = \App\Models\AturanPerizinanRutin::HARI;
@endphp

@section('content')
    <a href="{{ route('perizinan_rutin.index') }}" class="text-sm text-brand hover:underline">&larr; Perizinan Rutin</a>

    {{-- 1. Matriks aturan per jenis --}}
    @foreach ($tipe as $t)
        @php $sel = $aturan[$t->kode] ?? collect(); @endphp
        <form method="POST" action="{{ route('perizinan_rutin.aturan', $t->kode) }}" class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            @csrf @method('PUT')
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-900">Matriks {{ $t->nama }}</h2>
                <p class="text-xs text-gray-500">Contoh: hari <b>Ahad</b> ke-<b>2</b> 07:00, +0 hari 17:00 · Kepulangan: <b>Jumat</b> ke-<b>4</b> 15:00, +2 hari (Ahad) 17:00.
                    @unless ($t->aktif) <span class="font-semibold text-red-600">Jenis ini sedang nonaktif di Pengaturan Perizinan.</span>@endunless</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-xs text-gray-500"><tr><th class="py-1 text-start font-normal">Jenjang</th>
                        @foreach (['L' => 'Ikhwan', 'P' => 'Akhwat'] as $l)<th class="px-2 py-1 text-start font-normal">{{ $l }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($jenjang as $jj)
                            <tr class="border-t border-gray-100 align-top">
                                <td class="py-2 pe-3 font-medium text-gray-800">{{ $jj->nama }}</td>
                                @foreach (['L', 'P'] as $jk)
                                    @php $a = $sel[$jj->kode.'|'.$jk] ?? null; $n = "sel[{$jj->kode}][{$jk}]"; @endphp
                                    <td class="px-2 py-2" x-data="{ on: {{ $a ? 'true' : 'false' }} }">
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="{{ $n }}[aktif]" value="1" x-model="on" class="rounded border-gray-300"> Berlaku</label>
                                        <div x-show="on" x-cloak class="mt-1 flex flex-wrap items-center gap-1 text-xs">
                                            <select name="{{ $n }}[hari]" class="rounded border-gray-300 py-1 text-xs">
                                                @foreach ($hari as $i => $h)<option value="{{ $i }}" @selected(($a?->hari ?? ($t->kode === 'kepulangan' ? 5 : 0)) === $i)>{{ $h }}</option>@endforeach
                                            </select>
                                            ke-<select name="{{ $n }}[pekan_ke]" class="rounded border-gray-300 py-1 text-xs">
                                                @foreach (range(1, 5) as $p)<option value="{{ $p }}" @selected(($a?->pekan_ke ?? ($t->kode === 'kepulangan' ? 4 : 2)) === $p)>{{ $p }}</option>@endforeach
                                            </select>
                                            <input type="time" name="{{ $n }}[jam_mulai]" value="{{ substr((string) ($a?->jam_mulai ?? ($t->kode === 'kepulangan' ? '15:00' : '07:00')), 0, 5) }}" class="rounded border-gray-300 py-1 text-xs">
                                            → +<input type="number" name="{{ $n }}[lama_hari]" min="0" max="14" value="{{ $a?->lama_hari ?? ($t->kode === 'kepulangan' ? 2 : 0) }}" class="w-12 rounded border-gray-300 py-1 text-xs"> hari
                                            <input type="time" name="{{ $n }}[jam_selesai]" value="{{ substr((string) ($a?->jam_selesai ?? '17:00'), 0, 5) }}" class="rounded border-gray-300 py-1 text-xs">
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($ubah)<div class="mt-2 text-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan matriks {{ $t->nama }}</button></div>@endif
        </form>
    @endforeach

    @if ($ubah)
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            {{-- 2. Bangkitkan --}}
            <form method="POST" action="{{ route('perizinan_rutin.bangkitkan') }}" data-confirm="Buat jadwal dari matriks?" class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                @csrf
                <h2 class="font-semibold text-gray-900">Buat jadwal dari matriks</h2>
                <p class="mt-1 text-xs text-gray-500">Jadwal yang sudah ada (termasuk yang ditiadakan) tidak dibuat ulang — aman ditekan berulang.</p>
                <div class="mt-2 flex flex-wrap items-end gap-2">
                    <label class="text-xs">Mulai bulan<input type="month" name="dari" required value="{{ now()->format('Y-m') }}" class="mt-1 block rounded-lg border-gray-300 text-sm"></label>
                    <label class="text-xs">Untuk<select name="bulan" class="mt-1 block rounded-lg border-gray-300 text-sm">@foreach ([1, 2, 3, 6, 12] as $b)<option value="{{ $b }}" @selected($b === 3)>{{ $b }} bulan</option>@endforeach</select></label>
                    <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Buat jadwal</button>
                </div>
            </form>

            {{-- 3. Jadwal khusus --}}
            <form method="POST" action="{{ route('perizinan_rutin.tambah') }}" class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                @csrf
                <h2 class="font-semibold text-gray-900">Tambah jadwal khusus</h2>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    <select name="kode_tipe" class="rounded-lg border-gray-300 text-sm">@foreach ($tipe as $t)<option value="{{ $t->kode }}">{{ $t->nama }}</option>@endforeach</select>
                    <input type="datetime-local" name="mulai" required class="rounded-lg border-gray-300 text-sm">
                    <input type="datetime-local" name="selesai" required class="rounded-lg border-gray-300 text-sm">
                </div>
                @include('perizinan-rutin._cakupan', ['pilih' => []])
                <input name="catatan" maxlength="500" placeholder="Catatan untuk wali (opsional), mis. libur semester" class="mt-2 w-full rounded-lg border-gray-300 text-sm">
                <button class="mt-2 rounded-lg border border-brand px-4 py-2 font-semibold text-brand">Tambah</button>
            </form>
        </div>
    @endif

    {{-- 4. Jadwal mendatang --}}
    <h2 class="mb-2 mt-6 text-sm font-bold uppercase tracking-wide text-gray-600">Jadwal mendatang</h2>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Jadwal</th><th class="px-4 py-3">Cakupan</th><th class="px-4 py-3 text-right">Pengajuan</th>@if ($ubah)<th class="px-4 py-3 text-right">Aksi</th>@endif</tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($mendatang as $j)
                    <tr class="align-top">
                        <td class="px-4 py-3"><span class="font-semibold text-gray-900">{{ $j->tipe->nama }}</span>
                            @if ($j->status !== 'aktif')<span class="ms-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">Ditiadakan</span>@endif
                            @if ($j->khusus)<span class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">Khusus</span>@endif
                            <span class="block text-xs text-gray-500">{{ $wkt($j->mulai) }} → {{ $wkt($j->selesai) }}</span>
                            @if ($j->status !== 'aktif')<span class="block text-xs text-red-700">{{ $j->alasan_ditiadakan }}</span>@elseif ($j->catatan)<span class="block text-xs text-gray-500">{{ $j->catatan }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ $j->labelCakupan() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums"><a href="{{ route('perizinan_rutin.show', $j->id) }}" class="text-brand hover:underline">{{ $j->izin_count }}</a></td>
                        @if ($ubah)
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2" x-data="{ tiada: false }">
                                    <a href="{{ route('perizinan_rutin.edit', $j->id) }}" class="text-brand hover:underline">Ubah</a>
                                    @if ($j->status === 'aktif')
                                        <button type="button" @click="tiada = ! tiada" class="text-red-600 hover:underline">Tiadakan</button>
                                        <form x-show="tiada" x-cloak method="POST" action="{{ route('perizinan_rutin.tiadakan', $j->id) }}" data-confirm="Tiadakan jadwal ini? Pengajuan yang belum keluar dibatalkan dan wali dikabari." class="flex w-full gap-1">
                                            @csrf
                                            <input name="alasan" required maxlength="500" placeholder="Alasan (dibaca wali)" class="w-full rounded border-gray-300 text-xs">
                                            <button class="rounded bg-red-600 px-2 text-xs font-semibold text-white">OK</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('perizinan_rutin.aktifkan', $j->id) }}">@csrf<button class="text-emerald-700 hover:underline">Aktifkan lagi</button></form>
                                    @endif
                                    @if ($j->izin_count === 0)
                                        <form method="POST" action="{{ route('perizinan_rutin.destroy', $j->id) }}" data-confirm="Hapus jadwal ini?">@csrf @method('DELETE')<button class="text-gray-500 hover:underline">Hapus</button></form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">Belum ada jadwal mendatang.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
