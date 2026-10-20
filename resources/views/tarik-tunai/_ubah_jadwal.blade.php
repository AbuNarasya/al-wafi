{{-- Form "Ubah jadwal" — dipakai halaman sesi (keuangan) & halaman persetujuan (kesantrian).
     Wewenang (PenarikanTunaiService::ubahJadwal): batas pengajuan & persetujuan = keuangan ATAU kesantrian,
     sebelum dana diambil; tanggal ambil = keuangan, sebelum dana diambil; tanggal distribusi = keuangan,
     juga sesudah dana diambil. Isian yang tak boleh diubah ditampilkan terkunci (disabled tak ikut terkirim). --}}
@php
    $keu = \App\Support\Akses::boleh('sesi-tarik-tunai', 'ubah');
    $ksn = \App\Support\Akses::boleh('penarikan-tunai', 'ubah');
    $berjalan = $sesi->status === 'berjalan';
    $bolehBatas = ($keu || $ksn) && $berjalan;
    $bolehAmbil = $keu && $berjalan;
    $bolehDistribusi = $keu && in_array($sesi->status, ['berjalan', 'dana_diambil'], true);
    $kelasIsian = 'w-full rounded-lg border-gray-300 text-sm disabled:bg-gray-100 disabled:text-gray-500';
@endphp
@if ($bolehBatas || $bolehAmbil || $bolehDistribusi)
    <details class="{{ $kelas ?? 'mb-6' }} rounded-xl border border-gray-200 bg-white shadow-sm">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800">Ubah jadwal</summary>
        <form method="POST" action="{{ route('tarik_tunai.jadwal', $sesi->id_sesi) }}" class="space-y-3 border-t border-gray-100 p-4 text-sm">
            @csrf @method('PUT')
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="mb-1 block text-gray-700">Batas pengajuan wali (sampai akhir hari)</span>
                    <input type="date" name="batas_pengajuan" value="{{ $sesi->batas_pengajuan->toDateString() }}" @disabled(! $bolehBatas) class="{{ $kelasIsian }}"></label>
                <label class="block"><span class="mb-1 block text-gray-700">Batas persetujuan kesantrian (sampai akhir hari)</span>
                    <input type="date" name="batas_persetujuan" value="{{ $sesi->batas_persetujuan->toDateString() }}" @disabled(! $bolehBatas) class="{{ $kelasIsian }}"></label>
                <label class="block"><span class="mb-1 block text-gray-700">Tanggal ambil uang di bank</span>
                    <input type="date" name="tanggal_ambil" value="{{ $sesi->tanggal_ambil->toDateString() }}" @disabled(! $bolehAmbil) class="{{ $kelasIsian }}"></label>
                <label class="block"><span class="mb-1 block text-gray-700">Tanggal distribusi ke santri</span>
                    <input type="date" name="tanggal_distribusi" value="{{ $sesi->tanggal_distribusi->toDateString() }}" @disabled(! $bolehDistribusi) class="{{ $kelasIsian }}"></label>
            </div>
            <p class="text-xs text-gray-500">
                Batas berlaku sampai akhir hari yang dipilih. Untuk <b>menutup persetujuan hari ini juga</b>, pilih tanggal
                <b>kemarin</b>: pengajuan yang belum diputus gugur dan dana bisa langsung diambil. Wali dikabari bila batas pengajuan atau tanggal distribusi berubah.
                @unless ($keu) Tanggal ambil & distribusi diatur keuangan. @endunless
            </p>
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan jadwal</button>
        </form>
    </details>
@endif
