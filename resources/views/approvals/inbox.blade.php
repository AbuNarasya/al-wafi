@extends('layouts.app')

@section('title', 'Persetujuan Saya')

@section('content')
    <p class="mb-4 text-sm text-gray-500">Semua yang menunggu keputusan atau tindakan Anda. Klik sebuah baris untuk membuka halamannya.</p>

    @if ($items->isEmpty() && $pintasan->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-16 text-center text-gray-400">
            Tidak ada yang menunggu persetujuan atau tindakan Anda. 🎉
        </div>
    @endif

    {{-- Pintasan per bidang (12 Nov 2026): angkanya sama dengan penanda di menu modulnya. --}}
    @if ($pintasan->isNotEmpty())
        <div class="mb-6 grid gap-4 md:grid-cols-2">
            @foreach ($pintasan as $bidang => $baris)
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm" data-bidang="{{ $bidang }}">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
                        <h3 class="text-sm font-semibold text-gray-800">{{ $bidang }}</h3>
                        <span class="rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ $baris->sum('jumlah') }}</span>
                    </div>
                    <ul class="divide-y divide-gray-100">
                        @foreach ($baris as $b)
                            <li>
                                <a href="{{ url($b['tautan']) }}" class="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-gray-50">
                                    <span class="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 px-1.5 text-xs font-bold text-amber-800">{{ $b['jumlah'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-gray-800">{{ $b['label'] }}</span>
                                        <span class="block text-xs text-gray-400">{{ \App\Support\Navigation::labelUrl($b['url']) ?? '' }}</span>
                                    </span>
                                    <span class="shrink-0 text-gray-400">&rarr;</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    @if ($items->isNotEmpty())
        <h3 class="mb-3 text-sm font-semibold text-gray-800">Usulan Anggaran &amp; Pengajuan Pembayaran</h3>
        <div class="space-y-4">
            @foreach ($items as $inst)
                {{-- Kunci JENIS + id: dua jenis dokumen berbeda bisa punya id yang
                     sama, dan kunci id saja membuat kartu menampilkan dokumen lain. --}}
                @php $doc = $docs->get($inst->jenis_dokumen.'|'.(int) $inst->id_dokumen); @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-gray-900">{{ $doc?->nomor ?? $inst->jenis_dokumen . ' #' . $inst->id_dokumen }}</span>
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">Tahap {{ $inst->tahap_sekarang }}</span>
                                @if ($inst->overbudget)<span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">Overbudget</span>@endif
                                @if ($inst->belum_dianggarkan)<span class="rounded-full bg-orange-100 px-2 py-0.5 text-xs font-medium text-orange-700">Belum dianggarkan</span>@endif
                            </div>
                            <div class="mt-1 text-sm text-gray-600">{{ $doc?->keterangan }}</div>
                            <div class="mt-1 text-xs text-gray-400">Bagian: {{ $doc?->bagian?->nama_bagian ?? $inst->kode_bagian }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-lg font-semibold tabular-nums text-gray-900">@rp($inst->nominal)</div>
                            {{-- Tombol, bukan tautan kecil: inilah satu-satunya jalan
                                 memeriksa rinciannya sebelum memutuskan, jadi ia harus
                                 sebesar tombol keputusan di sebelahnya. --}}
                            @if ($doc)
                                <a href="{{ route('pengajuan.show', $doc->id) }}"
                                   class="mt-2 inline-block rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-brand hover:bg-gray-50">Lihat detail</a>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 pt-4">
                        {{-- Tolak --}}
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Tolak</button>
                            <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('approvals.reject', $inst->id) }}"
                                  class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                @csrf
                                <label class="block text-xs font-medium text-gray-600">Alasan penolakan</label>
                                <input type="text" name="alasan" required maxlength="255" class="w-full rounded border-gray-300 text-sm">
                                <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Tolak</button>
                            </form>
                        </div>
                        {{-- Setujui --}}
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Setujui</button>
                            <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('approvals.approve', $inst->id) }}"
                                  class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                @csrf
                                <label class="block text-xs font-medium text-gray-600">Catatan (opsional)</label>
                                <input type="text" name="catatan" maxlength="255" class="w-full rounded border-gray-300 text-sm">
                                <button class="w-full rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark">Konfirmasi Setuju</button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
