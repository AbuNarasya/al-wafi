{{--
    Tombol unduh + pemilih kolom — SATU komponen untuk seluruh unduhan aplikasi
    (Export Data, Laporan, Kontrol, Dashboard, daftar Santri).

    Tiga bentuk pemakaian:
      <x-unduh :url="route('…')" />        tautan biasa
      <x-unduh url-js="`…${ekspresi}…`" /> alamat dirakit dari state Alpine sekitarnya
      <x-unduh form />                     ditaruh DI DALAM <form> yang sudah punya
                                           tombol formatnya sendiri; komponen cuma
                                           menyumbang centang name="kolom[]".

    Daftar kolomnya diminta ke alamat unduhan itu juga (?kolom=daftar) — lihat
    Exporter & unduhKolom di app.js.
--}}
@props(['url' => null, 'urlJs' => null, 'form' => false, 'label' => 'Unduh:'])

<div class="relative flex items-center gap-1"
     x-data="unduhKolom(() => {!! $urlJs ?: ($url ? \Illuminate\Support\Js::from($url) : "''") !!}, { form: {{ $form ? 'true' : 'false' }} })"
     @click.outside="terbuka = false">

    @unless ($form)
        <span class="text-xs text-gray-400">{{ $label }}</span>
        @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $f => $lbl)
            <a :href="tautan(@js($f))"
               class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $lbl }}</a>
        @endforeach
    @endunless

    <button type="button" @click="buka()"
            class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
            :class="lengkap ? '' : 'border-brand text-brand'">
        Kolom<span x-show="!lengkap" x-cloak x-text="' (' + dipilih.length + ')'"></span>
    </button>

    <div x-show="terbuka" x-cloak
         class="absolute right-0 top-full z-30 mt-1 max-h-80 w-72 overflow-y-auto rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">

        <label class="flex items-center gap-2 border-b border-gray-100 pb-2 text-sm font-semibold text-gray-800">
            <input type="checkbox" :checked="lengkap" @change="lengkap = $event.target.checked"
                   class="rounded border-gray-300 text-brand focus:ring-brand">
            Data Lengkap
        </label>
        <p x-show="lengkap" class="pt-2 text-xs text-gray-400">Seluruh kolom ikut terunduh. Matikan untuk memilih sendiri.</p>

        <div x-show="!lengkap" x-cloak class="pt-2">
            <div class="mb-2 flex gap-2 text-xs">
                <button type="button" @click="semua(true)" class="text-brand hover:underline">Centang semua</button>
                <button type="button" @click="semua(false)" class="text-gray-500 hover:underline">Kosongkan</button>
            </div>
            <p x-show="status === 'memuat'" class="py-2 text-xs text-gray-400">Memuat daftar kolom…</p>
            <p x-show="status === 'kosong'" x-cloak class="py-2 text-xs text-gray-500">Belum ada data yang bisa diunduh, jadi belum ada kolom yang bisa dipilih.</p>
            <p x-show="status === 'gagal'" x-cloak class="py-2 text-xs text-red-600">Daftar kolom gagal dimuat. Unduh dengan Data Lengkap, atau muat ulang halaman.</p>
            <template x-for="k in kolom" :key="k.nama">
                <label class="flex items-center gap-2 py-0.5 text-sm text-gray-700">
                    {{-- Dalam form, centang inilah yang terkirim; di luar form ia
                         hanya menyetel href tautan di atas. --}}
                    <input type="checkbox" :name="{{ $form ? "'kolom[]'" : 'null' }}" :value="k.nama"
                           :disabled="lengkap" :checked="k.pilih" @change="k.pilih = $event.target.checked"
                           class="rounded border-gray-300 text-brand focus:ring-brand">
                    <span x-text="k.nama"></span>
                </label>
            </template>
        </div>
    </div>
</div>
