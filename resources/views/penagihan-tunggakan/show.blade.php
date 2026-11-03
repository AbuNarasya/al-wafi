@extends('layouts.app')

@section('title', 'Penagihan — '.$santri->nama)

@php
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    $cara = \App\Models\CatatanPenagihan::CARA;
    $bolehCatat = \App\Support\Akses::boleh('penagihan-tunggakan', 'buat');
    $kini = \Illuminate\Support\Carbon::today();
    // Nomor HP → tautan WhatsApp (08xx → 628xx).
    $wa = function (?string $hp) {
        $d = preg_replace('/\D/', '', (string) $hp);
        if ($d === '') {
            return null;
        }

        return 'https://wa.me/'.(str_starts_with($d, '0') ? '62'.substr($d, 1) : $d);
    };
    $wali = $santri->wali;
    $totalLewat = $lewat->reduce(fn ($a, $t) => \App\Support\Money::add($a, $t->sisa), '0');
    $dipilih = collect(old('tagihan', $lewat->pluck('id')->all()))->map(fn ($v) => (int) $v)->all();
@endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('penagihan_tunggakan.index') }}" class="text-sm text-gray-500 hover:underline">← Penagihan Tunggakan</a>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $santri->nama }}</h2>
            <p class="text-sm text-gray-500">
                {{ $santri->nis ? 'NIS '.$santri->nis.' · ' : '' }}{{ $santri->jenjang?->nama ?? $santri->kode_jenjang }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}
                · <a href="{{ route('santri.show', $santri->id) }}" class="text-brand hover:underline">detail santri</a>
            </p>
        </div>
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-right">
            <div class="text-xs font-medium text-rose-700">Lewat jatuh tempo</div>
            <div class="text-xl font-bold tabular-nums text-rose-900">@rp($totalLewat)</div>
            <div class="text-[11px] text-rose-700/80">{{ $lewat->count() }} tagihan</div>
        </div>
    </div>


    {{-- Kontak wali: yang dibutuhkan pertama kali sebelum menagih. --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <h3 class="mb-2 text-sm font-semibold text-gray-700">Kontak Wali</h3>
        @if ($wali)
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (\App\Models\Wali::PERAN as $p => $label)
                    @continue(blank($wali->{'nama_'.$p}) && blank($wali->{'telepon_'.$p}))
                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                        <div class="text-xs text-gray-500">{{ $label }}{{ $wali->kontak_utama === $p ? ' · kontak utama' : '' }}</div>
                        <div class="font-medium text-gray-800">{{ $wali->{'nama_'.$p} ?: '—' }}</div>
                        @if ($hp = $wali->{'telepon_'.$p})
                            <div class="flex flex-wrap items-center gap-2 text-gray-600">
                                <a href="tel:{{ $hp }}" class="hover:underline">{{ $hp }}</a>
                                @if ($link = $wa($hp))
                                    <a href="{{ $link }}" target="_blank" rel="noopener" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-800 hover:bg-emerald-200">WhatsApp</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-gray-500">Santri ini belum terhubung ke data wali.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('penagihan_tunggakan.catat', $santri->id) }}"
          data-confirm="Simpan catatan penagihan untuk tagihan yang dicentang? Catatan tidak bisa diubah sesudahnya.">

        @csrf
        <div class="mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        @if ($bolehCatat)<th class="w-8 px-4 py-3"></th>@endif
                        <th class="px-4 py-3">Tagihan</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3 text-right">Sisa</th>
                        <th class="px-4 py-3">Jatuh Tempo</th>
                        <th class="px-4 py-3">Riwayat Penagihan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($lewat as $t)
                        <tr class="align-top">
                            @if ($bolehCatat)
                                <td class="px-4 py-2"><input type="checkbox" name="tagihan[]" value="{{ $t->id }}" @checked(in_array($t->id, $dipilih, true))
                                           class="rounded border-gray-300 text-brand focus:ring-brand" aria-label="Pilih {{ \App\Services\Modules\PenagihanTunggakanService::namaTagihan($t) }}"></td>
                            @endif
                            <td class="px-4 py-2 font-medium text-gray-800">{{ \App\Services\Modules\PenagihanTunggakanService::namaTagihan($t) }}
                                @if ($t->tahun_ajaran)<div class="text-[11px] text-gray-400">T.A {{ $t->tahun_ajaran }}</div>@endif</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums text-gray-600">@rp($t->nominal)</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums text-rose-700">@rp($t->sisa)</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $tgl($t->jatuh_tempo) }}
                                <div><span class="rounded bg-rose-100 px-1.5 py-0.5 text-[11px] text-rose-700">lewat {{ (int) \Illuminate\Support\Carbon::parse($t->jatuh_tempo)->diffInDays($kini) }} hari</span></div></td>
                            <td class="px-4 py-2 text-xs">
                                @forelse ($t->catatanPenagihan as $c)
                                    <div class="mb-2 border-l-2 border-gray-200 pl-2 last:mb-0">
                                        <div class="text-gray-700"><b>{{ $tgl($c->ditagih_pada) }}</b> · {{ $cara[$c->cara] ?? $c->cara }}
                                            <span class="text-gray-400">— {{ $c->pencatat?->nama ?? 'sistem' }}</span></div>
                                        <div class="whitespace-pre-line text-gray-600">{{ $c->tanggapan }}</div>
                                        @if ($c->janji_tanggal)
                                            <div class="{{ $c->janji_tanggal->lt($kini) && $loop->first ? 'font-medium text-amber-700' : 'text-sky-700' }}">
                                                Janji bayar {{ $tgl($c->janji_tanggal) }}@if ($c->janji_nominal) · Rp {{ number_format((float) $c->janji_nominal, 0, ',', '.') }}@endif
                                                {{ $c->janji_tanggal->lt($kini) && $loop->first ? '(sudah lewat)' : '' }}
                                            </div>
                                        @endif
                                        @if ($c->tindak_lanjut)<div class="text-gray-500">Berikutnya: {{ $c->tindak_lanjut }}</div>@endif
                                    </div>
                                @empty
                                    <span class="text-gray-400">belum pernah ditagih</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Tidak ada tagihan yang lewat jatuh tempo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($bolehCatat && $lewat->isNotEmpty())
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-700">Catat Penagihan</h3>
                <p class="mb-3 text-xs text-gray-500">Dicatat untuk setiap tagihan yang dicentang di atas. Catatan tidak bisa diubah atau dihapus — bila keliru, tulis catatan baru.</p>
                @error('tagihan')<p class="mb-2 text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block text-xs font-medium text-gray-600">Tanggal ditagih <span class="text-red-500">*</span>
                        <input type="date" name="ditagih_pada" required max="{{ $kini->format('Y-m-d') }}" value="{{ old('ditagih_pada', $kini->format('Y-m-d')) }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        @error('ditagih_pada')<span class="text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-xs font-medium text-gray-600">Cara menagih <span class="text-red-500">*</span>
                        <select name="cara" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            @foreach ($cara as $kode => $nama)
                                <option value="{{ $kode }}" @selected(old('cara', 'whatsapp') === $kode)>{{ $nama }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-medium text-gray-600">Janji bayar (tanggal)
                        <input type="date" name="janji_tanggal" value="{{ old('janji_tanggal') }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        @error('janji_tanggal')<span class="text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-xs font-medium text-gray-600">Janji bayar (nominal)
                        <x-input-rupiah name="janji_nominal" :value="old('janji_nominal')" class="mt-1" />
                    </label>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="block text-xs font-medium text-gray-600">Tanggapan wali <span class="text-red-500">*</span>
                        <textarea name="tanggapan" rows="3" required maxlength="2000" placeholder="mis. Ayah menyanggupi bayar setelah gajian, minta waktu sampai tanggal 10"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('tanggapan') }}</textarea>
                        @error('tanggapan')<span class="text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="block text-xs font-medium text-gray-600">Langkah berikutnya
                        <textarea name="tindak_lanjut" rows="3" maxlength="2000" placeholder="mis. Hubungi ulang tanggal 11 bila belum masuk"
                                  class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('tindak_lanjut') }}</textarea>
                    </label>
                </div>
                <button class="mt-3 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan catatan penagihan</button>
            </div>
        @endif
    </form>

    @if ($belum->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <h3 class="mb-2 text-sm font-semibold text-gray-700">Tagihan belum lunas yang belum jatuh tempo</h3>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($belum as $t)
                    <li class="flex justify-between gap-3 py-1.5">
                        <span class="text-gray-700">{{ \App\Services\Modules\PenagihanTunggakanService::namaTagihan($t) }}
                            <span class="text-xs text-gray-400">{{ $t->jatuh_tempo ? 'jatuh tempo '.$tgl($t->jatuh_tempo) : 'tanpa jatuh tempo' }}</span></span>
                        <span class="tabular-nums text-gray-600">@rp($t->sisa)</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
