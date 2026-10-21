{{-- Keterangan tegas di bawah progres PSB (permintaan user 21 Okt 2026): sudah/belum diterima. $status = kode status santri. --}}
@if (\App\Services\Ppsb\Tahap::sudahDiterima((string) $status))
    <p class="mt-2 flex items-center gap-1.5 text-sm font-bold text-emerald-700">
        <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Santri diterima
    </p>
@else
    <p class="mt-2 flex items-center gap-1.5 text-sm font-bold text-amber-700">
        <span class="h-2 w-2 rounded-full bg-amber-500"></span> Santri belum diterima
    </p>
@endif
