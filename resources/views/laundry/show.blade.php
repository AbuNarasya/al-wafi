@extends('layouts.app')

@section('title', 'Laundry '.$l->nomor)

@php
    $modul = \App\Support\BagianLaundry::BAGIAN[\App\Support\BagianLaundry::dariJk($l->santri?->jenis_kelamin) ?? 'ikhwan']['modul'];
    $bolehUbah = \App\Support\Akses::boleh($modul, 'ubah');
@endphp

@section('content')
    <a href="{{ route('laundry.index') }}" class="text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    <div class="mt-3 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold text-gray-900">{{ $l->santri?->nama }}</p>
                        <p class="text-gray-500">{{ $l->santri?->nis }} · {{ $l->santri?->jenjang?->nama }}{{ $l->santri?->kelas ? ' · '.$l->santri->kelas->nama : '' }}</p>
                    </div>
                    <span @class(['rounded-full px-3 py-1 text-xs font-semibold',
                        'bg-amber-100 text-amber-800' => in_array($l->status, ['diterima', 'diperiksa', 'diproses'], true),
                        'bg-blue-100 text-blue-800' => $l->status === 'selesai', 'bg-emerald-100 text-emerald-800' => $l->status === 'diserahkan',
                        'bg-gray-100 text-gray-600' => $l->status === 'dibatalkan'])>{{ $l->labelStatus() }}</span>
                </div>
                <p class="mt-3">{{ $l->nomor }} · <b>{{ $l->berat }} kg</b> · {{ $l->jumlahPotong() }} potong
                    @if ($l->berat_terima !== null && (float) $l->berat_terima !== (float) $l->berat)<span class="text-xs text-gray-500">(saat diterima {{ $l->berat_terima }} kg)</span>@endif</p>
                <div class="mt-2 flex flex-wrap gap-3 text-sm">
                    <a href="{{ route('laundry.label', $l->id) }}?cetak=1" target="_blank" class="text-brand hover:underline">Cetak label kantong</a>
                    @if ($l->status === 'diterima' && $bolehUbah)<a href="{{ route('laundry.periksa', $l->id) }}" class="font-semibold text-brand hover:underline">Periksa sekarang (timbang ulang)</a>@endif
                </div>
                @if ($l->catatan)<p class="mt-1 text-gray-600">Catatan: {{ $l->catatan }}</p>@endif

                <table class="mt-3 w-full">
                    <thead class="text-xs text-gray-500"><tr><th class="text-start font-normal">Pakaian</th><th class="text-end font-normal">Diterima</th>
                        @if ($l->status === 'diserahkan')<th class="text-end font-normal">Dikembalikan</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($l->item as $it)
                            <tr class="border-t border-gray-100">
                                <td class="py-1.5">{{ $it->nama_pakaian }}</td>
                                <td class="text-end tabular-nums">{{ $it->jumlah }}</td>
                                @if ($l->status === 'diserahkan')
                                    <td @class(['text-end tabular-nums', 'font-semibold text-red-700' => $it->kurang() > 0])>{{ $it->jumlah_kembali }}@if ($it->kurang() > 0) (kurang {{ $it->kurang() }})@endif</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($l->catatan_serah)<p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-red-800">Catatan penyerahan: {{ $l->catatan_serah }}</p>@endif
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <p class="mb-2 font-semibold text-gray-900">Perjalanan cucian</p>
                <ol class="space-y-2">
                    @foreach (\App\Models\Laundry::LANGKAH as $s => $label)
                        <li @class(['flex justify-between gap-2', 'text-gray-400' => ! $l->{"{$s}_pada"}])>
                            <span>{{ $label }}</span>
                            <span class="text-end text-xs">{{ $l->{"{$s}_pada"}?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—' }}<br>{{ $petugas[$l->{"{$s}_oleh"}] ?? '' }}</span>
                        </li>
                    @endforeach
                    @if ($l->status === 'dibatalkan')
                        <li class="flex justify-between gap-2 text-red-700"><span>Dibatalkan</span>
                            <span class="text-end text-xs">{{ $l->dibatalkan_pada?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}<br>{{ $petugas[$l->dibatalkan_oleh] ?? '' }}</span></li>
                        <li class="text-xs text-red-700">Alasan: {{ $l->alasan_batal }}</li>
                    @endif
                </ol>
                <p class="mt-3 text-xs text-gray-500">Dikenali saat diterima: {{ $l->cara_terima === 'kartu' ? 'tap kartu' : 'pencarian manual' }}@if ($l->cara_serah) · saat diserahkan: {{ $l->cara_serah === 'kartu' ? 'tap kartu' : 'pencarian manual' }}@endif</p>
            </div>

            @if (in_array($l->status, \App\Models\Laundry::BERJALAN, true) && $bolehUbah)
                {{-- Paket 1 (3 Nov 2026): ubah data — berat, daftar pakaian, catatan; alasan wajib, tercatat di jejak audit. --}}
                <form method="POST" action="{{ route('laundry.ubah', $l->id) }}" x-data="{ buka: @js($errors->any() || old('alasan') !== null) }"
                      data-confirm="Simpan perubahan data {{ $l->nomor }}?" class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                    @csrf @method('PUT')
                    <button type="button" x-show="! buka" @click="buka = true" class="text-sm text-brand hover:underline">Ubah data (salah input berat / pakaian / catatan)</button>
                    <div x-show="buka" x-cloak class="space-y-3">
                        <p class="font-semibold text-gray-900">Ubah data kantong</p>
                        @if ($terkunci)
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Kelebihan bulan ini sudah ditagih — <b>berat</b> terkunci; pakaian & catatan masih bisa diubah.</p>
                        @endif
                        <label class="block"><span class="text-xs text-gray-500">Berat (kg)</span>
                            <input name="berat" type="number" step="0.01" min="0.01" max="{{ \App\Services\Modules\LaundryService::BERAT_MAKS }}" required
                                   value="{{ old('berat', $l->berat) }}" @readonly($terkunci) class="w-full rounded-lg border-gray-300 text-sm"></label>
                        @include('laundry._isian-pakaian', ['jenis' => $jenis, 'awal' => $l->item,
                            'judul' => $l->status === 'diterima' ? 'Daftar pakaian (belum diperiksa — boleh kosong)' : 'Daftar pakaian *'])
                        <label class="block"><span class="text-xs text-gray-500">Catatan</span>
                            <textarea name="catatan" rows="2" maxlength="1000" class="w-full rounded-lg border-gray-300 text-sm">{{ old('catatan', $l->catatan) }}</textarea></label>
                        <input name="alasan" required maxlength="500" value="{{ old('alasan') }}" placeholder="Alasan perubahan *" class="w-full rounded-lg border-gray-300 text-sm">
                        <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white">Simpan perubahan</button>
                    </div>
                </form>
            @elseif ($terkunci)
                <p class="rounded-xl border border-gray-200 bg-white p-4 text-xs text-gray-500 shadow-sm">Kelebihan laundry bulan ini sudah ditagih — kantong terkunci.</p>
            @endif

            @if (in_array($l->status, ['diterima', 'diperiksa'], true) && ! $terkunci && \App\Support\Akses::boleh($modul, 'hapus'))
                <form method="POST" action="{{ route('laundry.batal', $l->id) }}" class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm" data-confirm="Batalkan kantong {{ $l->nomor }}?">
                    @csrf
                    <p class="font-semibold text-gray-900">Batalkan (salah catat)</p>
                    <input name="alasan" required maxlength="500" placeholder="Alasan" class="mt-2 w-full rounded-lg border-gray-300 text-sm">
                    <button class="mt-2 rounded-lg border border-red-300 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-50">Batalkan</button>
                </form>
            @endif
        </div>
    </div>
@endsection
