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
                <p class="text-sm text-slate-500">{{ $santri->jenjang?->nama ?? $santri->kode_jenjang }}{{ $santri->kelas ? ' · '.__('kelas :tingkat', ['tingkat' => $santri->kelas->nama]) : ($santri->tingkat ? ' · '.__('kelas :tingkat', ['tingkat' => $santri->tingkat]) : '') }}</p>
            </div>
            <span @class(['shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                'bg-emerald-50 text-emerald-700' => $aktif, 'bg-blue-50 text-brand' => $tahapPsb !== null,
                'bg-slate-100 text-slate-600' => ! $aktif && $tahapPsb === null])>{{ $label }}</span>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
            <div><dt class="text-xs text-slate-500">{{ $santri->nis ? __('NIS') : __('No. pendaftaran') }}</dt><dd class="font-medium text-slate-900">{{ $santri->nis ?: $santri->no_pendaftaran }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ __('Angkatan') }}</dt><dd class="font-medium text-slate-900">{{ $santri->tahun_ajaran ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ __('Jenis kelamin') }}</dt><dd class="font-medium text-slate-900">{{ $santri->jenis_kelamin === 'P' ? __('Perempuan') : __('Laki-laki') }}</dd></div>
            <div><dt class="text-xs text-slate-500">{{ __('Tahun berjalan') }}</dt><dd class="font-medium text-slate-900">{{ $santri->tahun_ajaran_berjalan ?: ($santri->tahun_ajaran ?: '—') }}</dd></div>
        </dl>
    </div>

    {{-- Progres PSB (calon) --}}
    @if ($tahapPsb !== null)
        <section class="mt-5">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Pendaftaran') }}</h2>
            <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex gap-1">
                    @for ($i = 0; $i < 7; $i++)
                        <div @class(['h-1.5 flex-1 rounded-full', 'bg-brand' => $i <= $tahapPsb, 'bg-slate-200' => $i > $tahapPsb])></div>
                    @endfor
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ __('Tahap :tahap dari :total', ['tahap' => $tahapPsb + 1, 'total' => 7]) }} &middot; {{ $label }}</p>
                @include('portal._status-diterima', ['status' => $santri->status])
                @if (\App\Services\Ppsb\UjianSeleksi::bolehKartu($santri))
                    <a href="{{ route('portal.kartu_ujian', $santri->id) }}" class="mt-3 flex items-center justify-between rounded-2xl bg-brand px-4 py-3 text-sm font-semibold text-white">
                        <span>{{ __('Kartu Peserta Ujian') }}</span><span>{{ __('Cetak') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></span>
                    </a>
                @endif
            </div>
        </section>
    @endif

    {{-- Berkas pendaftaran — daftar periksa yang diingatkan, bukan penghalang tahap. --}}
    @if ($berkas)
        <a href="{{ route('portal.berkas', $santri->id) }}" @class(['mt-5 flex items-center gap-3 rounded-3xl p-4 shadow-sm ring-1',
            'bg-amber-50 ring-amber-200' => $berkas['kurang'] > 0, 'bg-white ring-slate-200' => $berkas['kurang'] === 0])>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-brand ring-1 ring-slate-200"><x-portal.ikon nama="unggah" kelas="h-5 w-5" /></span>
            <span class="min-w-0 flex-1 text-sm">
                <span class="block font-semibold text-slate-900">{{ __('Berkas pendaftaran') }}</span>
                <span @class(['block text-xs', 'text-amber-900' => $berkas['kurang'] > 0, 'text-slate-500' => $berkas['kurang'] === 0])>
                    @if ($berkas['diganti'] > 0)
                        {{ __(':diganti berkas perlu diganti · :kurang dari :total belum diterima', ['diganti' => $berkas['diganti'], 'kurang' => $berkas['kurang'], 'total' => $berkas['total']]) }}
                    @elseif ($berkas['kurang'] > 0)
                        {{ __(':kurang dari :total berkas registrasi belum diterima — unggah di sini', ['kurang' => $berkas['kurang'], 'total' => $berkas['total']]) }}
                    @else
                        {{ __('Berkas registrasi lengkap diterima') }}
                    @endif
                </span>
            </span>
            <x-portal.ikon nama="kanan" kelas="h-4 w-4 text-slate-400" />
        </a>
    @endif

    {{-- Tagihan --}}
    <section class="mt-5">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Tagihan') }}</h2>
            <a href="{{ route('portal.tagihan', ['anak' => $santri->id]) }}" class="text-xs font-semibold text-brand">{{ __('Semua') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
        </div>
        @if ($jumlahBelum === 0)
            <div class="rounded-3xl bg-emerald-50 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">{{ __('Semua tagihan sudah lunas.') }}</div>
        @else
            <div class="rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <span class="text-sm text-slate-600">{{ __(':jumlah belum lunas', ['jumlah' => $jumlahBelum]) }}</span>
                    <span class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($sisa) }}</span>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($belum as $t)
                        <a href="{{ route('portal.bayar', $t['id']) }}" class="flex items-center justify-between gap-2 px-4 py-3">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $t['nama'] }}</span>
                                @if ($t['keterangan'])<span class="block truncate text-xs text-slate-600">{{ $t['keterangan'] }}</span>@endif
                                @if ($t['jatuh_tempo'])<span class="block text-xs text-slate-500">{{ __('Jatuh tempo :tanggal', ['tanggal' => $t['jatuh_tempo']->format('d/m/Y')]) }}</span>@endif
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
            <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Uang saku & tabungan') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs text-slate-500">{{ __('Uang saku') }}</p>
                    <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($santri->dompet?->saldo ?? 0) }}</p>
                </div>
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-xs text-slate-500">{{ __('Tabungan') }}</p>
                    <p class="text-lg font-bold tabular-nums text-slate-900">{{ $rp($santri->tabungan?->saldo ?? 0) }}</p>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 text-sm font-semibold">
                <a href="{{ route('portal.dompet.pindah') }}" class="rounded-2xl bg-brand py-2.5 text-center text-white">{{ __('Pindah saldo') }}</a>
                <a href="{{ route('portal.dompet.tabungan') }}" class="rounded-2xl bg-white py-2.5 text-center text-brand ring-1 ring-slate-300">{{ __('Tarik tabungan') }}</a>
            </div>

            @if ($mutasi->isNotEmpty())
                <div class="mt-3 divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200" x-data>
                    @foreach ($mutasi as $m)
                        <div @class(['flex items-start justify-between gap-2 px-4 py-3', 'cursor-pointer active:bg-slate-50' => $m['rinci']])
                             @if ($m['rinci']) role="button" tabindex="0" @click="$dispatch('rincian-belanja', @js($m['rinci']))" @keydown.enter="$dispatch('rincian-belanja', @js($m['rinci']))" @endif>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $m['dompet'] }} &middot; {{ $m['label'] }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $m['tanggal']->format('d/m/Y') }}{{ $m['keterangan'] ? ' · '.$m['keterangan'] : '' }}</p>
                                @if ($m['rinci'])<p class="text-xs font-medium text-brand">{{ __('Lihat rincian') }} <span class="inline-block rtl:-scale-x-100">›</span></p>@endif
                            </div>
                            <p @class(['rp shrink-0 text-sm font-bold tabular-nums', 'text-red-700' => $m['keluar'], 'text-emerald-700' => ! $m['keluar']])>
                                {{ $m['keluar'] ? '−' : '+' }}{{ number_format($m['nominal'], 0, ',', '.') }}
                            </p>
                        </div>
                    @endforeach
                </div>
                @include('portal._rincian-belanja')
            @endif
        </section>

        @if ($kegiatan->isNotEmpty())
            <section class="mt-5">
                <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-amber-500"></span> {{ __('Uang saku kegiatan') }}</h2>
                <a href="{{ route('portal.tarik_tunai.index') }}" class="block divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                    @foreach ($kegiatan as $k)
                        <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900">{{ $k['sesi']->nama }}</p>
                                <p class="text-xs text-slate-500">
                                    @if ($k['pengajuan'])
                                        <span class="rp">Rp {{ number_format((float) $k['pengajuan']->nominalBerlaku(), 0, ',', '.') }}</span> &middot; {{ __($k['pengajuan']->labelStatus()) }}
                                    @elseif ($k['sesi']->status === 'berjalan' && $k['sesi']->tahap() === 'pengajuan')
                                        {{ __('Belum mengajukan · batas :tanggal', ['tanggal' => $k['sesi']->batas_pengajuan->locale(app()->getLocale())->translatedFormat('j M')]) }}
                                    @else
                                        {{ __('Tidak mengajukan') }}
                                    @endif
                                </p>
                            </div>
                            <span class="inline-block text-slate-400 rtl:-scale-x-100">&rsaquo;</span>
                        </div>
                    @endforeach
                </a>
            </section>
        @endif

        {{-- Kartu belanja Wafi Mart: semua aksi dengan PIN WALI (papan PIN). --}}
        @if ($belanja)
            @php $punyaPinWali = \App\Services\PortalWali\SesiWali::punyaPin(); @endphp
            {{-- `panel`, bukan `buka`: formulir di dalamnya memakai pinPad yang punya method buka(). --}}
            <section class="mt-5" x-data="{ panel: '' }">
                <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Kartu belanja Wafi Mart') }}</h2>
                <div class="space-y-3 rounded-3xl bg-white p-4 text-sm shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">{{ __('Kartu') }}</span>
                        @if ($belanja['kartu'])
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">{{ __('Aktif') }} · <span class="font-mono">…{{ substr($belanja['kartu']->uid, -4) }}</span></span>
                        @else
                            <span class="text-xs text-slate-400">{{ __('Belum ada / diblokir — dipasang di kantor') }}</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">{{ __('Batas belanja harian') }}</span>
                        <span class="font-semibold tabular-nums">{{ $rp($belanja['batas']) }}{{ $belanja['milik'] === null ? ' ('.__('bawaan').')' : '' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">{{ __('PIN belanja anak') }}</span>
                        <span class="text-xs {{ $belanja['punyaPin'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $belanja['punyaPin'] ? __('terpasang') : __('belum — belanja di atas batas akan ditolak') }}</span>
                    </div>
                    <p class="text-xs text-slate-500">{{ __('Belanja sampai batas harian cukup tempel kartu. Di atas batas, anak mengetik PIN belanjanya.') }}</p>

                    <div class="grid grid-cols-3 gap-2 pt-1 text-xs font-semibold">
                        <button type="button" @click="panel = panel ==='batas' ? '' : 'batas'" class="rounded-2xl bg-slate-100 py-2 text-slate-700">{{ __('Ubah batas') }}</button>
                        <button type="button" @click="panel = panel ==='pin' ? '' : 'pin'" class="rounded-2xl bg-slate-100 py-2 text-slate-700">{{ $belanja['punyaPin'] ? __('Ganti PIN') : __('Pasang PIN') }}</button>
                        @if ($belanja['kartu'])
                            <button type="button" @click="panel = panel ==='blokir' ? '' : 'blokir'" class="rounded-2xl bg-red-50 py-2 text-red-700">{{ __('Blokir kartu') }}</button>
                        @endif
                    </div>

                    <form x-show="panel ==='batas'" x-cloak method="POST" action="{{ route('portal.kartu.batas', $santri->id) }}" class="space-y-2"
                          data-no-confirm x-data="{ ...pinPad(@js($punyaPinWali), @js(route('portal.pin'))), nilai: @js($belanja['milik'] === null ? '' : (string) (int) $belanja['milik']) }" @submit.prevent="buka()">
                        @csrf
                        <input type="text" inputmode="numeric" :value="fmtRupiah(nilai)" @input="nilai = ketikRupiah($event)"
                               placeholder="{{ __('Kosongkan = bawaan :jumlah', ['jumlah' => $rp($belanja['bawaan'])]) }}" class="w-full rounded-2xl border-slate-300 text-end">
                        <input type="hidden" name="batas_harian" :value="nilai">
                        <button class="w-full rounded-2xl bg-brand py-2.5 font-semibold text-white">{{ __('Simpan batas') }}</button>
                        @include('portal._pin', ['judul' => __('Ubah batas belanja :nama', ['nama' => $santri->nama])])
                    </form>

                    <form x-show="panel ==='pin'" x-cloak method="POST" action="{{ route('portal.kartu.pin', $santri->id) }}" class="space-y-2"
                          data-no-confirm x-data="pinPad(@js($punyaPinWali), @js(route('portal.pin')))" @submit.prevent="buka()">
                        @csrf
                        <input type="password" inputmode="numeric" name="pin_anak" required maxlength="6" autocomplete="off" placeholder="{{ __('PIN belanja anak (6 angka)') }}" class="w-full rounded-2xl border-slate-300 text-center tracking-widest">
                        <input type="password" inputmode="numeric" name="pin_anak_ulang" required maxlength="6" autocomplete="off" placeholder="{{ __('Ulangi PIN anak') }}" class="w-full rounded-2xl border-slate-300 text-center tracking-widest">
                        <button class="w-full rounded-2xl bg-brand py-2.5 font-semibold text-white">{{ __('Simpan PIN anak') }}</button>
                        @include('portal._pin', ['judul' => __('Konfirmasi dengan PIN Anda (wali)')])
                    </form>

                    @if ($belanja['kartu'])
                        <form x-show="panel ==='blokir'" x-cloak method="POST" action="{{ route('portal.kartu.blokir', $santri->id) }}" class="space-y-2"
                              data-no-confirm x-data="pinPad(@js($punyaPinWali), @js(route('portal.pin')))" @submit.prevent="buka()">
                            @csrf
                            <p class="text-xs text-red-700">{{ __('Kartu langsung tak bisa dipakai belanja. Membuka blokir / kartu baru hanya di kantor.') }}</p>
                            <button class="w-full rounded-2xl bg-red-600 py-2.5 font-semibold text-white">{{ __('Blokir kartu sekarang') }}</button>
                            @include('portal._pin', ['judul' => __('Blokir kartu :nama', ['nama' => $santri->nama])])
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
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('Pembayaran terakhir') }}</h2>
                <a href="{{ route('portal.tagihan', ['anak' => $santri->id, 'tab' => 'riwayat']) }}" class="text-xs font-semibold text-brand">{{ __('Semua') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></a>
            </div>
            <div class="divide-y divide-slate-100 rounded-3xl bg-white shadow-sm ring-1 ring-slate-200">
                @foreach ($riwayat as $p)
                    <div class="flex items-start justify-between gap-2 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $p->tagihan ? \App\Http\Controllers\PortalKeluargaController::namaTagihan($p->tagihan) : __('Pembayaran') }}</p>
                            @if ($p->tagihan && ($ket = \App\Http\Controllers\PortalKeluargaController::keteranganTagihan($p->tagihan)))
                                <p class="text-xs text-slate-600">{{ $ket }}</p>
                            @endif
                            <p class="text-xs text-slate-500">{{ $p->tanggal->format('d/m/Y') }}</p>
                            @if ($p->status === 'menunggu_verifikasi')<p class="text-xs font-medium text-amber-700">{{ __('Menunggu verifikasi') }}</p>@endif
                        </div>
                        <p class="shrink-0 text-sm font-bold tabular-nums text-slate-900">{{ $rp($p->nominal) }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Pengunduran diri (21 Okt 2026): untuk santri aktif, atau bila sudah ada pengajuannya. --}}
    @if ($aktif || \App\Models\PengajuanKeluar::where('id_santri', $santri->id)->exists())
        <a href="{{ route('portal.mundur', $santri->id) }}" class="mt-8 block text-center text-sm font-semibold text-slate-500 underline">{{ __('Pengunduran diri santri') }}</a>
    @endif
@endsection
