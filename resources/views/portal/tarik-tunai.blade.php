@extends('layouts.portal')

@section('title', __('Uang saku kegiatan'))
@section('kembali', route('portal.dompet'))

@php
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $warna = ['diajukan' => 'bg-amber-50 text-amber-700', 'disetujui' => 'bg-emerald-50 text-emerald-700', 'ditolak' => 'bg-red-50 text-red-700',
        'dibatalkan' => 'bg-slate-100 text-slate-600', 'gugur' => 'bg-slate-100 text-slate-600', 'diserahkan' => 'bg-blue-50 text-brand', 'tak_diambil' => 'bg-slate-100 text-slate-600'];
    $punyaPin = \App\Services\PortalWali\SesiWali::punyaPin();
@endphp

@section('content')
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-500 text-white shadow-sm"><x-portal.ikon nama="dompet" /></span>
        <div class="min-w-0">
            <h1 class="truncate text-xl font-bold text-slate-900">{{ __('Uang saku kegiatan') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Uang tunai untuk dibawa anak ke kegiatan, diambil dari uang sakunya.') }}</p>
        </div>
    </div>

    @forelse ($sesi as $s)
        @php $bukaAju = $s->status === 'berjalan' && $s->tahap() === 'pengajuan'; @endphp
        <section class="mb-5 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-bold text-slate-900">{{ $s->nama }}</h2>
            @if ($s->keterangan)
                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $s->keterangan }}</p>
            @endif
            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-2xl bg-slate-50 p-2"><dt class="text-slate-500">{{ __('Batas pengajuan') }}</dt><dd class="font-semibold text-slate-900">{{ $s->batas_pengajuan->translatedFormat('j M Y') }}</dd></div>
                <div class="rounded-2xl bg-slate-50 p-2"><dt class="text-slate-500">{{ __('Diterima anak') }}</dt><dd class="font-semibold text-slate-900">{{ $s->tanggal_distribusi->translatedFormat('j M Y') }}</dd></div>
                <div class="col-span-2 rounded-2xl bg-slate-50 p-2"><dt class="text-slate-500">{{ __('Maksimal per anak') }}</dt><dd class="font-semibold text-slate-900">{{ $rp($s->maks_per_santri) }} &middot; {{ __('kelipatan Rp 50.000') }}</dd></div>
            </dl>

            <div class="mt-4 space-y-3">
                @foreach ($s->santri as $a)
                    @php
                        $p = $s->pengajuan->where('id_santri', $a->id)->first();
                        $hidup = $p && in_array($p->status, ['diajukan', 'disetujui'], true);
                        $tersedia = \App\Services\Modules\PenarikanTunaiService::tersedia($a->id, $a->dompet?->saldo ?? 0);
                        $batasPilih = min((int) $s->maks_per_santri, (int) floor(((float) $tersedia + ($hidup ? (float) $p->nominalBerlaku() : 0)) / 50000) * 50000);
                    @endphp
                    <div class="rounded-2xl border border-slate-200 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $a->nama }}</p>
                                <p class="text-xs text-slate-500">{{ __('Uang saku bisa dipakai :nominal', ['nominal' => $rp(max(0, (float) $tersedia))]) }}</p>
                            </div>
                            @if ($p)
                                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $warna[$p->statusEfektif()] }}">{{ __($p->labelStatus()) }}</span>
                            @endif
                        </div>

                        @if ($p)
                            <p class="mt-2 text-sm text-slate-700">
                                {{ __('Diajukan :nominal', ['nominal' => $rp($p->nominal)]) }}
                                @if ($p->nominal_disetujui !== null && (float) $p->nominal_disetujui !== (float) $p->nominal)
                                    &middot; {!! __('disetujui :nominal', ['nominal' => '<b>'.e($rp($p->nominal_disetujui)).'</b>']) !!}
                                @endif
                                <span class="text-xs text-slate-500">({{ $p->nomor }})</span>
                            </p>
                            @if ($p->status === 'ditolak' && $p->alasan_tolak)
                                <p class="text-xs text-red-700">{{ $p->alasan_tolak }}</p>
                            @endif
                        @endif

                        @if ($bukaAju && (! $p || $hidup || in_array($p->status, ['ditolak', 'dibatalkan'], true)))
                            @if ($batasPilih < 50000)
                                <p class="mt-2 text-xs text-slate-500">{{ __('Uang saku belum cukup untuk mengajukan (minimal Rp 50.000).') }}</p>
                            @else
                                <form method="POST" action="{{ $hidup ? route('portal.tarik_tunai.ubah', $p->id) : route('portal.tarik_tunai.ajukan') }}" data-no-confirm
                                      class="mt-3 flex items-end gap-2" x-data="pinPad(@js($punyaPin), @js(route('portal.pin')))" @submit.prevent="buka()">
                                    @csrf
                                    <input type="hidden" name="id_sesi" value="{{ $s->id_sesi }}">
                                    <input type="hidden" name="id_santri" value="{{ $a->id }}">
                                    <div class="flex-1">
                                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ $hidup ? __('Ubah nominal') : __('Nominal') }}</label>
                                        <select name="nominal" required class="w-full rounded-2xl border border-slate-300 bg-white px-3 py-2.5 text-base">
                                            @for ($n = 50000; $n <= $batasPilih; $n += 50000)
                                                <option value="{{ $n }}" @selected($hidup && (int) $p->nominal === $n)>{{ $rp($n) }}</option>
                                            @endfor
                                        </select>
                                    </div>
                                    <button class="rounded-2xl bg-brand px-4 py-2.5 text-sm font-bold text-white">{{ $hidup ? __('Ubah') : __('Ajukan') }}</button>
                                    @include('portal._pin', ['judul' => $hidup
                                        ? __('Ubah uang saku kegiatan untuk :nama', ['nama' => $a->nama])
                                        : __('Ajukan uang saku kegiatan untuk :nama', ['nama' => $a->nama])])
                                </form>
                                @if ($hidup)
                                    <form method="POST" action="{{ route('portal.tarik_tunai.batal', $p->id) }}" data-confirm="{{ __('Batalkan pengajuan untuk :nama?', ['nama' => $a->nama]) }}" class="mt-2">
                                        @csrf<button class="text-xs font-semibold text-red-700">{{ __('Batalkan pengajuan') }}</button>
                                    </form>
                                @endif
                            @endif
                        @elseif (! $p)
                            <p class="mt-2 text-xs text-slate-500">{{ __('Batas pengajuan sudah lewat.') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-slate-500">{{ __('Selama diajukan atau disetujui, uang saku sebesar itu dicadangkan — tak bisa dipakai belanja atau dipindah.') }}
                {{ __('Pengajuan yang belum diputus sampai akhir :tanggal gugur dengan sendirinya.', ['tanggal' => $s->batas_persetujuan->translatedFormat('j M Y')]) }}</p>
        </section>
    @empty
        <div class="rounded-3xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">{{ __('Tidak ada kegiatan yang membuka pengajuan uang saku saat ini.') }}</div>
    @endforelse
@endsection
