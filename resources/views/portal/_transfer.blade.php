{{--
  Isian transfer bank langsung: rekening tujuan + nominal + tanggal + bukti.
  Dipakai Isi Dompet & bayar tagihan calon. $rekening = list dari PortalBayarController::rekening().

  FOTO BUKTI DIKECILKAN DI HP sebelum dikirim. Batas unggahan aplikasi 500 KB
  (App\Support\Unggahan), sedangkan foto kamera HP lazimnya 2–4 MB — tanpa ini
  hampir setiap wali ditolak. PDF tak bisa dikecilkan, jadi yang terlalu besar
  diberi tahu sebelum terkirim.
--}}
@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $maksByte = \App\Support\Unggahan::MAKS_KB * 1024;
@endphp

@if ($rekening === [])
    <div class="rounded-3xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-200">
        Rekening tujuan transfer belum disiapkan. Silakan hubungi bagian keuangan.
    </div>
@else
    <section>
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> 1. Transfer ke rekening</h2>
        <div class="space-y-2">
            @foreach ($rekening as $i => $r)
                <label class="flex cursor-pointer items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-brand">
                    <input type="radio" name="kode_rekening" value="{{ $r['kode'] }}" required class="text-brand focus:ring-brand"
                           @checked(old('kode_rekening', count($rekening) === 1 ? $r['kode'] : null) === $r['kode'])>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-brand"><x-portal.ikon nama="bank" kelas="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-slate-900">{{ $r['bank'] }}</span>
                        <span class="block font-mono text-base tracking-wide text-slate-900">{{ $r['nomor'] }}</span>
                        <span class="block text-xs text-slate-500">a.n. {{ $r['atas_nama'] }}</span>
                    </span>
                    <button type="button" class="shrink-0 rounded-xl bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                            onclick="event.preventDefault(); navigator.clipboard?.writeText(@js(preg_replace('/\D/', '', $r['nomor']))); this.textContent = 'Tersalin'">Salin</button>
                </label>
            @endforeach
        </div>
        @error('kode_rekening')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </section>

    <section class="mt-5 space-y-4">
        <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> 2. Isi data transfer</h2>
        {{-- Bayar beberapa tagihan: nominalnya per tagihan di keranjang (nominal[id]), bukan di sini. --}}
        @unless ($tanpaNominal ?? false)
        <div>
            <label for="nominal" class="mb-1 block text-sm font-medium text-slate-700">Nominal yang ditransfer</label>
            <input id="nominal" name="nominal" type="number" min="1" step="1" required
                   value="{{ old('nominal', $nominalAwal ?? '') }}" class="{{ $kelasInput }}">
            @error('nominal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @endunless
        <div>
            <label for="tanggal" class="mb-1 block text-sm font-medium text-slate-700">Tanggal transfer</label>
            <input id="tanggal" name="tanggal" type="date" required max="{{ now()->toDateString() }}"
                   value="{{ old('tanggal', now()->toDateString()) }}" class="{{ $kelasInput }}">
            @error('tanggal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div x-data="unggahBukti({{ $maksByte }})">
            <label class="mb-1 block text-sm font-medium text-slate-700">Bukti transfer</label>
            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 bg-white p-5 text-center">
                <template x-if="pratinjau"><img :src="pratinjau" alt="Pratinjau bukti" class="max-h-48 rounded-xl"></template>
                <span x-show="! pratinjau" class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><x-portal.ikon nama="unggah" /></span>
                <span class="text-sm font-semibold text-brand" x-text="nama || 'Pilih foto / PDF bukti transfer'"></span>
                <span class="text-xs text-slate-500" x-text="keterangan"></span>
                <input type="file" name="bukti" accept="image/*,application/pdf" required class="sr-only" @change="pilih($event)">
            </label>
            <p x-show="galat" x-text="galat" x-cloak class="mt-1 text-xs text-red-700"></p>
            @error('bukti')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
    </section>

    @once
        <script>
            // Kecilkan foto bukti di HP sampai di bawah batas unggahan (lihat komentar di atas).
            window.unggahBukti = (maks) => ({
                nama: '', keterangan: 'Foto dikecilkan otomatis sebelum dikirim.', pratinjau: null, galat: '',
                async pilih(e) {
                    const input = e.target, berkas = input.files[0];
                    this.galat = ''; this.pratinjau = null;
                    if (! berkas) return;
                    this.nama = berkas.name;
                    if (! berkas.type.startsWith('image/')) {
                        this.keterangan = Math.round(berkas.size / 1024) + ' KB';
                        if (berkas.size > maks) { this.galat = 'PDF terlalu besar (maks ' + Math.round(maks / 1024) + ' KB). Kirim foto layar bukti transfer saja.'; input.value = ''; this.nama = ''; }
                        return;
                    }
                    try {
                        const hasil = berkas.size <= maks * 0.95 ? berkas : await this.kecilkan(berkas, maks);
                        const dt = new DataTransfer(); dt.items.add(hasil); input.files = dt.files;
                        this.pratinjau = URL.createObjectURL(hasil);
                        this.keterangan = Math.round(hasil.size / 1024) + ' KB';
                    } catch (_) {
                        this.galat = 'Foto tidak bisa diproses. Coba foto lain.'; input.value = ''; this.nama = '';
                    }
                },
                async kecilkan(berkas, maks) {
                    const gambar = await createImageBitmap(berkas);
                    for (const [sisi, mutu] of [[1600, .75], [1400, .6], [1200, .5], [1000, .45], [800, .4]]) {
                        const skala = Math.min(1, sisi / Math.max(gambar.width, gambar.height));
                        const kanvas = document.createElement('canvas');
                        kanvas.width = Math.round(gambar.width * skala); kanvas.height = Math.round(gambar.height * skala);
                        kanvas.getContext('2d').drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
                        const blob = await new Promise(r => kanvas.toBlob(r, 'image/jpeg', mutu));
                        if (blob && blob.size <= maks * 0.95) return new File([blob], 'bukti-transfer.jpg', { type: 'image/jpeg' });
                    }
                    throw new Error('terlalu besar');
                },
            });
        </script>
    @endonce
@endif
