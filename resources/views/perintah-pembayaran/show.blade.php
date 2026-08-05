@extends('layouts.app')

@section('title', 'Perintah Pembayaran ' . $pp->nomor)

@php
    $warna = [
        'draf' => 'bg-gray-100 text-gray-600', 'menunggu' => 'bg-amber-100 text-amber-800',
        'diotorisasi' => 'bg-indigo-100 text-indigo-700', 'sebagian' => 'bg-blue-100 text-blue-700',
        'terbayar' => 'bg-emerald-100 text-emerald-700', 'selesai' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-red-100 text-red-700',
    ];
    $warnaBaris = [
        'diajukan' => 'bg-gray-100 text-gray-600', 'disetujui' => 'bg-emerald-100 text-emerald-700',
        'ditunda' => 'bg-amber-100 text-amber-800', 'batal' => 'bg-red-100 text-red-700',
    ];
    $labelSumber = \App\Models\PerintahPembayaranDetail::SUMBER;
    $sedangOtorisasi = $pp->status === 'menunggu' && $bolehOtorisasi;
    // Lunas tapi belum ditutup — di sinilah tombol "PP Sudah Selesai" disodorkan.
    $siapDitutup = in_array($pp->status, ['diotorisasi', 'sebagian', 'terbayar'], true);
@endphp

