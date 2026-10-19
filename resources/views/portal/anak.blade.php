@extends('layouts.portal')

@section('title', $santri->nama)

@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

@section('content')
    {{-- Profil --}}
    <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start gap-3">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brand text-xl font-bold text-white">{{ mb_strtoupper(mb_substr($santri->nama, 0, 1)) }}</span>
            <div class="min-w-0 flex-1">
                <p class="text-lg font-bold text-slate-900">{{ $santri->nama }}</p>
                <p class="text-sm text-slate-500">{{ $santri->jenjang?->nama ?? $santri->kode_jenjang }}{{ $santri->tingkat ? ' · kelas '.$santri->tingkat : '' }}</p>
            </div>
            <span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                'bg-emerald-50 text-emerald-700' => $aktif, 'bg-blue-50 text-brand' => $tahapPsb !== null,
                'bg-slate-100 text-slate-600' => ! $aktif && $tahapPsb === null])>{{ $label }}</span>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
            <div><dt class="text-xs text-slate-500">{{ $santri->nis ? 'NIS' : 'No. pendaftaran' }}</dt><dd class="font-medium text-slate-900">{{ $santri->nis ?: $santri->no_pendaftaran }}</dd></div>
            <div><dt class="text-xs text-slate-500">Angkatan</dt><dd class="font-medium text-slate-900">{{ $santri->tahun_ajaran ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Jenis kelamin</dt><dd class="font-medium text-slate-900">{{ $santri->jenis_kelamin === 'P' ? 'Perempuan' : 'Laki-laki' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Tahun berjalan</dt><dd class="font-medium text-slate-900">{{ $santri->tahun_ajaran_berjalan ?: ($santri->tahun_ajaran ?: '—') }}</dd></div>
        </dl>
    </div>

    {{-- Progres PSB (calon) --}}
    @if ($tahapPsb !== null)
        <section class="mt-5">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Pendaftaran</h2>
            <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex gap-1">
                    @for ($i = 0; $i < 7; $i++)
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-brand' => $i <= $tahapPsb, 'bg-slate-200' => $i > $tahapPsb])></div>
                    @endfor
                </div>
                <p class="mt-2 text-sm text-slate-600">Tahap {{ $tahapPsb + 1 }} dari 7 &middot; {{ $label }}</p>
            </div>
        </section>
    @endif

    {{-- Berkas pendaftaran — daftar periksa yang diingatkan, bukan penghalang tahap. --}}
    @if ($berkas)
        <a href="{{ route('portal.berkas', $santri->id) }}" @class(['mt-5 flex items-center gap-3 rounded-3xl p-4 shadow-sm ring-1',
            'bg-amber-50 ring-amber-200' => $berkas['kurang'] > 0, 'bg-white ring-slate-200' => $berkas['kurang'] === 0])>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-brand ring-1 ring-slate-200"><x-portal.ikon nama="unggah" kelas="h-5 w-5" /></span>
            <span class="min-w-0 flex-1 text-sm">
                <span class="block font-semibold text-slate-900">Berkas pendaftaran</span>
                <span @class(['block text-xs', 'text-amber-900' => $berkas['kurang'] > 0, 'text-slate-500' => $berkas['kurang'] === 0])>
                    @if ($berkas['diganti'] > 0)
                        {{ $berkas['diganti'] }} berkas perlu diganti · {{ $berkas['kurang'] }} dari {{ $berkas['total'] }} belum diterima
                    @elseif ($berkas['kurang'] > 0)
                        {{ $berkas['kurang'] }} dari {{ $berkas['total'] }} berkas registrasi belum diterima — unggah di sini
                    @else
                        Berkas registrasi lengkap diterima
                    @endif
                </span>
            </span>
            <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
        </a>
    @endif

    {{-- Tagihan --}}
    <section class="mt-5">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Tagihan</h2>
            <a href="{{ route('portal.tagihan', ['anak' => $santri->id]) }}" class="text-xs font-semibold text-brand">Semua &rarr;</a>
        </div>
        @if ($jumlahBelum === 0)
            <div class="rounded-3xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">Semua tagihan sudah lunas.</div>
        @else
            <div class="rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <span class="text-sm text-slate-600">{{ $jumlahBelum }} belum lunas</span>
                    <span class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($sisa) }}</span>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($belum as $t)
                        <a href="{{ route('portal.bayar', $t['id']) }}" class="flex items-center justify-between gap-2 px-4 py-3">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $t['nama'] }}</span>
                                @if ($t['keterangan'])<span class="block truncate text-xs text-slate-600">{{ $t['keterangan'] }}</span>@endif
                                @if ($t['jatuh_tempo'])<span class="block text-xs text-slate-500">Jatuh tempo {{ $t['jatuh_tempo']->format('d/m/Y') }}</span>@endif
                            </span>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-slate-900">{{ $rp($t['sisa']) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- Uang saku & tabungan (santri aktif) --}}
    @if ($aktif)
        <section class="mt-5">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Uang saku &amp; tabungan</h2>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs text-slate-500">Uang saku</p>
                    <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($santri->dompet?->saldo ?? 0) }}</p>
                </div>
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs text-slate-500">Tabungan</p>
                    <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($santri->tabungan?->saldo ?? 0) }}</p>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 text-sm font-semibold">
                <a href="{{ route('portal.dompet.pindah') }}" class="rounded-2xl bg-brand py-2.5 text-center text-white">Pindah saldo</a>
                <a href="{{ route('portal.dompet.tabungan') }}" class="rounded-2xl bg-white py-2.5 text-center text-brand ring-1 ring-slate-300">Tarik tabungan</a>
            </div>

            @if ($mutasi->isNotEmpty())
                <div class="mt-3 divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                    @foreach ($mutasi as $m)
                        <div class="flex items-start justify-between gap-2 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $m['dompet'] }} &middot; {{ $m['label'] }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $m['tanggal']->format('d/m/Y') }}{{ $m['keterangan'] ? ' · '.$m['keterangan'] : '' }}</p>
                            </div>
                            <p @class(['shrink-0 text-sm font-bold tabular-nums', 'text-red-700' => $m['keluar'], 'text-emerald-700' => ! $m['keluar']])>
                                {{ $m['keluar'] ? '−' : '+' }}{{ number_format($m['nominal'], 0, ',', '.') }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Kartu belanja Wafi Mart: semua aksi dengan PIN WALI (papan PIN). --}}
        @if ($belanja)
            @php $punyaPinWali = \App\Services\PortalWali\SesiWali::punyaPin(); @endphp
            {{-- `panel`, bukan `buka`: formulir di dalamnya memakai pinPad yang punya method buka(). --}}
            <section class="mt-5" x-data="{ panel: '' }">
                <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Kartu belanja Wafi Mart</h2>
                <div class="space-y-3 rounded-3xl bg-white p-4 text-sm shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">Kartu</span>
                        @if ($belanja['kartu'])
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Aktif · …{{ substr($belanja['kartu']->uid, -4) }}</span>
                        @else
                            <span class="text-xs text-slate-400">Belum ada / diblokir — dipasang di kantor</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">Batas belanja harian</span>
                        <span class="font-semibold tabular-nums">{{ $rp($belanja['batas']) }}{{ $belanja['milik'] === null ? ' (bawaan)' : '' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">PIN belanja anak</span>
                        <span class="text-xs {{ $belanja['punyaPin'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $belanja['punyaPin'] ? 'terpasang' : 'belum — belanja di atas batas akan ditolak' }}</span>
                    </div>
                    <p class="text-xs text-slate-500">Belanja sampai batas harian cukup tempel kartu. Di atas batas, anak mengetik PIN belanjanya.</p>

                    <div class="grid grid-cols-3 gap-2 pt-1 text-xs font-semibold">
                        <button type="button" @click="panel = panel ==='batas' ? '' : 'batas'" class="rounded-2xl bg-slate-100 py-2 text-slate-700">Ubah batas</button>
                        <button type="button" @click="panel = panel ==='pin' ? '' : 'pin'" class="rounded-2xl bg-slate-100 py-2 text-slate-700">{{ $belanja['punyaPin'] ? 'Ganti PIN' : 'Pasang PIN' }}</button>
                        @if ($belanja['kartu'])
                            <button type="button" @click="panel = panel ==='blokir' ? '' : 'blokir'" class="rounded-2xl bg-red-50 py-2 text-red-700">Blokir kartu</button>
                        @endif
                    </div>

                    <form x-show="panel ==='batas'" x-cloak method="POST" action="{{ route('portal.kartu.batas', $santri->id) }}" class="space-y-2"
                          data-no-confirm x-data="{ ...pinPad(@js($punyaPinWali), @js(route('portal.pin'))), nilai: @js($belanja['milik'] === null ? '' : (string) (int) $belanja['milik']) }" @submit.prevent="buka()">
                        @csrf
                        <input type="text" inputmode="numeric" :value="fmtRupiah(nilai)" @input="nilai = ketikRupiah($event)"
                               placeholder="Kosongkan = bawaan {{ $rp($belanja['bawaan']) }}" class="w-full rounded-2xl border-slate-300 text-right">
                        <input type="hidden" name="batas_harian" :value="nilai">
                        <button class="w-full rounded-2xl bg-brand py-2.5 font-semibold text-white">Simpan batas</button>
                        @include('portal._pin', ['judul' => 'Ubah batas belanja '.$santri->nama])
                    </form>

                    <form x-show="panel ==='pin'" x-cloak method="POST" action="{{ route('portal.kartu.pin', $santri->id) }}" class="space-y-2"
                          data-no-confirm x-data="pinPad(@js($punyaPinWali), @js(route('portal.pin')))" @submit.prevent="buka()">
                        @csrf
                        <input type="password" inputmode="numeric" name="pin_anak" required maxlength="6" autocomplete="off" placeholder="PIN belanja anak (6 angka)" class="w-full rounded-2xl border-slate-300 text-center tracking-widest">
                        <input type="password" inputmode="numeric" name="pin_anak_ulang" required maxlength="6" autocomplete="off" placeholder="Ulangi PIN anak" class="w-full rounded-2xl border-slate-300 text-center tracking-widest">
                        <button class="w-full rounded-2xl bg-brand py-2.5 font-semibold text-white">Simpan PIN anak</button>
                        @include('portal._pin', ['judul' => 'Konfirmasi dengan PIN Anda (wali)'])
                    </form>

                    @if ($belanja['kartu'])
                        <form x-show="panel ==='blokir'" x-cloak method="POST" action="{{ route('portal.kartu.blokir', $santri->id) }}" class="space-y-2"
                              data-no-confirm x-data="pinPad(@js($punyaPinWali), @js(route('portal.pin')))" @submit.prevent="buka()">
                            @csrf
                            <p class="text-xs text-red-700">Kartu langsung tak bisa dipakai belanja. Membuka blokir / kartu baru hanya di kantor.</p>
                            <button class="w-full rounded-2xl bg-red-600 py-2.5 font-semibold text-white">Blokir kartu sekarang</button>
                            @include('portal._pin', ['judul' => 'Blokir kartu '.$santri->nama])
                        </form>
                    @endif
                </div>
            </section>
        @endif
    @endif

    {{-- Riwayat bayar --}}
    @if ($riwayat->isNotEmpty())
        <section class="mt-5">
            <div class="mb-2 flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> Pembayaran terakhir</h2>
                <a href="{{ route('portal.tagihan', ['anak' => $santri->id, 'tab' => 'riwayat']) }}" class="text-xs font-semibold text-brand">Semua &rarr;</a>
            </div>
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($riwayat as $p)
                    <div class="flex items-start justify-between gap-2 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $p->tagihan ? \App\Http\Controllers\PortalKeluargaController::namaTagihan($p->tagihan) : 'Pembayaran' }}</p>
                            @if ($p->tagihan && ($ket = \App\Http\Controllers\PortalKeluargaController::keteranganTagihan($p->tagihan)))
                                <p class="text-xs text-slate-600">{{ $ket }}</p>
                            @endif
                            <p class="text-xs text-slate-500">{{ $p->tanggal->format('d/m/Y') }}</p>
                            @if ($p->status === 'menunggu_verifikasi')<p class="text-xs font-medium text-amber-700">Menunggu verifikasi</p>@endif
                        </div>
                        <p class="shrink-0 text-sm font-bold tabular-nums text-slate-900">{{ $rp($p->nominal) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
