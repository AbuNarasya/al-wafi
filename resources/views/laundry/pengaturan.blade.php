@extends('layouts.app')

@section('title', 'Pengaturan Laundry')

@section('content')
    @php $ubah = \App\Support\Akses::boleh('laundry-master', 'ubah'); @endphp
    <form method="POST" action="{{ route('laundry.pengaturan.simpan') }}" class="mx-auto max-w-3xl space-y-4">
        @csrf @method('PUT')
        <div class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Tarif &amp; kuota</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tarif kelebihan per kg <span class="text-red-500">*</span></label>
                    <x-input-rupiah name="tarif_per_kg" :value="old('tarif_per_kg', $p->tarif_per_kg)" required placeholder="mis. 7000" />
                </div>
                <label class="block"><span class="mb-1 block text-sm font-medium text-gray-700">Kuota bawaan per bulan (kg) <span class="text-red-500">*</span></span>
                    <input name="kuota_bawaan_kg" type="number" step="0.5" min="0" required value="{{ old('kuota_bawaan_kg', (float) $p->kuota_bawaan_kg) }}" class="w-full rounded-lg border-gray-300 text-sm"></label>
            </div>
            <label class="block"><span class="mb-1 block text-sm font-medium text-gray-700">Jenis biaya penagihan (bawaan)</span>
                <select name="kode_jenis_bawaan" class="w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— belum dipilih —</option>
                    @foreach ($opsiJenis as $k => $n)<option value="{{ $k }}" @selected(old('kode_jenis_bawaan', $p->kode_jenis_bawaan) === $k)>{{ $n }}</option>@endforeach
                </select>
                <span class="mt-1 block text-xs text-gray-500">Jenis biaya bertipe Lain-lain — menentukan akun pendapatan/piutang &amp; unit bisnis tagihannya.</span></label>
            <p class="text-xs text-gray-500">Kuota dihitung per <b>bulan kalender</b> dari tanggal cucian diterima; sisa kuota tidak terbawa ke bulan berikutnya.</p>
        </div>

        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Per jenjang <span class="text-xs font-normal text-gray-500">(kosong = ikut bawaan)</span></h2>
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-start font-normal">Jenjang</th><th class="w-40 text-start font-normal">Kuota (kg)</th><th class="text-start font-normal">Jenis biaya</th></tr></thead>
                <tbody>
                    @foreach ($jenjang as $j)
                        @php $x = $perJenjang->get($j->kode); @endphp
                        <tr class="border-t border-gray-100">
                            <td class="py-2">{{ $j->nama }}</td>
                            <td class="py-2 pe-3"><input name="jenjang[{{ $j->kode }}][kuota_kg]" type="number" step="0.5" min="0" value="{{ old("jenjang.{$j->kode}.kuota_kg", $x?->kuota_kg !== null ? (float) $x->kuota_kg : '') }}"
                                                         placeholder="{{ (float) $p->kuota_bawaan_kg }}" class="w-full rounded-lg border-gray-300 text-sm"></td>
                            <td class="py-2"><select name="jenjang[{{ $j->kode }}][kode_jenis]" class="w-full rounded-lg border-gray-300 text-sm">
                                <option value="">— bawaan —</option>
                                @foreach ($opsiJenis as $k => $n)<option value="{{ $k }}" @selected(old("jenjang.{$j->kode}.kode_jenis", $x?->kode_jenis) === $k)>{{ $n }}</option>@endforeach
                            </select></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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

        @if ($ubah)
            <div class="flex justify-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan pengaturan</button></div>
        @endif
    </form>
@endsection
