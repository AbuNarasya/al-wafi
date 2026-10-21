@extends('layouts.app')

@section('title', 'Kunjungan '.$k->nomor)

@section('content')
    @php
        $s = $k->santri;
        $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m/Y H:i');
        $baris = fn ($l, $v) => $v !== null && $v !== '' ? '<tr><td class="w-40 py-1 pr-3 align-top text-gray-500">'.e($l).'</td><td class="py-1">'.nl2br(e($v)).'</td></tr>' : '';
    @endphp
    <a href="{{ route('klinik.index') }}" class="text-sm text-brand hover:underline">&larr; Klinik</a>
    <div class="mb-4 mt-1 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $s->nama }} <span class="font-mono text-sm font-normal text-gray-500">{{ $k->nomor }}</span></h1>
            <p class="text-sm text-gray-600">{{ $s->nis }} · {{ $s->jenjang?->nama }}{{ $s->kelas ? ' · '.$s->kelas->nama : '' }} ·
                <a href="{{ route('klinik.riwayat', $s->id) }}" class="text-brand hover:underline">riwayat klinik</a></p>
        </div>
        <span @class(['rounded-full px-3 py-1 text-sm font-semibold', 'bg-amber-50 text-amber-700' => $k->status === 'dirawat', 'bg-emerald-50 text-emerald-700' => $k->status === 'selesai'])>
            {{ $k->status === 'dirawat' ? 'Sedang dirawat' : 'Selesai' }}</span>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm lg:col-span-2">
            <table class="w-full">
                {!! $baris('Datang', $jam($k->datang_pada)) !!}
                {!! $baris('Keluhan', $k->keluhan) !!}
                {!! $baris('Tanda vital', collect([$k->suhu ? "Suhu {$k->suhu}°C" : null, $k->tensi ? "Tensi {$k->tensi}" : null, $k->nadi ? "Nadi {$k->nadi}/mnt" : null, $k->berat ? "Berat {$k->berat} kg" : null])->filter()->join(' · ')) !!}
                {!! $baris('Pemeriksaan', $k->pemeriksaan) !!}
                {!! $baris('Tindakan', $k->tindakan) !!}
                {!! $baris('Obat', $k->obat->map(fn ($o) => trim($o->nama.' '.($o->dosis ? "({$o->dosis})" : '').' '.($o->jumlah ? "× {$o->jumlah}" : '')))->join("\n")) !!}
                {!! $baris('Tindak lanjut', $k->labelTindakLanjut().($k->rujukan_ke ? " — {$k->rujukan_ke}" : '')) !!}
                {!! $baris('Selesai', $jam($k->selesai_pada)) !!}
                {!! $baris('Catatan selesai', $k->catatan_selesai) !!}
                {!! $baris('Dicatat oleh', $k->pencatat?->nama) !!}
            </table>
        </div>

        <div class="space-y-4">
            @if ($k->status === 'dirawat' && \App\Support\Akses::boleh('klinik', 'ubah'))
                <form method="POST" action="{{ route('klinik.selesai', $k->id) }}" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                    @csrf
                    <p class="mb-2 font-semibold text-amber-800">Selesai dirawat</p>
                    <textarea name="catatan" rows="2" placeholder="Catatan (kondisi saat pulang ke asrama)" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
                    <button class="mt-2 rounded-lg bg-brand px-3 py-1.5 font-semibold text-white">Selesai / pulang ke asrama</button>
                </form>
            @endif

            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <p class="mb-2 font-semibold text-gray-900">Tagihan ke wali</p>
                @if ($k->tagihan)
                    <p>{{ $k->tagihan->keterangan }} — <b>Rp {{ number_format((float) $k->tagihan->nominal, 0, ',', '.') }}</b>
                        <span class="text-xs text-gray-500">({{ $k->tagihan->status }})</span></p>
                @elseif (\App\Support\Akses::boleh('klinik', 'buat') && \App\Support\Akses::boleh('tagihan-lain', 'buat'))
                    @if ($jenisBiaya->isEmpty())
                        <p class="text-xs text-amber-700">Belum ada jenis biaya bertipe Lain-lain (mis. "Biaya Klinik") di master Jenis Biaya.</p>
                    @else
                        <form method="POST" action="{{ route('klinik.tagihan', $k->id) }}" class="space-y-2" data-confirm="Terbitkan tagihan ini ke wali {{ $s->nama }}?">
                            @csrf
                            <select name="kode_jenis" class="w-full rounded-lg border-gray-300 text-sm">@foreach ($jenisBiaya as $kode => $n)<option value="{{ $kode }}">{{ $n }}</option>@endforeach</select>
                            <input name="nominal" type="number" min="1" required placeholder="Nominal (Rp)" class="w-full rounded-lg border-gray-300 text-sm">
                            <input name="keterangan" maxlength="255" placeholder="Keterangan (mis. obat khusus, rujukan RS)" class="w-full rounded-lg border-gray-300 text-sm">
                            <button class="rounded-lg border border-brand px-3 py-1.5 font-semibold text-brand">Terbitkan tagihan</button>
                        </form>
                    @endif
                @else
                    <p class="text-gray-400">Tidak ada.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