@section('content')
<div class="mx-auto max-w-6xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('perintah_pembayaran.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
        <div class="flex items-center gap-2">
            <a href="{{ route('perintah_pembayaran.print', $pp->kode_transaksi) }}" target="_blank"
               class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">🖨 Cetak</a>
            @if ($pp->status === 'draf' && \App\Support\Akses::boleh('perintah-pembayaran', 'ubah'))
                <form method="POST" action="{{ route('perintah_pembayaran.ajukan', $pp->kode_transaksi) }}">
                    @csrf
                    <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Ajukan Otorisasi</button>
                </form>
            @endif
            @if ($pp->bolehDibayar() && \App\Support\Akses::boleh('cash-out', 'buat'))
                <a href="{{ route('cash_out.create', ['perintah' => $pp->kode_transaksi]) }}"
                   class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">Register Kas Keluar</a>
            @endif
        </div>
    </div>

    {{-- Kepala dokumen --}}
    <div class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
        <div><div class="text-xs text-gray-400">Nomor</div><div class="font-mono font-semibold text-gray-900">{{ $pp->nomor }}</div></div>
        <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $pp->tanggal->format('d M Y') }}</div></div>
        <div><div class="text-xs text-gray-400">Status</div>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warna[$pp->status] ?? '' }}">{{ \App\Models\PerintahPembayaran::STATUS[$pp->status] ?? $pp->status }}</span>
        </div>
        <div><div class="text-xs text-gray-400">Disusun</div><div>{{ $pp->penyusun?->nama ?? '—' }}</div></div>
        <div class="sm:col-span-2"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $pp->keterangan }}</div></div>
        <div><div class="text-xs text-gray-400">Tanggal Bayar</div><div>{{ $pp->tanggal_bayar?->format('d M Y') ?? ($pp->tanggal_usulan ? 'usulan '.$pp->tanggal_usulan->format('d/m/Y') : '—') }}</div></div>
        <div><div class="text-xs text-gray-400">Metode</div><div>{{ \App\Models\PerintahPembayaran::METODE[$pp->metode] ?? '—' }}</div></div>
    </div>

    @if ($pp->diotorisasi_pada)
        <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm text-indigo-900">
            Diotorisasi <b>{{ $pp->pengotorisasi?->nama }}</b> pada {{ $pp->diotorisasi_pada->format('d M Y H:i') }}
            @if ($pp->catatan_otorisasi) — {{ $pp->catatan_otorisasi }} @endif
        </div>
    @endif
    @if ($pp->status === 'ditolak')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-800">
            <b>Ditolak.</b> {{ $pp->alasan_tolak }}
        </div>
    @endif
    @if ($pp->ditutup_pada)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900">
            <b>Dinyatakan selesai</b> pada {{ $pp->ditutup_pada->format('d M Y H:i') }}.
            @if ($pp->alasan_tutup) Alasan: {{ $pp->alasan_tutup }} @endif
            <div class="mt-0.5 text-xs">Kewajiban yang belum direalisasikan dinyatakan batal dibayar dari perintah ini — dokumennya sendiri tetap utuh dan bisa diajukan lagi.</div>
        </div>
    @endif

    <form method="POST" action="{{ route('perintah_pembayaran.otorisasi', $pp->kode_transaksi) }}" id="formOtorisasi">@csrf</form>

    {{-- Panel dana bebas: yang menonjol angka akhirnya, rinciannya dilipat. --}}
    @if ($sedangOtorisasi)
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:col-span-1" x-data="{ rinci: false }">
                <div class="text-xs uppercase tracking-wide text-gray-400">Dana yang bisa dipakai</div>
                <div class="text-2xl font-bold tabular-nums text-emerald-700">@rp($batasOtorisasi)</div>
                <button type="button" @click="rinci = !rinci" class="mt-1 text-xs text-brand hover:underline"
                        x-text="rinci ? '▾ Sembunyikan rincian' : '▸ Lihat rincian perhitungan'"></button>
                <div x-show="rinci" x-cloak class="mt-2 border-t border-gray-100 pt-2">
                    <table class="w-full text-xs">
                        <tr><td class="py-0.5 text-gray-600">Saldo kas &amp; bank</td><td class="py-0.5 text-right tabular-nums">@rp($dana['saldo_kas'])</td></tr>
                        <tr class="text-red-700"><td class="py-0.5">&minus; Titipan</td><td class="py-0.5 text-right tabular-nums">@rp($dana['pengurang'])</td></tr>
                        <tr class="text-red-700"><td class="py-0.5">&minus; Perintah lain</td><td class="py-0.5 text-right tabular-nums">@rp($dana['komitmen'])</td></tr>
                    </table>
                    <p class="mt-1 text-[11px] text-gray-500">Komitmen perintah ini sendiri tidak ikut dikurangkan.</p>
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm lg:col-span-2">
                <div class="mb-2 text-xs uppercase tracking-wide text-gray-400">Saldo aktual per rekening</div>
                <table class="w-full text-sm">
                    @foreach ($dana['rincian_kas'] as $k)
                        <tr class="border-b border-gray-50 last:border-0">
                            <td class="py-1 text-gray-700">{{ $k['nama'] }}</td>
                            <td class="py-1 text-xs text-gray-400">{{ $k['jenis'] }}</td>
                            <td class="py-1 text-right tabular-nums">@rp($k['saldo'])</td>
                        </tr>
                    @endforeach
                </table>
                <p class="mt-1 text-[11px] text-gray-500">Kenyataan fisik sebelum dikurangi apa pun — wajar bila jauh berbeda dari dana bebas.</p>
            </div>
        </div>
    @endif

    {{-- Rincian --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700">
            Rincian kewajiban ({{ $pp->detail->count() }})
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Dokumen</th>
                        <th class="px-3 py-2">Pihak</th>
                        <th class="px-3 py-2">Keterangan</th>
                        <th class="px-3 py-2">Riwayat</th>
                        <th class="px-3 py-2">Jatuh Tempo</th>
                        <th class="px-3 py-2 text-right">Diajukan</th>
                        <th class="px-3 py-2 text-right">Diotorisasi</th>
                        <th class="px-3 py-2 text-right">Terbayar</th>
                        <th class="px-3 py-2 text-right">Sisa</th>
                        <th class="px-3 py-2">Baris</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($pp->detail as $d)
                        <tr>
                            <td class="px-3 py-2">
                                <div class="font-mono text-xs">{{ $d->nomor_dokumen }}</div>
                                <div class="text-xs text-gray-400">{{ $labelSumber[$d->sumber] ?? $d->sumber }}{{ $d->kode_unit ? ' · '.($d->unit?->nama_unit ?? $d->kode_unit) : '' }}</div>
                                @if ($d->ditambahkan_pengotorisasi)
                                    <span class="mt-0.5 inline-block rounded bg-accent-soft px-1.5 py-0.5 text-[10px] font-medium text-amber-800">ditambahkan pengotorisasi</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $d->pihak ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $d->keterangan ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">
                                @if (empty($riwayat[$d->id]))
                                    <span class="text-gray-400">baru</span>
                                @else
                                    <span class="font-medium text-amber-800">ke-{{ count($riwayat[$d->id]) + 1 }}</span>
                                    @foreach (array_slice($riwayat[$d->id], -2) as $r)
                                        <div class="text-gray-500">{{ $r['nomor_pp'] }}: {{ \App\Models\PerintahPembayaranDetail::STATUS[$r['status']] ?? $r['status'] }}@if ($r['alasan']) — {{ $r['alasan'] }}@endif</div>
                                    @endforeach
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-500">{{ $d->jatuh_tempo?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-500">@rp($d->nominal_diajukan)</td>
                            <td class="px-3 py-2 text-right">
                                @if ($sedangOtorisasi)
                                    {{-- Bernomor ribuan. Di layar tempat orang menyetujui uang, angka
                                         telanjang seperti 12500000 mengundang kelebihan satu nol —
                                         dan kelebihan itu baru ketahuan setelah uangnya keluar. --}}
                                    <div x-data="{ n: {{ (int) $d->nominal_diajukan }} }">
                                        <input type="text" inputmode="numeric"
                                               :value="fmtRupiah(n)" @input="n = ketikRupiah($event)"
                                               class="w-36 rounded border border-gray-400 px-2 py-1 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand">
                                        <input type="hidden" form="formOtorisasi" name="baris[{{ $d->id }}]" :value="n">
                                        <p class="mt-0.5 text-[11px] text-gray-400" x-show="n === 0" x-cloak>akan ditunda</p>
                                    </div>
                                    <div class="mt-1"><input type="text" form="formOtorisasi" name="alasan[{{ $d->id }}]"
                                           placeholder="alasan bila ditunda / dikurangi"
                                           class="w-44 rounded border border-gray-300 px-2 py-1 text-xs"></div>
                                @else
                                    <span class="tabular-nums font-medium">@rp($d->nominal_diotorisasi)</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">@rp($d->terbayar)</td>
                            <td class="px-3 py-2 text-right tabular-nums font-medium">@rp($d->sisa)</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warnaBaris[$d->status_baris] ?? '' }}">
                                    {{ \App\Models\PerintahPembayaranDetail::STATUS[$d->status_baris] ?? $d->status_baris }}
                                </span>
                                @if ($d->alasan)<div class="mt-0.5 text-xs text-gray-500">{{ $d->alasan }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 text-sm font-semibold">
                    <tr>
                        <td class="px-3 py-2" colspan="5">Total</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-500">@rp($pp->total_diajukan)</td>
                        <td class="px-3 py-2 text-right tabular-nums">@rp($pp->total_diotorisasi)</td>
                        <td class="px-3 py-2" colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($sedangOtorisasi)
            <div class="space-y-3 border-t border-gray-200 bg-gray-50 px-4 py-3">
                <p class="text-xs text-gray-600">
                    Isi <b>nol</b> pada nominal untuk <b>menunda</b> baris itu — kewajibannya dilepas dan bisa diajukan lagi di perintah berikutnya.
                </p>
                <div class="grid gap-3 sm:grid-cols-4">
                    <x-field name="tanggal_bayar" label="Tanggal Bayar" type="date" form="formOtorisasi"
                             :value="old('tanggal_bayar', $pp->tanggal_usulan?->toDateString() ?? now()->toDateString())" required />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Metode <span class="text-red-500">*</span></label>
                        <select name="metode" form="formOtorisasi" required class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            @foreach (\App\Models\PerintahPembayaran::METODE as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Rekening Sumber (rencana)</label>
                        <select name="kode_rekening_rencana" form="formOtorisasi" class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            @foreach ($rekeningOptions as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-field name="catatan" label="Catatan Otorisasi" form="formOtorisasi" :value="old('catatan')" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="submit" form="formOtorisasi"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Otorisasi Pembayaran</button>
                </div>
            </div>
        @elseif ($pp->status === 'menunggu' && ! $bolehOtorisasi)
            <div class="border-t border-gray-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Menunggu otorisasi pejabat.
                @if ((int) $pp->disusun_oleh === (int) auth()->user()->id_pengguna)
                    <b>Anda penyusun perintah ini</b>, jadi tidak bisa mengotorisasinya sendiri — mintakan ke pejabat lain yang berwenang.
                @endif
            </div>
        @endif
    </div>

    {{-- Tolak & tutup --}}
    @if (\App\Support\Akses::boleh('otorisasi-pembayaran', 'ubah'))
        <div class="flex flex-wrap gap-3">
            @if ($pp->status === 'menunggu' && $bolehOtorisasi)
                <form method="POST" action="{{ route('perintah_pembayaran.tolak', $pp->kode_transaksi) }}"
                      class="flex items-center gap-2 rounded-xl border border-red-200 bg-white p-3 shadow-sm">
                    @csrf
                    <input type="text" name="alasan" required maxlength="255" placeholder="Alasan penolakan"
                           class="rounded border border-gray-300 px-2 py-1.5 text-sm">
                    <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Tolak Seluruhnya</button>
                </form>
            @endif

            @if ($siapDitutup)
                <form method="POST" action="{{ route('perintah_pembayaran.tutup', $pp->kode_transaksi) }}"
                      class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm"
                      onsubmit="return confirm('Nyatakan perintah ini selesai? Kewajiban yang belum dibayar akan dibatalkan dari perintah ini.')">
                    @csrf
                    <div>
                        <input type="text" name="alasan" maxlength="255" placeholder="Alasan (wajib bila masih ada sisa)"
                               class="w-72 rounded border border-gray-300 px-2 py-1.5 text-sm">
                        <p class="mt-1 text-xs text-gray-500">Alasan inilah yang menjawab “kenapa sisanya tidak dibayar?”.</p>
                    </div>
                    <button class="rounded-lg bg-brand-dark px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand">PP Sudah Selesai</button>
                </form>
            @endif
        </div>
    @endif
</div>
@endsection
