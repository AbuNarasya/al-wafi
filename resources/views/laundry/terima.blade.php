@extends('layouts.app')

@section('title', 'Terima Cucian')

@section('content')
    <a href="{{ route('laundry.index') }}" class="text-sm text-brand hover:underline">&larr; Papan Laundry</a>

    @unless ($santri)
        @include('laundry._kenali', ['rute' => 'laundry.terima', 'judul' => 'Terima cucian — tap kartu santri'])
    @else
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <form method="POST" action="{{ route('laundry.simpan_terima') }}" data-no-confirm class="space-y-4 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm lg:col-span-2"
                  x-data="{ item: @js(old('item', [['id_jenis_pakaian' => '', 'jumlah' => '']])), berat: @js(old('berat', '')),
                            terpakai: @js((float) $kuota['terpakai']), kuota: @js((float) $kuota['kuota']), tarif: @js($kuota['tarif'] !== null ? (float) $kuota['tarif'] : null),
                            get potong() { return this.item.reduce((t, b) => t + (parseInt(b.jumlah) || 0), 0) },
                            get sesudah() { return this.terpakai + (parseFloat(this.berat) || 0) },
                            get lebih() { return Math.max(0, this.sesudah - this.kuota) } }">
                @csrf
                @if ($cara === 'kartu')<input type="hidden" name="kartu" value="{{ $uid }}">@else<input type="hidden" name="santri" value="{{ $santri->id }}">@endif
                <div class="flex items-center justify-between">
                    <p class="text-base font-semibold text-gray-900">{{ $santri->nama }}
                        <span class="text-sm font-normal text-gray-500">{{ $santri->nis }} · {{ $santri->jenjang?->nama }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></p>
                    <a href="{{ route('laundry.terima') }}" class="text-xs text-brand hover:underline">santri lain</a>
                </div>
                @if ($cara === 'manual')
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Tanpa kartu — dicatat sebagai pencarian manual.</p>
                @endif

                <label class="block max-w-xs"><span class="text-xs text-gray-500">Berat (kg) *</span>
                    <input name="berat" type="number" step="0.01" min="0.01" max="{{ \App\Services\Modules\LaundryService::BERAT_MAKS }}" required autofocus
                           x-model="berat" class="w-full rounded-lg border-gray-300 text-lg font-semibold tabular-nums"></label>

                {{-- Kuota bulan ini (tahap 2): terpakai sebelumnya + setoran ini. Kelebihannya ditagih awal bulan depan. --}}
                <div class="rounded-lg px-3 py-2 text-xs" :class="lebih > 0 ? 'bg-amber-50 text-amber-900 ring-1 ring-amber-200' : 'bg-gray-50 text-gray-600'">
                    Kuota {{ now()->locale('id')->translatedFormat('F') }}: <b>{{ number_format((float) $kuota['kuota'], 1, ',', '.') }} kg</b> ·
                    terpakai <b x-text="sesudah.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></b> kg
                    <span x-show="lebih > 0"> · <b>melebihi kuota <span x-text="lebih.toLocaleString('id-ID', { maximumFractionDigits: 2 })"></span> kg</b>
                        <span x-show="tarif !== null"> — perkiraan tagihan bulan depan Rp <span x-text="Math.round(lebih * (tarif || 0)).toLocaleString('id-ID')"></span></span></span>
                    <span x-show="lebih <= 0"> · sisa <span x-text="Math.max(0, kuota - sesudah).toLocaleString('id-ID', { maximumFractionDigits: 2 })"></span> kg</span>
                </div>

                <div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs text-gray-500">Daftar pakaian * — dicocokkan lagi saat diserahkan</span>
                        <span class="text-xs text-gray-500"><b x-text="potong"></b> potong</span>
                    </div>
                    <template x-for="(b, i) in item" :key="i">
                        <div class="mt-1 flex gap-2">
                            <select :name="`item[${i}][id_jenis_pakaian]`" x-model="b.id_jenis_pakaian" class="flex-1 rounded-lg border-gray-300 text-sm">
                                <option value="">— jenis pakaian —</option>
                                @foreach ($jenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach
                            </select>
                            <input :name="`item[${i}][jumlah]`" x-model="b.jumlah" type="number" min="1" max="200" placeholder="jml" class="w-20 rounded-lg border-gray-300 text-sm">
                            <button type="button" @click="item.splice(i, 1)" class="px-2 text-gray-400" x-show="item.length > 1">&times;</button>
                        </div>
                    </template>
                    <button type="button" @click="item.push({ id_jenis_pakaian: '', jumlah: '' })" class="mt-1 text-xs text-brand hover:underline">+ pakaian</button>
                </div>

                <label class="block"><span class="text-xs text-gray-500">Catatan (noda, sobek, pakaian khusus, …)</span>
                    <textarea name="catatan" rows="2" class="w-full rounded-lg border-gray-300 text-sm">{{ old('catatan') }}</textarea></label>
                <button class="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Simpan &amp; terima</button>
            </form>

            <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
                <p class="mb-2 font-semibold text-gray-900">Cucian yang masih di laundry</p>
                @forelse ($berjalan as $b)
                    <a href="{{ route('laundry.show', $b->id) }}" class="flex justify-between border-t border-gray-100 py-1.5 first:border-0 hover:underline">
                        <span>{{ $b->nomor }} · {{ $b->berat }} kg</span><span class="text-xs text-gray-500">{{ $b->labelStatus() }}</span></a>
                @empty
                    <p class="text-gray-400">Tidak ada.</p>
                @endforelse
            </div>
        </div>
    @endunless
@endsection
