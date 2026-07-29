{{-- Bagian laporan: judul + grup akun (subtotal) + total.
     $detail (opsional) = ['from'=>…, 'to'=>…] → tampilkan kolom "Lihat detail"
     per akun yang menaut ke Buku Besar akun itu pada periode yang sama
     (drill-down Laba Rugi). Neraca/Perubahan Modal tak mengirimnya. --}}
@props(['section', 'extra' => null, 'detail' => null])

@php $span = $detail ? 4 : 3; @endphp

<div class="rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-gray-600">{{ $section['title'] }}</div>
    <table class="min-w-full text-sm">
        <tbody>
            @forelse ($section['groups'] as $g)
                <tr class="bg-gray-50">
                    <td class="px-4 py-2 font-medium text-gray-700" colspan="2">{{ $g['kode_grup'] }} — {{ $g['nama_grup'] }}</td>
                    <td class="px-4 py-2 text-right font-medium text-gray-700 tabular-nums">@rp($g['subtotal'])</td>
                    @if ($detail)<td></td>@endif
                </tr>
                @foreach ($g['accounts'] as $a)
                    <tr class="border-t border-gray-50">
                        <td class="px-4 py-1.5 pl-8 text-gray-500">{{ $a['kode_coa'] }}</td>
                        <td class="px-4 py-1.5 text-gray-700">{{ $a['nama_coa'] }}</td>
                        <td class="px-4 py-1.5 text-right text-gray-600 tabular-nums">@rp($a['nilai'])</td>
                        @if ($detail)
                            <td class="px-4 py-1.5 text-right">
                                {{-- Unit ikut dibawa: kalau laporannya sedang disaring per
                                     unit, buku besarnya harus menyaring hal yang sama —
                                     kalau tidak, angkanya tak akan cocok. --}}
                                <a href="{{ route('reports.buku_besar', array_filter(['kode_coa' => $a['kode_coa'], 'from' => $detail['from'], 'to' => $detail['to'], 'kode_unit' => $detail['kode_unit'] ?? null])) }}"
                                   target="_blank" class="text-xs text-brand hover:underline">Lihat detail</a>
                            </td>
                        @endif
                    </tr>
                @endforeach
            @empty
                <tr><td class="px-4 py-4 text-center text-gray-400" colspan="{{ $span }}">Tidak ada saldo.</td></tr>
            @endforelse
            @if ($extra)
                <tr class="border-t border-gray-100">
                    <td class="px-4 py-2 text-gray-600" colspan="2">{{ $extra['label'] }}</td>
                    <td class="px-4 py-2 text-right text-gray-700 tabular-nums">@rp($extra['nilai'])</td>
                    @if ($detail)<td></td>@endif
                </tr>
            @endif
            <tr class="border-t-2 border-gray-200 bg-gray-50">
                <td class="px-4 py-2.5 font-semibold text-gray-900" colspan="2">Total {{ $section['title'] }}</td>
                <td class="px-4 py-2.5 text-right font-semibold text-gray-900 tabular-nums">@rp($section['total'])</td>
                @if ($detail)<td></td>@endif
            </tr>
        </tbody>
    </table>
</div>
