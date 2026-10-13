{{--
  Isian periode penerbitan tagihan lain-lain.
   • Jenis BULANAN (jenis_biaya.berulang, keputusan user 13 Okt 2026): pilihan BULAN, wajib,
     bawaan bulan berjalan. Satu tagihan per santri per bulan — bulan yang sudah terbit dilewati.
   • Jenis PER KEGIATAN: isian bebas opsional seperti sebelumnya. Dikosongkan = sekali saja.
  Variabel: $bulanan (bool), $contoh (placeholder).
--}}
@if ($bulanan)
    <div>
        <label for="periode" class="mb-1 block text-sm font-medium text-gray-700">Bulan tagihan <span class="text-red-500">*</span></label>
        <input id="periode" name="periode" type="month" required value="{{ old('periode', now()->format('Y-m')) }}"
               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
        <p class="mt-1 text-xs text-gray-400">
            Ditagih tiap bulan — satu tagihan per santri per bulan. Santri yang sudah ditagih bulan ini dilewati.
            @error('periode')<span class="text-red-600">{{ $message }}</span>@enderror
        </p>
    </div>
@else
    <x-field name="periode" label="Periode (opsional)" :value="old('periode')" :placeholder="$contoh"
             hint="Dikosongkan = ditagih sekali saja untuk kegiatan ini." />
@endif
