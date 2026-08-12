{{-- Tab "Tagihan Santri Aktif" — keadaan piutang kesantrian dalam satu layar.
     Seluruh angka HANYA memuat santri berstatus aktif; alumni & santri keluar
     ditagih dengan cara berbeda dan mencampurnya membuat angkanya tak bisa
     ditindaklanjuti. --}}

@php
    $r = $ringkasan;
    $sisaAging = collect($aging)->sum(fn ($a) => (float) $a['sisa']);
@endphp

<div class="space-y-4">

    {{-- Penyaring tahun ajaran --}}
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="tab" value="kesantrian">
        <label class="text-xs font-medium text-gray-500">Tahun Ajaran</label>
        <select name="ta" onchange="this.form.submit()"
                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
            @foreach ($opsiTa as $kode => $label)
                <option value="{{ $kode }}" @selected($ta === $kode)>{{ $label }}</option>
            @endforeach
            <option value="semua" @selected($ta === 'semua')>Semua tahun (piutang total)</option>
        </select>
    </form>

    {{-- Blok 1 — ringkasan uang & orang --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Total Tagihan</div>
            <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900">@rp($r['tagihan'])</div>
            <div class="mt-1 text-xs text-gray-400">{{ number_format($r['jumlah_tagihan'], 0, ',', '.') }} baris tagihan</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Sudah Terbayar</div>
            <div class="mt-1 text-xl font-semibold tabular-nums text-emerald-700">@rp($r['terbayar'])</div>
            <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100">
                <div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ min(100, $r['persen']) }}%"></div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Sisa Tunggakan</div>
            <div class="mt-1 text-xl font-semibold tabular-nums {{ (float) $r['sisa'] > 0 ? 'text-red-600' : 'text-gray-900' }}">@rp($r['sisa'])</div>
            <div class="mt-1 text-xs text-gray-400">{{ number_format($r['menunggak'], 0, ',', '.') }} santri menunggak</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Tertagih</div>
            <div class="mt-1 text-xl font-semibold tabular-nums text-gray-900">{{ number_format($r['persen'], 1, ',', '.') }}%</div>
            <div class="mt-1 text-xs text-gray-400">
                {{ number_format($r['lunas_semua'], 0, ',', '.') }} dari {{ number_format($r['santri_aktif'], 0, ',', '.') }} santri lunas
            </div>
        </div>
    </div>

    {{-- Tunggakan tahun ajaran LAMA — sengaja menonjol. Di pesantren, tunggakan
         tahun sebelumnya sering yang terbesar dan paling terlupakan. --}}
    @if (! empty($tahunLama))
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
            <div class="text-sm font-semibold text-amber-800">Tunggakan Tahun Ajaran Sebelumnya</div>
            <p class="mt-0.5 text-xs text-amber-700">
                Tidak termasuk dalam angka di atas. Masih melekat pada santri yang sekarang aktif.
            </p>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                @foreach ($tahunLama as $t)
                    <div>
                        <div class="text-xs text-amber-700">T.A {{ $t['tahun_ajaran'] }} · {{ $t['santri'] }} santri</div>
                        <div class="font-semibold tabular-nums text-amber-900">@rp($t['sisa'])</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">

        {{-- Blok 2 — per perilaku biaya --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-gray-800">Tunggakan per Jenis Biaya</h3>
                <p class="text-xs text-gray-400">Tiap jenis ditagih dengan cara & oleh orang yang berbeda.</p>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Jenis</th>
                        <th class="px-4 py-2 text-right">Tagihan</th>
                        <th class="px-4 py-2 text-right">Sisa</th>
                        <th class="px-4 py-2 text-right">Tertagih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($perPerilaku as $p)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                {{ $p['label'] }}
                                <div class="text-xs text-gray-400">{{ $p['santri'] }} santri</div>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">@rp($p['tagihan'])</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ (float) $p['sisa'] > 0 ? 'font-medium text-red-600' : 'text-gray-400' }}">@rp($p['sisa'])</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ number_format($p['persen'], 1, ',', '.') }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Belum ada tagihan pada tahun ajaran ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Blok 3 — umur tunggakan --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800">Umur Tunggakan</h3>
            <p class="mb-3 text-xs text-gray-400">Menentukan siapa yang ditagih lebih dulu.</p>
            <div class="space-y-2">
                @foreach ($aging as $a)
                    @php
                        $lebar = $sisaAging > 0 ? ((float) $a['sisa'] / $sisaAging) * 100 : 0;
                        $warna = match ($a['kunci']) {
                            'belum' => 'bg-gray-300',
                            'd30' => 'bg-amber-400',
                            'd60' => 'bg-orange-500',
                            'd90' => 'bg-red-500',
                            default => 'bg-red-700',
                        };
                    @endphp
                    <div>
                        <div class="flex items-baseline justify-between gap-2 text-xs">
                            <span class="{{ $a['kunci'] === 'lebih' ? 'font-semibold text-red-700' : 'text-gray-600' }}">
                                {{ $a['label'] }}
                                <span class="text-gray-400">({{ $a['jumlah'] }})</span>
                            </span>
                            <span class="tabular-nums font-medium text-gray-800">@rp($a['sisa'])</span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full bg-gray-100">
                            <div class="h-2 rounded-full {{ $warna }}" style="width: {{ $lebar }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Blok 6 — dompet cukup tapi masih menunggak. Ditaruh menonjol karena
         inilah yang paling langsung menghasilkan uang: dananya sudah ada di kas
         pesantren, tagihannya tinggal ditutup. --}}
    @if ($dompetCukup['jumlah_wali'] > 0)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold text-emerald-900">Saldo Dompet Cukup, Tagihan Masih Menunggak</h3>
                <span class="text-sm font-semibold tabular-nums text-emerald-900">@rp($dompetCukup['tertutupi'])</span>
            </div>
            <p class="mt-0.5 text-xs text-emerald-700">
                {{ $dompetCukup['jumlah_wali'] }} wali · {{ $dompetCukup['jumlah_santri'] }} santri. Uangnya sudah ada di kas
                pesantren — kemungkinan auto-debet belum menyala atau gagal berjalan.
            </p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wide text-emerald-700">
                        <tr>
                            <th class="py-1 pr-4">Wali</th>
                            <th class="py-1 pr-4 text-right">Saldo Dompet</th>
                            <th class="py-1 text-right">Tunggakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-emerald-100">
                        @foreach ($dompetCukup['baris'] as $d)
                            <tr>
                                <td class="py-1.5 pr-4">
                                    {{ $d['nama'] }}
                                    <span class="text-xs text-emerald-600">· {{ $d['santri'] }} santri</span>
                                </td>
                                <td class="py-1.5 pr-4 text-right tabular-nums text-emerald-800">@rp($d['saldo'])</td>
                                <td class="py-1.5 text-right tabular-nums font-medium text-emerald-900">@rp($d['sisa'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">

        {{-- Blok 4 — sebaran per jenjang --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-gray-800">Sebaran per Jenjang</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Jenjang</th>
                        <th class="px-4 py-2 text-right">Santri</th>
                        <th class="px-4 py-2 text-right">Tagihan</th>
                        <th class="px-4 py-2 text-right">Sisa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($perJenjang as $j)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $j['nama'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $j['santri'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">@rp($j['tagihan'])</td>
                            <td class="px-4 py-2 text-right tabular-nums {{ (float) $j['sisa'] > 0 ? 'font-medium text-red-600' : 'text-gray-400' }}">@rp($j['sisa'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Blok 5 — penunggak terbesar --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-gray-800">Sepuluh Tunggakan Terbesar</h3>
                <p class="text-xs text-gray-400">Tertaut ke rekap pembayaran masing-masing.</p>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Santri</th>
                        <th class="px-4 py-2 text-right">Tagihan</th>
                        <th class="px-4 py-2 text-right">Sisa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($penunggak as $p)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <a href="{{ route('rekap_pembayaran.show', $p['id']) }}"
                                   class="text-brand hover:underline">{{ $p['nama'] }}</a>
                                <div class="text-xs text-gray-400">{{ $p['nis'] ?: 'NIS belum terbit' }}</div>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $p['jumlah_tagihan'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium text-red-600">@rp($p['sisa'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">Tak ada tunggakan. </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
