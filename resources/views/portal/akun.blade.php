@extends('layouts.portal')

@section('title', 'Akun')

@section('content')
    <div class="flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($nama, 0, 1)) }}</span>
        <div class="min-w-0">
            <p class="truncate text-lg font-bold text-slate-900">{{ $nama }}</p>
            <p class="truncate text-sm text-slate-500">{{ $peran }} &middot; {{ $email }}</p>
            <p class="text-sm text-slate-500">{{ $telepon ?: '—' }}</p>
        </div>
    </div>
    <p class="mt-2 px-2 text-xs text-slate-500">Perubahan nama, nomor HP, atau email dilakukan lewat panitia. Setelah email diubah, semua perangkat harus masuk ulang.</p>

    <a href="{{ route('portal.pin') }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="kartu" kelas="h-5 w-5" /></span>
        <span class="min-w-0 flex-1 text-sm">
            <span class="block font-semibold text-slate-900">PIN transaksi</span>
            <span class="block text-xs text-slate-500">{{ \App\Services\PortalWali\SesiWali::punyaPin() ? 'Ganti PIN atau setel ulang bila lupa' : 'Belum dibuat — wajib untuk transaksi dompet' }}</span>
        </span>
        <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
    </a>

    {{-- Tampilan gelap / terang — per perangkat, tersimpan di HP ini --}}
    <div x-data="{ pilihan: modeGelap.pilihan() }" class="mt-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="bulan" kelas="h-5 w-5" /></span>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-slate-900">Tampilan</p>
                <p class="text-xs text-slate-500">Berlaku untuk perangkat ini saja.</p>
            </div>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
            <button type="button" @click="pilihan = '0'; modeGelap.atur(false)" :class="pilihan === '0' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">Terang</button>
            <button type="button" @click="pilihan = '1'; modeGelap.atur(true)" :class="pilihan === '1' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">Gelap</button>
            <button type="button" @click="pilihan = null; modeGelap.ikutHp()" :class="pilihan === null ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">Ikut HP</button>
        </div>
    </div>

    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Perangkat saya</h2>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($perangkat as $p)
                <div class="flex items-center gap-3 px-4 py-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="perangkat" kelas="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-900">{{ $p['nama'] }}
                            @if ($p['ini'])<span class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">perangkat ini</span>@endif
                        </p>
                        <p class="text-xs text-slate-500">Terakhir dipakai {{ $p['terakhir']?->locale('id')->diffForHumans() ?? '—' }}</p>
                    </div>
                    @unless ($p['ini'])
                        <form method="POST" action="{{ route('portal.cabut_perangkat', $p['id']) }}"
                              data-confirm="Cabut perangkat ini? Perangkat itu harus memasukkan kode lagi untuk masuk.">
                            @csrf
                            <button class="text-sm font-semibold text-red-700">Cabut</button>
                        </form>
                    @endunless
                </div>
            @empty
                <p class="p-4 text-center text-xs text-slate-500">Tak ada perangkat yang diingat. Centang &ldquo;Ingat perangkat ini&rdquo; saat masuk agar tak perlu kode setiap kali.</p>
            @endforelse
        </div>
    </section>

    <div class="mt-5 space-y-2">
        <a href="{{ route('portal.daftar') }}" class="flex items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 p-4 text-sm font-semibold text-brand">
            <x-portal.ikon nama="isi" kelas="h-5 w-5" /> Daftarkan anak lain
        </a>
        <form method="POST" action="{{ route('portal.keluar') }}" data-no-confirm>
            @csrf
            <button class="flex w-full items-center justify-center gap-2 rounded-3xl bg-white p-4 text-sm font-semibold text-red-700 shadow-sm ring-1 ring-slate-200">
                <x-portal.ikon nama="keluar" kelas="h-5 w-5" /> Keluar
            </button>
        </form>
    </div>
@endsection
