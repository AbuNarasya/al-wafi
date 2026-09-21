@extends('layouts.app')

@section('title', $batch->judul)

@php
    $warnaKeputusan = [
        'terbit' => 'bg-emerald-100 text-emerald-700',
        'bebas' => 'bg-gray-100 text-gray-600',
        'dilewati' => 'bg-blue-50 text-blue-700',
        'terhalang' => 'bg-red-100 text-red-700',
    ];
    $warnaHasil = [
        'menunggu' => 'text-gray-400',
        'terbit' => 'text-emerald-700',
        'dilewati' => 'text-blue-700',
        'gagal' => 'text-red-700',
    ];
    $labelStatus = [
        'draft' => 'Draft — belum diotorisasi',
        'diotorisasi' => 'Diotorisasi — menunggu rilis',
        'dirilis' => 'Sudah dirilis',
        'sebagian' => 'Dirilis sebagian',
        'gagal' => 'Gagal dirilis',
        'dibatalkan' => 'Dibatalkan',
    ];
@endphp

@section('content')
    <a href="{{ route('batch_tagihan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:underline">&larr; Daftar batch</a>

    <div class="mb-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">{{ $batch->judul }}</h2>
                <p class="text-sm text-gray-500">{{ $batch->labelModul() }} · {{ $labelStatus[$batch->status] ?? $batch->status }}</p>
            </div>
            <div class="text-right">
                <div class="text-xs text-gray-500">{{ $batch->jumlah_baris }} tagihan akan terbit</div>
                <div class="text-xl font-semibold text-gray-800">@rp($batch->total)</div>
            </div>
        </div>

        @if ($batch->status === 'draft' || $batch->status === 'diotorisasi')
            <div class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Belum ada tagihan maupun jurnal yang lahir dari batch ini. Angkanya sudah
                <b>dikunci</b> &mdash; yang terbit nanti adalah angka yang Anda lihat di sini, walau tarifnya berubah.
            </div>
        @endif

        @if ($batch->catatan)
            <div class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700">{{ $batch->catatan }}</div>
        @endif

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-xs text-gray-500">Disusun</dt>
                <dd class="text-gray-800">{{ $batch->penyusun?->nama ?? '—' }}<br>
                    <span class="text-xs text-gray-500">{{ $batch->created_at?->format('d/m/Y H:i') }}</span></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Diotorisasi</dt>
                <dd class="text-gray-800">{{ $batch->pengotorisasi?->nama ?? '—' }}<br>
                    <span class="text-xs text-gray-500">{{ $batch->diotorisasi_pada?->format('d/m/Y H:i') }}</span></dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">Rilis</dt>
                <dd class="text-gray-800">
                    @if ($batch->dirilis_pada)
                        {{ $batch->perilis?->nama ?? 'Penjadwal otomatis' }}<br>
                        <span class="text-xs text-gray-500">{{ $batch->dirilis_pada->format('d/m/Y H:i') }}</span>
                    @elseif ($batch->rilis_pada)
                        Dijadwalkan<br>
                        <span class="text-xs text-gray-500">{{ $batch->rilis_pada->format('d/m/Y H:i') }}</span>
                    @else
                        <span class="text-gray-400">manual</span>
                    @endif
                </dd>
            </div>
        </dl>

        @if ($berhak && ! in_array($batch->status, \App\Models\BatchTagihan::SELESAI, true))
            <div class="mt-5 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                @if ($batch->status === 'draft')
                    <form method="POST" action="{{ route('batch_tagihan.otorisasi', $batch->id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        {{-- Dipisah tanggal & jam, alasannya sama seperti di layar susun. --}}
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600">Waktu rilis otomatis</label>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="date" name="rilis_tanggal"
                                       value="{{ $batch->rilis_pada?->format('Y-m-d') }}"
                                       class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                                <span class="text-sm text-gray-500">pukul</span>
                                <input type="time" name="rilis_jam"
                                       value="{{ $batch->rilis_pada?->format('H:i') ?: '00:00' }}"
                                       class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            </div>
                            <p class="mt-1 text-xs text-gray-400">Tanggal dikosongkan = rilis hanya lewat tombol.</p>
                        </div>
                        <button type="submit"
                                class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                            Otorisasi
                        </button>
                    </form>
                @endif

                @if ($batch->status === 'diotorisasi')
                    <form method="POST" action="{{ route('batch_tagihan.rilis', $batch->id) }}"
                          data-confirm="Terbitkan {{ $batch->jumlah_baris }} tagihan senilai Rp {{ number_format((float) $batch->total, 0, ',', '.') }} sekarang?">
                        @csrf
                        <button type="submit"
                                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Rilis Sekarang
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('batch_tagihan.batalkan', $batch->id) }}"
                      data-confirm="Batalkan batch ini? Tak ada tagihan yang perlu dibalik karena belum ada yang terbit."
                      class="ml-auto">
                    @csrf
                    @method('DELETE')
                    <input type="text" name="alasan" placeholder="Alasan (opsional)"
                           class="mr-2 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Batalkan
                    </button>
                </form>
            </div>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Santri</th>
                    <th class="px-4 py-3">Jenjang</th>
                    <th class="px-4 py-3 text-right">Nominal</th>
                    <th class="px-4 py-3">Keputusan</th>
                    <th class="px-4 py-3">Hasil rilis</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($baris as $b)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800">{{ $b->santri?->nama ?? '—' }}</div>
                            <div class="text-xs text-gray-500">{{ $b->santri?->nis ?: 'belum ber-NIS' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $b->santri?->kode_jenjang }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($b->nominal !== null)
                                @rp($b->nominal)
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $warnaKeputusan[$b->keputusan] ?? '' }}">
                                {{ ucfirst($b->keputusan) }}
                            </span>
                            @if ($b->alasan)
                                <div class="mt-1 text-xs text-gray-500">{{ $b->alasan }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-medium {{ $warnaHasil[$b->hasil] ?? '' }}">{{ ucfirst($b->hasil) }}</span>
                            @if ($b->hasil_alasan)
                                <div class="mt-1 text-xs text-gray-500">{{ $b->hasil_alasan }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $baris->links() }}</div>
@endsection
