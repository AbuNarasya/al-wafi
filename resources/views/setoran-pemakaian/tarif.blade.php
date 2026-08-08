@extends('layouts.app')

@section('title', 'Matriks Tarif Layanan')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Besaran <b>layanan bersatuan</b> — laundry per kilogram dan sejenisnya.
    </p>
    <p class="mb-4 text-sm text-gray-500">
        Hanya jenis biaya yang cara tagihnya <b>pemakaian</b> yang muncul di sini. Aturnya di
        <a href="{{ route('jenis_biaya.index') }}" class="font-medium text-brand hover:underline">Jenis Biaya</a>.
        Untuk kegiatan berpeserta, besarannya ada di
        <a href="{{ route('tagihan_lain.tarif') }}" class="font-medium text-brand hover:underline">Matriks Tarif Kegiatan</a>.
    </p>

    @if (! $grid)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada layanan yang ditagih menurut pemakaian.
            <a href="{{ route('jenis_biaya.create') }}" class="font-medium underline">Tambahkan dulu di Jenis Biaya</a>
            dengan cara menagih &ldquo;pemakaian&rdquo;.
        </div>
    @else
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600">
            <b>Tarif dikosongkan</b> berarti besarannya <b>belum diatur</b> &mdash; Setoran Laundry akan menolak mencatat
            pemakaian layanan itu, karena kuantitas yang tak bisa dihargai hanya menumpuk jadi pekerjaan yang harus diulang.
            <br>
            Nol adalah angka yang sah: layanan gratis yang pemakaiannya tetap dicatat.
            <b>Kuota</b> dikosongkan berarti tak ada jatah gratis &mdash; seluruh pemakaian ditagih.
        </div>

        <form method="POST" action="{{ route('setoran_pemakaian.tarif.simpan') }}">
            @csrf @method('PUT')

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Layanan</th>
                            <th class="px-4 py-3 text-right">Tarif per Satuan</th>
                            <th class="px-4 py-3 text-left">Nama Satuan</th>
                            <th class="px-4 py-3 text-right">Kuota Gratis / Periode</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($grid as $b)
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800">{{ $b['nama'] }}</div>
                                    <div class="text-[11px] text-gray-400">
                                        <span class="font-mono">{{ $b['kode'] }}</span>{{ $b['nama_jenjang'] ? ' · '.$b['nama_jenjang'] : '' }}
                                    </div>
                                    @if ($b['status'] !== 'aktif')
                                        <div class="mt-0.5 text-[11px] text-amber-600">jenis biayanya nonaktif</div>
                                    @endif
                                </td>
                                <td class="px-2 py-2">
                                    <x-input-rupiah :name="'baris[' . $b['kode'] . '][tarif_satuan]'"
                                                    :value="$b['tarif_satuan']"
                                                    placeholder="belum diatur" />
                                </td>
                                <td class="px-2 py-2">
                                    <input type="text" name="baris[{{ $b['kode'] }}][nama_satuan]"
                                           value="{{ $b['nama_satuan'] }}" placeholder="kg" maxlength="20"
                                           class="w-24 rounded border-gray-300 text-sm">
                                </td>
                                <td class="px-2 py-2">
                                    <input type="number" step="0.01" min="0" name="baris[{{ $b['kode'] }}][kuota_gratis]"
                                           value="{{ $b['kuota_gratis'] }}" placeholder="tanpa kuota"
                                           class="w-32 rounded border-gray-300 text-right text-sm tabular-nums">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (\App\Support\Akses::boleh('tarif-pemakaian', 'ubah'))
                <div class="mt-3 flex justify-end gap-2">
                    <a href="{{ route('setoran_pemakaian.tarif') }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Tarif</button>
                </div>
            @endif
        </form>
    @endif
@endsection
