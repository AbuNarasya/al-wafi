{{--
  Tab PPSB. Semua angka datang dari PpsbDashboardService untuk SATU tahun ajaran.
  Aturan yang berlaku di seluruh tab ini (ditegaskan juga di layar agar tak salah
  baca): hanya pembayaran TERVERIFIKASI yang dihitung, calon yang baru diinput
  tanpa membayar registrasi tidak masuk hitungan mana pun, dan bulan diambil dari
  tanggal pembayaran.
--}}
<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Dashboard PPSB</h2>
            <p class="mt-1 text-sm text-gray-500">
                Angka dihitung dari pembayaran <b>terverifikasi</b>. Calon yang baru diinput tetapi belum membayar registrasi tidak dihitung.
            </p>
        </div>
        <form method="GET" class="flex items-end gap-2">
            <input type="hidden" name="tab" value="ppsb">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500">Tahun Ajaran</label>
                <select name="ta" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-400 px-3 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    @foreach ($opsiTa as $kode => $label)
                        <option value="{{ $kode }}" @selected($ta === $kode)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>

    @unless ($masterSiap)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Belum ada jenis biaya <b>Registrasi</b> / <b>Uang Pangkal</b> yang aktif untuk T.A {{ $ta }} —
            selama itu belum diisi, pendaftar &amp; closing tak akan pernah terhitung.
            Lengkapi di menu PPSB → Jenis Biaya.
        </div>
    @endunless

    {{-- ===== 3 & 4: kartu ringkas + tautan rincian ===== --}}
    @php
        $tautanDetail = fn ($jenis) => route('dashboard', ['tab' => 'ppsb', 'ta' => $ta, 'jalur' => $basisJalur, 'detail' => $jenis]).'#rincian';
        $kartu = [
            ['registrasi', 'Penerimaan Registrasi', $penerimaan['registrasi'], 'text-emerald-700', null],
            ['uang_pangkal', 'Penerimaan Uang Pangkal', $penerimaan['uang_pangkal'], 'text-emerald-700', 'termasuk cicilan termin'],
            ['perlengkapan', 'Penerimaan Perlengkapan', $penerimaan['perlengkapan'] ?? '0', 'text-emerald-700', 'tanpa potongan gelombang'],
            ['total', 'Total Penerimaan', $penerimaan['total'], 'text-brand', null],
            ['outstanding', 'Outstanding Closing', $outstanding['total'], 'text-amber-600', $outstanding['jumlah_santri'].' santri yang sudah mulai membayar'],
        ];
        // Kolom komponen pada rincian: tampil bila kartunya memang komponen itu,
        // atau bila yang dibuka kartu Total (yang memuat ketiganya).
        $kolomKomponen = ['registrasi' => 'Registrasi', 'uang_pangkal' => 'Uang Pangkal', 'perlengkapan' => 'Perlengkapan'];
    @endphp
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($kartu as [$jenis, $label, $nilai, $warna, $catatan])
            <div class="flex flex-col rounded-xl border bg-white p-4 shadow-sm {{ $detail === $jenis ? 'border-brand ring-1 ring-brand' : 'border-gray-200' }}">
                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                <div class="mt-2 text-xl font-bold {{ $warna }}">@rp($nilai)</div>
                @if ($catatan)<div class="text-[11px] text-gray-400">{{ $catatan }}</div>@endif
                <a href="{{ $detail === $jenis ? route('dashboard', ['tab' => 'ppsb', 'ta' => $ta, 'jalur' => $basisJalur]) : $tautanDetail($jenis) }}"
                   class="mt-2 self-start text-xs font-medium text-brand hover:underline">
                    {{ $detail === $jenis ? 'Tutup detail' : 'Lihat detail →' }}
                </a>
            </div>
        @endforeach
    </div>

    {{-- Rincian kartu: daftar santri penyumbang angkanya, tiap baris menuju rekap
         pembayaran santri itu. Hanya dimuat saat diminta. --}}
    @if ($rincian !== null)
        @php
            $judulDetail = ['registrasi' => 'Penerimaan Registrasi', 'uang_pangkal' => 'Penerimaan Uang Pangkal',
                'perlengkapan' => 'Penerimaan Biaya Perlengkapan',
                'total' => 'Total Penerimaan', 'outstanding' => 'Outstanding Closing'][$detail];
        @endphp
        <div id="rincian" class="overflow-hidden rounded-xl border border-brand/40 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-brand-soft/40 px-4 py-2.5">
                <div class="text-sm font-semibold text-gray-700">
                    Rincian {{ $judulDetail }} — T.A {{ $ta }}
                </div>
                <a href="{{ route('dashboard', ['tab' => 'ppsb', 'ta' => $ta, 'jalur' => $basisJalur]) }}"
                   class="text-xs text-gray-500 hover:text-gray-700">Tutup</a>
            </div>

            {{-- Pencarian DIKERJAKAN SERVER lalu dipaginasi: menyaring di browser
                 hanya akan menyaring 25 baris yang kebetulan tampil, dan itu
                 menyesatkan begitu pendaftarnya ratusan. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-2.5">
                <form method="GET" class="flex flex-1 items-center gap-2" style="min-width: 240px">
                    <input type="hidden" name="tab" value="ppsb">
                    <input type="hidden" name="ta" value="{{ $ta }}">
                    <input type="hidden" name="jalur" value="{{ $basisJalur }}">
                    <input type="hidden" name="detail" value="{{ $detail }}">
                    <input type="text" name="cari" value="{{ $cari }}" data-filter-auto
                           placeholder="Cari nomor pendaftaran, NIS, atau nama…"
                           class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                </form>
                @if ($cari !== '')
                    <a href="{{ route('dashboard', ['tab' => 'ppsb', 'ta' => $ta, 'jalur' => $basisJalur, 'detail' => $detail]) }}"
                       class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                @endif
                <span class="text-xs text-gray-500">
                    {{ $rincian->total() }} santri{{ $cari !== '' ? ' cocok' : '' }}
                </span>
                <div class="ml-auto flex items-center gap-1">
                    <x-unduh :url="route('dashboard.ppsb_export', ['jenis' => $detail, 'ta' => $ta, 'cari' => $cari])" label="" />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2">No. Daftar / NIS</th>
                            <th class="px-4 py-2">Nama</th>
                            <th class="px-4 py-2">Jenjang · Jalur</th>
                            @if ($detail === 'outstanding')
                                <th class="px-4 py-2 text-right">Tagihan</th>
                                <th class="px-4 py-2 text-right">Terbayar</th>
                                <th class="px-4 py-2 text-right">Sisa</th>
                                <th class="px-4 py-2">Jatuh Tempo</th>
                            @else
                                @foreach ($kolomKomponen as $k => $labelKolom)
                                    @if ($detail === 'total' || $detail === $k)<th class="px-4 py-2 text-right">{{ $labelKolom }}</th>@endif
                                @endforeach
                                <th class="px-4 py-2 text-right">Total</th>
                                <th class="px-4 py-2 text-right">Bayar</th>
                                <th class="px-4 py-2">Terakhir</th>
                            @endif
                            <th class="px-4 py-2 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rincian as $r)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-medium text-gray-900">{{ $r->no_pendaftaran ?? '—' }}
                                    <div class="text-xs font-normal text-gray-400">{{ $r->nis ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-2">
                                    <a href="{{ route('rekap_pembayaran.show', $r->id) }}" class="text-brand hover:underline">{{ $r->nama }}</a>
                                </td>
                                <td class="px-4 py-2 text-gray-500">{{ $r->jenjang ?? '—' }} · {{ $r->jalur ?? '—' }}</td>
                                @if ($detail === 'outstanding')
                                    <td class="px-4 py-2 text-right tabular-nums">@rp($r->nominal)</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-emerald-700">@rp($r->terbayar)</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums text-amber-700">@rp($r->sisa)</td>
                                    <td class="px-4 py-2 text-gray-500">{{ $r->jatuh_tempo ? \Illuminate\Support\Carbon::parse($r->jatuh_tempo)->format('d/m/Y') : '—' }}</td>
                                @else
                                    @foreach (array_keys($kolomKomponen) as $k)
                                        @if ($detail === 'total' || $detail === $k)<td class="px-4 py-2 text-right tabular-nums">@rp($r->$k)</td>@endif
                                    @endforeach
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums">@rp($r->total)</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-500">{{ $r->jumlah_bayar }}×</td>
                                    <td class="px-4 py-2 text-gray-500">{{ $r->terakhir ? \Illuminate\Support\Carbon::parse($r->terakhir)->format('d/m/Y') : '—' }}</td>
                                @endif
                                <td class="px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('rekap_pembayaran.show', $r->id) }}" class="text-brand hover:underline">Rekap</a>
                                        <a href="{{ route('santri.show', $r->id) }}" class="text-gray-500 hover:underline">Detail</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">
                                {{ $cari !== '' ? 'Tidak ada santri yang cocok dengan pencarian.' : 'Belum ada santri pada rincian ini.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rincian->hasPages())
                <div class="border-t border-gray-100 px-4 py-2">{{ $rincian->onEachSide(1)->links() }}</div>
            @endif
        </div>
    @endif

    @if ($outstanding['per_jenjang'])
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700">
                Outstanding Uang Pangkal per Jenjang
                <span class="ml-1 font-normal text-gray-400">— hanya yang sudah mulai membayar</span>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Jenjang</th><th class="px-4 py-2 text-right">Santri</th><th class="px-4 py-2 text-right">Sisa</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($outstanding['per_jenjang'] as $r)
                        <tr><td class="px-4 py-2">{{ $r['nama'] ?? $r['kode'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $r['santri'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($r['nominal'])</td></tr>
                    @endforeach
                    <tr class="bg-gray-50 font-semibold">
                        <td class="px-4 py-2">Total</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $outstanding['jumlah_santri'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($outstanding['total'])</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    {{-- ===== 1 & 2: tabel + grafik bulanan ===== --}}
    {{-- Grafik membandingkan POLA MUSIMAN antar tahun ajaran; rincian per jenjang
         dibaca di tabel bulanan tepat di bawahnya. --}}
    <div class="space-y-4">
        <x-grafik-tren :tren="$trenPendaftar" judul="Trend Pendaftar per Bulan" satuan="Jumlah Pendaftar" />
        <x-grafik-tren :tren="$trenClosing" judul="Trend Closing per Bulan" satuan="Jumlah Closing" />
    </div>

    @foreach ([['Pendaftar', $pendaftar, 'Dihitung saat registrasi dibayar'], ['Closing', $closing, 'Dihitung saat uang pangkal mulai dibayar']] as [$judul, $tabel, $ket])
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700">
                Total {{ $judul }} per Jenjang — T.A {{ $ta }}
                <span class="ml-1 font-normal text-gray-400">— {{ $ket }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="sticky left-0 bg-gray-50 px-4 py-2 text-left">Jenjang</th>
                            @foreach ($tabel['bulan'] as $b)
                                <th class="px-2 py-2 text-right">{{ $b['label'] }}</th>
                            @endforeach
                            @if ($tabel['ada_luar'])
                                <th class="px-2 py-2 text-right" title="Pembayaran di luar 12 bulan musim penerimaan T.A ini">Di luar rentang</th>
                            @endif
                            <th class="px-4 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($tabel['baris'] as $baris)
                            <tr class="hover:bg-gray-50">
                                <td class="sticky left-0 bg-white px-4 py-2 font-medium text-gray-900">{{ $baris['nama'] ?: $baris['kode'] }}</td>
                                @foreach ($tabel['bulan'] as $b)
                                    <td class="px-2 py-2 text-right tabular-nums {{ $baris['sel'][$b['kunci']] === 0 ? 'text-gray-300' : '' }}">
                                        {{ $baris['sel'][$b['kunci']] }}
                                    </td>
                                @endforeach
                                @if ($tabel['ada_luar'])
                                    <td class="px-2 py-2 text-right tabular-nums {{ $baris['luar'] === 0 ? 'text-gray-300' : 'text-amber-600' }}">{{ $baris['luar'] }}</td>
                                @endif
                                <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ $baris['total'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="px-4 py-8 text-center text-gray-400">Belum ada jenjang terdaftar.</td></tr>
                        @endforelse
                        <tr class="bg-gray-50 font-semibold">
                            <td class="sticky left-0 bg-gray-50 px-4 py-2">Total</td>
                            @foreach ($tabel['bulan'] as $b)
                                <td class="px-2 py-2 text-right tabular-nums">{{ $tabel['total'][$b['kunci']] }}</td>
                            @endforeach
                            @if ($tabel['ada_luar'])
                                <td class="px-2 py-2 text-right tabular-nums text-amber-600">{{ $tabel['total']['luar'] }}</td>
                            @endif
                            <td class="px-4 py-2 text-right tabular-nums text-brand">{{ $tabel['total']['total'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- ===== 5: plan vs aktual ===== --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700">
            Plan vs Aktual per Jenjang &amp; Jenis Kelamin
            <span class="ml-1 font-normal text-gray-400">— aktual = sudah mulai membayar uang pangkal</span>
        </div>
        <div class="overflow-x-auto">
            {{-- Kepala tabel dua tingkat: Ikhwan / Akhwat / Total, masing-masing
                 Plan · Aktual · Pencapaian (mengikuti format yang diminta user). --}}
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th rowspan="2" class="border-r border-gray-200 px-4 py-2 text-left align-middle">Jenjang</th>
                        <th colspan="3" class="border-r border-gray-200 px-3 py-2 text-center">Ikhwan</th>
                        <th colspan="3" class="border-r border-gray-200 px-3 py-2 text-center">Akhwat</th>
                        <th colspan="3" class="px-3 py-2 text-center">Total</th>
                    </tr>
                    <tr>
                        @foreach (['ikhwan', 'akhwat', 'total'] as $grup)
                            <th class="px-3 py-2 text-right font-medium">Plan</th>
                            <th class="px-3 py-2 text-right font-medium">Aktual</th>
                            <th class="px-3 py-2 text-right font-medium {{ $grup !== 'total' ? 'border-r border-gray-200' : '' }}">Pencapaian</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php
                        $sel = fn ($v) => $v === null ? '—' : $v;
                        $pct = fn ($v) => $v === null ? '—' : $v . '%';
                    @endphp
                    @forelse ($plan['baris'] as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="border-r border-gray-100 px-4 py-2 font-medium text-gray-900">
                                {{ $r['nama'] ?: $r['kode'] }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $sel($r['target_l']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $r['aktual_l'] }}</td>
                            <td class="border-r border-gray-100 px-3 py-2 text-right tabular-nums">{{ $pct($r['persen_l']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $sel($r['target_p']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $r['aktual_p'] }}</td>
                            <td class="border-r border-gray-100 px-3 py-2 text-right tabular-nums">{{ $pct($r['persen_p']) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ $r['target'] }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ $r['aktual'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums {{ $r['persen'] !== null && $r['persen'] < 100 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $pct($r['persen']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400">Belum ada target maupun data jenjang.</td></tr>
                    @endforelse
                    <tr class="bg-gray-50 font-semibold">
                        <td class="border-r border-gray-200 px-4 py-2">Total</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['target_l'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['aktual_l'] }}</td>
                        <td class="border-r border-gray-200 px-3 py-2 text-right tabular-nums">{{ $pct($plan['total']['persen_l']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['target_p'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['aktual_p'] }}</td>
                        <td class="border-r border-gray-200 px-3 py-2 text-right tabular-nums">{{ $pct($plan['total']['persen_p']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['target'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $plan['total']['aktual'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-brand">{{ $pct($plan['total']['persen']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        @if (collect($plan['baris'])->contains(fn ($r) => $r['target_l'] === null && $r['target'] > 0))
            <p class="px-4 py-2 text-[11px] text-gray-400">
                Jenjang bertanda &ldquo;—&rdquo; targetnya belum dirinci per jenis kelamin. Lengkapi di menu PPSB → Target Santri.
            </p>
        @endif
    </div>

    {{-- ===== Sebaran per jalur pendaftaran ===== --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5">
            <div class="text-sm font-semibold text-gray-700">
                Jalur Pendaftaran
                <span class="ml-1 font-normal text-gray-400">
                    — {{ $basisJalur === 'pendaftar' ? 'yang sudah membayar registrasi' : 'yang sudah mulai membayar uang pangkal' }}
                </span>
            </div>
            {{-- Basis bisa digeser: sebaran jalur berguna dibaca dua-duanya —
                 minat masuk (pendaftar) vs yang benar-benar mengikat (closing). --}}
            <div class="inline-flex overflow-hidden rounded border border-gray-200 text-xs">
                @foreach (['closing' => 'Closing', 'pendaftar' => 'Pendaftar'] as $kunci => $label)
                    <a href="{{ route('dashboard', ['tab' => 'ppsb', 'ta' => $ta, 'jalur' => $kunci]) }}"
                       class="px-2.5 py-1 {{ $basisJalur === $kunci ? 'bg-brand text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th rowspan="2" class="border-r border-gray-200 px-4 py-2 text-left align-middle">Jalur Pendaftaran</th>
                        <th colspan="{{ count($jalur['jenjang']) + 1 }}" class="border-r border-gray-200 px-3 py-2 text-center">Ikhwan</th>
                        <th colspan="{{ count($jalur['jenjang']) + 1 }}" class="border-r border-gray-200 px-3 py-2 text-center">Akhwat</th>
                        <th rowspan="2" class="px-3 py-2 text-right align-middle">Total</th>
                    </tr>
                    <tr>
                        @foreach (['L', 'P'] as $jk)
                            @foreach ($jalur['jenjang'] as $kode => $nama)
                                <th class="px-3 py-2 text-right font-medium">{{ $nama ?: $kode }}</th>
                            @endforeach
                            <th class="border-r border-gray-200 px-3 py-2 text-right font-medium">Jml</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($jalur['baris'] as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="border-r border-gray-100 px-4 py-2 font-medium text-gray-900">
                                {{ $r['nama'] ?: $r['kode'] }}
                            </td>
                            @foreach (['L', 'P'] as $jk)
                                @foreach ($jalur['jenjang'] as $kode => $nama)
                                    <td class="px-3 py-2 text-right tabular-nums {{ $r['sel'][$jk][$kode] === 0 ? 'text-gray-300' : '' }}">{{ $r['sel'][$jk][$kode] }}</td>
                                @endforeach
                                <td class="border-r border-gray-100 px-3 py-2 text-right font-medium tabular-nums">{{ $r['jumlah'][$jk] }}</td>
                            @endforeach
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $r['total'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 2 * (count($jalur['jenjang']) + 1) + 2 }}" class="px-4 py-8 text-center text-gray-400">Belum ada jalur pendaftaran di master.</td></tr>
                    @endforelse
                    <tr class="bg-gray-50 font-semibold">
                        <td class="border-r border-gray-200 px-4 py-2">Total</td>
                        @foreach (['L', 'P'] as $jk)
                            @foreach ($jalur['jenjang'] as $kode => $nama)
                                <td class="px-3 py-2 text-right tabular-nums">{{ $jalur['total']['sel'][$jk][$kode] }}</td>
                            @endforeach
                            <td class="border-r border-gray-200 px-3 py-2 text-right tabular-nums">{{ $jalur['total']['jumlah'][$jk] }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-right tabular-nums text-brand">{{ $jalur['total']['total'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ===== 6: ranking sumber informasi ===== --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-sm font-semibold text-gray-700">
            Ranking Sumber Informasi
            <span class="ml-1 font-normal text-gray-400">— dari {{ $sumber['total'] }} pendaftar berbayar</span>
        </div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Sumber</th><th class="px-4 py-2 text-right">Jumlah</th><th class="px-4 py-2 text-right">Porsi</th><th class="px-4 py-2">Sebaran</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($sumber['baris'] as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 text-gray-400">{{ $r['peringkat'] }}</td>
                        <td class="px-4 py-2 font-medium text-gray-900">{{ $r['nama'] }}
                            @if ($r['rincian'])
                                <div class="text-xs font-normal text-gray-400">
                                    {{ collect($r['rincian'])->map(fn ($n, $teks) => "{$teks} ({$n})")->implode(', ') }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r['jumlah'] }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $r['persen'] }}%</td>
                        <td class="px-4 py-2">
                            <div class="h-2 w-full max-w-40 rounded-full bg-gray-100">
                                <div class="h-2 rounded-full bg-brand" style="width: {{ $r['persen'] }}%"></div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Belum ada pendaftar berbayar pada T.A ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
