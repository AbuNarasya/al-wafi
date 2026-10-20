@extends('layouts.app')

@section('title', 'Sesi '.$sesi->nomor)

@section('content')
    @php
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $bolehUbah = $sesi->status === 'berjalan' && \App\Support\Akses::boleh('sesi-tarik-tunai', 'ubah');
        $bolehBatal = $sesi->status === 'berjalan' && \App\Support\Akses::boleh('sesi-tarik-tunai', 'hapus');
        $warna = ['diajukan' => 'bg-amber-100 text-amber-800', 'disetujui' => 'bg-emerald-100 text-emerald-800', 'ditolak' => 'bg-red-100 text-red-700',
            'dibatalkan' => 'bg-gray-100 text-gray-500', 'gugur' => 'bg-gray-100 text-gray-500', 'diserahkan' => 'bg-sky-100 text-sky-800', 'tak_diambil' => 'bg-gray-100 text-gray-500'];
    @endphp

    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('tarik_tunai.index') }}" class="text-sm text-gray-500 hover:underline">← Semua sesi</a>
            <h1 class="text-lg font-bold text-gray-900">{{ $sesi->nama }} <span class="font-mono text-sm font-normal text-gray-500">{{ $sesi->nomor }}</span></h1>
            <div class="mt-1 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-sky-800">Tahap: {{ $sesi->labelTahap() }}</span>
                @if ($sesi->status === 'berjalan')
                    <span class="rounded-full px-2 py-0.5 {{ $sesi->dibuka ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">{{ $sesi->dibuka ? 'Terbuka untuk wali' : 'Tertutup untuk wali' }}</span>
                @endif
                @if ($sesi->status === 'batal')
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-red-700">Dibatalkan: {{ $sesi->alasan_batal }}</span>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($bolehUbah)
                <form method="POST" action="{{ route('tarik_tunai.dibuka', $sesi->id_sesi) }}">
                    @csrf<input type="hidden" name="dibuka" value="{{ $sesi->dibuka ? '0' : '1' }}">
                    <button class="rounded-lg px-3 py-2 text-sm font-semibold {{ $sesi->dibuka ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-emerald-600 text-white hover:bg-emerald-700' }}">
                        {{ $sesi->dibuka ? 'Tutup untuk wali' : 'Buka untuk wali' }}</button>
                </form>
            @endif
            @if ($bolehBatal)
                <form method="POST" action="{{ route('tarik_tunai.batal', $sesi->id_sesi) }}" x-data="{ panel: false }" class="relative">
                    @csrf
                    <button type="button" @click="panel = !panel" class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50">Batalkan sesi</button>
                    <div x-show="panel" x-cloak @click.outside="panel = false" class="absolute right-0 z-10 mt-1 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                        <p class="text-xs text-gray-500">Pengajuan yang masih hidup ikut batal; wali dikabari.</p>
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Batalkan sesi</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Batas pengajuan', $sesi->batas_pengajuan->format('d/m/Y')],
            ['Batas persetujuan', $sesi->batas_persetujuan->format('d/m/Y')],
            ['Ambil di bank', $sesi->tanggal_ambil->format('d/m/Y').' · '.$sesi->bank?->nama_rekening],
            ['Distribusi', $sesi->tanggal_distribusi->format('d/m/Y').' · '.$sesi->kas?->nama_rekening],
        ] as [$l, $v])
            <div class="rounded-xl border border-gray-200 bg-white p-3"><div class="text-xs text-gray-500">{{ $l }}</div><div class="text-sm font-semibold text-gray-900">{{ $v }}</div></div>
        @endforeach
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3"><div class="text-xs text-gray-500">Peserta</div><div class="text-xl font-bold tabular-nums">{{ $rekap['peserta'] }}</div><div class="text-xs text-gray-500">maks {{ $rp($sesi->maks_per_santri) }}/santri</div></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3"><div class="text-xs text-amber-800">Menunggu persetujuan</div><div class="text-xl font-bold tabular-nums text-amber-900">{{ $rekap['jumlah']['diajukan'] ?? 0 }}</div><div class="text-xs text-amber-800">{{ $rp($rekap['diajukan']) }}</div></div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3"><div class="text-xs text-emerald-800">Disetujui</div><div class="text-xl font-bold tabular-nums text-emerald-900">{{ $rekap['jumlah']['disetujui'] ?? 0 }}</div><div class="text-xs text-emerald-800">{{ $rp($rekap['disetujui']) }}</div></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><div class="text-xs text-gray-500">Ditolak / gugur / batal</div><div class="text-xl font-bold tabular-nums">{{ ($rekap['jumlah']['ditolak'] ?? 0) + ($rekap['jumlah']['gugur'] ?? 0) + ($rekap['jumlah']['dibatalkan'] ?? 0) }}</div></div>
    </div>

    @php $bolehKerja = \App\Support\Akses::boleh('sesi-tarik-tunai', 'ubah'); @endphp
    @if ($sesi->tahap() === 'siap_ambil')
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm">
            <div class="text-sky-900">Batas persetujuan sudah lewat. Tarik <b>{{ $rp($rekap['disetujui']) }}</b> dari {{ $sesi->bank?->nama_rekening }}, lalu catat di sini.</div>
            <div class="flex gap-2">
                <a href="{{ route('tarik_tunai.rekap', $sesi->id_sesi) }}" class="rounded-lg border border-sky-300 bg-white px-3 py-2 font-semibold text-sky-800 hover:bg-sky-100">Rekap & pecahan</a>
                @if ($bolehKerja)
                    <form method="POST" action="{{ route('tarik_tunai.ambil', $sesi->id_sesi) }}" data-confirm="Catat dana Rp {{ number_format((float) $rekap['disetujui'], 0, ',', '.') }} sudah diambil dari bank? Jurnal D Kas Tunai Kegiatan / K Bank dibukukan.">
                        @csrf<button class="rounded-lg bg-sky-600 px-3 py-2 font-semibold text-white hover:bg-sky-700">Dana sudah diambil</button>
                    </form>
                @endif
            </div>
        </div>
    @elseif ($sesi->status === 'dana_diambil')
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
            <div class="text-emerald-900">
                Dana {{ $rp($sesi->total_diambil) }} diambil {{ $sesi->dana_diambil_pada->format('d/m/Y H:i') }}.
                Diserahkan {{ $rekap['jumlah']['diserahkan'] ?? 0 }} dari {{ ($rekap['jumlah']['diserahkan'] ?? 0) + ($rekap['jumlah']['disetujui'] ?? 0) }} santri.
                <a href="{{ route('tarik_tunai.rekap', $sesi->id_sesi) }}" class="ml-1 underline">Rekap</a>
            </div>
            @if ($bolehKerja)
                <div class="flex flex-wrap items-end gap-2">
                    <a href="{{ route('tarik_tunai.distribusi', $sesi->id_sesi) }}" class="rounded-lg bg-emerald-600 px-3 py-2 font-semibold text-white hover:bg-emerald-700">Buka layar distribusi</a>
                    <form method="POST" action="{{ route('tarik_tunai.selesai', $sesi->id_sesi) }}" class="flex items-end gap-2"
                          data-confirm="Selesaikan sesi? Pengajuan yang belum diambil dilepas dan jurnal penutup dibukukan.">
                        @csrf
                        <div><label class="mb-1 block text-xs text-emerald-900">Uang fisik sisa (dihitung)</label>
                            <input type="number" name="uang_fisik" min="0" step="1" required value="{{ old('uang_fisik') }}" class="w-36 rounded-lg border-gray-300 text-right text-sm"></div>
                        <button class="rounded-lg border border-emerald-600 bg-white px-3 py-2 font-semibold text-emerald-700 hover:bg-emerald-100">Selesaikan sesi</button>
                    </form>
                </div>
            @endif
        </div>
    @elseif ($sesi->status === 'selesai')
        <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
            Selesai {{ $sesi->selesai_pada->format('d/m/Y H:i') }}: diambil {{ $rp($sesi->total_diambil) }} · diserahkan {{ $rp($sesi->total_diserahkan) }}
            · disetor balik {{ $rp($sesi->sisa_disetor) }}. <a href="{{ route('tarik_tunai.rekap', $sesi->id_sesi) }}" class="underline">Rekap</a>
        </div>
    @endif

    @include('tarik-tunai._ubah_jadwal', ['sesi' => $sesi, 'kelas' => 'mb-3'])

    @if ($bolehUbah)
        <details class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm" @if ($errors->any()) open @endif>
            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-800">Ubah data sesi (nama, batas nominal, rekening)</summary>
            <form method="POST" action="{{ route('tarik_tunai.update', $sesi->id_sesi) }}" class="space-y-4 border-t border-gray-100 p-4">
                @csrf @method('PUT')
                @include('tarik-tunai._jadwal', ['sesi' => $sesi, 'tanpaJadwal' => true])
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Simpan</button>
            </form>
        </details>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5">
            <div class="text-sm font-semibold text-gray-800">Peserta & pengajuan</div>
            @if ($bolehUbah)
                <form method="POST" action="{{ route('tarik_tunai.peserta.tambah', $sesi->id_sesi) }}" class="flex items-center gap-2">
                    @csrf
                    <input type="text" name="nis" required placeholder="Tambah peserta: NIS (pisah koma)" class="w-64 rounded-lg border-gray-300 text-sm">
                    <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">Tambah</button>
                </form>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Jenjang</th><th class="px-4 py-2 text-right">Uang saku</th><th class="px-4 py-2 text-right">Diajukan</th><th class="px-4 py-2 text-right">Disetujui</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($peserta as $s)
                        @php $p = $pengajuan->get($s->id); $st = $p?->statusEfektif(); @endphp
                        <tr>
                            <td class="px-4 py-2"><div class="font-medium text-gray-900">{{ $s->nama }}</div><div class="text-xs text-gray-500">{{ $s->nis }}</div></td>
                            <td class="px-4 py-2">{{ $s->jenjang?->nama }}{{ $s->tingkat ? ' · '.$s->tingkat : '' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $rp($s->dompet?->saldo ?? 0) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $p ? $rp($p->nominal) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $p?->nominal_disetujui !== null ? $rp($p->nominal_disetujui) : '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($p)
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $warna[$st] }}">{{ $p->labelStatus() }}</span>
                                    <div class="text-xs text-gray-500">{{ $p->nomor }} · {{ $p->namaPengaju() }}</div>
                                    @if ($st === 'ditolak')<div class="text-xs text-red-700">{{ $p->alasan_tolak }}</div>@endif
                                @else
                                    <span class="text-xs text-gray-400">Belum mengajukan</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if ($bolehUbah && (! $p || ! in_array($p->status, ['diajukan', 'disetujui'], true)))
                                    <form method="POST" action="{{ route('tarik_tunai.peserta.hapus', [$sesi->id_sesi, $s->id]) }}" data-confirm="Keluarkan {{ $s->nama }} dari peserta?">
                                        @csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Keluarkan</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Belum ada peserta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
