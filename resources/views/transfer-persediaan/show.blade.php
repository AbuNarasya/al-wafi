@extends('layouts.app')

@section('title', 'Transfer ' . $doc->nomor)

@section('content')
    @php $angka = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ','); @endphp
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <a href="{{ route('transfer_persediaan.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if ($doc->status === 'dikirim' && \App\Support\Akses::boleh('transfer-persediaan', 'hapus'))
                <form method="POST" action="{{ route('transfer_persediaan.batal', $doc->id_transfer) }}" x-data="{ buka: false }" class="relative">
                    @csrf @method('DELETE')
                    <button type="button" @click="buka = !buka" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Batalkan Kiriman</button>
                    <div x-show="buka" x-cloak @click.outside="buka = false" class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        <p class="text-xs text-gray-600">Barang kembali ke {{ $doc->asal?->nama }} dengan harga pokok semula.</p>
                        <input type="text" name="alasan" required placeholder="Alasan pembatalan" class="w-full rounded border-gray-300 text-sm">
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Batalkan</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor</div><div class="font-mono font-semibold">{{ $doc->nomor }}</div></div>
            <div><div class="text-xs text-gray-400">Dari → Ke</div><div>{{ $doc->asal?->nama }} → {{ $doc->tujuan?->nama }}</div></div>
            <div><div class="text-xs text-gray-400">Dikirim</div><div>{{ $doc->tanggal_kirim->format('d M Y') }} · {{ $doc->pengirim?->nama ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div><div class="font-medium">{{ \App\Models\TransferPersediaan::STATUS[$doc->status] }}</div></div>
            @if ($doc->tanggal_terima)
                <div><div class="text-xs text-gray-400">Diterima</div><div>{{ $doc->tanggal_terima->format('d M Y') }} · {{ $doc->penerima?->nama ?? '—' }}</div></div>
            @endif
            @if ($doc->catatan_terima)<div class="sm:col-span-2"><div class="text-xs text-gray-400">Catatan terima</div><div>{{ $doc->catatan_terima }}</div></div>@endif
            @if ($doc->putusan)
                <div class="sm:col-span-2"><div class="text-xs text-gray-400">Putusan selisih</div>
                    <div>{{ $doc->putusan === 'kembali' ? 'Dikembalikan ke asal' : 'Dibukukan sebagai kehilangan' }} · {{ $doc->pemutus?->nama }}{{ $doc->catatan_putusan ? ' — '.$doc->catatan_putusan : '' }}</div></div>
            @elseif ($doc->catatan_putusan)
                <div class="sm:col-span-2"><div class="text-xs text-gray-400">Catatan</div><div>{{ $doc->catatan_putusan }}</div></div>
            @endif
            @if ($doc->keterangan)<div class="sm:col-span-4"><div class="text-xs text-gray-400">Keterangan</div><div>{{ $doc->keterangan }}</div></div>@endif
        </div>

        @php $bisaTerima = $doc->status === 'dikirim' && \App\Support\Akses::boleh('transfer-persediaan', 'ubah'); @endphp
        <form method="POST" action="{{ route('transfer_persediaan.terima', $doc->id_transfer) }}" class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            @csrf
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Barang</th><th class="px-4 py-3 text-right">Dikirim</th><th class="px-4 py-3 text-right">Diterima</th><th class="px-4 py-3 text-right">Selisih</th><th class="px-4 py-3 text-right">Nilai</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($doc->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->persediaan?->nama_persediaan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $angka($d->kuantiti_input) }} {{ $d->satuan }}
                                @if ((float) $d->isi > 1)<div class="text-xs text-gray-500">= {{ $angka($d->kuantiti) }} {{ $d->persediaan?->satuan }}</div>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{-- Diisi dalam satuan DASAR: dari 1 dus bisa tiba 20 pcs. --}}
                                @if ($bisaTerima)
                                    <input type="number" step="0.0001" min="0" max="{{ (float) $d->kuantiti }}" name="diterima[{{ $d->id }}]"
                                           value="{{ old('diterima.'.$d->id, $angka($d->kuantiti)) }}" class="w-24 rounded border-gray-300 text-right text-sm"> {{ $d->persediaan?->satuan }}
                                @elseif ($d->kuantiti_diterima !== null)
                                    {{ $angka($d->kuantiti_diterima) }} {{ $d->persediaan?->satuan }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums {{ (float) $d->selisih() > 0 ? 'font-medium text-red-600' : 'text-gray-400' }}">
                                {{ (float) $d->selisih() > 0 ? $angka($d->selisih()).' '.$d->persediaan?->satuan : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->nilai)</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($bisaTerima)
                <div class="flex flex-wrap items-end gap-3 border-t border-gray-100 p-4">
                    <div><label class="mb-1 block text-xs font-medium text-gray-600">Tanggal terima</label>
                        <input type="date" name="tanggal_terima" value="{{ now()->toDateString() }}" required class="rounded border-gray-300 text-sm"></div>
                    <div class="min-w-[14rem] flex-1"><label class="mb-1 block text-xs font-medium text-gray-600">Catatan (mis. 2 pcs penyok, 1 dus kurang)</label>
                        <input type="text" name="catatan_terima" class="w-full rounded border-gray-300 text-sm"></div>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Catat Penerimaan</button>
                    <p class="w-full text-xs text-gray-500">Isi jumlah yang benar-benar tiba. Kekurangan tertahan di <b>Dalam Perjalanan</b> sampai diputus atasan.</p>
                </div>
            @endif
        </form>

        @if ($doc->status === 'selisih')
            @if (\App\Support\Akses::boleh('persetujuan-persediaan', 'ubah'))
                <form method="POST" action="{{ route('transfer_persediaan.putus', $doc->id_transfer) }}" x-data="{ putusan: 'kembali' }"
                      class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                    @csrf
                    <div class="font-semibold text-amber-900">Putuskan selisih</div>
                    <label class="flex items-start gap-2"><input type="radio" name="putusan" value="kembali" x-model="putusan" class="mt-1">
                        <span><b>Kembalikan ke {{ $doc->asal?->nama }}</b> — barang ternyata tak ikut terkirim / masih di asal.</span></label>
                    <label class="flex items-start gap-2"><input type="radio" name="putusan" value="hilang" x-model="putusan" class="mt-1">
                        <span><b>Bukukan sebagai kehilangan</b> — dijurnal ke akun Selisih Persediaan (beban).</span></label>
                    <div x-show="putusan === 'hilang'" class="max-w-xs">
                        <x-field name="kode_bagian" label="Bagian yang menanggung" :options="$bagianOptions" />
                    </div>
                    <input type="text" name="catatan_putusan" placeholder="Catatan (opsional)" class="w-full rounded border-gray-300 text-sm">
                    <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Simpan Putusan</button>
                </form>
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Ada selisih kirim–terima. Barangnya tertahan <b>Dalam Perjalanan</b> sampai pemegang hak Persetujuan Persediaan memutusnya.
                </div>
            @endif
        @endif
    </div>
@endsection
