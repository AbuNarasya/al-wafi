@extends('layouts.app')

@section('title', 'Setoran Laundry')

@section('content')
    @php
        $satuan = $jenis?->nama_satuan ?: 'satuan';
        $bolehCatat = \App\Support\Akses::boleh('setoran-laundry', 'buat');
        $bolehTerbit = \App\Support\Akses::boleh('tagihan-lain', 'buat');
        $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    @endphp

    <p class="mb-4 text-sm text-gray-500">
        Dicatat tiap kali santri menyetor. Aplikasi yang menjumlahkan; tagihan terbit sekali di akhir periode,
        <b>hanya atas kelebihan di atas kuota</b>.
    </p>

    @if (! $opsiJenis)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada layanan yang ditagih menurut pemakaian.
            <a href="{{ route('jenis_biaya.create') }}" class="font-medium underline">Tambahkan dulu di Jenis Biaya</a>
            dengan cara menagih &ldquo;pemakaian&rdquo;, lengkap dengan tarif per satuan dan kuotanya.
        </div>
    @else
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Layanan</label>
                <select name="jenis" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiJenis as $kode => $nama)
                        <option value="{{ $kode }}" @selected($kode === $kodeJenis)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            @if ($jenis && $jenis->tarif_satuan)
                <div class="text-xs text-gray-500">
                    Tarif <b>Rp {{ number_format((float) $jenis->tarif_satuan, 0, ',', '.') }}</b> / {{ $satuan }}
                    &middot; kuota gratis <b>{{ $angka($jenis->kuota_gratis ?? 0) }} {{ $satuan }}</b> per periode
                </div>
            @endif
            <noscript><button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
        </form>

        @if ($galat)
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">{{ $galat }}</div>
        @else
            @if ($bolehCatat)
                <form method="POST" action="{{ route('setoran_pemakaian.catat') }}"
                      class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    @csrf
                    <input type="hidden" name="kode_jenis" value="{{ $kodeJenis }}">
                    <div class="grid gap-3 sm:grid-cols-[1fr_2fr_1fr_1fr_auto] sm:items-end">
                        <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                        <x-field name="id_santri" label="Santri" :options="['' => '— pilih santri —'] + $santriAktif" required />
                        <x-field name="kuantitas" :label="'Jumlah (' . $satuan . ')'" type="number" :value="old('kuantitas')" required />
                        <x-field name="catatan" label="Catatan" :value="old('catatan')" placeholder="opsional" />
                        <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-dark">Catat</button>
                    </div>
                </form>
            @endif

            {{-- Rekap berjalan: yang membuat petugas tahu SEBELUM akhir bulan
                 siapa yang sudah mendekati kuotanya. --}}
            <div class="mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-4 py-3">
                    <strong class="text-sm text-gray-900">Rekap berjalan</strong>
                    <span class="text-xs text-gray-400">{{ count($rekap) }} santri &middot; yang belum tertagih saja</span>
                </div>

                @if (! $rekap)
                    <div class="p-10 text-center text-gray-400">Belum ada setoran yang belum tertagih.</div>
                @else
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3 text-left">Santri</th>
                                <th class="px-4 py-3 text-right">Setoran</th>
                                <th class="px-4 py-3 text-right">Total {{ $satuan }}</th>
                                <th class="px-4 py-3 text-right">Kena tagih</th>
                                <th class="px-4 py-3 text-right">Nominal</th>
                                <th class="px-4 py-3 text-left">Kuota</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rekap as $r)
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-gray-800">{{ $r['santri']?->nama }}</div>
                                        <div class="font-mono text-[11px] text-gray-400">{{ $r['santri']?->nis }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-gray-500">{{ $r['jumlah_setoran'] }}&times;</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $angka($r['kuantitas']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $angka($r['kena_tagih']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums font-semibold">
                                        {{ number_format((float) $r['nominal'], 0, ',', '.') }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if ((float) $r['kena_tagih'] > 0)
                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                                lewat {{ $angka($r['kena_tagih']) }} {{ $satuan }}
                                            </span>
                                        @else
                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">
                                                sisa {{ $angka($r['sisa_kuota']) }} {{ $satuan }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($rekap && $bolehTerbit)
                <form method="POST" action="{{ route('setoran_pemakaian.terbitkan') }}"
                      class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                      data-confirm="Terbitkan tagihan atas seluruh setoran yang belum tertagih?">
                    @csrf
                    <input type="hidden" name="kode_jenis" value="{{ $kodeJenis }}">
                    <div class="mb-1 text-sm font-semibold text-gray-900">Terbitkan tagihan periode</div>
                    <p class="mb-3 text-xs text-gray-500">
                        Menyapu seluruh setoran yang <b>belum tertagih</b> sampai akhir periode &mdash; termasuk yang
                        telat dicatat dari bulan sebelumnya, supaya tak ada timbangan yang menguap.
                        Santri yang masih di bawah kuota tidak diterbitkan tagihan, dan setorannya tetap terhitung berikutnya.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <x-field name="periode" label="Periode" :value="old('periode', now()->format('Y-m'))" required placeholder="2026-08" />
                        <x-field name="tanggal" label="Tanggal tagihan" type="date" :value="old('tanggal', now()->toDateString())" required />
                        <x-field name="jatuh_tempo" label="Jatuh Tempo" type="date" :value="old('jatuh_tempo')" hint="Dipakai Reminder Tagihan." />
                        <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" placeholder="Laundry Agustus 2026" />
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan</button>
                    </div>
                </form>
            @endif

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">30 setoran terakhir</div>
                @if ($riwayat->isEmpty())
                    <div class="p-10 text-center text-gray-400">Belum ada setoran.</div>
                @else
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3 text-left">Tanggal</th>
                                <th class="px-4 py-3 text-left">Santri</th>
                                <th class="px-4 py-3 text-right">{{ ucfirst($satuan) }}</th>
                                <th class="px-4 py-3 text-left">Catatan</th>
                                <th class="px-4 py-3 text-left">Dicatat</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($riwayat as $s)
                                <tr class="hover:bg-gray-50/60 {{ $s->id_tagihan ? 'text-gray-400' : '' }}">
                                    <td class="px-4 py-2.5">{{ $s->tanggal?->format('d/m/Y') }}</td>
                                    <td class="px-4 py-2.5">{{ $s->santri?->nama }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $angka($s->kuantitas) }}</td>
                                    <td class="px-4 py-2.5">{{ $s->catatan ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-xs">{{ $s->pencatat?->nama ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right">
                                        @if ($s->id_tagihan)
                                            <span class="text-[11px]">sudah tertagih</span>
                                        @elseif ($bolehCatat)
                                            <form method="POST" action="{{ route('setoran_pemakaian.hapus', $s->id) }}" class="inline"
                                                  data-confirm="Hapus setoran ini?">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-600 hover:underline">Hapus</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    @endif
@endsection
