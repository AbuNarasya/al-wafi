{{--
  Daftar pakaian (jenis + jumlah) dengan PENCARIAN: jenis diketik, saran muncul dari <datalist> (permintaan user
  3 Nov 2026 — dulu hanya bisa digulir). Yang terkirim tetap id jenisnya (hidden), dicocokkan dari nama.
  Pakai: @include('laundry._isian-pakaian', ['jenis' => $jenis, 'awal' => $l->item ?? collect()])
--}}
@php
    $namaDari = $jenis->pluck('nama', 'id');
    $awal = collect(old('item', ($awal ?? collect())->map(fn ($it) => ['id_jenis_pakaian' => $it->id_jenis_pakaian, 'jumlah' => $it->jumlah])->all()))
        ->map(fn ($b) => ['nama' => $namaDari[(int) ($b['id_jenis_pakaian'] ?? 0)] ?? '', 'jumlah' => $b['jumlah'] ?? ''])
        ->filter(fn ($b) => $b['nama'] !== '' || $b['jumlah'] !== '')->values()->all() ?: [['nama' => '', 'jumlah' => '']];
    $idDariNama = $jenis->mapWithKeys(fn ($j) => [mb_strtolower(trim($j->nama)) => $j->id]);
    $daftarId = 'jenis-pakaian-'.uniqid();
@endphp
<div x-data="{ item: @js($awal), peta: @js($idDariNama),
               idDari(n) { return this.peta[(n || '').trim().toLowerCase()] ?? '' },
               ubahJumlah(b, d) { b.jumlah = Math.min(200, Math.max(1, (parseInt(b.jumlah) || 0) + d)) },
               get potong() { return this.item.reduce((t, b) => t + (parseInt(b.jumlah) || 0), 0) } }">
    <div class="flex items-baseline justify-between">
        <span class="text-xs text-gray-500">{{ $judul ?? 'Daftar pakaian — dicocokkan lagi saat diserahkan' }}</span>
        <span class="text-xs text-gray-500"><b x-text="potong"></b> potong</span>
    </div>
    <datalist id="{{ $daftarId }}">
        @foreach ($jenis as $j)<option value="{{ $j->nama }}"></option>@endforeach
    </datalist>
    <template x-for="(b, i) in item" :key="i">
        <div class="mt-1">
            <div class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1">
                <input type="text" list="{{ $daftarId }}" x-model="b.nama" placeholder="ketik jenis pakaian…" autocomplete="off"
                       class="min-w-[10rem] flex-1 rounded-lg border-gray-300 text-sm" :class="b.nama && ! idDari(b.nama) ? 'border-red-400' : ''">
                <input type="hidden" :name="`item[${i}][id_jenis_pakaian]`" :value="idDari(b.nama)">
                <div class="flex shrink-0 items-center gap-1.5">
                    <button type="button" @click="ubahJumlah(b, -1)" :disabled="(parseInt(b.jumlah) || 0) <= 1"
                            class="h-8 w-8 rounded-full bg-gray-100 font-bold text-gray-700 disabled:opacity-40" aria-label="Kurangi">−</button>
                    <input :name="`item[${i}][jumlah]`" x-model="b.jumlah" type="number" min="1" max="200" placeholder="0" inputmode="numeric"
                           @focus="$event.target.select()"
                           class="w-12 rounded-lg border-gray-200 px-1 text-center text-sm font-bold tabular-nums text-brand [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                    <button type="button" @click="ubahJumlah(b, 1)" class="h-8 w-8 rounded-full bg-brand font-bold text-white" aria-label="Tambah">+</button>
                    <button type="button" @click="item.splice(i, 1)" class="px-1 text-gray-400" x-show="item.length > 1" aria-label="Hapus baris">&times;</button>
                </div>
            </div>
            <p x-show="b.nama && ! idDari(b.nama)" x-cloak class="mt-0.5 text-[11px] text-red-600">Jenis tidak ada di daftar — pilih dari saran, atau tambahkan di master Jenis Pakaian.</p>
        </div>
    </template>
    <button type="button" @click="item.push({ nama: '', jumlah: '' })" class="mt-1 text-xs text-brand hover:underline">+ pakaian</button>
</div>
