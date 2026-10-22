@extends('layouts.portal')

@section('title', __('Akun'))

@section('content')
    <div class="flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($nama, 0, 1)) }}</span>
        <div class="min-w-0">
            <p class="truncate text-lg font-bold text-slate-900">{{ $nama }}</p>
            <p class="truncate text-sm text-slate-500">{{ $peran }} &middot; {{ $email }}</p>
            <p class="text-sm text-slate-500">{{ $telepon ?: '—' }}</p>
        </div>
    </div>
    <a href="{{ route('portal.data', ['tab' => 'wali']) }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="data" kelas="h-5 w-5" /></span>
        <span class="min-w-0 flex-1 text-sm">
            <span class="block font-semibold text-slate-900">{{ __('Data Wali & Santri') }}</span>
            <span class="block text-xs text-slate-500">{{ __('Periksa & betulkan nama, nomor HP, email, alamat') }}</span>
        </span>
        <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
    </a>

    <a href="{{ route('portal.sandi.ganti') }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="kartu" kelas="h-5 w-5" /></span>
        <span class="min-w-0 flex-1 text-sm">
            <span class="block font-semibold text-slate-900">{{ __('Password masuk') }}</span>
            <span class="block text-xs text-slate-500">{{ __('Ganti password untuk masuk Portal Wali') }}</span>
        </span>
        <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
    </a>

    <a href="{{ route('portal.pin') }}" class="mt-4 flex items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="kartu" kelas="h-5 w-5" /></span>
        <span class="min-w-0 flex-1 text-sm">
            <span class="block font-semibold text-slate-900">{{ __('PIN transaksi') }}</span>
            <span class="block text-xs text-slate-500">{{ \App\Services\PortalWali\SesiWali::punyaPin() ? __('Ganti PIN atau setel ulang bila lupa') : __('Belum dibuat — wajib untuk transaksi dompet') }}</span>
        </span>
        <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
    </a>

    {{-- Tampilan gelap / terang — per perangkat, tersimpan di HP ini --}}
    <div x-data="{ pilihan: modeGelap.pilihan() }" class="mt-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="bulan" kelas="h-5 w-5" /></span>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-semibold text-slate-900">{{ __('Tampilan') }}</p>
                <p class="text-xs text-slate-500">{{ __('Berlaku untuk perangkat ini saja.') }}</p>
            </div>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-1 rounded-2xl bg-slate-200 p-1 text-center text-sm">
            <button type="button" @click="pilihan = '0'; modeGelap.atur(false)" :class="pilihan === '0' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ __('Terang') }}</button>
            <button type="button" @click="pilihan = '1'; modeGelap.atur(true)" :class="pilihan === '1' ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ __('Gelap') }}</button>
            <button type="button" @click="pilihan = null; modeGelap.ikutHp()" :class="pilihan === null ? 'bg-white font-semibold text-slate-900 shadow-sm' : 'text-slate-600'" class="rounded-xl py-2">{{ __('Ikut HP') }}</button>
        </div>
    </div>

    {{-- Push notification (keputusan user 21 Okt 2026): nyala/mati PER PERANGKAT, kategorinya PER ORANG. --}}
    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Notifikasi') }}</h2>
        <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
             x-data="pushLangganan({ kunciPublik: @js($pushKunci), url: @js(route('portal.push.langganan')) })">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="lonceng" kelas="h-5 w-5" /></span>
                <div class="min-w-0 flex-1 text-sm">
                    <p class="font-semibold text-slate-900">{{ __('Notifikasi di HP ini') }}</p>
                    <p class="text-xs text-slate-500">{{ __('Kabar terbaru muncul di HP walau aplikasinya tertutup.') }}</p>
                </div>
            </div>
            <template x-if="halangan"><p class="mt-3 rounded-2xl bg-amber-50 px-3 py-2 text-xs text-amber-800" x-text="halangan"></p></template>
            <template x-if="pesan"><p class="mt-3 rounded-2xl bg-slate-50 px-3 py-2 text-xs text-slate-700" x-text="pesan"></p></template>
            <template x-if="didukung">
                <div class="mt-3">
                    <button type="button" x-show="! aktif" :disabled="sibuk" @click="nyalakan()"
                            class="w-full rounded-2xl bg-brand py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ __('Nyalakan notifikasi') }}</button>
                    <div x-show="aktif" x-cloak class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-emerald-700">{{ __('Aktif di HP ini') }}</span>
                        <button type="button" :disabled="sibuk" @click="matikan()" class="text-sm font-semibold text-slate-500 disabled:opacity-50">{{ __('Matikan') }}</button>
                    </div>
                </div>
            </template>

            <form method="POST" action="{{ route('portal.push.kategori') }}" class="mt-4 border-t border-slate-100 pt-3" data-no-confirm>
                @csrf
                <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-500">{{ __('Kabar yang dikirim ke HP') }}</p>
                <div class="space-y-1">
                    @foreach ($kategori as $kode => $label)
                        <label class="flex items-center gap-3 rounded-2xl px-1 py-2 text-sm text-slate-800">
                            <input type="checkbox" name="kategori[]" value="{{ $kode }}" @checked(in_array($kode, $kategoriNyala, true))
                                   class="h-5 w-5 rounded border-slate-300 text-brand focus:ring-brand">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ __('Berlaku untuk semua HP Anda. Kabar yang tak dicentang tetap ada di menu Kabar, hanya tidak berbunyi.') }}</p>
                <button class="mt-3 w-full rounded-2xl bg-white py-2.5 text-sm font-semibold text-brand ring-1 ring-slate-300">{{ __('Simpan pilihan') }}</button>
            </form>
        </div>
    </section>

    <section class="mt-5">
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Perangkat saya') }}</h2>
        <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
            @forelse ($perangkat as $p)
                <div class="flex items-center gap-3 px-4 py-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-600"><x-portal.ikon nama="perangkat" kelas="h-5 w-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-900">{{ $p['nama'] }}
                            @if ($p['ini'])<span class="ms-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ __('perangkat ini') }}</span>@endif
                        </p>
                        <p class="text-xs text-slate-500">{{ __('Terakhir dipakai :waktu', ['waktu' => $p['terakhir']?->locale(app()->getLocale())->diffForHumans() ?? '—']) }}</p>
                    </div>
                    @unless ($p['ini'])
                        <form method="POST" action="{{ route('portal.cabut_perangkat', $p['id']) }}"
                              data-confirm="{{ __('Cabut perangkat ini? Perangkat itu harus memasukkan kode lagi untuk masuk.') }}">
                            @csrf
                            <button class="text-sm font-semibold text-red-700">{{ __('Cabut') }}</button>
                        </form>
                    @endunless
                </div>
            @empty
                <p class="p-4 text-center text-xs text-slate-500">{{ __('Tak ada perangkat yang diingat. Centang “Ingat perangkat ini” saat masuk agar tak perlu kode setiap kali.') }}</p>
            @endforelse
        </div>
    </section>

    <div class="mt-5 space-y-2">
        <a href="{{ route('portal.daftar') }}" class="flex items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 p-4 text-sm font-semibold text-brand">
            <x-portal.ikon nama="isi" kelas="h-5 w-5" /> {{ __('Daftarkan anak lain') }}
        </a>
        <form method="POST" action="{{ route('portal.keluar') }}" data-no-confirm>
            @csrf
            <button class="flex w-full items-center justify-center gap-2 rounded-3xl bg-white p-4 text-sm font-semibold text-red-700 shadow-sm ring-1 ring-slate-200">
                <x-portal.ikon nama="keluar" kelas="h-5 w-5" /> {{ __('Keluar') }}
            </button>
        </form>
    </div>
@endsection
