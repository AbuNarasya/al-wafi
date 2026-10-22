@extends('layouts.portal')

@section('title', __('Bayar online'))
@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $judul = match ($t->jenis) {
        'isi_dompet' => __('Isi Dompet Wali'),
        'bayar_kelompok' => __('Bayar :jumlah tagihan sekaligus', ['jumlah' => $t->rincian->count()]),
        default => \App\Http\Controllers\PortalKeluargaController::namaTagihan($t->tagihan).' — '.$t->tagihan->santri->nama,
    };
    $kembali = match ($t->jenis) {
        'isi_dompet' => route('portal.dompet'),
        'bayar_kelompok' => route('portal.tagihan'),
        default => route('portal.tagihan', ['anak' => $t->tagihan?->id_santri]),
    };
@endphp

@section('kembali', $kembali)

@php
    $warna = ['menunggu' => 'bg-amber-50 text-amber-800', 'lunas' => 'bg-emerald-50 text-emerald-700',
        'kedaluwarsa' => 'bg-slate-100 text-slate-600', 'gagal' => 'bg-red-50 text-red-700', 'batal' => 'bg-slate-100 text-slate-600'];
@endphp

@section('content')
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-2">
            <p class="min-w-0 font-semibold text-slate-900">{{ $judul }}</p>
            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$t->status] ?? '' }}">{{ __($t->labelStatus()) }}</span>
        </div>
        <p class="mt-0.5 font-mono text-xs text-slate-500">{{ $t->order_id }}</p>

        <div class="mt-4 space-y-1 text-sm">
            @foreach ($t->rincian as $r)
                <div class="flex justify-between gap-2 text-slate-600">
                    <span class="min-w-0 truncate">{{ \App\Http\Controllers\PortalKeluargaController::namaTagihan($r->tagihan) }} — {{ $r->tagihan->santri->nama }}</span>
                    <span class="shrink-0 tabular-nums">{{ $rp($r->nominal) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between text-slate-600"><span>{{ $t->rincian->isNotEmpty() ? __('Nominal (jumlah)') : __('Nominal') }}</span><span class="tabular-nums">{{ $rp($t->nominal) }}</span></div>
            <div class="flex justify-between text-slate-600"><span>{{ __('Biaya layanan Virtual Account') }}</span><span class="tabular-nums">{{ $rp($t->biaya) }}</span></div>
            <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-bold text-slate-900">
                <span>{{ __('Total dibayar') }}</span><span class="tabular-nums">{{ $rp($t->total) }}</span>
            </div>
        </div>

        @if ($t->nomor_va)
            <div class="mt-4 rounded-2xl bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Virtual Account {{ $t->labelBank() }}</p>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <p class="font-mono text-lg font-bold tracking-wide text-slate-900">{{ $t->nomor_va }}</p>
                    <button type="button" class="shrink-0 rounded-xl bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                            onclick="navigator.clipboard?.writeText(@js(preg_replace('/\D/', '', $t->nomor_va))); this.textContent = @js(__('Tersalin'))">{{ __('Salin') }}</button>
                </div>
            </div>
        @endif

        @if ($t->status === 'menunggu' && $t->kedaluwarsa_pada)
            <p class="mt-3 text-xs text-slate-500">{{ __('Bayar sebelum :waktu WIB.', ['waktu' => $t->kedaluwarsa_pada->timezone('Asia/Jakarta')->locale(app()->getLocale())->translatedFormat('d F Y, H:i')]) }}</p>
        @endif
    </div>

    @if ($t->status === 'menunggu' && $t->snap_token)
      {{-- Halaman memantau dirinya sendiri (tiap 5 detik, ringan — lihat PortalBayarOnlineController::status)
           supaya tak tampak macet: berganti sendiri begitu notifikasi Midtrans membukukan pembayarannya.
           Berhenti saat tab tak dilihat & sesudah 10 menit, supaya database tak terus terbangun. --}}
      <div x-data="{
              memproses: false, lama: false, memeriksa: false, mulai: Date.now(), selesai: Date.now(),
              async cek() {
                  try {
                      const r = await fetch(@js(route('portal.online.status', $t->order_id)), { headers: { Accept: 'application/json' } });
                      if (r.ok && (await r.json()).status !== 'menunggu') location.reload();
                  } catch (e) {}
              },
              init() {
                  const putaran = setInterval(() => {
                      if (Date.now() - this.mulai > 600000) return clearInterval(putaran);
                      if (this.memproses && Date.now() - this.selesai > 45000) this.lama = true;
                      if (! document.hidden) this.cek();
                  }, 5000);
                  window.addEventListener('midtrans-dibayar', () => { this.memproses = true; this.selesai = Date.now(); this.cek(); });
              },
           }">
        <p class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500">
            <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            {{ __('Menunggu pembayaran — halaman ini diperbarui otomatis') }}
        </p>

        <button type="button" id="tombol-bayar" class="mt-3 w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">
            {{ $t->nomor_va ? __('Lihat cara bayar') : __('Pilih bank & bayar') }}
        </button>
        <a href="{{ route('portal.online.lihat', $t->order_id) }}" @click="memeriksa = true"
           class="mt-3 flex items-center justify-center gap-2 rounded-2xl bg-white py-3 text-center text-sm font-semibold text-brand shadow-sm ring-1 ring-slate-200">
            <svg x-show="memeriksa" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <span x-text="memeriksa ? @js(__('Memeriksa ke Midtrans…')) : @js(__('Saya sudah bayar — cek status'))">{{ __('Saya sudah bayar — cek status') }}</span>
        </a>

        {{-- Sesudah membayar di popup Midtrans: tanda "sedang diproses" sampai lunas tercatat. --}}
        <div x-show="memproses" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-6">
            <div class="w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-xl">
                <svg class="mx-auto h-10 w-10 animate-spin text-brand" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <p class="mt-4 text-base font-bold text-slate-900">{{ __('Pembayaran sedang diproses…') }}</p>
                <p class="mt-1 text-sm text-slate-500" x-show="! lama">{{ __('Mohon tunggu sebentar, kami sedang menerima konfirmasi dari bank.') }}</p>
                <p class="mt-1 text-sm text-slate-500" x-show="lama" x-cloak>
                    {{ $t->jenis === 'isi_dompet'
                        ? __('Konfirmasi dari Midtrans sedang dalam perjalanan. Saldo akan diperbarui otomatis — halaman ini boleh ditutup.')
                        : __('Konfirmasi dari Midtrans sedang dalam perjalanan. Tagihan akan diperbarui otomatis — halaman ini boleh ditutup.') }}
                </p>
            </div>
        </div>
      </div>
        {{-- Ganti metode = batalkan VA ini (dicek dulu ke Midtrans) lalu kembali ke layar bayar. --}}
        <form method="POST" action="{{ route('portal.online.batal', $t->order_id) }}" class="mt-3"
              data-confirm="{{ $t->rincian->count() > 1
                  ? __('Batalkan Virtual Account ini? Ke-:jumlah tagihan di dalamnya akan terbuka untuk dibayar dengan cara lain. Nomor VA-nya tidak bisa dipakai lagi.', ['jumlah' => $t->rincian->count()])
                  : __('Batalkan Virtual Account ini lalu pilih cara bayar lain? Nomor VA-nya tidak bisa dipakai lagi.') }}">
            @csrf
            <input type="hidden" name="ganti" value="1">
            <button class="w-full rounded-2xl bg-white py-3 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200">{{ __('Ganti metode pembayaran') }}</button>
        </form>
        <form method="POST" action="{{ route('portal.online.batal', $t->order_id) }}" class="mt-3 text-center"
              data-confirm="{{ __('Batalkan pesanan pembayaran ini? Nomor Virtual Account-nya tidak bisa dipakai lagi.') }}">
            @csrf
            <button class="text-sm font-semibold text-red-700">{{ __('Batalkan pesanan') }}</button>
        </form>

        <script src="{{ $snapJs }}" data-client-key="{{ $clientKey }}"></script>
        <script>
            document.getElementById('tombol-bayar').addEventListener('click', function () {
                if (! window.snap) { location.href = @js($t->snap_url); return; }
                const muatUlang = () => location.reload();
                window.snap.pay(@js($t->snap_token), {
                    // Lunas di Midtrans → tunggu pembukuannya dengan tanda "sedang diproses".
                    onSuccess: () => window.dispatchEvent(new CustomEvent('midtrans-dibayar')),
                    // VA baru terbit (belum dibayar) → muat ulang supaya nomornya tampil.
                    onPending: muatUlang, onError: muatUlang,
                });
            });
        </script>
    @elseif ($t->status === 'lunas')
        <div class="mt-4 rounded-3xl bg-emerald-50 p-5 text-center text-sm text-emerald-800 ring-1 ring-emerald-200">
            <p class="text-base font-bold">{{ __('Alhamdulillah, pembayaran diterima.') }}</p>
            <p class="mt-1">{{ $t->jenis === 'isi_dompet' ? __('Saldo Dompet Wali sudah bertambah.') : __('Tagihan sudah tercatat terbayar.') }}</p>
        </div>
        <a href="{{ $kembali }}"
           class="mt-4 block rounded-2xl bg-brand py-3 text-center text-sm font-bold text-white">
            {{ $t->jenis === 'isi_dompet' ? __('Lihat dompet') : __('Lihat tagihan') }}
        </a>
    @else
        <div class="mt-4 rounded-3xl bg-slate-50 p-5 text-center text-sm text-slate-600 ring-1 ring-slate-200">
            {{ __('Pesanan ini :status — tidak ada dana yang terpotong. Silakan buat pembayaran baru bila perlu.', ['status' => mb_strtolower(__($t->labelStatus()))]) }}
        </div>
    @endif
@endsection
