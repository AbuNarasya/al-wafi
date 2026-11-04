@extends('layouts.app')

@section('title', 'Pengaturan Perizinan')

@section('content')
    @php $ubah = \App\Support\Akses::boleh('perizinan-pengaturan', 'ubah'); @endphp
    <form method="POST" action="{{ route('perizinan.pengaturan.simpan') }}" class="mx-auto max-w-3xl space-y-4">
        @csrf @method('PUT')
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Tingkat persetujuan</h2>
            <p class="text-sm text-gray-500">Aktifkan salah satu atau keduanya. Bila keduanya aktif, pengajuan disetujui tingkat 1 dulu lalu tingkat 2.
                Penyetuju tiap tingkat = pemegang hak <i>Perizinan — persetujuan tingkat 1/2</i> (centang Ubah).</p>
            @foreach ([1, 2] as $t)
                <div class="grid items-center gap-3 sm:grid-cols-[auto,1fr]">
                    <label class="flex items-center gap-2 text-sm font-medium"><input type="hidden" name="tingkat{{ $t }}_aktif" value="0">
                        <input type="checkbox" name="tingkat{{ $t }}_aktif" value="1" @checked(old("tingkat{$t}_aktif", $p->{"tingkat{$t}_aktif"})) class="rounded border-gray-300"> Tingkat {{ $t }} aktif</label>
                    <input name="label_tingkat{{ $t }}" required maxlength="100" value="{{ old("label_tingkat{$t}", $p->{"label_tingkat{$t}"}) }}" placeholder="Nama penyetuju" class="rounded-lg border-gray-300 text-sm">
                </div>
            @endforeach
        </div>

        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Jenis izin, batas pengajuan &amp; persetujuan</h2>
            <p class="text-sm text-gray-500">
                <b>Batas</b> = wali mengajukan paling lambat <b>H-n</b> sebelum tanggal keluar (mis. Umroh H-14, Keluarga H-3).
                Kosongkan bila tanpa batas (mis. Sakit); 0 = paling lambat hari itu juga. Staf yang mencatat atas nama wali tidak terkena batas.
                <b>Persetujuan otomatis</b> = pengajuan langsung disetujui tanpa menunggu petugas.
                <b>Kebijakan tunggakan</b>: "Izin tidak diberikan" = tak bisa diajukan &amp; tertahan di pos keluar; "Boleh keluar, tidak boleh kembali" =
                tertahan di pos masuk. Dihitung dari jumlah tagihan lewat jatuh tempo &amp; belum lunas. Yang berhak mengizinkan di pos: hak <i>Perizinan — izinkan santri yang tertahan tunggakan</i>.
            </p>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-start font-normal">Jenis</th><th class="w-16 text-start font-normal">Aktif</th>
                    <th class="w-32 text-start font-normal">Batas (H-n)</th><th class="w-48 text-start font-normal">Persetujuan</th><th class="text-start font-normal">Kebijakan tunggakan</th></tr></thead>
                <tbody>
                    @foreach ($tipe as $t)
                        <tr class="border-t border-gray-100">
                            <td class="py-2">{{ $t->nama }}<span class="block text-xs text-gray-400">{{ $t->rutin ? 'rutin (berjadwal) · ' : '' }}{{ $t->pulang ? 'izin pulang' : 'kembali di hari yang sama' }}{{ $t->wajib_keperluan ? ' · keperluan wajib' : '' }}</span></td>
                            <td class="py-2"><input type="checkbox" name="tipe[{{ $t->kode }}][aktif]" value="1" @checked($t->aktif) class="rounded border-gray-300"></td>
                            <td class="py-2"><div class="flex items-center gap-1"><span class="text-gray-500">H-</span>
                                <input type="number" name="tipe[{{ $t->kode }}][batas_hari]" min="0" max="365" value="{{ $t->batas_hari }}" placeholder="tanpa" class="w-20 rounded-lg border-gray-300 text-sm"></div></td>
                            <td class="py-2"><select name="tipe[{{ $t->kode }}][persetujuan]" class="rounded-lg border-gray-300 text-sm">
                                <option value="petugas" @selected($t->persetujuan === 'petugas')>Perlu persetujuan petugas</option>
                                <option value="otomatis" @selected($t->persetujuan === 'otomatis')>Otomatis disetujui</option>
                            </select></td>
                            <td class="py-2" x-data="{ k: @js($t->tunggakan_kebijakan) }">
                                <select name="tipe[{{ $t->kode }}][tunggakan_kebijakan]" x-model="k" class="rounded-lg border-gray-300 text-sm">
                                    @foreach (\App\Services\Modules\TunggakanIzin::KEBIJAKAN as $kk => $kl)<option value="{{ $kk }}">{{ $kl }}</option>@endforeach
                                </select>
                                <div x-show="k !== 'tidak'" x-cloak class="mt-1 flex items-center gap-1 text-xs text-gray-600">
                                    bila menunggak ≥ <input type="number" name="tipe[{{ $t->kode }}][tunggakan_periode]" min="1" max="60" value="{{ $t->tunggakan_periode }}" class="w-14 rounded border-gray-300 py-1 text-xs"> periode,
                                    <select name="tipe[{{ $t->kode }}][tunggakan_lingkup]" class="rounded border-gray-300 py-1 text-xs">
                                        @foreach (\App\Services\Modules\TunggakanIzin::LINGKUP as $lk => $ll)<option value="{{ $lk }}" @selected($t->tunggakan_lingkup === $lk)>{{ $ll }}</option>@endforeach
                                    </select>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Penjemput diwakilkan &mdash; isian wajib</h2>
            <p class="text-sm text-gray-500">Saat mengajukan, wali memilih <b>dijemput wali</b> (nama &amp; HP diambil dari data keluarga) atau <b>diwakilkan</b>.
                Bila diwakilkan, isian yang dicentang di bawah wajib diisi. Datanya tampil di pos untuk dicocokkan sebelum santri dilepas.</p>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (\App\Services\Modules\PerizinanService::ISIAN_WAKIL as $k => $label)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="wakil_wajib[]" value="{{ $k }}" @checked(in_array($k, $p->wakil_wajib, true)) class="rounded border-gray-300"> {{ $label }}</label>
                @endforeach
            </div>
        </div>
        @if ($ubah)<div class="flex justify-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan pengaturan</button></div>@endif
    </form>
@endsection
