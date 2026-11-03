@extends('layouts.app')

@section('title', 'Kuota & Tagihan Laundry')

@section('content')
    @php $boleh = \App\Support\Akses::boleh('laundry-tagihan', 'buat'); $bolehUbah = \App\Support\Akses::boleh('laundry-tagihan', 'ubah'); @endphp
    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex items-end gap-2 text-sm">
            <label><span class="block text-xs text-gray-500">Bulan</span>
                <input type="month" name="periode" value="{{ $periode }}" class="rounded-lg border-gray-300 text-sm"></label>
            <label><span class="block text-xs text-gray-500">Bagian</span>
                <select name="bagian" class="rounded-lg border-gray-300 text-sm">
                    <option value="">Ikhwan &amp; Akhwat</option>
                    @foreach (\App\Support\BagianLaundry::BAGIAN as $k => $b)<option value="{{ $k }}" @selected($bagian === $k)>{{ $b['label'] }}</option>@endforeach
                </select></label>
            <button class="rounded-lg border border-gray-300 px-3 py-2 hover:bg-gray-50">Tampilkan</button>
        </form>
        @if (! $berjalan && $boleh)
            <div class="flex gap-2">
                <form method="POST" action="{{ route('laundry.tagihan.susun') }}" data-no-confirm>@csrf <input type="hidden" name="periode" value="{{ $periode }}">
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">Susun ulang draf</button></form>
                <form method="POST" action="{{ route('laundry.tagihan.terbitkan') }}" data-confirm="Terbitkan seluruh draf kelebihan laundry {{ $bulan }} sebagai tagihan ke wali?">@csrf <input type="hidden" name="periode" value="{{ $periode }}">
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Terbitkan sekarang</button></form>
            </div>
        @endif
    </div>

    {{-- Saklar terbit otomatis (permintaan user 2 Nov 2026). --}}
    <div @class(['mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm',
        'border-emerald-200 bg-emerald-50' => $pengaturan->terbit_otomatis, 'border-gray-200 bg-gray-50' => ! $pengaturan->terbit_otomatis])>
        <p>
            Terbit otomatis:
            @if ($pengaturan->terbit_otomatis)
                <b class="text-emerald-700">NYALA</b> — draf kelebihan diterbitkan sendiri tiap tanggal {{ $pengaturan->hari_terbit }}.
            @else
                <b class="text-gray-700">MATI</b> — tagihan hanya terbit bila ditekan "Terbitkan sekarang".
            @endif
        </p>
        @if (\App\Support\Akses::boleh('laundry-master', 'ubah'))
            <form method="POST" action="{{ route('laundry.tagihan.otomatis') }}"
                  data-confirm="{{ $pengaturan->terbit_otomatis ? 'Matikan terbit otomatis? Tagihan kelebihan harus diterbitkan manual.' : 'Nyalakan terbit otomatis? Draf akan terbit sendiri tiap tanggal '.$pengaturan->hari_terbit.'.' }}">
                @csrf
                <input type="hidden" name="aktif" value="{{ $pengaturan->terbit_otomatis ? 0 : 1 }}">
                <button @class(['rounded-lg px-3 py-1.5 text-sm font-semibold',
                    'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $pengaturan->terbit_otomatis,
                    'bg-emerald-600 text-white hover:bg-emerald-700' => ! $pengaturan->terbit_otomatis])>{{ $pengaturan->terbit_otomatis ? 'Matikan' : 'Nyalakan' }}</button>
            </form>
        @endif
    </div>

    @if ($pengaturan->tarif_per_kg === null)
        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">Tarif kelebihan per kg belum diatur — tagihan tak bisa disusun. Atur di <a href="{{ route('laundry.pengaturan') }}" class="underline">Pengaturan Laundry</a>.</p>
    @endif

    <div class="mt-3 rounded-xl border border-gray-200 bg-white p-4 text-sm shadow-sm">
        <p class="font-semibold text-gray-900">{{ $bulan }} {{ $berjalan ? '— bulan berjalan (perkiraan)' : '' }}</p>
        <p class="mt-1 text-gray-600">{{ number_format($ringkas['kg'], 2, ',', '.') }} kg dari {{ $ringkas['santri'] }} santri ·
            tarif kelebihan {{ $pengaturan->tarif_per_kg !== null ? 'Rp '.number_format((float) $pengaturan->tarif_per_kg, 0, ',', '.').'/kg' : 'belum diatur' }}</p>
        @if ($berjalan)
            <p class="mt-1 text-xs text-gray-500">Belum bisa ditagih: kuota bulan ini masih berjalan. Draf disusun otomatis tanggal 1 bulan depan.</p>
        @elseif ($baris->where('status', 'draf')->isNotEmpty())
            <p class="mt-1 text-xs text-amber-700">
                @if ($pengaturan->terbit_otomatis)
                    Draf akan <b>terbit otomatis {{ $tanggalTerbit->translatedFormat('j F Y') }}</b>. Periksa dulu; kecualikan santri bila perlu.
                @else
                    Terbit otomatis dimatikan — terbitkan sendiri bila sudah diperiksa.
                @endif
            </p>
        @endif
    </div>

    <div class="mt-3 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500">
                <tr><th class="px-3 py-2 text-start">Santri</th><th class="px-3 py-2 text-end">Terpakai</th><th class="px-3 py-2 text-end">Kuota</th>
                    <th class="px-3 py-2 text-end">Lebih</th><th class="px-3 py-2 text-end">Nominal</th><th class="px-3 py-2 text-start">Status</th><th class="px-3 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($baris as $t)
                    @php $lebih = (float) $t->kg_lebih > 0; @endphp
                    <tr @class(['bg-amber-50/40' => $berjalan && $lebih])>
                        <td class="px-3 py-2">{{ $t->santri?->nama }} <span class="text-xs text-gray-400">{{ $t->santri?->kelas?->nama ?? $t->santri?->nis }}</span></td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format((float) $t->kg_total, 2, ',', '.') }} kg</td>
                        <td class="px-3 py-2 text-end tabular-nums">{{ number_format((float) $t->kuota_kg, 2, ',', '.') }}</td>
                        <td @class(['px-3 py-2 text-end tabular-nums', 'font-semibold text-red-700' => $lebih])>{{ number_format((float) $t->kg_lebih, 2, ',', '.') }}</td>
                        <td class="px-3 py-2 text-end tabular-nums">
                            @if ($t->nominal !== null) @rp($t->nominal) @else — @endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($t->status === 'perkiraan')
                                <span class="text-xs text-gray-500">{{ $lebih ? 'melebihi kuota' : 'dalam kuota' }}</span>
                            @else
                                {{ \App\Models\TagihanLaundry::LABEL[$t->status] }}
                                @if ($t->status === 'dikecualikan')<span class="block text-xs text-gray-500">{{ $t->alasan_kecuali }}</span>@endif
                            @endif
                        </td>
                        <td class="px-3 py-2 text-end">
                            @if ($bolehUbah && $t->status === 'draf')
                                <form method="POST" action="{{ route('laundry.tagihan.kecualikan', $t->id) }}" x-data="{ buka: false }" class="inline-flex gap-1">
                                    @csrf
                                    <button type="button" x-show="! buka" @click="buka = true" class="text-xs text-gray-600 underline">kecualikan</button>
                                    <input x-show="buka" x-cloak name="alasan" required placeholder="alasan" class="w-36 rounded border-gray-300 text-xs">
                                    <button x-show="buka" x-cloak class="text-xs font-semibold text-red-700">simpan</button>
                                </form>
                            @elseif ($bolehUbah && $t->status === 'dikecualikan')
                                <form method="POST" action="{{ route('laundry.tagihan.batal_kecuali', $t->id) }}" data-no-confirm>@csrf
                                    <button class="text-xs text-gray-600 underline">tarik pengecualian</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">{{ $berjalan ? 'Belum ada cucian bulan ini.' : 'Tidak ada santri yang melebihi kuota (atau draf belum disusun).' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
