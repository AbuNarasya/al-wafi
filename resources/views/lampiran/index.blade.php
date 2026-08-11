@extends('layouts.app')

@section('title', 'Lampiran — ' . $nomor)

@section('content')
    {{-- `berkasPreview` dipakai ulang dari layar Berkas Santri: modal + PDF.js
         yang sama, supaya pratinjau lampiran berperilaku persis sama. --}}
    <div class="mx-auto max-w-4xl" x-data="berkasPreview">
        <a href="{{ $urlKembali }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke {{ $label }}</a>

        <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">Lampiran {{ $label }} {{ $nomor }}</h2>
            <p class="mt-1 text-xs text-gray-500">
                Dokumen pendukung: invoice, penawaran, nota, kwitansi, bukti transfer.
                Berkas hanya bisa dibuka oleh yang berhak atas modul ini.
            </p>
            @unless ($terbuka)
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                    Dokumen berstatus <span class="font-semibold">{{ $status }}</span> — lampirannya sudah menjadi
                    bagian bukti pembukuan. Masih bisa dilihat &amp; diunduh, tapi tidak bisa ditambah atau dihapus.
                </p>
            @endunless
        </div>

        @if ($terbuka && $bolehUnggah)
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-800">Unggah Lampiran</h3>
                <form method="POST" action="{{ route('lampiran.store', [$jenis, $idDokumen]) }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">
                            Berkas (PDF/JPG/PNG/WEBP, maks {{ $maksLabel }}) <span class="text-red-500">*</span>
                        </label>
                        <input type="file" name="berkas" accept=".pdf,.jpg,.jpeg,.png,.webp" required
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @error('berkas')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-field name="keterangan" label="Keterangan" :value="old('keterangan')"
                             placeholder="mis. Invoice CV Berkah no. 118" />
                    <div class="flex justify-end">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Unggah</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Nama Berkas</th>
                        <th class="px-4 py-3">Diunggah</th>
                        <th class="px-4 py-3 text-right">Ukuran</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        @php $berkasJs = ['id' => $r->id, 'nama' => $r->nama_asli, 'mime' => $r->mime, 'url' => route('lampiran.berkas', $r->id)]; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <button type="button" class="max-w-full truncate text-left text-brand underline hover:text-brand-dark"
                                        @click='buka(@json($berkasJs))'>{{ $r->nama_asli }}</button>
                                @if ($r->keterangan)<div class="text-xs text-gray-400">{{ $r->keterangan }}</div>@endif
                            </td>
                            <td class="px-4 py-2 text-gray-500">
                                {{ $r->pengunggah?->nama ?? '—' }}
                                <div class="text-xs text-gray-400">{{ $r->created_at?->format('d/m/Y H:i') }}</div>
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">{{ $r->ukuranTerbaca() }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" class="text-brand hover:underline" @click='buka(@json($berkasJs))'>Lihat</button>
                                    <a href="{{ route('lampiran.unduh', $r->id) }}" class="text-gray-600 hover:underline">Unduh</a>
                                    @if ($terbuka && $bolehHapus)
                                        <form method="POST" action="{{ route('lampiran.destroy', $r->id) }}" onsubmit="return confirm('Hapus lampiran ini?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Belum ada lampiran.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Modal pratinjau — markup sama dengan layar Berkas Santri. --}}
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
