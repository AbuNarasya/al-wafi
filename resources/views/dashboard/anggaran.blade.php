{{-- Tab "Anggaran & Pengajuan" — RINGKASAN. Daftar rincinya tetap di
     /budget/realisasi dan /pengajuan-pembayaran; di sini hanya angka yang
     menjawab "sisa berapa" dan "sedang tertahan di mana". --}}
@php
    $warnaPersen = fn (?float $p) => match (true) {
        $p === null => 'text-gray-400',
        $p > 100 => 'text-red-600',
        $p >= 85 => 'text-amber-600',
        default => 'text-emerald-600',
    };
@endphp

{{-- ---------- Status Anggaran ---------- --}}
<div class="mb-6">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">Status Anggaran</h2>
            <p class="text-xs text-gray-500">
                Pemakaian dihitung <b>realisasi + komitmen</b> — komitmen adalah pengajuan yang sudah diajukan tapi belum berjurnal.
                Tanpa itu, dua pengajuan masing-masing 60% anggaran akan tampak aman sampai keduanya terlanjur disetujui.
            </p>
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="tab" value="anggaran">
            <select name="tahun" onchange="this.form.submit()"
                    class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-brand focus:ring-brand">
                @foreach ($tahunOpsi as $t)
                    <option value="{{ $t }}" @selected($t === $tahun)>T.A {{ $t }}</option>
                @endforeach
            </select>
            @if ($anggaran && count($anggaran['bagian_opsi']) > 1)
                <select name="bagian" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-brand focus:ring-brand">
                    <option value="">— semua bagian —</option>
                    @foreach ($anggaran['bagian_opsi'] as $b)
                        <option value="{{ $b['kode_bagian'] }}" @selected($bagian === $b['kode_bagian'])>{{ $b['nama_bagian'] }}</option>
                    @endforeach
                </select>
            @endif
        </form>
    </div>

    @if ($anggaranDitolak)
        <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-500 shadow-sm">
            {{ $anggaranDitolak }}
        </div>
    @elseif (! $anggaran['ada_anggaran'])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada anggaran yang tercatat untuk {{ $anggaran['label_ta'] }}{{ $bagian ? ' pada bagian ini' : '' }}.
            Selama anggaran belum diisi, setiap pengajuan akan dinilai <b>belum dianggarkan</b> dan rantainya menyertakan eskalasi.
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                ['Anggaran', $anggaran['anggaran'], 'text-gray-900'],
                ['Realisasi', $anggaran['realisasi'], 'text-gray-900'],
                ['Komitmen', $anggaran['komitmen'], 'text-amber-700'],
                ['Terpakai', $anggaran['terpakai'], 'text-gray-900'],
                ['Sisa', $anggaran['sisa'], 'text-emerald-700'],
            ] as [$label, $nilai, $warna])
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold tabular-nums {{ $warna }}">@rp($nilai)</div>
                </div>
            @endforeach
        </div>

        <div class="mt-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600">Terpakai terhadap anggaran — {{ $anggaran['label_ta'] }}</span>
                <span class="font-semibold tabular-nums {{ $warnaPersen($anggaran['persen']) }}">
                    {{ $anggaran['persen'] === null ? '—' : $anggaran['persen'] . '%' }}
                </span>
            </div>
            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-full rounded-full {{ ($anggaran['persen'] ?? 0) > 100 ? 'bg-red-500' : (($anggaran['persen'] ?? 0) >= 85 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                     style="width: {{ min(100, max(0, $anggaran['persen'] ?? 0)) }}%"></div>
            </div>
        </div>

        @if ($anggaran['perhatian'])
            <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-800">
                    Akun yang perlu diperhatikan
                    <span class="ml-1 text-xs font-normal text-gray-400">
                        {{ $anggaran['jumlah_perhatian'] }} akun tembus atau belum dianggarkan{{ $anggaran['jumlah_perhatian'] > count($anggaran['perhatian']) ? ' — 10 teratas ditampilkan' : '' }}
                    </span>
                </div>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Akun</th>
                            <th class="px-4 py-2.5 text-right">Anggaran</th>
                            <th class="px-4 py-2.5 text-right">Realisasi</th>
                            <th class="px-4 py-2.5 text-right">Komitmen</th>
                            <th class="px-4 py-2.5 text-right">Sisa</th>
                            <th class="px-4 py-2.5">Keadaan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($anggaran['perhatian'] as $r)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800">{{ $r['nama_coa'] }}</div>
                                    <div class="font-mono text-[11px] text-gray-400">{{ $r['kode_coa'] }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">@rp($r['anggaran'])</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">@rp($r['realisasi'])</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-amber-700">@rp($r['komitmen'])</td>
                                <td class="px-4 py-2.5 text-right tabular-nums {{ $r['tembus'] ? 'text-red-600' : 'text-gray-700' }}">@rp($r['sisa'])</td>
                                <td class="px-4 py-2.5">
                                    @if ($r['belum_dianggarkan'])
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Belum dianggarkan</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">Tembus {{ $r['persen'] }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-3 text-sm text-emerald-700">Tidak ada akun yang tembus anggaran maupun belanja di luar anggaran.</p>
        @endif
    @endif
</div>

{{-- ---------- Status Pengajuan ---------- --}}
<div class="mb-6">
    <h2 class="mb-1 text-sm font-semibold text-gray-900">Status Pengajuan</h2>
    <p class="mb-3 text-xs text-gray-500">Pembayaran, uang muka, dan penyelesaian uang muka — cacah dokumen beserta nominalnya.</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2.5">Jenis</th>
                    <th class="px-4 py-2.5 text-right">Masih berjalan</th>
                    @foreach ($statusLabel as $label)
                        <th class="px-4 py-2.5 text-right">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($jenisLabel as $kode => $label)
                    @php $baris = $pengajuan['matriks'][$kode]; @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ $label }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <div class="font-semibold text-brand">{{ $baris['_berjalan']['jumlah'] }}</div>
                            <div class="text-[11px] tabular-nums text-gray-500">@rp($baris['_berjalan']['total'])</div>
                        </td>
                        @foreach (array_keys($statusLabel) as $status)
                            <td class="px-4 py-2.5 text-right {{ $baris[$status]['jumlah'] === 0 ? 'text-gray-300' : '' }}">
                                <div class="{{ $baris[$status]['jumlah'] === 0 ? '' : 'font-semibold text-gray-800' }}">{{ $baris[$status]['jumlah'] }}</div>
                                @if ($baris[$status]['jumlah'] > 0)
                                    <div class="text-[11px] tabular-nums text-gray-500">@rp($baris[$status]['total'])</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    {{-- Umur tertahan: yang paling sering jadi masalah nyata, dan tak pernah
         disebut layar daftar mana pun. --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
        <h3 class="mb-1 text-sm font-semibold text-gray-800">Paling lama tertahan</h3>
        <p class="mb-3 text-xs text-gray-400">Dokumen yang belum diposting, diurut dari yang paling tua.</p>
        @forelse ($pengajuan['tertahan'] as $t)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 py-2 first:border-t-0 first:pt-0">
                <div class="min-w-0">
                    <a href="{{ route('pengajuan.show', $t['id']) }}" class="font-medium text-brand hover:underline">{{ $t['nomor'] }}</a>
                    <span class="ml-1 text-xs text-gray-500">{{ $t['jenis'] }} · {{ $t['bagian'] }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="tabular-nums text-gray-700">@rp($t['nominal'])</span>
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $t['umur_hari'] >= 14 ? 'bg-red-100 text-red-700' : ($t['umur_hari'] >= 7 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600') }}">
                        {{ $t['umur_hari'] }} hari
                    </span>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400">Tidak ada pengajuan yang tertahan.</p>
        @endforelse
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-semibold text-gray-800">Antrean per tahap</h3>
            @forelse ($pengajuan['antrean'] as $a)
                <div class="flex items-center justify-between border-t border-gray-100 py-1.5 text-sm first:border-t-0 first:pt-0">
                    <span class="text-gray-700">{{ $a['tahap'] }}</span>
                    <span class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-semibold text-brand">{{ $a['jumlah'] }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">Tidak ada yang menunggu persetujuan.</p>
            @endforelse
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800">Uang muka outstanding</h3>
            <p class="mb-2 text-xs text-gray-400">Sudah cair, belum diselesaikan.</p>
            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($pengajuan['uang_muka']['total'])</div>
            <div class="text-xs text-gray-500">{{ $pengajuan['uang_muka']['jumlah'] }} dokumen</div>
            @if ($pengajuan['uang_muka']['terlama'])
                <div class="mt-2 border-t border-gray-100 pt-2 text-xs text-gray-600">
                    Terlama: <b>{{ $pengajuan['uang_muka']['terlama']['nomor_ref'] }}</b>
                    ({{ $pengajuan['uang_muka']['terlama']['penerima'] ?: '—' }}) ·
                    <span class="tabular-nums">@rp($pengajuan['uang_muka']['terlama']['sisa'])</span> ·
                    {{ $pengajuan['uang_muka']['terlama']['umur_hari'] }} hari
                </div>
            @endif
        </div>
    </div>
</div>
