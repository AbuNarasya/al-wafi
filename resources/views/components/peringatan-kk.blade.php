@props(['portal' => false])
{{-- Peringatan "isi sesuai Kartu Keluarga" — dipasang di SETIAP form yang mencatat data
     pribadi santri / wali (keputusan user 14 Okt 2026). Satu komponen supaya bunyinya
     tak bercabang; `portal` hanya mengganti gayanya ke bingkai Portal Wali. --}}
<div {{ $attributes->class([
    'flex gap-3 text-sm leading-relaxed text-amber-900',
    'rounded-3xl bg-amber-50 p-4 ring-1 ring-amber-200' => $portal,
    'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3' => ! $portal,
]) }} role="note">
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>
    </svg>
    <div>
        <p class="font-semibold">Isi sesuai Kartu Keluarga (KK)</p>
        <p class="mt-0.5">
            Nama, NIK, tempat &amp; tanggal lahir, dan jenis kelamin santri maupun orang tua / wali harus
            ditulis <b>persis seperti yang tertera di Kartu Keluarga</b> — termasuk ejaannya. Data ini dipakai
            untuk dokumen resmi seperti rapor, ijazah, dan pendataan Kemenag (EMIS); beda satu huruf saja
            bisa membuat data ditolak.
        </p>
    </div>
</div>
