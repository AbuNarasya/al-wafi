{{--
  Riwayat pengunduran diri & clearance satu santri (permintaan user 11 Nov 2026) — dipakai di halaman detail santri,
  terutama santri keluar sebagai arsip: tahap persetujuan, clearance, dokumen terunggah, dan berita acara.
  Pakai: @include('pengunduran-diri._riwayat', ['riwayat' => $koleksiPengajuanKeluar])
--}}
@if ($riwayat->isNotEmpty())
    @php
        $tgl = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m/Y H:i');
        $namaPetugas = \App\Models\User::whereIn('id_pengguna', $riwayat->flatMap(fn ($p) => collect(array_keys(\App\Services\Modules\BeritaAcaraClearance::TAHAP))
            ->map(fn ($t) => $p->{"{$t}_oleh"})->push($p->dikeluarkan_oleh))->filter()->unique()->values())->pluck('nama', 'id_pengguna');
    @endphp
    <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <h2 class="font-semibold text-gray-900">Riwayat pengunduran diri & clearance</h2>
        @foreach ($riwayat as $p)
            <div class="mt-3 rounded-lg border border-gray-100 p-3 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p>
                        <a href="{{ route('pengunduran_diri.show', $p->id) }}" class="font-mono font-semibold text-brand hover:underline">{{ $p->nomor }}</a>
                        <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $p->labelStatus() }}</span>
                        <span class="ml-1 text-xs text-gray-500">diajukan {{ $tgl($p->created_at) }}</span>
                    </p>
                    @if (\App\Services\Modules\BeritaAcaraClearance::tersedia($p))
                        <a href="{{ route('pengunduran_diri.berita_acara', $p->id) }}" data-tanpa-muat
                           class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark">Unduh Berita Acara (PDF)</a>
                    @endif
                </div>
                <p class="mt-1 text-xs text-gray-600">Alasan: {{ $p->alasan }}</p>

                <table class="mt-2 w-full text-xs">
                    <thead class="text-left text-gray-500">
                        <tr><th class="py-1 pr-2 font-medium">Tahap</th><th class="py-1 pr-2 font-medium">Petugas</th><th class="py-1 pr-2 font-medium">Waktu</th><th class="py-1 font-medium">Catatan</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach (\App\Services\Modules\BeritaAcaraClearance::TAHAP as $k => $label)
                            <tr>
                                <td class="py-1 pr-2">{{ $label }}</td>
                                <td class="py-1 pr-2">{!! $p->{$k.'_pada'} ? '<span class="text-emerald-600">✓</span> '.e($namaPetugas[$p->{$k.'_oleh'}] ?? '—') : '<span class="text-gray-400">—</span>' !!}</td>
                                <td class="py-1 pr-2 whitespace-nowrap">{{ $tgl($p->{$k.'_pada'}) ?? '—' }}</td>
                                <td class="py-1 text-gray-600">{{ $p->{$k.'_catatan'} ?: '—' }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="py-1 pr-2">Dikeluarkan</td>
                            <td class="py-1 pr-2">{{ $p->dikeluarkan_oleh ? ($namaPetugas[$p->dikeluarkan_oleh] ?? '—') : '—' }}</td>
                            <td class="py-1 pr-2 whitespace-nowrap">{{ $tgl($p->dikeluarkan_pada) ?? '—' }}</td>
                            <td class="py-1 text-gray-600">{{ $p->dapodik_pada ? 'Dapodik ditandai '.$tgl($p->dapodik_pada) : '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Dokumen</p>
                @forelse ($p->dokumen as $d)
                    <p class="text-xs">
                        <a href="{{ route('pengunduran_diri.dokumen', $d->id) }}" target="_blank" data-tanpa-muat class="text-brand hover:underline">{{ \App\Models\DokumenPengajuanKeluar::JENIS[$d->jenis] ?? $d->jenis }}</a>
                        <span class="text-gray-500">{{ $d->keterangan ?: $d->nama_asli }} · {{ $d->created_at?->format('d/m/Y') }}</span>
                    </p>
                @empty
                    <p class="text-xs text-gray-500">Belum ada dokumen.</p>
                @endforelse
            </div>
        @endforeach
    </div>
@endif
