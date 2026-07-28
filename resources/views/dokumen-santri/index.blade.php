@extends('layouts.app')

@section('title', 'Berkas — ' . $santri->nama)

@php
    $perKelompok = collect($kelengkapan)->groupBy('kelompok');
@endphp

@section('content')
    <div class="mx-auto max-w-4xl" x-data="berkasPreview">
        <a href="{{ route('santri.show', $santri->id) }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke santri</a>

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Kelengkapan (dipisah registrasi vs pasca-lulus) --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-800">Kelengkapan Berkas</h3>
                <div class="space-y-4">
                    @foreach (['registrasi', 'pindahan', 'pasca_lulus'] as $kelompok)
                        @php $isi = $perKelompok[$kelompok] ?? collect(); @endphp
                        @continue ($isi->isEmpty())
                        <div>
                            <h4 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ \App\Services\Ppsb\DokumenPolicy::LABEL_KELOMPOK[$kelompok] }}
                            </h4>
                            @if ($kelompok === 'pindahan')
                                <p class="mb-1 text-[11px] text-gray-400">Hanya untuk calon pindahan — boleh dikosongkan bila mendaftar dari jenjang awal.</p>
                            @endif
                            <ul class="space-y-1.5 text-sm">
                                @foreach ($isi as $k)
                                    <li class="{{ $k['ada'] ? 'text-emerald-700' : 'text-gray-400' }}">
                                        {{ $k['ada'] ? '✓' : '○' }} {{ $k['label'] }}
                                        @if ($kelompok === 'pindahan')
                                            <span class="rounded bg-amber-50 px-1 text-[10px] text-amber-600">opsional</span>
                                        @elseif ($kelompok === 'pasca_lulus')
                                            <span class="rounded bg-blue-50 px-1 text-[10px] text-blue-600">pasca lulus</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Upload --}}
            @if (\App\Support\Akses::boleh('dokumen-santri', 'buat'))
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-gray-800">Unggah Berkas</h3>
                    <form method="POST" action="{{ route('dokumen_santri.store', $santri->id) }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-field name="jenis" label="Jenis Berkas" :value="old('jenis')" :options="$jenisOptions" required />
                            <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" hint="Wajib bila jenis 'Lainnya'." />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">File (PDF/JPG/PNG, maks 5 MB) <span class="text-red-500">*</span></label>
                            <input type="file" name="berkas" accept=".pdf,.jpg,.jpeg,.png" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            @error('berkas')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex justify-end"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Unggah</button></div>
                    </form>

                    {{-- Kontak wali kelas sekolah asal: data santri, dilengkapi bersama berkas pindahan. --}}
                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h3 class="text-sm font-semibold text-gray-800">Wali Kelas Sekolah Asal</h3>
                        <p class="mb-3 text-xs text-gray-400">Kontak untuk konfirmasi berkas pindahan. Tersimpan pada data santri, bukan sebagai berkas.</p>
                        <form method="POST" action="{{ route('dokumen_santri.wali_kelas', $santri->id) }}" class="space-y-3"
                              data-confirm="Simpan data wali kelas sekolah asal?">
                            @csrf @method('PUT')
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-field name="wali_kelas_asal" label="Nama Wali Kelas" :value="old('wali_kelas_asal', $santri->wali_kelas_asal)" placeholder="mis. Ibu Aminah, S.Pd." />
                                <x-field name="cp_wali_kelas_asal" label="CP Wali Kelas" :value="old('cp_wali_kelas_asal', $santri->cp_wali_kelas_asal)" placeholder="mis. 0812xxxxxxx" />
                            </div>
                            <div class="flex justify-end"><button class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Simpan Kontak</button></div>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        {{-- Daftar berkas --}}
        <h3 class="mb-2 mt-6 text-sm font-semibold text-gray-700">Berkas Terunggah</h3>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Jenis</th><th class="px-4 py-3">Nama File</th><th class="px-4 py-3 text-right">Ukuran</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($dokumen as $d)
                        @php $dokJs = ['id' => $d->id, 'nama' => $d->nama_asli, 'mime' => $d->mime, 'url' => route('dokumen_santri.berkas', $d->id)]; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ \App\Services\Ppsb\DokumenPolicy::LABEL_DOKUMEN[$d->jenis] ?? $d->jenis }}@if ($d->keterangan)<div class="text-xs text-gray-400">{{ $d->keterangan }}</div>@endif</td>
                            <td class="px-4 py-2">
                                <button type="button" class="max-w-full truncate text-left text-brand underline hover:text-brand-dark" @click='buka(@json($dokJs))'>{{ $d->nama_asli }}</button>
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ number_format($d->ukuran / 1024, 0) }} KB</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" class="text-brand hover:underline" @click='buka(@json($dokJs))'>Lihat</button>
                                    <a href="{{ route('dokumen_santri.download', $d->id) }}" class="text-gray-600 hover:underline">Unduh</a>
                                    @if (\App\Support\Akses::boleh('dokumen-santri', 'hapus'))
                                        <form method="POST" action="{{ route('dokumen_santri.destroy', $d->id) }}" onsubmit="return confirm('Hapus berkas ini?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Belum ada berkas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Modal pratinjau berkas --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
             @click.self="tutup()" @keydown.escape.window="tutup()">
            <div class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white text-left shadow-xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="truncate pr-3 font-semibold text-gray-800" x-text="dok ? 'Lihat — ' + dok.nama : ''"></h3>
                    <button @click="tutup()" class="shrink-0 text-gray-400 hover:text-gray-600">✕</button>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <template x-if="isPdf">
                        <div>
                            <p x-show="status === 'memuat'" class="mb-2 text-sm text-gray-500">Memuat PDF…</p>
                            <p x-show="status === 'gagal'" class="mb-2 text-sm text-red-600">Gagal memuat PDF (<span x-text="pesan"></span>). Coba tombol "Buka di tab baru".</p>
                            <div x-ref="pdfbox" class="max-h-[72vh] overflow-y-auto rounded bg-gray-100 p-2"></div>
                        </div>
                    </template>
                    <template x-if="isGambar">
                        <img :src="dok?.url" :alt="dok?.nama" class="mx-auto max-h-[70vh] max-w-full rounded">
                    </template>
                    <template x-if="dok && !isPdf && !isGambar">
                        <p class="text-sm text-gray-500">Pratinjau tak tersedia untuk jenis berkas ini — <a :href="dok?.url" target="_blank" rel="noreferrer" class="text-brand underline">buka di tab baru</a>.</p>
                    </template>
                </div>
                <div class="flex justify-end gap-2 border-t border-gray-200 px-5 py-3">
                    <a :href="dok?.url" target="_blank" rel="noreferrer" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Buka di tab baru</a>
                    <button @click="tutup()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Tutup</button>
                </div>
            </div>
        </div>
    </div>
@endsection
