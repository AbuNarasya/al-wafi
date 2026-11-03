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
            <h2 class="text-base font-semibold text-gray-900">Tipe izin &amp; batas waktu pengajuan</h2>
            <p class="text-sm text-gray-500">Batas = pengajuan oleh wali paling lambat sekian jam sebelum waktu keluar (0 = boleh mendadak). Staf yang mencatat atas nama wali tidak terkena batas.</p>
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-start font-normal">Tipe</th><th class="w-24 text-start font-normal">Aktif</th><th class="w-40 text-start font-normal">Batas (jam)</th></tr></thead>
                <tbody>
                    @foreach ($tipe as $t)
                        <tr class="border-t border-gray-100">
                            <td class="py-2">{{ $t->nama }}<span class="block text-xs text-gray-400">{{ $t->pulang ? 'izin pulang' : 'kembali di hari yang sama' }}{{ $t->wajib_keperluan ? ' · keperluan wajib' : '' }}</span></td>
                            <td class="py-2"><input type="checkbox" name="tipe[{{ $t->kode }}][aktif]" value="1" @checked($t->aktif) class="rounded border-gray-300"></td>
                            <td class="py-2"><input type="number" name="tipe[{{ $t->kode }}][batas_jam]" min="0" max="720" value="{{ $t->batas_jam }}" class="w-28 rounded-lg border-gray-300 text-sm"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($ubah)<div class="flex justify-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan pengaturan</button></div>@endif
    </form>
@endsection
