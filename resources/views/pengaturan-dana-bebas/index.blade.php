@extends('layouts.app')

@section('title', 'Akun Pengurang Dana Bebas')

@section('content')
<div class="mx-auto max-w-4xl space-y-4">
    <div>
        <h2 class="text-xl font-semibold text-gray-900">Akun Pengurang Dana Bebas</h2>
        <p class="mt-1 max-w-2xl text-sm text-gray-600">
            Saldo kas &amp; bank bukan uang yang boleh dibelanjakan seluruhnya — di dalamnya ada uang milik
            orang lain yang kebetulan disimpan di rekening yang sama. Akun yang dicentang di sini
            <b>dikurangkan</b> saat menghitung dana yang bisa dipakai.
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Saldo kas &amp; bank</div><div class="tabular-nums font-semibold">@rp($dana['saldo_kas'])</div></div>
            <div><div class="text-xs text-gray-400">Pengurang</div><div class="tabular-nums font-semibold text-red-700">@rp($dana['pengurang'])</div></div>
            <div><div class="text-xs text-gray-400">Komitmen perintah</div><div class="tabular-nums font-semibold text-red-700">@rp($dana['komitmen'])</div></div>
            <div><div class="text-xs text-gray-400">Dana bebas dipakai</div><div class="tabular-nums text-lg font-bold text-emerald-700">@rp($dana['dana_bebas'])</div></div>
        </div>
    </div>

    <form method="POST" action="{{ route('pengaturan_dana_bebas.update') }}"
          x-data="{ cari: '', hanyaKewajiban: true }">
        @csrf @method('PUT')

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2">
                <span class="text-sm font-medium text-gray-700">Pilih akun</span>
                <label class="ml-auto flex items-center gap-1 text-xs text-gray-600">
                    <input type="checkbox" x-model="hanyaKewajiban" class="rounded border-gray-300 text-brand">
                    Hanya akun kewajiban
                </label>
                <input type="text" x-model="cari" placeholder="Cari kode / nama akun…"
                       class="rounded-lg border border-gray-300 px-2 py-1 text-xs">
            </div>

            <div class="max-h-[32rem] overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="w-14 px-3 py-2">Kurangi</th>
                            <th class="px-3 py-2">Akun</th>
                            <th class="px-3 py-2">Nama</th>
                            <th class="px-3 py-2">Saldo Normal</th>
                            <th class="px-3 py-2 text-right">Saldo Kini</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($akun as $a)
                            @php $aktif = in_array($a->kode_coa, $dipilih, true); @endphp
                            <tr class="{{ $aktif ? 'bg-brand-soft/40' : '' }}"
                                x-show="(!hanyaKewajiban || @js($a->jenis_saldo === 'kredit'))
                                        && (cari === '' || @js(mb_strtolower($a->kode_coa.' '.$a->nama_coa)).includes(cari.toLowerCase()))">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="kode_coa[]" value="{{ $a->kode_coa }}" @checked($aktif)
                                           class="rounded border-gray-300 text-brand focus:ring-brand">
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $a->kode_coa }}</td>
                                <td class="px-3 py-2">{{ $a->nama_coa }}</td>
                                <td class="px-3 py-2 text-xs text-gray-500">{{ $a->jenis_saldo }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    @if ($rincian->has($a->kode_coa))
                                        @rp($rincian[$a->kode_coa]['saldo'])
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-4 py-3">
                <span class="text-xs text-gray-600">
                    Mengubah daftar ini mengubah batas seluruh perintah pembayaran.
                </span>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </div>
    </form>
</div>
@endsection
