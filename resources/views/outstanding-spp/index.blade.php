@extends('layouts.app')

@section('title', 'Outstanding SPP')

@php
    $bolehUbah = \App\Support\Akses::boleh('outstanding-spp', 'ubah');
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
@endphp

@section('content')
    <div class="mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Daftar Outstanding SPP</h2>
        <p class="mt-1 text-sm text-gray-500">
            Tagihan SPP yang sudah terbit tetapi <b>belum tertutup</b>. Yang terpotong otomatis dari
            saldo prabayar atau Dompet Wali saat penerbitan tidak muncul di sini — dan sebuah baris
            hilang dengan sendirinya begitu tagihannya lunas.
        </p>
    </div>

    {{-- Ringkasan: yang pertama ditanyakan sebelum menyisir daftar. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
            <div class="text-xs font-medium text-rose-700">Sisa Belum Tertagih</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-rose-900">@rp($ringkasan['sisa'])</div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <div class="text-xs font-medium text-amber-700">Menunggu Verifikasi</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-amber-900">@rp($ringkasan['menunggu'])</div>
            <div class="text-[11px] text-amber-700/80">sudah disetor, belum diakui</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div class="text-xs font-medium text-gray-500">Tagihan</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ $ringkasan['baris'] }}</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
            <div class="text-xs font-medium text-gray-500">Santri</div>
            <div class="mt-0.5 text-xl font-bold tabular-nums text-gray-900">{{ $ringkasan['santri'] }}</div>
        </div>
    </div>

    {{-- Rincian per jenjang: angka total sendirian tak memberi tahu ke mana harus
         menagih. Paletnya SAMA dengan rekap di layar SPP dan dibagi menurut urutan
         master yang sama, sehingga "ungu = SMP" berlaku di kedua layar. Kelasnya
         ditulis utuh di sini — Tailwind memindai resources/views, bukan app/. --}}
    @if (count($ringkasan['per_jenjang']) > 1)
        @php
            $paletJenjang = [
                ['blok' => 'border-sky-200 bg-sky-50', 'judul' => 'text-sky-900', 'ket' => 'text-sky-700/70'],
                ['blok' => 'border-violet-200 bg-violet-50', 'judul' => 'text-violet-900', 'ket' => 'text-violet-700/70'],
                ['blok' => 'border-teal-200 bg-teal-50', 'judul' => 'text-teal-900', 'ket' => 'text-teal-700/70'],
                ['blok' => 'border-orange-200 bg-orange-50', 'judul' => 'text-orange-900', 'ket' => 'text-orange-700/70'],
                ['blok' => 'border-fuchsia-200 bg-fuchsia-50', 'judul' => 'text-fuchsia-900', 'ket' => 'text-fuchsia-700/70'],
                ['blok' => 'border-lime-200 bg-lime-50', 'judul' => 'text-lime-900', 'ket' => 'text-lime-700/70'],
            ];
        @endphp
        <div class="mb-4">
            <h3 class="mb-2 text-sm font-semibold text-gray-700">Outstanding per Jenjang</h3>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($ringkasan['per_jenjang'] as $i => $j)
                    @php $w = $paletJenjang[$i % count($paletJenjang)]; @endphp
                    <a href="{{ route('outstanding_spp.index', array_filter([
                            'jenjang' => $j['kode_jenjang'],
                            'periode' => $filter['periode'] ?: null,
                            'q' => $filter['q'] ?: null,
                       ])) }}"
                       class="block rounded-xl border {{ $w['blok'] }} px-4 py-3 transition hover:shadow-sm">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-sm font-semibold {{ $w['judul'] }}">{{ $j['nama'] }}</span>
                            <span class="text-lg font-bold tabular-nums {{ $w['judul'] }}">@rp($j['sisa'])</span>
                        </div>
                        <div class="mt-0.5 text-[11px] {{ $w['ket'] }}">
                            {{ $j['baris'] }} tagihan · {{ $j['santri'] }} santri
                            @if (! \App\Support\Money::isZero($j['menunggu']))
                                · <span class="font-semibold text-amber-700">@rp($j['menunggu']) menunggu</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Penyaring dikerjakan SERVER: daftar ini bisa memuat seluruh santri × seluruh
         periode, jadi menyaringnya di browser hanya menyaring yang sudah dirender. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
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
            <label class="mb-1 block text-xs font-medium text-gray-500">Periode</label>
            <select name="periode" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm">
                <option value="">— semua —</option>
                @foreach ($opsiPeriode as $p)
                    <option value="{{ $p }}" @selected($filter['periode'] === $p)>{{ $p }}</option>
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
            <label class="mb-1 block text-xs font-medium text-gray-500">Cari</label>
            <input type="text" name="q" value="{{ $filter['q'] }}" placeholder="NIS atau nama santri"
                   class="w-56 rounded-lg border-gray-300 px-3 py-1.5 text-sm">
        </div>
        <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Saring</button>
        @if (array_filter($filter))
            <a href="{{ route('outstanding_spp.index') }}" class="px-2 py-1.5 text-sm text-gray-500 hover:underline">Reset</a>
        @endif
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">NIS</th>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang</th>
                    <th class="px-4 py-3">Tingkat</th>
                    <th class="px-4 py-3">T.A</th>
                    <th class="px-4 py-3">Periode</th>
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
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ $r['jenjang'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $r['tingkat'] ? 'Tingkat '.$r['tingkat'] : '—' }}</td>
                        {{-- Tunggakan dari tahun ajaran LAMA ditandai: itulah yang paling
                             perlu terlihat di daftar ini, dan warnanya membuatnya
                             terbaca sebagai lintas tahun, bukan sekadar satu baris lagi. --}}
                        <td class="px-4 py-2">
                            @if ($taBerjalan && $r['tahun_ajaran'] && $r['tahun_ajaran'] !== $taBerjalan)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs font-medium text-rose-800" title="Tunggakan dari tahun ajaran sebelumnya">{{ $r['tahun_ajaran'] }}</span>
                            @else
                                <span class="text-gray-600">{{ $r['tahun_ajaran'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 tabular-nums">{{ $r['periode'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($r['nominal'])</td>
                        <td class="px-4 py-2 text-right tabular-nums text-gray-500">
                            @rp($r['terbayar'])
                            {{-- Setoran yang belum diverifikasi belum mengurangi sisa. Tanpa
                                 baris ini, petugas menagih ulang orang yang sudah membayar. --}}
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
                            {{-- Kenapa tak terpotong otomatis: dua sebab yang paling sering,
                                 dan keduanya butuh tindakan yang berbeda. --}}
                            @if (! $r['auto_debet'])
                                <div class="text-[11px] text-gray-400">auto-debet mati</div>
                            @elseif (\App\Support\Money::isZero($r['saldo_dompet']))
                                <div class="text-[11px] text-amber-700">dompet kosong</div>
                            @else
                                <div class="text-[11px] text-gray-400">dompet @rp($r['saldo_dompet'])</div>
                            @endif
                        </td>
                        @if ($bolehUbah)
                            <td class="px-4 py-2 text-right" x-data="{ buka: false }">
                                <button type="button" @click="buka = !buka" class="text-xs text-brand hover:underline">Edit tagihan</button>
                                <div x-show="buka" x-cloak class="mt-2 w-80 rounded-lg border border-gray-200 bg-gray-50 p-3 text-left">
                                    <form method="POST" action="{{ route('outstanding_spp.koreksi', $r['id_tagihan']) }}" class="space-y-2"
                                          data-confirm="Koreksi nominal SPP {{ $r['nama'] }} periode {{ $r['periode'] }}? Selisihnya diterbitkan sebagai jurnal penyesuaian.">
                                        @csrf @method('PUT')
                                        <label class="block text-xs text-gray-600">Nominal yang Benar <span class="text-red-500">*</span>
                                            <x-input-rupiah name="nominal" required :value="$r['nominal']" class="mt-0.5" />
                                        </label>
                                        <label class="block text-xs text-gray-600">Jatuh Tempo
                                            <input type="date" name="jatuh_tempo" value="{{ $r['jatuh_tempo'] ? \Illuminate\Support\Carbon::parse($r['jatuh_tempo'])->format('Y-m-d') : '' }}"
                                                   class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                        </label>
                                        <label class="block text-xs text-gray-600">Alasan Koreksi <span class="text-red-500">*</span>
                                            <input type="text" name="alasan" required placeholder="mis. salah ketik nol"
                                                   class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                        </label>
                                        <p class="text-[11px] text-gray-500">
                                            SPP diakui saat terbit, jadi selisihnya <b>diterbitkan sebagai jurnal penyesuaian</b> —
                                            bukan ditimpa diam-diam. Nominal <b>0</b> sah: itulah cara membebaskan santri yang telanjur tertagih.
                                        </p>
                                        <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800">Simpan Koreksi</button>
                                    </form>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $bolehUbah ? 12 : 11 }}" class="px-4 py-12 text-center text-sm text-gray-400">
                            @if (array_filter($filter))
                                Tidak ada tagihan SPP yang cocok dengan penyaring ini.
                            @else
                                Tidak ada tagihan SPP yang menggantung. Semuanya sudah lunas.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
