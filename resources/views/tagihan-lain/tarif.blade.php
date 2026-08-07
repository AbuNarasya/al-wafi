@extends('layouts.app')

@section('title', 'Matriks Tarif Kegiatan')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Tarif kegiatan per <b>kegiatan &times; jenjang</b>. Umroh SMA tidak harus sama dengan umroh SMP.
    </p>
    <p class="mb-4 text-sm text-gray-500">
        Hanya jenis biaya yang cara tagihnya <b>kepesertaan</b> yang muncul di sini. Aturnya di
        <a href="{{ route('jenis_biaya.index') }}" class="font-medium text-brand hover:underline">Jenis Biaya</a>.
    </p>

    @if (! $grid['jenjang'])
        <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
            Master jenjang belum ada. Isi dulu di Setting Awal &rarr; Jenjang Pendidikan.
        </div>
    @elseif (! $grid['baris'])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada jenis biaya yang ditagih menurut kepesertaan.
            <a href="{{ route('jenis_biaya.create') }}" class="font-medium underline">Tambahkan dulu di Jenis Biaya</a>
            dengan cara menagih &ldquo;kepesertaan&rdquo;, baru tarifnya bisa diisi di sini.
        </div>
    @else
        {{-- Peringatan ini WAJIB ada: bentuknya sama persis dengan matriks Tarif
             Biaya, tetapi arti sel kosongnya BERLAWANAN. --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600">
            <b>Dua keadaan tiap sel</b> &mdash;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">angka</span> jenjang itu ikut, sebesar itu &middot;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">dikosongkan</span> jenjang itu <b>tidak ikut</b> kegiatannya, dan santrinya tak bisa didaftarkan sebagai peserta.
            <br>
            Berbeda dari matriks <b>Tarif Biaya</b>, sel kosong di sini <b>bukan</b> berarti &ldquo;belum diisi&rdquo; dan
            tidak menghentikan apa pun &mdash; tak ada yang aneh bila SDTQ memang tidak ikut program umroh.
            Nol adalah angka yang sah: tagihan tetap terbit sebesar Rp&nbsp;0 untuk mencatat kepesertaannya.
        </div>

        <form method="POST" action="{{ route('tagihan_lain.tarif.simpan') }}">
            @csrf @method('PUT')

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-4 py-3">
                    <strong class="text-sm text-gray-900">Tarif Kegiatan</strong>
                    <span class="text-xs text-gray-400">nilai dalam rupiah</span>
                </div>

                <table data-matriks class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Kegiatan</th>
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
                                    @if ($b['status'] !== 'aktif')
                                        <div class="mt-0.5 text-[11px] text-amber-600">jenis biayanya nonaktif</div>
                                    @endif
                                </td>
                                @foreach ($grid['jenjang'] as $j)
                                    <td class="px-2 py-2">
                                        <x-input-rupiah :name="'sel[' . $b['kode'] . '][' . $j['kode'] . ']'"
                                                        :value="$b['sel'][$j['kode']]"
                                                        placeholder="tidak ikut" />
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (\App\Support\Akses::boleh('tagihan-lain', 'ubah'))
                <div class="mt-3 flex justify-end gap-2">
                    <a href="{{ route('tagihan_lain.tarif') }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Tarif</button>
                </div>
            @endif
        </form>
    @endif
@endsection
