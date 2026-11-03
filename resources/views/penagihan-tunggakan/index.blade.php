@extends('layouts.app')

@section('title', 'Penagihan Tunggakan')

@php
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    $cara = \App\Models\CatatanPenagihan::CARA;
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Penagihan Tunggakan</h2>
        <p class="mt-1 text-sm text-gray-500">
            Santri yang punya tagihan <b>lewat jatuh tempo</b> — semua jenis tagihan, semua jenjang. Buka santri untuk
            melihat rinciannya dan mencatat penagihan ke wali (kapan, lewat apa, tanggapan wali, janji bayar).
            Santri hilang dari daftar begitu tunggakannya lunas. Tagihan yang dicicil lewat rencana angsuran ditagih dari
            modul Angsuran Uang Pangkal.
        </p>
    </div>


    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
            <div class="text-xs font-medium text-rose-700">Total Tunggakan</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-rose-900">@rp($ringkasan['sisa'])</div>
            <div class="text-[11px] text-rose-700/80">{{ $ringkasan['tagihan'] }} tagihan · {{ $ringkasan['santri'] }} santri</div>
        </div>
        <a href="{{ route('penagihan_tunggakan.index', ['status' => 'belum_ditagih']) }}"
           class="rounded-xl border border-gray-200 bg-white px-4 py-3 transition hover:shadow-sm">
            <div class="text-xs font-medium text-gray-500">Belum Pernah Ditagih</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ $ringkasan['belum_ditagih'] }}</div>
            <div class="text-[11px] text-gray-400">santri · klik untuk menyaring</div>
        </a>
        <a href="{{ route('penagihan_tunggakan.index', ['status' => 'janji_lewat']) }}"
           class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 transition hover:shadow-sm">
            <div class="text-xs font-medium text-amber-700">Janji Bayar Sudah Lewat</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-amber-900">{{ $ringkasan['janji_lewat'] }}</div>
            <div class="text-[11px] text-amber-700/80">santri · saatnya menagih lagi</div>
        </a>
        <a href="{{ route('penagihan_tunggakan.index', ['umur' => 90]) }}"
           class="rounded-xl border border-gray-200 bg-white px-4 py-3 transition hover:shadow-sm">
            <div class="text-xs font-medium text-gray-500">Menunggak &gt; 90 Hari</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ count(array_filter($daftar, fn ($r) => $r['hari_terlama'] >= 90)) }}</div>
            <div class="text-[11px] text-gray-400">santri dalam saringan ini</div>
        </a>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Jenjang</label>
            <select name="jenjang" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiJenjang as $kode => $nama)
                    <option value="{{ $kode }}" @selected($filter['jenjang'] === $kode)>{{ $nama }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Kelas</label>
            <select name="kelas" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiKelas as $id => $nama)
                    <option value="{{ $id }}" @selected($filter['kelas'] === (string) $id)>{{ $nama }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Lama Menunggak</label>
            <select name="umur" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ([30, 60, 90] as $h)
                    <option value="{{ $h }}" @selected($filter['umur'] === (string) $h)>&gt; {{ $h }} hari</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Status Penagihan</label>
            <select name="status" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                <option value="belum_ditagih" @selected($filter['status'] === 'belum_ditagih')>Belum pernah ditagih</option>
                <option value="sudah_ditagih" @selected($filter['status'] === 'sudah_ditagih')>Sudah ditagih</option>
                <option value="janji_lewat" @selected($filter['status'] === 'janji_lewat')>Janji bayar sudah lewat</option>
                <option value="janji_berjalan" @selected($filter['status'] === 'janji_berjalan')>Janji bayar belum jatuh</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Cari</label>
            <input type="text" name="q" value="{{ $filter['q'] }}" placeholder="NIS atau nama santri"
                   class="w-56 rounded-lg border-gray-300 px-3 py-1.5 text-sm">
        </div>
        <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Saring</button>
        @if (array_filter($filter))
            <a href="{{ route('penagihan_tunggakan.index') }}" class="px-2 py-1.5 text-sm text-gray-500 hover:underline">Reset</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang / Kelas</th>
                    <th class="px-4 py-3">Tagihan Menunggak</th>
                    <th class="px-4 py-3 text-right">Sisa</th>
                    <th class="px-4 py-3">Terlama</th>
                    <th class="px-4 py-3">Wali</th>
                    <th class="px-4 py-3">Penagihan Terakhir</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($daftar as $r)
                    <tr class="align-top hover:bg-gray-50">
                        <td class="px-4 py-2">
                            <a href="{{ route('penagihan_tunggakan.show', $r['id_santri']) }}" class="font-medium text-brand hover:underline">{{ $r['nama'] }}</a>
                            <div class="font-mono text-[11px] text-gray-400">{{ $r['nis'] ?: '—' }}</div>
                            @if ($r['status_santri'] !== 'aktif')
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ ucfirst(str_replace('_', ' ', $r['status_santri'] ?? '—')) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $r['jenjang'] ?: '—' }}<div class="text-[11px] text-gray-400">{{ $r['kelas'] ?: 'tanpa kelas' }}</div></td>
                        <td class="px-4 py-2 text-xs text-gray-600">
                            <span class="font-semibold text-gray-800">{{ $r['jumlah'] }} tagihan</span>
                            <div class="text-gray-500">{{ implode(', ', array_slice($r['tagihan'], 0, 3)) }}{{ $r['jumlah'] > 3 ? ', …' : '' }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-rose-700">@rp($r['sisa'])</td>
                        <td class="whitespace-nowrap px-4 py-2"><span class="rounded bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-700">{{ $r['hari_terlama'] }} hari</span></td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $r['nama_wali'] ?? '—' }}<div class="text-gray-400">{{ $r['telepon_wali'] }}</div></td>
                        <td class="px-4 py-2 text-xs">
                            @if ($c = $r['catatan_terakhir'])
                                <div class="text-gray-700">{{ $tgl($c->ditagih_pada) }} · {{ $cara[$c->cara] ?? $c->cara }}
                                    <span class="text-gray-400">({{ $r['kali_ditagih'] }}×)</span></div>
                                <div class="max-w-xs truncate text-gray-500" title="{{ $c->tanggapan }}">{{ $c->tanggapan }}</div>
                                @if ($r['janji_lewat'])
                                    <span class="mt-0.5 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">janji {{ $tgl($r['janji_lewat']) }} lewat</span>
                                @elseif ($r['janji_berikut'])
                                    <span class="mt-0.5 inline-block rounded bg-sky-100 px-1.5 py-0.5 text-[11px] text-sky-800">janji bayar {{ $tgl($r['janji_berikut']) }}</span>
                                @endif
                            @else
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">belum pernah ditagih</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2 text-right">
                            <a href="{{ route('penagihan_tunggakan.show', $r['id_santri']) }}" class="text-xs font-semibold text-brand hover:underline">Rincian &amp; catat</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Tidak ada tunggakan lewat jatuh tempo{{ array_filter($filter) ? ' untuk saringan ini' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
