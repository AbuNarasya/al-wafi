{{-- Rantai persetujuan sebagai SATU daftar.

     Dulu keterangannya terbelah dua: daftar "Tahap Persetujuan" (tahap + status,
     tanpa nama) dan daftar "Riwayat" (nama + tanggal, tanpa tahap). Untuk tahu
     siapa yang menyetujui tahap tertentu, pembaca harus memasangkan sendiri dua
     daftar itu — dan kalau dokumen sedang berhenti, tak ada yang menyebut ia
     berhenti di siapa.

     Sekarang tiap baris berbunyi: nama tahap (status nama-orang), tanggalnya
     di luar kurung. Pemasangannya memakai `approval_logs.urutan`, yang memang
     menyimpan nomor tahap pada tiap catatan approve/reject.

     Menerima:
       $t          — ApprovalService::timeline()
       $verifikasi — true bila dokumen ini mengenal verifikasi keuangan
                     (Pengajuan Pembayaran; usulan anggaran tidak punya)
       $timKeuangan — list<string> nama penyetuju verifikasi, boleh kosong
--}}
@php
    $verifikasi ??= false;
    $timKeuangan ??= [];

    $sebutan = fn ($s) => ! empty($s['fungsi']) ? "tim {$s['fungsi']}" : ($s['nama_level_pengajuan'] ?? "peringkat {$s['peringkat']}");
    $ditolak = ($t['status'] ?? null) === 'ditolak';
    $selesai = ($t['status'] ?? null) === 'disetujui';

    $logs = collect($t['logs']);
    $logTahap = fn (int $urutan) => $logs->first(fn ($l) => in_array($l->aksi, ['approve', 'reject'], true) && (int) $l->urutan === $urutan);
    $logAksi = fn (string $aksi) => $logs->first(fn ($l) => $l->aksi === $aksi);

    // Peristiwa yang bukan tahap & bukan pengajuan/verifikasi (koreksi akun,
    // pembatalan). Tetap ditampilkan — kalau dibuang, jejak koreksi hilang.
    $lain = $logs->filter(fn ($l) => in_array($l->aksi, ['edit', 'void'], true));

    $warna = [
        'selesai' => ['ti' => '✓', 'kelas' => 'bg-emerald-500 text-white', 'teks' => 'text-emerald-700'],
        'sekarang' => ['ti' => '•', 'kelas' => 'bg-brand text-white', 'teks' => 'text-brand'],
        'ditolak' => ['ti' => '✕', 'kelas' => 'bg-red-500 text-white', 'teks' => 'text-red-700'],
        'menunggu' => ['ti' => '', 'kelas' => 'border border-gray-300 bg-white text-gray-400', 'teks' => 'text-gray-400'],
    ];
@endphp

