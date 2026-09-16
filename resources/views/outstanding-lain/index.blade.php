@extends('layouts.app')

@section('title', 'Outstanding Tagihan Lain')

@php
    $bolehUbah = \App\Support\Akses::boleh('outstanding-lain', 'ubah');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Daftar Outstanding Tagihan Lain</h2>
        <p class="mt-1 text-sm text-gray-500">
            Tagihan <b>lain-lain</b> (laundry, ekskul, seragam, insidental) dan <b>daftar ulang</b> yang sudah
            terbit tetapi belum tertutup — inilah yang dihitung penanda tugas
            “Pembayaran SPP &amp; Tagihan Lain”. Sebuah baris hilang dengan sendirinya begitu tagihannya lunas.
            SPP punya layarnya sendiri di <a href="{{ route('outstanding_spp.index') }}" class="text-brand hover:underline">Daftar Outstanding SPP</a>.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- Ringkasan: yang pertama ditanyakan sebelum menyisir daftar. Kartu "lewat
         jatuh tempo" ditaruh paling depan karena itulah pekerjaan yang mendesak —
         penanda tugas menghitung hal yang sama. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('outstanding_lain.index', ['tempo' => 'lewat']) }}"
           class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 transition hover:shadow-sm">
            <div class="text-xs font-medium text-rose-700">Lewat Jatuh Tempo</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-rose-900">@rp($ringkasan['lewat_sisa'])</div>
            <div class="text-[11px] text-rose-700/80">{{ $ringkasan['lewat'] }} tagihan · klik untuk menyaring</div>
        </a>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div class="text-xs font-medium text-gray-500">Seluruh Sisa</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">@rp($ringkasan['sisa'])</div>
            <div class="text-[11px] text-gray-400">{{ $ringkasan['baris'] }} tagihan · {{ $ringkasan['santri'] }} santri</div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="text-xs font-medium text-amber-700">Menunggu Verifikasi</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-amber-900">@rp($ringkasan['menunggu'])</div>
            <div class="text-[11px] text-amber-700/80">sudah disetor, belum diakui</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div class="text-xs font-medium text-gray-500">Jenis Tagihan</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ count($ringkasan['per_jenis']) }}</div>
            <div class="text-[11px] text-gray-400">yang sedang menunggak</div>
        </div>
    </div>

    {{-- Dipecah per JENIS, bukan per jenjang seperti layar SPP: di sini satu santri
         bisa menunggak beberapa hal sekaligus, dan tindakan untuk laundry berbeda
         dari tindakan untuk daftar ulang. Kelas Tailwind ditulis utuh — pemindainya
         membaca resources/views, bukan app/. --}}
    @if (count($ringkasan['per_jenis']) > 1)
        @php
            $palet = [
                ['blok' => 'border-sky-200 bg-sky-50', 'judul' => 'text-sky-900', 'ket' => 'text-sky-700/70'],
                ['blok' => 'border-violet-200 bg-violet-50', 'judul' => 'text-violet-900', 'ket' => 'text-violet-700/70'],
                ['blok' => 'border-teal-200 bg-teal-50', 'judul' => 'text-teal-900', 'ket' => 'text-teal-700/70'],
                ['blok' => 'border-orange-200 bg-orange-50', 'judul' => 'text-orange-900', 'ket' => 'text-orange-700/70'],
                ['blok' => 'border-fuchsia-200 bg-fuchsia-50', 'judul' => 'text-fuchsia-900', 'ket' => 'text-fuchsia-700/70'],
                ['blok' => 'border-lime-200 bg-lime-50', 'judul' => 'text-lime-900', 'ket' => 'text-lime-700/70'],
            ];
        @endphp
        <div class="mb-4">
            <h3 class="mb-2 text-sm font-semibold text-gray-700">Outstanding per Jenis Tagihan</h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($ringkasan['per_jenis'] as $i => $j)
                    @php $w = $palet[$i % count($palet)]; @endphp
                    <a href="{{ route('outstanding_lain.index', array_filter([
                            'jenis' => $j['kode_jenis'],
                            'jenjang' => $filter['jenjang'] ?: null,
                            'tempo' => $filter['tempo'] ?: null,
                            'q' => $filter['q'] ?: null,
                       ])) }}"
                       class="block rounded-xl border {{ $w['blok'] }} px-4 py-3 transition hover:shadow-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-semibold {{ $w['judul'] }}">{{ $j['nama'] }}</span>
                            <span class="text-lg font-bold tabular-nums {{ $w['judul'] }}">@rp($j['sisa'])</span>
                        </div>
                        <div class="mt-0.5 text-[11px] {{ $w['ket'] }}">
                            {{ $j['baris'] }} tagihan · {{ $j['jumlah_santri'] }} santri
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Penyaring dikerjakan SERVER: daftar ini bisa memuat seluruh santri, jadi
         menyaringnya di browser hanya menyaring yang sudah dirender. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Jenis Tagihan</label>
            <select name="jenis" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiJenis as $kode => $nama)
                    <option value="{{ $kode }}" @selected($filter['jenis'] === $kode)>{{ $nama }}</option>
                @endforeach
            </select>
        </div>
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
            <label class="mb-1 block text-xs font-medium text-gray-500">Tahun Ajaran</label>
            <select name="tahun_ajaran" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiTahunAjaran as $t)
                    <option value="{{ $t }}" @selected($filter['tahun_ajaran'] === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Jatuh Tempo</label>
            <select name="tempo" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                <option value="lewat" @selected($filter['tempo'] === 'lewat')>Sudah lewat</option>
                <option value="tanpa" @selected($filter['tempo'] === 'tanpa')>Tanpa jatuh tempo</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-500">Cari</label>
            <input type="text" name="q" value="{{ $filter['q'] }}" placeholder="NIS atau nama santri"
                   class="w-56 rounded-lg border-gray-300 px-3 py-1.5 text-sm">
        </div>
        <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Saring</button>
        @if (array_filter($filter))
            <a href="{{ route('outstanding_lain.index') }}" class="px-2 py-1.5 text-sm text-gray-500 hover:underline">Reset</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">NIS</th>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang</th>
                    <th class="px-4 py-3">Tagihan</th>
                    <th class="px-4 py-3">T.A</th>
                    <th class="px-4 py-3 text-right">Nominal</th>
                    <th class="px-4 py-3 text-right">Terbayar</th>
                    <th class="px-4 py-3 text-right">Sisa</th>
                    <th class="px-4 py-3">Jatuh Tempo</th>
                    <th class="px-4 py-3">Wali</th>
                    @if ($bolehUbah)<th class="px-4 py-3"></th>@endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($daftar as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-600">{{ $r['nis'] ?: '—' }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('santri.show', $r['id_santri']) }}" class="text-brand hover:underline">{{ $r['nama'] }}</a>
                            {{-- Santri yang sudah tidak aktif tetap boleh menunggak, dan
                                 tindakannya berbeda — ditagih lewat wali, bukan lewat asrama. --}}
                            @if ($r['status_santri'] !== 'aktif')
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">{{ ucfirst(str_replace('_', ' ', $r['status_santri'] ?? '—')) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ $r['jenjang'] ?: '—' }}
                            @if ($r['tingkat'])<div class="text-[11px] text-gray-400">Tingkat {{ $r['tingkat'] }}</div>@endif
                        </td>
                        <td class="px-4 py-2">
                            {{ $r['jenis'] }}
                            @if ($r['periode'])<span class="text-gray-400">{{ $r['periode'] }}</span>@endif
                            @if ($r['keterangan'])<div class="text-[11px] text-gray-400">{{ $r['keterangan'] }}</div>@endif
                            @if ($r['saldo_awal'])
                                <span class="mt-0.5 inline-block rounded-full bg-sky-100 px-2 py-0.5 text-[11px] text-sky-700">saldo awal</span>
                            @endif
                        </td>
                        {{-- Tunggakan dari tahun ajaran LAMA ditandai: itulah yang paling
                             perlu terlihat, dan warnanya membuatnya terbaca sebagai lintas
                             tahun, bukan sekadar satu baris lagi. --}}
                        <td class="px-4 py-2">
                            @if ($taBerjalan && $r['tahun_ajaran'] && $r['tahun_ajaran'] !== $taBerjalan)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-medium text-rose-800" title="Tunggakan dari tahun ajaran sebelumnya">{{ $r['tahun_ajaran'] }}</span>
                            @else
                                <span class="text-gray-600">{{ $r['tahun_ajaran'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['nominal'])</td>
                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">
                            @rp($r['terbayar'])
                            @if (! \App\Support\Money::isZero($r['menunggu']))
                                <div class="text-[11px] font-medium text-amber-700">+@rp($r['menunggu']) menunggu</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums text-rose-700">@rp($r['sisa'])</td>
                        <td class="px-4 py-2">
                            {{ $tgl($r['jatuh_tempo']) }}
                            @if ($r['hari_lewat'] !== null && $r['hari_lewat'] > 0)
                                <span class="ml-1 rounded bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-700">lewat {{ $r['hari_lewat'] }} hari</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-600">
                            {{ $r['nama_wali'] ?? '—' }}
                            <div class="text-gray-400">{{ $r['telepon_wali'] }}</div>
                            {{-- Kenapa tak terpotong otomatis. Untuk tagihan lain-lain,
                                 dompet yang isinya KURANG dari sisa tak menolong sama
                                 sekali — jenis ini harus dilunasi sekaligus. --}}
                            @if (! $r['auto_debet'])
                                <div class="text-[11px] text-gray-400">auto-debet mati</div>
                            @elseif ($r['perilaku'] === 'lain' && ! $r['dompet_cukup'])
                                <div class="text-[11px] text-amber-700">dompet @rp($r['saldo_dompet']) — kurang, harus lunas sekaligus</div>
                            @elseif (\App\Support\Money::isZero($r['saldo_dompet']))
                                <div class="text-[11px] text-amber-700">dompet kosong</div>
                            @else
                                <div class="text-[11px] text-emerald-700">dompet @rp($r['saldo_dompet']) — cukup</div>
                            @endif
                        </td>
                        @if ($bolehUbah)
                            <td class="px-4 py-2 text-right" x-data="{ buka: false }">
                                <button type="button" @click="buka = !buka" class="text-xs text-brand hover:underline">Edit tagihan</button>
                                <div x-show="buka" x-cloak class="mt-2 w-80 rounded-lg border border-gray-200 bg-gray-50 p-3 text-left">
                                    {{-- @rp() TIDAK dipakai di dalam atribut — ia mengeluarkan
                                         <span class="rp"> dan kutipnya menutup atributnya lebih awal. --}}
                                    <form method="POST" action="{{ route('outstanding_lain.koreksi', $r['id_tagihan']) }}" class="space-y-2"
                                          data-confirm="Koreksi tagihan {{ $r['jenis'] }} untuk {{ $r['nama'] }} (kini Rp {{ number_format((float) $r['nominal'], 0, ',', '.') }})?">
                                        @csrf @method('PUT')
                                        <label class="block text-xs text-gray-600">Nominal yang Benar <span class="text-red-500">*</span>
                                            <x-input-rupiah name="nominal" required :value="$r['nominal']" class="mt-0.5" />
                                        </label>
                                        <label class="block text-xs text-gray-600">Jatuh Tempo
                                            <input type="date" name="jatuh_tempo" value="{{ $r['jatuh_tempo'] ? \Illuminate\Support\Carbon::parse($r['jatuh_tempo'])->format('Y-m-d') : '' }}"
                                                   class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                        </label>
                                        <label class="block text-xs text-gray-600">Alasan <span class="text-red-500">*</span>
                                            <input type="text" name="alasan" required placeholder="mis. santri tidak ikut laundry bulan ini"
                                                   class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                        </label>
                                        <p class="text-[11px] leading-relaxed text-gray-500">
                                            Mengubah <b>nominal</b> menerbitkan jurnal penyesuaian bila tagihannya sudah diakrualkan;
                                            kelebihan bayar masuk <b>Dompet Wali</b>. Nominal <b>0</b> sah — itulah cara membatalkan
                                            tagihan yang telanjur terbit. Mengubah <b>jatuh tempo</b> saja tidak menyentuh buku besar.
                                        </p>
                                        <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800">Simpan</button>
                                    </form>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $bolehUbah ? 11 : 10 }}" class="px-4 py-12 text-center text-sm text-gray-400">
                            @if (array_filter($filter))
                                Tidak ada tagihan yang cocok dengan penyaring ini.
                            @else
                                Tidak ada tagihan lain-lain atau daftar ulang yang menggantung. Semuanya sudah lunas.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
