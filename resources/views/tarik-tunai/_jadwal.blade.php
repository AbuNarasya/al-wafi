{{-- Isian jadwal & batas sesi — dipakai form buat (index) dan form ubah (show). --}}
@php $s = $sesi ?? null; @endphp
<div class="grid gap-3 sm:grid-cols-2">
    <div class="sm:col-span-2"><x-field name="nama" label="Nama kegiatan" :value="$s?->nama" required hint="mis. Rihlah Kelas 9 — Yogyakarta" /></div>
    <x-field name="batas_pengajuan" type="datetime-local" label="Batas pengajuan wali" :value="$s?->batas_pengajuan?->format('Y-m-d\TH:i')" required />
    <x-field name="batas_persetujuan" type="datetime-local" label="Batas persetujuan kesantrian" :value="$s?->batas_persetujuan?->format('Y-m-d\TH:i')" required />
    <x-field name="tanggal_ambil" type="date" label="Tanggal ambil uang di bank" :value="$s?->tanggal_ambil?->toDateString()" required />
    <x-field name="tanggal_distribusi" type="date" label="Tanggal distribusi ke santri" :value="$s?->tanggal_distribusi?->toDateString()" required />
    <x-field name="maks_per_santri" type="number" label="Maksimal per santri (kelipatan Rp 50.000)" :value="$s ? (int) $s->maks_per_santri : null" required />
    <div></div>
    <x-field name="kode_coa_bank" label="Diambil dari rekening bank" :options="['' => '— pilih —'] + $rekening" :value="$s?->kode_coa_bank" required />
    <x-field name="kode_coa_kas" label="Kas tunai kegiatan (penampung)" :options="['' => '— pilih —'] + $rekening" :value="$s?->kode_coa_kas" required hint="Kas tempat uang tunai dipegang sampai dibagikan." />
    <div class="sm:col-span-2"><x-field name="keterangan" label="Keterangan untuk wali" :value="$s?->keterangan" textarea /></div>
</div>
