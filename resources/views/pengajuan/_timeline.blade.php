{{-- Rantai persetujuan (port ApprovalTimeline dev): banner eskalasi + "Sekarang
     Menunggu" (tahap berjalan + kandidat penyetuju) + "Tahap Persetujuan" (semua
     tahap yang berlaku + statusnya) + "Riwayat". Menerima $t = ApprovalService::timeline(). --}}
@php
    $sebutan = fn ($s) => ! empty($s['fungsi']) ? "tim {$s['fungsi']}" : ($s['nama_level_pengajuan'] ?? "peringkat {$s['peringkat']}");
    $ditolak = ($t['status'] ?? null) === 'ditolak';
    $selesai = in_array($t['status'] ?? null, ['disetujui'], true);
    $statusTahap = function ($urutan) use ($t, $ditolak, $selesai) {
        if ($ditolak && $urutan === $t['tahap_sekarang']) return 'ditolak';
        if ($selesai || $urutan < $t['tahap_sekarang']) return 'selesai';
        if ($urutan === $t['tahap_sekarang']) return 'sekarang';
        return 'menunggu';
    };
    $bulat = ['selesai' => 'bg-emerald-500 text-white', 'sekarang' => 'bg-brand text-white', 'ditolak' => 'bg-red-500 text-white', 'menunggu' => 'bg-gray-200 text-gray-500'];
    $aksiLabel = [
        'ajukan' => ['Diajukan', 'bg-gray-100 text-gray-600'], 'approve' => ['Disetujui', 'bg-emerald-100 text-emerald-700'],
        'reject' => ['Ditolak', 'bg-red-100 text-red-700'], 'edit' => ['Dikoreksi', 'bg-amber-100 text-amber-700'],
        'void' => ['Dibatalkan', 'bg-gray-200 text-gray-600'], 'verifikasi' => ['Diverifikasi', 'bg-sky-100 text-sky-700'],
    ];
@endphp

<div class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
    @if ($t['overbudget'] || $t['belum_dianggarkan'])
        <div class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-800">
            ⚠️ {{ $t['belum_dianggarkan'] ? 'Akun ini belum dianggarkan' : 'Pengajuan ini melampaui anggaran' }} — rantai menyertakan <b>eskalasi ke Ketua Yayasan</b>.
        </div>
    @endif

    @if ($t['menunggu'])
        <div class="rounded-lg border border-brand/30 bg-brand-soft/50 px-3 py-2.5 text-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Sekarang menunggu</div>
            <div class="mt-0.5"><b>{{ $t['menunggu']['nama_tahap'] }}</b> <span class="text-xs text-gray-500">({{ $sebutan($t['menunggu']) }})</span></div>
            <div class="mt-0.5">
                @if (count($t['menunggu']['kandidat']) > 0)
                    di: <b>{{ implode(', ', $t['menunggu']['kandidat']) }}</b>
                @else
                    <span class="text-red-600">⚠️ Tidak ada pengguna aktif sebagai "{{ $sebutan($t['menunggu']) }}"{{ $t['menunggu']['scope'] === 'bagian' ? ' di bagian ini' : '' }} — pengajuan tertahan sampai posisi itu diisi di menu Pengguna.</span>
                @endif
            </div>
        </div>
    @endif

    <div>
        <div class="mb-2 text-sm font-semibold text-gray-800">Tahap Persetujuan</div>
        <ol class="space-y-1.5">
            @foreach ($t['tahap'] as $s)
                @php $st = $statusTahap($s['urutan']); @endphp
                <li class="flex items-center gap-2 text-sm">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs {{ $bulat[$st] }}">
                        {{ $st === 'selesai' ? '✓' : ($st === 'ditolak' ? '✕' : $s['urutan']) }}
                    </span>
                    <span class="{{ $st === 'menunggu' ? 'text-gray-400' : 'text-gray-800' }}">{{ $s['nama_tahap'] }}</span>
                    <span class="text-xs text-gray-400">({{ $sebutan($s) }}{{ $s['scope'] === 'bagian' ? ', sebagian dgn pemohon' : '' }})</span>
                    @if ($st === 'sekarang' && ! $ditolak && ! $selesai)
                        <span class="rounded bg-brand/10 px-2 py-0.5 text-xs text-brand">menunggu</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    <div>
        <div class="mb-2 text-sm font-semibold text-gray-800">Riwayat</div>
        @forelse ($t['logs'] as $l)
            @php [$teks, $cls] = $aksiLabel[$l->aksi] ?? [$l->aksi, 'bg-gray-100 text-gray-600']; @endphp
            <div class="mb-2 rounded-lg border border-gray-100 px-3 py-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded px-2 py-0.5 text-xs {{ $cls }}">{{ $teks }}</span>
                    <b>{{ $l->nama_pengguna }}</b>
                    <span class="text-xs text-gray-400">{{ $l->waktu?->format('d M Y') }}</span>
                </div>
                @if ($l->catatan)<p class="mt-1 text-gray-600">{{ $l->catatan }}</p>@endif
            </div>
        @empty
            <p class="text-sm text-gray-400">Belum ada riwayat.</p>
        @endforelse
    </div>

    <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
        Nominal: <b>@rp($t['nominal'])</b>@if ($t['kode_bagian']) · Bagian: <b>{{ $t['kode_bagian'] }}</b>@endif
    </div>
</div>
