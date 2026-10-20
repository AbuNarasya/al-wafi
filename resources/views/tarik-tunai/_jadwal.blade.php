{{-- Isian sesi — form buat (index, lengkap) dan form "Ubah data sesi" (show, $tanpaJadwal = true:
     tanggal diubah lewat _ubah_jadwal, yang wewenangnya dibagi keuangan & kesantrian). --}}
@php $s = $sesi ?? null; $tanpaJadwal = $tanpaJadwal ?? false; @endphp
<div class="grid gap-3 sm:grid-cols-2">
    <div class="sm:col-span-2"><x-field name="nama" label="Nama kegiatan" :value="$s?->nama" required hint="mis. Rihlah Kelas 9 — Yogyakarta" /></div>
    @unless ($tanpaJadwal)
        {{-- Cukup tanggal (permintaan user 20 Okt 2026): batas berlaku SAMPAI AKHIR HARI itu (23:59). --}}
        <x-field name="batas_pengajuan" type="date" label="Batas pengajuan wali (sampai akhir hari)" :value="$s?->batas_pengajuan?->toDateString()" required />
        <x-field name="batas_persetujuan" type="date" label="Batas persetujuan kesantrian (sampai akhir hari)" :value="$s?->batas_persetujuan?->toDateString()" required />
        <x-field name="tanggal_ambil" type="date" label="Tanggal ambil uang di bank" :value="$s?->tanggal_ambil?->toDateString()" required />
        <x-field name="tanggal_distribusi" type="date" label="Tanggal distribusi ke santri" :value="$s?->tanggal_distribusi?->toDateString()" required />
    @endunless
    <x-field name="maks_per_santri" type="number" label="Maksimal per santri (kelipatan Rp 50.000)" :value="$s ? (int) $s->maks_per_santri : null" required />
    <div></div>
    <x-field name="kode_coa_bank" label="Diambil dari rekening bank" :options="['' => '— pilih —'] + $rekening" :value="$s?->kode_coa_bank" required />
    <x-field name="kode_coa_kas" label="Kas tunai kegiatan (penampung)" :options="['' => '— pilih —'] + $rekening" :value="$s?->kode_coa_kas" required hint="Kas tempat uang tunai dipegang sampai dibagikan." />
    <div class="sm:col-span-2"><x-field name="keterangan" label="Keterangan untuk wali" :value="$s?->keterangan" textarea /></div>
</div>
