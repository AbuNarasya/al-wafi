@extends('layouts.portal')

@section('title', 'Bayar online')
@section('kembali', $t->jenis === 'isi_dompet' ? route('portal.dompet') : route('portal.tagihan', ['anak' => $t->tagihan?->id_santri]))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $judul = $t->jenis === 'isi_dompet'
        ? 'Isi Dompet Wali'
        : \App\Http\Controllers\PortalKeluargaController::namaTagihan($t->tagihan).' — '.$t->tagihan->santri->nama;
    $warna = ['menunggu' => 'bg-amber-50 text-amber-800', 'lunas' => 'bg-emerald-50 text-emerald-700',
        'kedaluwarsa' => 'bg-slate-100 text-slate-600', 'gagal' => 'bg-red-50 text-red-700', 'batal' => 'bg-slate-100 text-slate-600'];
@endphp

@section('content')
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 font-semibold text-slate-900">{{ $judul }}</p>
            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$t->status] ?? '' }}">{{ $t->labelStatus() }}</span>
        </div>
        <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $t->order_id }}</p>

        <div class="mt-4 space-y-1 text-sm">
            <div class="flex justify-between text-slate-600"><span>Nominal</span><span class="tabular-nums">{{ $rp($t->nominal) }}</span></div>
            <div class="flex justify-between text-slate-600"><span>Biaya layanan Virtual Account</span><span class="tabular-nums">{{ $rp($t->biaya) }}</span></div>
            <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold text-slate-900">
                <span>Total dibayar</span><span class="tabular-nums">{{ $rp($t->total) }}</span>
            </div>
        </div>

        @if ($t->nomor_va)
            <div class="mt-4 rounded-2xl bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Virtual Account {{ $t->labelBank() }}</p>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <p class="font-mono text-lg font-bold tracking-wide text-slate-900">{{ $t->nomor_va }}</p>
                    <button type="button" class="shrink-0 rounded-xl bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                            onclick="navigator.clipboard?.writeText(@js(preg_replace('/\D/', '', $t->nomor_va))); this.textContent = 'Tersalin'">Salin</button>
                </div>
            </div>
        @endif

        @if ($t->status === 'menunggu' && $t->kedaluwarsa_pada)
            <p class="mt-3 text-xs text-slate-500">Bayar sebelum {{ $t->kedaluwarsa_pada->timezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i') }} WIB.</p>
        @endif
    </div>

    @if ($t->status === 'menunggu' && $t->snap_token)
        <button type="button" id="tombol-bayar" class="mt-4 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">
            {{ $t->nomor_va ? 'Lihat cara bayar' : 'Pilih bank & bayar' }}
        </button>
        <a href="{{ route('portal.online.lihat', $t->order_id) }}" class="mt-3 block rounded-2xl bg-white py-3 text-center text-sm font-semibold text-brand shadow-sm ring-1 ring-slate-200">
            Saya sudah bayar — cek status
        </a>
        <form method="POST" action="{{ route('portal.online.batal', $t->order_id) }}" class="mt-3 text-center"
              data-confirm="Batalkan pesanan pembayaran ini? Nomor Virtual Account-nya tidak bisa dipakai lagi.">
            @csrf
            <button class="text-sm font-semibold text-red-700">Batalkan pesanan</button>
        </form>

        <script src="{{ $snapJs }}" data-client-key="{{ $clientKey }}"></script>
        <script>
            document.getElementById('tombol-bayar').addEventListener('click', function () {
                if (! window.snap) { location.href = @js($t->snap_url); return; }
                const muatUlang = () => location.reload();
                window.snap.pay(@js($t->snap_token), { onSuccess: muatUlang, onPending: muatUlang, onError: muatUlang });
            });
        </script>
    @elseif ($t->status === 'lunas')
        <div class="mt-4 rounded-3xl bg-emerald-50 p-5 text-center text-sm text-emerald-800 ring-1 ring-emerald-200">
            <p class="text-base font-bold">Alhamdulillah, pembayaran diterima.</p>
            <p class="mt-1">{{ $t->jenis === 'isi_dompet' ? 'Saldo Dompet Wali sudah bertambah.' : 'Tagihan sudah tercatat terbayar.' }}</p>
        </div>
        <a href="{{ $t->jenis === 'isi_dompet' ? route('portal.dompet') : route('portal.tagihan', ['anak' => $t->tagihan?->id_santri]) }}"
           class="mt-4 block rounded-2xl bg-brand py-3 text-center text-sm font-bold text-white">
            {{ $t->jenis === 'isi_dompet' ? 'Lihat dompet' : 'Lihat tagihan' }}
        </a>
    @else
        <div class="mt-4 rounded-3xl bg-slate-50 p-5 text-center text-sm text-slate-600 ring-1 ring-slate-200">
            Pesanan ini {{ strtolower($t->labelStatus()) }} &mdash; tidak ada dana yang terpotong. Silakan buat pembayaran baru bila perlu.
        </div>
    @endif
@endsection
