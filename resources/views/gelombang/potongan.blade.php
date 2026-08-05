@extends('layouts.app')

@section('title', 'Potongan Gelombang')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Potongan uang pangkal per <b>gelombang &times; jenjang</b>. Satu layar untuk satu tahun ajaran.
    </p>
    <p class="mb-4 text-sm text-gray-500">
        Nama, periode, dan masa berlakunya diatur di
        <a href="{{ route('gelombang.index', ['ta' => $ta]) }}" class="font-medium text-brand hover:underline">Master Gelombang</a>.
    </p>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Ajaran</label>
            <select name="ta" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                @foreach ($opsiTa as $kode => $label)
                    <option value="{{ $kode }}" @selected($kode === $ta)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
    </form>

    @if (! $grid || ! $grid['jenjang'])
        <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
            Tahun ajaran atau jenjang belum ada. Isi dulu di Setting Awal.
        </div>
    @elseif (! $grid['baris'])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada gelombang aktif untuk T.A {{ $ta }}.
            <a href="{{ route('gelombang.create', ['ta' => $ta]) }}" class="font-medium underline">Tambahkan dulu di Master Gelombang</a>,
            baru potongannya bisa diisi di sini.
        </div>
    @else
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600">
            <b>Dua keadaan tiap sel</b> &mdash;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">angka</span> potongan berlaku &middot;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">dikosongkan</span> tidak ada potongan untuk kombinasi itu.
            <br>
            Tiap jenjang disebut <b>sendiri-sendiri</b> &mdash; tidak ada kolom "semua jenjang", karena jenjang selalu diketahui saat menagih.
            Nol adalah angka yang sah: tagihan tetap terbit sebesar penuh.
        </div>

        <form method="POST" action="{{ route('gelombang.potongan.simpan') }}">
            @csrf @method('PUT')
            <input type="hidden" name="tahun_ajaran" value="{{ $ta }}">

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-4 py-3">
                    <strong class="text-sm text-gray-900">T.A {{ $ta }}</strong>
                    <span class="text-xs text-gray-400">nilai dalam rupiah</span>
                </div>

                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Gelombang</th>
                            @foreach ($grid['jenjang'] as $j)
                                <th class="px-4 py-3 text-right">{{ $j['nama'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($grid['baris'] as $b)
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800">{{ $b['nama'] }}</div>
                                    <div class="font-mono text-[11px] text-gray-400">{{ $b['kode'] }}</div>
                                    @if ($b['keadaan'] !== 'berlaku')
                                        {{-- Gelombang yang periodenya lewat/belum mulai tetap bisa
                                             diisi — supaya bisa disiapkan atau diperbaiki — tapi
                                             keadaannya disebut, bukan didiamkan. --}}
                                        <div class="mt-0.5 text-[11px] text-amber-600">
                                            {{ $b['keadaan'] === 'kedaluwarsa' ? 'periode lewat' : 'belum mulai' }} · {{ $b['periode'] }}
                                        </div>
                                    @endif
                                </td>
                                @foreach ($grid['jenjang'] as $j)
                                    <td class="px-2 py-2">
                                        <x-input-rupiah :name="'sel[' . $b['kode'] . '][' . $j['kode'] . ']'"
                                                        :value="$b['sel'][$j['kode']]"
                                                        placeholder="tanpa potongan" />
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($grid['arsip'])
                    <div class="border-t border-gray-100 bg-gray-50 px-4 py-3 text-xs text-gray-500">
                        Gelombang <b>{{ implode(', ', $grid['arsip']) }}</b>
                        tidak ditampilkan karena berstatus Arsip di Master Gelombang.
                    </div>
                @endif
            </div>

            @if (\App\Support\Akses::boleh('potongan-gelombang', 'ubah'))
                <div class="mt-3 flex justify-end gap-2">
                    <a href="{{ route('gelombang.potongan', ['ta' => $ta]) }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Potongan</button>
                </div>
            @endif
        </form>
    @endif
@endsection