<div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
    @if ($t['overbudget'] || $t['belum_dianggarkan'])
        <div class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
            &#9888; {{ $t['belum_dianggarkan'] ? 'Akun ini belum dianggarkan' : 'Pengajuan ini melampaui anggaran' }} &mdash;
            rantai menyertakan <b>eskalasi ke Ketua Yayasan</b>.
        </div>
    @endif

    <div>
        <div class="mb-2 text-sm font-semibold text-gray-800">Rantai persetujuan</div>
        <ol class="divide-y divide-gray-100">

            {{-- Baris pembuka: pengajuannya sendiri. --}}
            @php $awal = $logAksi('ajukan'); @endphp
            <li class="flex gap-2.5 py-2">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gray-200 text-[11px] text-gray-600">&#8901;</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                        <span class="text-gray-800">Diajukan</span>
                        <span class="text-gray-400">(<b class="font-medium text-gray-900">{{ $awal?->nama_pengguna ?? '—' }}</b>)</span>
                        @if ($awal?->waktu)<span class="text-xs text-gray-400">{{ $awal->waktu->format('d M Y') }}</span>@endif
                    </div>
                    @if ($awal?->catatan)<p class="mt-0.5 text-xs text-gray-500">{{ $awal->catatan }}</p>@endif
                </div>
            </li>

            {{-- Tahap persetujuan, masing-masing dengan penyetujunya. --}}
            @foreach ($t['tahap'] as $s)
                @php
                    $log = $logTahap((int) $s['urutan']);
                    $st = match (true) {
                        $log && $log->aksi === 'reject' => 'ditolak',
                        (bool) $log, $selesai => 'selesai',
                        (int) $s['urutan'] === (int) $t['tahap_sekarang'] && ! $ditolak => 'sekarang',
                        default => 'menunggu',
                    };
                    $w = $warna[$st];
                    $peran = $sebutan($s).($s['scope'] === 'bagian' ? ', sebagian dengan pemohon' : '');
                    $kandidat = $st === 'sekarang' ? ($t['menunggu']['kandidat'] ?? []) : [];
                @endphp
                <li class="flex gap-2.5 py-2 {{ $st === 'sekarang' ? 'rounded-lg bg-brand-soft/60 px-2' : '' }}">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] {{ $w['kelas'] }}">{{ $w['ti'] }}</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                            <span class="{{ $st === 'menunggu' ? 'text-gray-400' : 'text-gray-800' }} {{ $st === 'sekarang' ? 'font-medium' : '' }}">{{ $s['nama_tahap'] }}</span>
                            <span class="text-gray-400">(@if ($st === 'selesai')<span class="{{ $w['teks'] }}">disetujui</span> <b class="font-medium text-gray-900">{{ $log?->nama_pengguna }}</b>@elseif ($st === 'ditolak')<span class="{{ $w['teks'] }}">ditolak</span> <b class="font-medium text-gray-900">{{ $log?->nama_pengguna }}</b>@elseif ($st === 'sekarang' && $kandidat)<span class="{{ $w['teks'] }}">menunggu di</span> <b class="font-medium text-gray-900">{{ implode(', ', $kandidat) }}</b>@elseif ($st === 'sekarang')<span class="{{ $w['teks'] }}">menunggu</span>@else{{ 'belum giliran' }}@endif)</span>
                            @if ($log?->waktu)<span class="text-xs text-gray-400">{{ $log->waktu->format('d M Y') }}</span>@endif
                        </div>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $peran }}</p>
                        @if ($log?->catatan)<p class="mt-0.5 text-xs text-gray-500">{{ $log->catatan }}</p>@endif
                        @if ($st === 'sekarang' && ! $kandidat)
                            <p class="mt-1 text-xs text-red-600">
                                &#9888; Belum ada pengguna aktif sebagai &ldquo;{{ $sebutan($s) }}&rdquo;{{ $s['scope'] === 'bagian' ? ' di bagian ini' : '' }} &mdash;
                                pengajuan tertahan sampai posisi itu diisi di menu Pengguna.
                            </p>
                        @endif
                    </div>
                </li>
            @endforeach

            {{-- Koreksi & pembatalan: bukan tahap, tapi tetap bagian perjalanannya. --}}
            @foreach ($lain as $l)
                <li class="flex gap-2.5 py-2">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-100 text-[11px] text-amber-700">!</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                            <span class="text-gray-800">{{ $l->aksi === 'edit' ? 'Koreksi akun' : 'Dibatalkan' }}</span>
                            <span class="text-gray-400">(<b class="font-medium text-gray-900">{{ $l->nama_pengguna }}</b>)</span>
                            @if ($l->waktu)<span class="text-xs text-gray-400">{{ $l->waktu->format('d M Y') }}</span>@endif
                        </div>
                        @if ($l->catatan)<p class="mt-0.5 text-xs text-gray-500">{{ $l->catatan }}</p>@endif
                    </div>
                </li>
            @endforeach

            {{-- Verifikasi keuangan: langkah SESUDAH rantai, tetapi tetap di daftar
                 yang sama supaya perjalanan dokumen terbaca utuh. Usulan anggaran
                 tak mengenalnya, jadi barisnya hanya muncul bila diminta. --}}
            @if ($verifikasi)
                @php
                    $ver = $logAksi('verifikasi');
                    $stVer = match (true) {
                        (bool) $ver => 'selesai',
                        $ditolak => 'batal',
                        $selesai => 'sekarang',
                        default => 'menunggu',
                    };
                @endphp
                <li class="flex gap-2.5 py-2 {{ $stVer === 'sekarang' ? 'rounded-lg bg-brand-soft/60 px-2' : '' }}">
                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] {{ $stVer === 'selesai' ? 'bg-sky-500 text-white' : ($stVer === 'sekarang' ? 'bg-brand text-white' : 'border border-gray-300 bg-white text-gray-400') }}">{{ $stVer === 'selesai' ? '✓' : ($stVer === 'sekarang' ? '•' : '') }}</span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline gap-x-1.5 text-sm">
                            <span class="{{ in_array($stVer, ['menunggu', 'batal'], true) ? 'text-gray-400' : 'text-gray-800' }} {{ $stVer === 'sekarang' ? 'font-medium' : '' }}">Verifikasi keuangan</span>
                            <span class="text-gray-400">(@if ($stVer === 'selesai')<span class="text-sky-700">diverifikasi</span> <b class="font-medium text-gray-900">{{ $ver?->nama_pengguna }}</b>@elseif ($stVer === 'sekarang')<span class="text-brand">menunggu di</span> <b class="font-medium text-gray-900">{{ $timKeuangan ? implode(', ', $timKeuangan) : 'tim keuangan' }}</b>@elseif ($stVer === 'batal'){{ 'tidak berlanjut' }}@else{{ 'belum giliran' }}@endif)</span>
                            @if ($ver?->waktu)<span class="text-xs text-gray-400">{{ $ver->waktu->format('d M Y') }}</span>@endif
                        </div>
                        @if ($ver?->catatan)<p class="mt-0.5 text-xs text-gray-500">{{ $ver->catatan }}</p>@endif
                    </div>
                </li>
            @endif
        </ol>
    </div>

    <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
        Nominal: <b>@rp($t['nominal'])</b>@if ($t['kode_bagian']) &middot; Bagian: <b>{{ $t['nama_bagian'] ?? $t['kode_bagian'] }}</b>@endif
    </div>
</div>
