@extends('layouts.app')

@section('title', 'Pengaturan Laundry')

@section('content')
    @php $ubah = \App\Support\Akses::boleh('laundry-master', 'ubah'); @endphp
    <form method="POST" action="{{ route('laundry.pengaturan.simpan') }}" class="mx-auto max-w-3xl space-y-4">
        @csrf @method('PUT')
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Tarif &amp; kuota</h2>
            <div class="max-w-xs">
                <label class="mb-1 block text-sm font-medium text-gray-700">Tarif kelebihan per kg <span class="text-red-500">*</span></label>
                <x-input-rupiah name="tarif_per_kg" :value="old('tarif_per_kg', $p->tarif_per_kg)" required placeholder="mis. 7000" />
            </div>
            <label class="block"><span class="mb-1 block text-sm font-medium text-gray-700">Jenis biaya penagihan (bawaan)</span>
                <select name="kode_jenis_bawaan" class="w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— belum dipilih —</option>
                    @foreach ($opsiJenis as $k => $n)<option value="{{ $k }}" @selected(old('kode_jenis_bawaan', $p->kode_jenis_bawaan) === $k)>{{ $n }}</option>@endforeach
                </select>
                <span class="mt-1 block text-xs text-gray-500">Jenis biaya bertipe Lain-lain — menentukan akun pendapatan/piutang &amp; unit bisnis tagihannya.</span></label>
            <p class="text-xs text-gray-500">Kuota dihitung per <b>bulan kalender</b> dari tanggal cucian diterima; sisa kuota tidak terbawa ke bulan berikutnya.</p>
        </div>

        {{-- MATRIKS KUOTA jenjang × ikhwan/akhwat (keputusan user 3 Nov 2026, seperti matriks biaya). --}}
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Matriks kuota per bulan (kg) &amp; jenis biaya</h2>
            <p class="text-xs text-gray-500">Baris <b>Bawaan</b> berlaku untuk jenjang yang selnya dikosongkan. Kuota ikhwan &amp; akhwat boleh berbeda.</p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="text-xs text-gray-500"><tr>
                        <th class="text-start font-normal">Jenjang</th><th class="w-32 text-start font-normal">Ikhwan</th>
                        <th class="w-32 text-start font-normal">Akhwat</th><th class="text-start font-normal">Jenis biaya</th></tr></thead>
                    <tbody>
                        <tr class="border-t border-gray-100 bg-gray-50">
                            <td class="py-2 ps-2 font-semibold">Bawaan <span class="text-red-500">*</span></td>
                            <td class="py-2 pe-3"><input name="kuota_ikhwan_kg" type="number" step="0.5" min="0" required value="{{ old('kuota_ikhwan_kg', (float) $p->kuota_ikhwan_kg) }}" class="w-full rounded-lg border-gray-300 text-sm"></td>
                            <td class="py-2 pe-3"><input name="kuota_akhwat_kg" type="number" step="0.5" min="0" required value="{{ old('kuota_akhwat_kg', (float) $p->kuota_akhwat_kg) }}" class="w-full rounded-lg border-gray-300 text-sm"></td>
                            <td class="py-2 text-xs text-gray-500">diatur di atas</td>
                        </tr>
                        @foreach ($jenjang as $j)
                            @php $x = $perJenjang->get($j->kode); @endphp
                            <tr class="border-t border-gray-100">
                                <td class="py-2">{{ $j->nama }}</td>
                                @foreach (['ikhwan', 'akhwat'] as $bg)
                                    <td class="py-2 pe-3"><input name="jenjang[{{ $j->kode }}][kuota_{{ $bg }}_kg]" type="number" step="0.5" min="0"
                                               value="{{ old("jenjang.{$j->kode}.kuota_{$bg}_kg", $x?->{"kuota_{$bg}_kg"} !== null ? (float) $x->{"kuota_{$bg}_kg"} : '') }}"
                                               placeholder="{{ (float) $p->{"kuota_{$bg}_kg"} }}" class="w-full rounded-lg border-gray-300 text-sm"></td>
                                @endforeach
                                <td class="py-2"><select name="jenjang[{{ $j->kode }}][kode_jenis]" class="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="">— bawaan —</option>
                                    @foreach ($opsiJenis as $k => $n)<option value="{{ $k }}" @selected(old("jenjang.{$j->kode}.kode_jenis", $x?->kode_jenis) === $k)>{{ $n }}</option>@endforeach
                                </select></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Penerbitan tagihan kelebihan</h2>
            <p class="text-sm text-gray-600">Tanggal 1 setiap bulan draf tagihan bulan lalu disusun otomatis; keuangan meninjaunya di <b>Kuota &amp; Tagihan</b>.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-sm font-medium text-gray-700">Terbit pada tanggal</span>
                    <input name="hari_terbit" type="number" min="1" max="28" required value="{{ old('hari_terbit', $p->hari_terbit) }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="flex items-center gap-2 pt-6 text-sm"><input type="hidden" name="terbit_otomatis" value="0">
                    <input type="checkbox" name="terbit_otomatis" value="1" @checked(old('terbit_otomatis', $p->terbit_otomatis)) class="rounded border-gray-300"> Terbitkan otomatis pada tanggal itu</label>
            </div>
            <p class="text-xs text-gray-500">Jurnal tagihan yang terbit otomatis dicatat atas nama orang yang terakhir menyimpan pengaturan ini.</p>
        </div>

        {{-- Paket 3 (3 Nov 2026): penahanan pengambilan karena tunggakan kelebihan laundry. --}}
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Penahanan pengambilan cucian</h2>
            <label class="block max-w-xs"><span class="mb-1 block text-sm font-medium text-gray-700">Tahan bila menunggak (periode)</span>
                <input name="tahan_setelah_periode" type="number" min="0" max="24" value="{{ old('tahan_setelah_periode', (int) ($p->tahan_setelah_periode ?? 0)) }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
            <p class="text-xs text-gray-500">Santri yang tagihan kelebihan laundry-nya <b>sudah lewat jatuh tempo</b> dan belum lunas sebanyak angka ini (atau lebih) tidak bisa mengambil cucian bersih — menyetor cucian kotor tetap boleh. <b>0 = tidak ada penahanan.</b> Keadaan mendesak: pemegang hak <i>Laundry — Izin</i> bisa mengizinkan penyerahan dengan alasan.</p>
        </div>

        @if ($ubah)
            <div class="flex justify-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan pengaturan</button></div>
        @endif
    </form>
@endsection
