@extends('layouts.app')

@section('title', 'Peserta Kegiatan')

@section('content')
    <p class="mb-4 text-sm text-gray-500">
        Kegiatan berbayar hanya ditagihkan kepada santri yang <b>ikut</b>. Nominalnya mengikuti
        <a href="{{ route('tagihan_lain.tarif') }}" class="font-medium text-brand hover:underline">tarif jenjangnya</a>,
        kecuali diberi nominal khusus di sini.
    </p>

    @if (! $opsiJenis)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800 shadow-sm">
            Belum ada jenis biaya yang ditagih menurut kepesertaan.
            <a href="{{ route('jenis_biaya.create') }}" class="font-medium underline">Tambahkan dulu di Jenis Biaya</a>.
        </div>
    @else
        <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Kegiatan</label>
                <select name="jenis" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiJenis as $kode => $nama)
                        <option value="{{ $kode }}" @selected($kode === $kodeJenis)>{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
        </form>

        @if (\App\Support\Akses::boleh('tagihan-lain', 'ubah'))
            <form method="POST" action="{{ route('tagihan_lain.peserta.tambah') }}"
                  class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                @csrf
                <input type="hidden" name="kode_jenis" value="{{ $kodeJenis }}">
                <div class="grid gap-3 sm:grid-cols-[2fr_1fr_auto] sm:items-end">
                    <x-field name="id_santri" label="Tambah peserta" :options="['' => '— pilih santri —'] + $santriAktif" required />
                    <x-field name="nominal" label="Nominal khusus (opsional)" type="number" :value="old('nominal')"
                             hint="Kosongkan untuk mengikuti tarif jenjangnya." />
                    <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-dark">Tambah</button>
                </div>
            </form>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-4 py-3">
                <strong class="text-sm text-gray-900">{{ $jenis?->nama }}</strong>
                <span class="text-xs text-gray-400">{{ count($baris) }} peserta</span>
            </div>

            @if (! $baris)
                <div class="p-10 text-center text-gray-400">Belum ada peserta.</div>
            @else
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Santri</th>
                            <th class="px-4 py-3 text-left">Jenjang</th>
                            <th class="px-4 py-3 text-right">Tarif jenjang</th>
                            <th class="px-4 py-3 text-right">Nominal</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($baris as $b)
                            @php($p = $b['rec'])
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium text-gray-800">{{ $p->santri?->nama }}</div>
                                    <div class="font-mono text-[11px] text-gray-400">{{ $p->santri?->nis }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-gray-600">{{ $p->santri?->kode_jenjang }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-gray-500">
                                    @if ($b['tarif'] === null)
                                        <span class="text-amber-600">tidak ikut</span>
                                    @else
                                        {{ number_format((float) $b['tarif'], 0, ',', '.') }}
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    @if ($b['nominal'] === null)
                                        <span class="text-red-600">belum ada tarif</span>
                                    @else
                                        <b>{{ number_format((float) $b['nominal'], 0, ',', '.') }}</b>
                                    @endif
                                    @if ($b['keringanan'])
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Keringanan</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($p->ikut())
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">Ikut</span>
                                    @else
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500">Berhenti</span>
                                    @endif
                                    @if ($p->santri?->status !== 'aktif')
                                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">Santri tidak aktif</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if (\App\Support\Akses::boleh('tagihan-lain', 'ubah'))
                                        <form method="POST" action="{{ route('tagihan_lain.peserta.status', $p->id) }}" class="inline">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="status" value="{{ $p->ikut() ? 'berhenti' : 'ikut' }}">
                                            <button class="rounded-lg border border-gray-300 px-3 py-1 text-xs hover:bg-gray-50">
                                                {{ $p->ikut() ? 'Hentikan' : 'Ikutkan' }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Penerbitan tak memilih santri dan tak mengetik nominal: keduanya sudah
             ditetapkan di daftar di atas dan di matriks tarif. --}}
        @if ($baris && \App\Support\Akses::boleh('tagihan-lain', 'buat'))
            <form method="POST" action="{{ route('tagihan_lain.peserta.terbitkan') }}"
                  class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                  data-confirm="Terbitkan tagihan untuk seluruh peserta yang berstatus Ikut?">
                @csrf
                <input type="hidden" name="kode_jenis" value="{{ $kodeJenis }}">
                <div class="mb-3 text-sm font-semibold text-gray-900">Terbitkan tagihan kegiatan ini</div>
                <div class="grid gap-3 sm:grid-cols-4">
                    <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                    <x-field name="jatuh_tempo" label="Jatuh Tempo" type="date" :value="old('jatuh_tempo')"
                             hint="Dipakai Reminder Tagihan." />
                    <x-field name="periode" label="Periode (opsional)" :value="old('periode')" placeholder="2026-08" />
                    <x-field name="keterangan" label="Keterangan (opsional)" :value="old('keterangan')" />
                </div>
                <div class="mt-3 flex justify-end">
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan</button>
                </div>
            </form>
        @endif
    @endif
@endsection
