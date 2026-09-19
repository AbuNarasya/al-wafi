@extends('layouts.app')

@section('title', 'Arus Kas')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Unit Bisnis</label>
                <select name="kode_unit" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                    <option value="">Semua unit</option>
                    @foreach ($unitOptions as $kode => $nama)
                        <option value="{{ $kode }}" @selected($unit === $kode)>{{ $nama }}</option>
                    @endforeach
                </select></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'arus-kas'])
    </div>

    @if (! empty($data['tanpa_rekening_kas']))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Belum ada satu pun rekening terdaftar di <b>Kas &amp; Rekening</b>, jadi laporan ini belum punya dasar.
            Akun kas dikenali dari daftar itu.
        </div>
    @endif

    <div class="mb-4 space-y-3">
        @if ($data['disaring_unit'])
            <div class="rounded-lg border border-brand/30 bg-brand-soft/50 px-3 py-2 text-sm">
                Menampilkan <b>unit {{ $unitOptions[$unit] ?? $unit }}</b> saja. Penyaringnya dikenakan pada baris
                <b>lawan kasnya</b> — di situlah kegiatan berada. Saldo kas awal/akhir tidak berdimensi unit,
                jadi uji keselarasan dimatikan di tampilan ini.
            </div>
        @elseif ($data['selaras'])
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                <b>Selaras dengan Neraca.</b> Saldo kas awal + arus bersih = saldo kas akhir.
            </div>
        @else
            <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                <b>TIDAK selaras.</b> Saldo kas awal + arus bersih = @rp($data['saldo_kas_hitung']),
                sedangkan saldo kas akhir menurut buku besar @rp($data['saldo_kas_akhir']).
                Telusuri lewat <a href="{{ route('reports.neraca_saldo', ['from' => $from, 'to' => $to]) }}" class="underline">Neraca Saldo</a>.
            </div>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            @foreach ($data['kelompok'] as $k)
                @continue($k['baris'] === [] && $k['kunci'] === 'belum')
                @php $belum = $k['kunci'] === 'belum'; @endphp
                <div class="rounded-xl border bg-white shadow-sm {{ $belum ? 'border-amber-300' : 'border-gray-200' }}">
                    <div class="flex items-center justify-between border-b px-4 py-2.5 {{ $belum ? 'border-amber-100 bg-amber-50' : 'border-gray-100' }}">
                        <span class="text-sm font-semibold uppercase tracking-wide {{ $belum ? 'text-amber-800' : 'text-gray-700' }}">{{ $k['label'] }}</span>
                        <span class="text-sm font-bold tabular-nums {{ (float) $k['total'] < 0 ? 'text-red-700' : 'text-gray-900' }}">@rp($k['total'])</span>
                    </div>

                    @if ($belum)
                        {{-- Diletakkan di dalam kelompoknya sendiri, bukan disembunyikan:
                             arus yang belum berkamar tetap ikut menghitung arus bersih,
                             dan pembacanya berhak tahu bagian mana yang belum rapi. --}}
                        <p class="border-b border-amber-100 bg-amber-50/60 px-4 py-2 text-xs text-amber-800">
                            Akun-akun ini belum punya klasifikasi arus kas. Angkanya tetap benar dan ikut terhitung,
                            tetapi belum masuk kamar yang semestinya. Lengkapi lewat
                            <a href="{{ route('coa_detail.index') }}" class="underline">Chart of Account</a>.
                        </p>
                    @endif

                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            @forelse ($k['baris'] as $b)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-1.5 text-gray-700">
                                        <a href="{{ route('reports.buku_besar', ['kode_coa' => $b['kode_coa'], 'from' => $from, 'to' => $to, 'kode_unit' => $unit]) }}"
                                           class="text-brand hover:underline">{{ $b['kode_coa'] }}</a>
                                        — {{ $b['nama_coa'] }}
                                    </td>
                                    <td class="px-4 py-1.5 text-right tabular-nums {{ (float) $b['arus'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">@rp($b['arus'])</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-4 py-4 text-center text-sm text-gray-400">Tidak ada arus kas pada kelompok ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach

            <div class="rounded-xl border-2 border-gray-300 bg-white px-4 py-3">
                <table class="min-w-full text-sm">
                    <tbody>
                        <tr><td class="py-1 text-gray-600">Saldo Kas Awal</td><td class="py-1 text-right tabular-nums">@rp($data['saldo_kas_awal'])</td></tr>
                        <tr><td class="py-1 font-semibold text-gray-800">Arus Kas Bersih</td>
                            <td class="py-1 text-right font-semibold tabular-nums {{ (float) $data['arus_bersih'] < 0 ? 'text-red-700' : 'text-emerald-700' }}">@rp($data['arus_bersih'])</td></tr>
                        <tr class="border-t border-gray-200">
                            <td class="pt-2 text-base font-bold text-gray-900">Saldo Kas Akhir</td>
                            <td class="pt-2 text-right text-base font-bold tabular-nums text-gray-900">@rp($data['saldo_kas_akhir'])</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if ($data['jembatan'])
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-2.5">
                    <div class="text-sm font-semibold text-gray-800">Jembatan Laba &rarr; Kas</div>
                    <p class="mt-0.5 text-xs text-gray-500">
                        Kenapa laporan bisa surplus sementara kasnya menipis.
                    </p>
                </div>
                <table class="min-w-full text-sm">
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($data['jembatan']['baris'] as $b)
                            <tr class="{{ $b['tebal'] ? 'bg-gray-50' : '' }}">
                                <td class="px-4 py-2 {{ $b['tebal'] ? 'font-semibold text-gray-900' : 'text-gray-600' }}">{{ $b['label'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums {{ $b['tebal'] ? 'font-bold text-gray-900' : ((float) $b['nilai'] < 0 ? 'text-red-700' : 'text-gray-800') }}">@rp($b['nilai'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
                    Tagihan santri diakui sebagai pendapatan sejak <b>terbit</b>; uangnya menyusul. Selisihnya muncul
                    sebagai kenaikan piutang di baris ketiga.
                    @if ((float) $data['jembatan']['selisih_penyeimbang'] != 0)
                        Selisih penyeimbang masih @rp($data['jembatan']['selisih_penyeimbang']) — makin lengkap
                        klasifikasi akun neraca, makin tajam rinciannya.
                    @endif
                </p>
            </div>
        @endif
    </div>
@endsection
