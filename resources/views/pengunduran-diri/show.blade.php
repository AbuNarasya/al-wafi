@extends('layouts.app')

@section('title', 'Pengunduran Diri '.$p->nomor)

@section('content')
    @php
        $s = $p->santri;
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m/Y H:i');
        $nama = fn ($tahap) => $p->oleh($tahap)?->nama;
    @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('pengunduran_diri.index') }}" class="text-sm text-brand hover:underline">&larr; Daftar pengunduran diri</a>
            <h1 class="mt-1 text-lg font-bold text-gray-900">{{ $s->nama }} <span class="font-mono text-sm font-normal text-gray-500">{{ $p->nomor }}</span></h1>
            <p class="text-sm text-gray-600">{{ $s->nis }} · {{ $s->jenjang?->nama }} · kelas {{ $s->kelas?->nama ?? '—' }} · wali kelas {{ $s->kelas?->waliKelas?->nama ?? 'belum ditetapkan' }}</p>
        </div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-brand">{{ $p->labelStatus() }}</span>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="mb-2 font-semibold text-gray-900">Pengajuan wali</h2>
                <p>{{ $p->alasan }}</p>
                <p class="mt-2 text-xs text-gray-500">Oleh {{ $p->wali?->{'nama_'.$p->diajukan_peran} ?: $p->wali?->nama }} ({{ $p->diajukan_peran }}) · {{ $tgl($p->created_at) }}
                    @if ($p->rencana_tanggal) · rencana keluar {{ $p->rencana_tanggal->format('d/m/Y') }}@endif</p>
                <h3 class="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Dokumen</h3>
                <ul class="space-y-1">
                    @foreach ($p->dokumen as $d)
                        <li><a href="{{ route('pengunduran_diri.dokumen', $d->id) }}" target="_blank" class="text-brand hover:underline">{{ \App\Models\DokumenPengajuanKeluar::JENIS[$d->jenis] }}</a>
                            <span class="text-xs text-gray-500">{{ $d->keterangan }} · {{ $d->created_at?->format('d/m/Y') }}</span></li>
                    @endforeach
                </ul>
            </div>

            {{-- 1. Wali kelas --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="mb-2 font-semibold text-gray-900">1. Verifikasi wali kelas</h2>
                @if ($p->wali_kelas_pada)
                    <p>{{ $p->status === 'dikembalikan' ? 'Dikembalikan' : 'Diverifikasi' }} oleh <b>{{ $nama('wali_kelas') }}</b> · {{ $tgl($p->wali_kelas_pada) }}</p>
                    @if ($p->wali_kelas_catatan)<p class="text-gray-600">Catatan: {{ $p->wali_kelas_catatan }}</p>@endif
                @endif
                @if ($p->status === 'menunggu_wali_kelas' && $bolehWaliKelas)
                    <form method="POST" action="{{ route('pengunduran_diri.verifikasi', $p->id) }}" class="mt-2 space-y-2">
                        @csrf
                        <textarea name="catatan" rows="2" placeholder="Catatan (wajib bila dikembalikan)" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                        <div class="flex gap-2">
                            <button name="keputusan" value="lanjut" class="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Verifikasi & teruskan</button>
                            <button name="keputusan" value="kembalikan" class="rounded-lg border border-amber-400 px-3 py-1.5 font-semibold text-amber-700">Kembalikan ke wali</button>
                        </div>
                    </form>
                @elseif ($p->status === 'menunggu_wali_kelas')
                    <p class="text-gray-500">Menunggu wali kelas.</p>
                @endif
            </div>

            {{-- 2. Kepala sekolah --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="mb-2 font-semibold text-gray-900">2. Persetujuan kepala sekolah</h2>
                @if ($p->kepala_sekolah_pada)
                    <p>{{ $p->status === 'ditolak' ? 'Tidak disetujui' : 'Disetujui' }} oleh <b>{{ $nama('kepala_sekolah') }}</b> · {{ $tgl($p->kepala_sekolah_pada) }}</p>
                    @if ($p->kepala_sekolah_catatan)<p class="text-gray-600">Catatan: {{ $p->kepala_sekolah_catatan }}</p>@endif
                @endif
                @if ($p->status === 'menunggu_kepala_sekolah' && $bolehKepsek)
                    <form method="POST" action="{{ route('pengunduran_diri.putuskan', $p->id) }}" class="mt-2 space-y-2">
                        @csrf
                        <textarea name="catatan" rows="2" placeholder="Catatan (wajib bila tidak disetujui)" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                        <div class="flex gap-2">
                            <button name="keputusan" value="ya" class="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Ya, setujui</button>
                            <button name="keputusan" value="tidak" class="rounded-lg border border-red-300 px-3 py-1.5 font-semibold text-red-700">Tidak</button>
                        </div>
                    </form>
                @elseif ($p->status === 'menunggu_kepala_sekolah')
                    <p class="text-gray-500">Menunggu kepala sekolah {{ $s->jenjang?->nama }}.</p>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            {{-- 3. Clearance --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="mb-2 font-semibold text-gray-900">3. Clearance</h2>
                @foreach (\App\Models\PengajuanKeluar::CLEARANCE as $pihak => $label)
                    <div class="border-t border-gray-100 py-3 first:border-0 first:pt-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ $label }}</span>
                            @if ($p->{"{$pihak}_pada"})<span class="text-xs font-semibold text-emerald-700">✓ {{ $nama($pihak) }} · {{ $tgl($p->{"{$pihak}_pada"}) }}</span>@endif
                        </div>
                        @if ($p->{"{$pihak}_catatan"})<p class="text-gray-600">Catatan: {{ $p->{"{$pihak}_catatan"} }}</p>@endif

                        @if ($pihak === 'keuangan')
                            <div class="mt-2 rounded-lg bg-gray-50 p-2 text-xs">
                                <p class="font-semibold">Tetap tertagih (seluruh tagihan, termasuk sisa PPSB): {{ $rp($keuangan['tetap_total']) }}</p>
                                @foreach ($keuangan['tetap'] as $t)<p class="pl-2">· {{ $t['nama'] }} — {{ $rp($t['sisa']) }}</p>@endforeach
                                <p class="mt-1 font-semibold">Titipan (dompet + tabungan): {{ $rp($keuangan['titipan']['total']) }}</p>
                            </div>
                        @endif
                        @if ($pihak === 'admin' && $bolehClearance['admin'] && in_array($p->status, ['clearance', 'siap_dikeluarkan', 'selesai'], true))
                            <form method="POST" action="{{ route('pengunduran_diri.unggah', $p->id) }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-center gap-2">
                                @csrf
                                <select name="jenis" class="rounded border-gray-300 text-xs">
                                    <option value="surat_mutasi">Surat mutasi</option><option value="surat_keputusan">Surat keputusan</option><option value="lainnya">Dokumen lain</option>
                                </select>
                                <input name="keterangan" maxlength="255" placeholder="Keterangan" class="w-32 rounded border-gray-300 text-xs">
                                <input type="file" name="berkas" required accept="image/*,application/pdf" class="text-xs">
                                <button class="rounded bg-gray-700 px-2 py-1 text-xs font-semibold text-white">Unggah</button>
                                <span class="text-[11px] text-gray-400">maks {{ $maksLabel }}</span>
                            </form>
                        @endif
                        @if ($p->status === 'clearance' && ! $p->{"{$pihak}_pada"} && $bolehClearance[$pihak])
                            <form method="POST" action="{{ route('pengunduran_diri.clearance', [$p->id, $pihak]) }}" class="mt-2 flex gap-2">
                                @csrf
                                <input name="catatan" maxlength="2000" placeholder="{{ $pihak === 'keuangan' && ! $keuangan['bersih'] ? 'Wajib: disepakati / dibebaskan, jelaskan' : 'Catatan (opsional)' }}" class="flex-1 rounded border-gray-300 text-xs">
                                <button class="rounded bg-emerald-600 px-3 py-1 text-xs font-semibold text-white">Beri clearance ✓</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- 4. Pengeluaran --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <h2 class="mb-2 font-semibold text-gray-900">4. Pengeluaran & Dapodik (admin sekolah)</h2>
                @if ($p->dikeluarkan_pada)
                    <p>Dikeluarkan oleh <b>{{ \App\Models\User::find($p->dikeluarkan_oleh)?->nama }}</b> · {{ $tgl($p->dikeluarkan_pada) }}</p>
                    @if ($p->dapodik_pada)
                        <p class="text-emerald-700">✓ Sudah diproses di Dapodik · {{ $tgl($p->dapodik_pada) }}</p>
                    @elseif ($bolehKeluarkan)
                        <form method="POST" action="{{ route('pengunduran_diri.dapodik', $p->id) }}" class="mt-2">@csrf
                            <button class="rounded bg-brand px-3 py-1 text-xs font-semibold text-white">Tandai sudah diproses di Dapodik</button></form>
                    @endif
                @elseif ($p->status === 'siap_dikeluarkan' && $bolehKeluarkan)
                    <form method="POST" action="{{ route('pengunduran_diri.keluarkan') }}" data-confirm="Keluarkan {{ $s->nama }} sekarang? Status menjadi Keluar dan sisa tagihan PPSB dibatalkan.">
                        @csrf <input type="hidden" name="pengajuan[]" value="{{ $p->id }}">
                        <button class="rounded bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">Keluarkan santri</button>
                    </form>
                    <p class="mt-1 text-xs text-gray-500">Biasanya diproses bersamaan di akhir bulan dari tab "Siap dikeluarkan".</p>
                @else
                    <p class="text-gray-500">Setelah ketiga clearance selesai.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
