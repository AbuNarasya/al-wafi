@extends('layouts.app')

@section('title', 'Rencana Angsuran — ' . $d['nama'])

@php
    $bolehUbah = \App\Support\Akses::boleh('angsuran-uang-pangkal', 'ubah');
    $tgl = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $labelTermin = ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum' => 'Belum'];
    $badgeTermin = ['lunas' => 'bg-emerald-100 text-emerald-700', 'sebagian' => 'bg-amber-100 text-amber-700', 'belum' => 'bg-slate-200 text-slate-500'];
    $rk = $d['rencana_aktif'];
    $terminInit = $rk ? collect($rk['termin'])->map(fn ($t) => ['nominal' => (string) $t['nominal'], 'jatuh_tempo' => \Illuminate\Support\Carbon::parse($t['jatuh_tempo'])->format('Y-m-d'), 'keterangan' => $t['keterangan'] ?? ''])->values() : [['nominal' => '', 'jatuh_tempo' => now()->toDateString(), 'keterangan' => '']];
@endphp

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-3 flex items-center justify-between">
            <a href="{{ route('angsuran_uang_pangkal.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <a href="{{ route('angsuran_uang_pangkal.cetak_detail', $d['id_santri']) }}" target="_blank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">🖨 Cetak</a>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">Rencana Angsuran — {{ $d['nama'] }}</h2>
            <div class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">No. Pendaftaran</span><b class="font-mono">{{ $d['no_pendaftaran'] }}</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Wali</span><b>{{ $d['nama_wali'] ?? '—' }}</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Total</span><b class="font-mono">@rp($d['total'])</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Terbayar</span><b class="font-mono">@rp($d['terbayar'])</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Sisa</span><b class="font-mono">@rp($d['sisa'])</b></div>
                <div class="flex justify-between"><span class="text-gray-500">Akrual</span><b>{{ $d['sudah_akrual'] ? 'Sudah (piutang)' : 'Belum daftar ulang' }}</b></div>
            </div>

            @if ($d['potongan'])
                @php $ps = $d['potongan']['status']; @endphp
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3">
                    <div class="mb-1 flex items-center justify-between">
                        <span class="font-semibold text-amber-800">Potongan Gelombang {{ $d['potongan']['gelombang'] }}</span>
                        <span class="rounded px-2 py-0.5 text-xs {{ ['berlaku' => 'bg-amber-100 text-amber-700', 'earned' => 'bg-emerald-100 text-emerald-700', 'hangus' => 'bg-rose-100 text-rose-700'][$ps] ?? '' }}">{{ ['berlaku' => 'berlaku', 'earned' => 'terkunci', 'hangus' => 'hangus'][$ps] ?? $ps }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-0.5 text-xs">
                        <div class="flex justify-between"><span class="text-gray-500">Uang pangkal normal</span><b class="font-mono">@rp($d['potongan']['nominal_normal'])</b></div>
                        <div class="flex justify-between"><span class="text-gray-500">Potongan</span><b class="font-mono text-amber-800">− @rp($d['potongan']['potongan'])</b></div>
                        <div class="flex justify-between"><span class="text-gray-500">Syarat</span><b>≥ {{ $d['potongan']['syarat_persen'] }}% ≤ tenggat</b></div>
                        <div class="flex justify-between"><span class="text-gray-500">Tenggat</span><b>{{ $tgl($d['potongan']['tenggat']) }}</b></div>
                    </div>
                </div>
            @endif

            {{-- Termin --}}
            @if (! $rk)
                <p class="mt-4 rounded bg-amber-50 px-3 py-2 text-sm text-amber-700">Belum ada rencana angsuran aktif.</p>
            @else
                <div x-data="{ reneg: false }" class="mt-5">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-800">Termin (versi {{ $rk['versi'] }})</h3>
                        @if ($bolehUbah)<button type="button" @click="reneg = !reneg" class="text-xs text-brand hover:underline">Re-negosiasi jadwal</button>@endif
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr><th class="px-3 py-2">#</th><th class="px-3 py-2 text-right">Nominal</th><th class="px-3 py-2 text-right">Tertutup</th><th class="px-3 py-2">Jatuh tempo</th><th class="px-3 py-2">Tgl Bayar</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Reminder</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($rk['termin'] as $t)
                                    <tr>
                                        <td class="px-3 py-1.5">{{ $t['urutan'] }}</td>
                                        <td class="px-3 py-1.5 text-right font-mono">@rp($t['nominal'])</td>
                                        <td class="px-3 py-1.5 text-right font-mono">@rp($t['tertutup'])</td>
                                        <td class="px-3 py-1.5">{{ $tgl($t['jatuh_tempo']) }}</td>
                                        <td class="px-3 py-1.5 text-gray-600">{{ $t['tanggal_lunas'] ? $tgl($t['tanggal_lunas']) : '—' }}</td>
                                        <td class="px-3 py-1.5"><span class="rounded px-2 py-0.5 text-xs {{ $badgeTermin[$t['status_termin']] ?? '' }}">{{ $labelTermin[$t['status_termin']] ?? $t['status_termin'] }}</span></td>
                                        <td class="px-3 py-1.5 text-xs text-gray-400">{{ $t['diingatkan_pada'] ? $tgl($t['diingatkan_pada']) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Form Re-negosiasi --}}
                    @if ($bolehUbah)
                        <div x-show="reneg" x-cloak class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50/40 p-4">
                            <form method="POST" action="{{ route('angsuran_uang_pangkal.renegosiasi', $d['id_santri']) }}" x-data="renegForm(@js($terminInit), {{ (float) $d['total'] }})">
                                @csrf
                                <p class="mb-3 rounded bg-white px-3 py-2 text-xs text-gray-500">Total tetap <b class="font-mono">@rp($d['total'])</b> — re-negosiasi hanya menata ulang jadwal. Versi lama disimpan sebagai riwayat.</p>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Disepakati pada</label><input type="date" name="disepakati_pada" value="{{ now()->toDateString() }}" required class="w-full rounded border-gray-300 text-sm"></div>
                                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Alasan</label><input type="text" name="alasan" required placeholder="mis. wali minta diperingan" class="w-full rounded border-gray-300 text-sm"></div>
                                </div>
                                <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-2 py-2">#</th><th class="px-2 py-2">Jatuh Tempo</th><th class="px-2 py-2">Keterangan</th><th class="px-2 py-2 text-right">Nominal</th><th></th></tr></thead>
                                        <tbody>
                                            <template x-for="(row, i) in rows" :key="i">
                                                <tr class="border-t border-gray-100">
                                                    <td class="px-2 py-1.5" x-text="i + 1"></td>
                                                    <td class="px-2 py-1.5"><input type="date" :name="`termin[${i}][jatuh_tempo]`" x-model="row.jatuh_tempo" required class="rounded border-gray-300 text-sm"></td>
                                                    <td class="px-2 py-1.5"><input type="text" :name="`termin[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border-gray-300 text-sm"></td>
                                                    <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" :name="`termin[${i}][nominal]`" x-model="row.nominal" required class="w-32 rounded border-gray-300 text-right text-sm"></td>
                                                    <td class="px-2 py-1.5 text-center"><button type="button" @click="hapus(i)" x-show="rows.length > 1" class="text-red-500">&times;</button></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                        <tfoot class="bg-gray-50"><tr class="border-t border-gray-200 font-medium"><td class="px-2 py-2" colspan="3"><button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah termin</button></td><td class="px-2 py-2 text-right tabular-nums" x-text="fmt(sum)"></td><td></td></tr></tfoot>
                                    </table>
                                </div>
                                <div class="mt-2 flex items-center justify-between">
                                    <span x-show="Math.abs(sum - total) < 0.005" class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">✓ Jumlah termin cocok</span>
                                    <span x-show="Math.abs(sum - total) >= 0.005" class="rounded-full bg-rose-100 px-3 py-1 text-xs font-medium text-rose-700" x-text="'Selisih: ' + fmt(sum - total)"></span>
                                    <button type="submit" :disabled="Math.abs(sum - total) >= 0.005" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Simpan Versi Baru</button>
                                </div>
                            </form>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Riwayat pembayaran --}}
            @if (! empty($d['pembayaran']))
                <div class="mt-5">
                    <h3 class="mb-1 font-semibold text-gray-800">Riwayat pembayaran</h3>
                    <ul class="text-xs text-gray-600">
                        @foreach ($d['pembayaran'] as $p)
                            <li class="flex justify-between border-b border-gray-100 py-1"><span>{{ $tgl($p['tanggal']) }} · {{ $p['nomor'] }} · {{ $p['status'] }}</span><span class="font-mono">@rp($p['nominal'])</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-1 text-xs text-gray-400">Rencana angsuran tidak menerbitkan jurnal — hanya pembayaran nyata yang berjurnal.</p>
                </div>
            @endif

            {{-- Riwayat versi --}}
            @if (! empty($d['riwayat']))
                <div class="mt-5">
                    <h3 class="mb-1 font-semibold text-gray-500">Riwayat kesepakatan (versi lama)</h3>
                    @foreach ($d['riwayat'] as $v)
                        <div class="mb-1 rounded bg-gray-50 px-3 py-2 text-xs"><b>Versi {{ $v['versi'] }}</b> · disepakati {{ $tgl($v['disepakati_pada']) }}@if ($v['alasan']) · diganti: “{{ $v['alasan'] }}”@endif<span class="ml-1 text-gray-400">({{ count($v['termin']) }} termin)</span></div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <script>
        function renegForm(init, total) {
            return {
                rows: init.length ? init : [{ nominal: '', jatuh_tempo: '', keterangan: '' }],
                total,
                get sum() { return this.rows.reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                tambah() { this.rows.push({ nominal: '', jatuh_tempo: '', keterangan: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                fmt(n) { return 'Rp ' + (Math.round(n) || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
