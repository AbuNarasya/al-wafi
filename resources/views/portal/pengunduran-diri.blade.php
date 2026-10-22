@extends('layouts.portal')

@section('title', 'Pengunduran diri')
@section('kembali', route('portal.anak', $santri->id))

@php
    $punyaPin = \App\Services\PortalWali\SesiWali::punyaPin();
    $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('j M Y, H:i');
    $tahap = $p ? [
        ['Diajukan wali', $p->created_at, null, true],
        ['Verifikasi wali kelas', $p->wali_kelas_pada, $p->wali_kelas_catatan, (bool) $p->wali_kelas_pada],
        ['Persetujuan kepala sekolah', $p->kepala_sekolah_pada, $p->kepala_sekolah_catatan, (bool) $p->kepala_sekolah_pada],
        ['Clearance keuangan', $p->keuangan_pada, null, (bool) $p->keuangan_pada],
        ['Clearance kesantrian', $p->kesantrian_pada, null, (bool) $p->kesantrian_pada],
        ['Clearance administrasi sekolah', $p->admin_pada, null, (bool) $p->admin_pada],
        ['Santri dikeluarkan', $p->dikeluarkan_pada, null, (bool) $p->dikeluarkan_pada],
    ] : [];
    $warna = in_array($p?->status, ['ditolak', 'dikembalikan', 'dibatalkan'], true) ? 'bg-amber-50 text-amber-800 ring-amber-200'
        : ($p?->status === 'selesai' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-blue-50 text-brand ring-blue-200');
@endphp

@section('content')
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Pengunduran diri</p>
        <p class="mt-1 text-lg font-bold text-slate-900">{{ $santri->nama }}</p>
        <p class="text-sm text-slate-500">{{ $santri->nis }}{{ $santri->kelas ? ' · kelas '.$santri->kelas->nama : '' }}</p>
    </div>

    @if ($p)
        <div class="mt-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex items-start justify-between gap-2">
                <p class="font-mono text-xs text-slate-500">{{ $p->nomor }}</p>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 {{ $warna }}">{{ $p->labelStatus() }}</span>
            </div>
            <p class="mt-2 text-sm text-slate-700">{{ $p->alasan }}</p>
            @if ($p->status === 'dikembalikan' && $p->wali_kelas_catatan)
                <p class="mt-2 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">Catatan wali kelas: {{ $p->wali_kelas_catatan }}</p>
            @elseif ($p->status === 'ditolak')
                <p class="mt-2 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">Catatan kepala sekolah: {{ $p->kepala_sekolah_catatan }}</p>
            @endif

            <ol class="mt-4 space-y-2">
                @foreach ($tahap as [$nama, $pada, $catatan, $beres])
                    <li class="flex items-start gap-3 text-sm">
                        <span @class(['mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold',
                            'bg-emerald-500 text-white' => $beres, 'bg-slate-200 text-slate-500' => ! $beres])>{{ $beres ? '✓' : '' }}</span>
                        <span class="min-w-0"><span @class(['font-medium', 'text-slate-900' => $beres, 'text-slate-500' => ! $beres])>{{ $nama }}</span>
                            @if ($pada)<span class="block text-xs text-slate-500">{{ $tgl($pada) }}</span>@endif</span>
                    </li>
                @endforeach
            </ol>

            @if ($p->dokumen->isNotEmpty())
                <p class="mt-4 text-xs font-semibold uppercase tracking-widest text-slate-500">Dokumen</p>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($p->dokumen as $d)
                        <li><a href="{{ route('portal.mundur.dokumen', $d->id) }}" class="font-medium text-brand">{{ \App\Models\DokumenPengajuanKeluar::JENIS[$d->jenis] ?? $d->jenis }}</a>
                            <span class="text-xs text-slate-500">{{ $d->keterangan }}</span></li>
                    @endforeach
                </ul>
            @endif

            @if (in_array($p->status, ['menunggu_wali_kelas', 'dikembalikan', 'menunggu_kepala_sekolah'], true))
                <form method="POST" action="{{ route('portal.mundur.batal', $santri->id) }}" class="mt-4 text-center" data-no-confirm
                      x-data="pinPad(@js($punyaPin), @js(route('portal.pin')))" @submit.prevent="buka()">
                    @csrf
                    <button class="text-sm font-semibold text-red-700">Batalkan pengajuan</button>
                    @include('portal._pin', ['judul' => 'Batalkan pengajuan pengunduran diri'])
                </form>
            @endif
        </div>
    @endif

    @if ($bolehAjukan || $p?->status === 'dikembalikan')
        @php $ulang = $p?->status === 'dikembalikan'; @endphp
        <form method="POST" action="{{ $ulang ? route('portal.mundur.ulang', $santri->id) : route('portal.mundur.ajukan', $santri->id) }}" enctype="multipart/form-data"
              class="mt-4 space-y-4 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200" data-no-confirm
              x-data="pinPad(@js($punyaPin), @js(route('portal.pin')))" @submit.prevent="buka()">
            @csrf
            <p class="font-semibold text-slate-900">{{ $ulang ? 'Perbaiki & kirim ulang' : 'Ajukan pengunduran diri' }}</p>
            @unless ($ulang)
                <p class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900">Pengajuan akan diverifikasi wali kelas, diputuskan kepala sekolah, lalu melalui clearance keuangan, kesantrian, dan administrasi sekolah. Santri dikeluarkan pada proses akhir bulan.
                    Seluruh tagihan yang belum lunas (SPP, sisa uang pangkal &amp; perlengkapan, dan tagihan lain) <b>tetap harus diselesaikan</b>.</p>
            @endunless
            <label class="block text-sm"><span class="mb-1 block font-medium text-slate-700">Alasan</span>
                <textarea name="alasan" rows="4" required maxlength="2000" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-base focus:border-brand focus:ring-brand">{{ old('alasan', $ulang ? $p->alasan : '') }}</textarea>
            </label>
            <label class="block text-sm"><span class="mb-1 block font-medium text-slate-700">Rencana tanggal keluar <span class="font-normal text-slate-400">(opsional)</span></span>
                <input type="date" name="rencana_tanggal" value="{{ old('rencana_tanggal', $ulang ? $p->rencana_tanggal?->toDateString() : '') }}" class="w-full rounded-2xl border border-slate-300 px-4 py-3 text-base">
            </label>
            <div x-data="unggahBukti({{ $maksByte }})">
                <span class="mb-1 block text-sm font-medium text-slate-700">Surat pengajuan pengunduran diri {{ $ulang ? '(opsional, bila diganti)' : '' }}</span>
                <label class="flex cursor-pointer flex-col items-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 bg-white p-5 text-center"
                       :class="seret && 'border-brand bg-blue-50'" @dragover.prevent="seret = true" @dragleave.prevent="seret = false" @drop.prevent="jatuhkan($event)">
                    <span class="text-sm font-semibold text-brand" x-text="nama || 'Pilih foto / PDF surat'"></span>
                    <span class="text-xs text-slate-500" x-text="keterangan"></span>
                    <input type="file" name="surat" accept="image/*,application/pdf" @unless ($ulang) required @endunless class="sr-only" @change="pilih($event)">
                </label>
                <p x-show="galat" x-text="galat" x-cloak class="mt-1 text-xs text-red-700"></p>
                @error('surat')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
            </div>
            <button class="w-full rounded-2xl bg-red-700 py-3 text-sm font-bold text-white">{{ $ulang ? 'Kirim ulang' : 'Ajukan pengunduran diri' }}</button>
            @include('portal._pin', ['judul' => 'Konfirmasi pengajuan pengunduran diri '.$santri->nama])
        </form>
    @endif
@endsection
