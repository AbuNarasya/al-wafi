@extends('layouts.app')

@section('title', 'Angsuran Uang Pangkal')

@php
    $bolehUbah = \App\Support\Akses::boleh('angsuran-uang-pangkal', 'ubah');
    $bolehBuat = \App\Support\Akses::boleh('angsuran-uang-pangkal', 'buat');
    $labelTagihan = ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum_bayar' => 'Belum bayar', 'batal' => 'Batal'];
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
@endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Angsuran Uang Pangkal</h2>
            <p class="mt-1 max-w-2xl text-sm text-gray-500">Kesepakatan pembayaran uang pangkal bertahap. Uang pangkal harus sudah ditagihkan (calon berstatus Diterima).</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($bolehUbah)
                <form method="POST" action="{{ route('angsuran_uang_pangkal.evaluasi_potongan') }}" onsubmit="return confirm('Evaluasi potongan gelombang untuk semua tagihan yang masih berlaku?')">
                    @csrf<button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Evaluasi Potongan</button>
                </form>
            @endif
            <a href="{{ route('angsuran_uang_pangkal.cetak_rekap') }}" target="_blank" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">🖨 Cetak Rekap</a>
            @if ($bolehBuat)
                <a href="{{ route('angsuran_uang_pangkal.create') }}" class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white hover:bg-brand-dark">+ Buat Rencana</a>
            @endif
        </div>
    </div>

    {{-- Panel potongan gelombang mendekati tenggat 50% --}}
    @if (! empty($potonganTempo))
        <div class="mb-4 rounded-xl border border-amber-200 bg-white shadow-sm">
            <div class="border-b border-amber-100 px-4 py-3 text-sm font-semibold text-amber-800">Potongan gelombang — mendekati tenggat 50% ({{ count($potonganTempo) }})</div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr><th class="px-3 py-2">Calon</th><th class="px-3 py-2">Wali (kontak)</th><th class="px-3 py-2">Gel.</th><th class="px-3 py-2 text-right">Terbayar / 50%</th><th class="px-3 py-2 text-right">Kurang</th><th class="px-3 py-2">Tenggat</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($potonganTempo as $p)
                            <tr class="{{ $p['hari_ke_tenggat'] <= 2 ? 'bg-amber-50' : '' }}">
                                <td class="px-3 py-2">{{ $p['nama'] }}<div class="text-xs text-gray-400">{{ $p['no_pendaftaran'] }}</div></td>
                                <td class="px-3 py-2 text-xs">{{ $p['nama_wali'] ?? '—' }}<div class="text-gray-400">{{ $p['telepon_wali'] }}</div></td>
                                <td class="px-3 py-2">{{ $p['gelombang'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">@rp($p['terbayar']) / @rp($p['ambang'])</td>
                                <td class="px-3 py-2 text-right tabular-nums text-amber-800">@rp($p['kurang'])</td>
                                <td class="px-3 py-2">{{ $tgl($p['tenggat']) }}
                                    @if ($p['hari_ke_tenggat'] <= 0)<span class="rounded bg-rose-100 px-2 py-0.5 text-xs text-rose-700">lewat</span>@else<span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">{{ $p['hari_ke_tenggat'] }} hari</span>@endif
                                </td>
                                <td class="px-3 py-2 text-right"><a href="{{ route('angsuran_uang_pangkal.show', $p['id_santri']) }}" class="text-xs text-brand hover:underline">Detail</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="px-4 py-2 text-xs text-gray-400">Bila ≥50% belum terbayar s/d tenggat, potongan hangus &amp; tagihan naik ke nominal normal.</p>
        </div>
    @endif

    {{-- Panel jatuh tempo (reminder) --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-brand">Termin jatuh tempo — perlu ditagih</h3>
            <form method="GET" class="flex items-center gap-2">
                <select name="dalam_hari" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-xs">
                    @foreach ($opsiHari as $v => $l)
                        <option value="{{ $v }}" @selected($dalamHari === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </form>
            <span class="text-xs text-gray-400">{{ count($jatuhTempo) }} termin</span>
        </div>
        @if (empty($jatuhTempo))
            <p class="px-4 py-6 text-sm text-gray-400">Tidak ada termin yang mendekati / lewat jatuh tempo.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                        <tr><th class="px-3 py-2">Calon</th><th class="px-3 py-2">Wali (kontak)</th><th class="px-3 py-2">Termin</th><th class="px-3 py-2 text-right">Sisa termin</th><th class="px-3 py-2">Jatuh tempo</th><th class="px-3 py-2">Aging</th><th class="px-3 py-2">Reminder</th><th class="px-3 py-2">Feedback</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($jatuhTempo as $r)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2">{{ $r['nama'] }}<div class="text-xs text-gray-400">{{ $r['no_pendaftaran'] }}</div></td>
                                <td class="px-3 py-2 text-xs">{{ $r['nama_wali'] ?? '—' }}<div class="text-gray-400">{{ $r['telepon_wali'] }}</div></td>
                                <td class="px-3 py-2">#{{ $r['urutan'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">@rp($r['sisa_termin'])</td>
                                <td class="px-3 py-2">{{ $tgl($r['jatuh_tempo']) }}</td>
                                <td class="px-3 py-2">
                                    @if ($r['aging'] === 'belum_jatuh_tempo')<span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">≤ jatuh tempo</span>@else<span class="rounded bg-rose-100 px-2 py-0.5 text-xs text-rose-700">Lewat {{ $r['hari_lewat'] }} hari</span>@endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <div class="text-gray-400">{{ $r['diingatkan_pada'] ? 'diingatkan ' . $tgl($r['diingatkan_pada']) : '—' }}</div>
                                    @if ($bolehUbah)
                                        <form method="POST" action="{{ route('angsuran_uang_pangkal.ingatkan', $r['id_termin']) }}"><input type="hidden" name="catatan" value="">@csrf<button class="text-brand hover:underline">Sudah diingatkan</button></form>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($bolehUbah)
                                        <form method="POST" action="{{ route('angsuran_uang_pangkal.feedback', $r['id_termin']) }}" class="flex items-center gap-1">
                                            @csrf<input type="text" name="feedback" value="{{ $r['feedback'] }}" placeholder="tanggapan wali" class="w-36 rounded border-gray-300 text-xs">
                                            <button class="text-brand hover:underline">simpan</button>
                                        </form>
                                    @else
                                        {{ $r['feedback'] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Daftar rencana aktif --}}
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">No. Pendaftaran</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Wali</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Terbayar</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3">Termin berikut</th><th class="px-4 py-3">Status</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono">{{ $r['no_pendaftaran'] }}</td>
                        <td class="px-4 py-2">{{ $r['nama'] }}</td>
                        <td class="px-4 py-2">{{ $r['nama_wali'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['total'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['terbayar'])</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['sisa'])</td>
                        <td class="px-4 py-2 text-xs">{{ $r['termin_berikut'] ? '#' . $r['termin_berikut']['urutan'] . ' · ' . $tgl($r['termin_berikut']['jatuh_tempo']) : '—' }}</td>
                        <td class="px-4 py-2"><span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs">{{ $labelTagihan[$r['status_tagihan']] ?? $r['status_tagihan'] }}</span></td>
                        <td class="px-4 py-2 text-right"><a href="{{ route('angsuran_uang_pangkal.show', $r['id_santri']) }}" class="text-xs text-brand hover:underline">Detail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Belum ada rencana angsuran.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
