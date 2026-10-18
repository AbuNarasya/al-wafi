@extends('layouts.pos')

@section('title', 'Retur Penjualan')
@section('kepala', 'Retur — '.$shift->lokasi->nama)
@section('subkepala', 'Uang kembali keluar dari laci shift '.$shift->nomor.' · batas retur '.($shift->lokasi->batas_hari_retur ?? 7).' hari')

@section('aksi')
    <a href="{{ route('pos.index') }}" class="rounded bg-white px-2 py-1 text-xs font-semibold text-brand-dark hover:bg-white/90">&larr; Kembali ke Kasir</a>
@endsection

@section('content')
    @php
        $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    @endphp
    <div class="mx-auto max-w-3xl space-y-4">
        <form method="GET" class="flex gap-2">
            <input type="text" name="struk" value="{{ $nomor }}" autofocus placeholder="Scan / ketik nomor struk (JL-…)"
                   class="flex-1 rounded-xl border-2 border-brand/40 px-4 py-3 text-lg focus:border-brand focus:ring-0">
            <button class="rounded-xl bg-brand px-5 font-semibold text-white hover:bg-brand-dark">Cari</button>
        </form>

        @if ($nomor !== '' && ! $jual)
            <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">Struk {{ $nomor }} tidak ditemukan.</div>
        @elseif ($jual)
            @if ($jual->status !== 'selesai')
                <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">Struk {{ $jual->nomor }} sudah dibatalkan.</div>
            @else
                <form method="POST" action="{{ route('pos.retur.simpan') }}" class="space-y-4"
                      onsubmit="return confirm('Simpan retur dan kembalikan uangnya ke pembeli?')">
                    @csrf
                    <input type="hidden" name="nomor_struk" value="{{ $jual->nomor }}">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <div><span class="font-mono font-semibold">{{ $jual->nomor }}</span> · {{ $jual->waktu->format('d/m/Y H:i') }} · kasir {{ $jual->kasir?->nama }}</div>
                            <div>Dibayar <b class="tabular-nums">{{ $rp($jual->total) }}</b></div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <tr><th class="px-3 py-2">Barang</th><th class="px-3 py-2 text-right">Dibeli</th><th class="px-3 py-2 text-right">Bisa diretur</th><th class="px-3 py-2">Jumlah retur</th><th class="px-3 py-2">Kondisi</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($jual->details as $i => $d)
                                    @php $sisa = (float) $d->kuantiti_input - (float) ($sudah[$d->id] ?? 0); @endphp
                                    <tr class="{{ $sisa <= 0 ? 'text-gray-400' : '' }}">
                                        <td class="px-3 py-2">
                                            {{ $d->persediaan?->nama_persediaan }}
                                            <div class="text-xs text-gray-500">{{ $rp($d->total) }} untuk {{ $angka($d->kuantiti_input) }} {{ $d->satuan }}</div>
                                            <input type="hidden" name="baris[{{ $i }}][id_penjualan_detail]" value="{{ $d->id }}">
                                        </td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $angka($d->kuantiti_input) }} {{ $d->satuan }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $angka(max(0, $sisa)) }}</td>
                                        <td class="px-3 py-2">
                                            <input type="number" name="baris[{{ $i }}][kuantiti]" min="0" max="{{ max(0, $sisa) }}" step="1" value="{{ old('baris.'.$i.'.kuantiti', 0) }}"
                                                   @disabled($sisa <= 0) class="w-20 rounded border-gray-300 text-right text-sm">
                                        </td>
                                        <td class="px-3 py-2">
                                            <select name="baris[{{ $i }}][kondisi]" @disabled($sisa <= 0) class="rounded border-gray-300 text-sm">
                                                <option value="layak" @selected(old('baris.'.$i.'.kondisi') !== 'rusak')>Layak jual — kembali ke rak</option>
                                                <option value="rusak" @selected(old('baris.'.$i.'.kondisi') === 'rusak')>Rusak — dibuang</option>
                                            </select>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-500">Uang kembali dihitung dari harga yang DIBAYAR di struk ini (sudah termasuk promo & diskon), bukan harga hari ini.</p>

                    <div class="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:grid-cols-3">
                        <input type="text" name="alasan" value="{{ old('alasan') }}" required maxlength="255" placeholder="Alasan retur" class="rounded-lg border-gray-300 text-sm sm:col-span-3">
                        <select name="id_pengawas" required class="rounded-lg border-gray-300 text-sm">
                            <option value="">— pengawas —</option>
                            @foreach ($pengawas as $p)<option value="{{ $p['id'] }}" @selected((string) old('id_pengawas') === (string) $p['id'])>{{ $p['nama'] }}</option>@endforeach
                        </select>
                        <input type="password" inputmode="numeric" name="pin" required maxlength="6" placeholder="PIN pengawas" autocomplete="off"
                               class="rounded-lg border-gray-300 text-center tracking-widest">
                        <button class="rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white hover:bg-amber-700">Simpan Retur</button>
                        @if (! $pengawas)
                            <p class="text-xs text-amber-800 sm:col-span-3">Belum ada pengawas yang memasang PIN (menu Profil) — retur belum bisa disetujui.</p>
                        @endif
                    </div>
                </form>
            @endif
        @endif
    </div>
@endsection
