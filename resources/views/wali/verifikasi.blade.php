@extends('layouts.app')

@section('title', 'Verifikasi Data Wali')

@section('content')
    @php
        $bolehUbah = \App\Support\Akses::boleh('wali', 'ubah');
        $bolehKirim = $bolehUbah && $aktif;
        $label = ['tanpa_link' => 'Belum ada link'] + \App\Models\VerifikasiWali::STATUS;
        $warna = [
            'tanpa_link' => 'bg-gray-100 text-gray-600', 'belum_mulai' => 'bg-sky-100 text-sky-700',
            'dibuat' => 'bg-amber-100 text-amber-700', 'terkirim' => 'bg-blue-100 text-blue-700',
            'dibuka' => 'bg-indigo-100 text-indigo-700', 'selesai' => 'bg-emerald-100 text-emerald-700',
            'kedaluwarsa' => 'bg-red-100 text-red-700',
        ];
        $selesai = $hitung['selesai'] ?? 0;
        $persen = $total > 0 ? round($selesai / $total * 100) : 0;
        $tgl = fn ($d) => $d?->locale('id')->translatedFormat('j M Y');
    @endphp

    <div x-data="{ mulai: @js(now()->format('Y-m-d')) }">
    <a href="{{ route('wali.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Wali / Keluarga</a>

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Kirim link unik ke tiap keluarga lewat WhatsApp. Wali membuka link, memeriksa &amp; membetulkan data keluarga
        dan santrinya, lalu membuktikan email aktifnya dengan kode — email itu langsung menjadi akun Portal Wali.
        Link berlaku <b>{{ \App\Services\PortalWali\LinkVerifikasi::HARI }} hari</b> sejak tanggal mulai yang dipilih.
        Yang dihitung hanya keluarga dengan anak aktif atau masih dalam proses PSB.
    </p>

    {{-- SAKLAR kampanye (permintaan user 14 Okt 2026): ditutup staf sendiri bila tuntas lebih
         cepat dari masa berlaku link. Mati ≠ dicabut — menyalakannya lagi menghidupkan link lama. --}}
    <div @class(['mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 shadow-sm',
                 'border-emerald-200 bg-emerald-50' => $aktif, 'border-amber-200 bg-amber-50' => ! $aktif])>
        <div class="text-sm">
            <p @class(['font-semibold', 'text-emerald-800' => $aktif, 'text-amber-900' => ! $aktif])>
                Verifikasi data: {{ $aktif ? 'AKTIF' : 'NONAKTIF' }}
            </p>
            <p @class(['text-xs', 'text-emerald-700' => $aktif, 'text-amber-800' => ! $aktif])>
                {{ $aktif ? 'Wali bisa membuka link yang masih dalam masa berlaku.' : 'Link & jalur cadangan tak bisa dibuka wali; link baru tak bisa dibuat. Link lama tidak dicabut.' }}
                @if ($saklar?->updated_at)
                    · diubah {{ \Illuminate\Support\Carbon::parse($saklar->updated_at)->locale('id')->translatedFormat('j M Y H:i') }}
                @endif
            </p>
        </div>
        @if ($bolehUbah)
            <form method="POST" action="{{ route('wali.verifikasi.saklar') }}"
                  data-confirm="{{ $aktif ? 'Nonaktifkan verifikasi data? Wali tak bisa membuka link sampai diaktifkan lagi.' : 'Aktifkan verifikasi data? Link yang masih dalam masa berlaku bisa dibuka wali lagi.' }}">
                @csrf
                <input type="hidden" name="aktif" value="{{ $aktif ? 0 : 1 }}">
                <button @class(['rounded-lg px-4 py-2 text-sm font-semibold',
                                'border border-amber-300 bg-white text-amber-800 hover:bg-amber-50' => $aktif,
                                'bg-emerald-600 text-white hover:bg-emerald-700' => ! $aktif])>
                    {{ $aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
            </form>
        @endif
    </div>

    {{-- Ringkasan --}}
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <div class="text-xs text-gray-500">Selesai diverifikasi</div>
                <div class="text-2xl font-semibold text-gray-900">{{ number_format($selesai, 0, ',', '.') }}
                    <span class="text-base font-normal text-gray-500">dari {{ number_format($total, 0, ',', '.') }} keluarga ({{ $persen }}%)</span></div>
            </div>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $persen }}%"></div></div>
        <div class="mt-3 flex flex-wrap gap-2 text-xs">
            <a href="{{ route('wali.verifikasi') }}" @class(['rounded-full px-2.5 py-1', 'bg-gray-800 text-white' => $status === '', 'bg-gray-100 text-gray-700 hover:bg-gray-200' => $status !== ''])>Semua · {{ $total }}</a>
            @foreach ($label as $kode => $teks)
                @if (($hitung[$kode] ?? 0) > 0)
                    <a href="{{ route('wali.verifikasi', ['status' => $kode]) }}"
                       @class(['rounded-full px-2.5 py-1', 'ring-2 ring-gray-800' => $status === $kode, $warna[$kode]])>{{ $teks }} · {{ $hitung[$kode] }}</a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Buat link massal --}}
    @if ($bolehKirim)
        <form method="POST" action="{{ route('wali.verifikasi.massal') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
              data-confirm="Buat link verifikasi untuk semua keluarga yang belum punya link berlaku?">
            @csrf
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Mulai berlaku</label>
                <input type="date" name="mulai" x-model="mulai" required class="rounded-lg border-gray-300 text-sm">
            </div>
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Buat link untuk semua yang belum punya</button>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="ulang" value="1" class="rounded border-gray-300 text-brand focus:ring-brand">
                Termasuk keluarga yang sudah selesai (verifikasi ulang)
            </label>
            <p class="w-full text-xs text-gray-500 sm:w-auto sm:flex-1">Link yang sudah ada &amp; masih berlaku tidak disentuh. Tanggal ini juga dipakai tombol "Buat link" per keluarga di bawah.</p>
        </form>
    @endif

    {{-- Akhiri kampanye — tetap tersedia saat saklar mati: justru saat itu link yang tersebar perlu dipastikan mati. --}}
    @if ($bolehUbah && (($hitung['dibuat'] ?? 0) + ($hitung['terkirim'] ?? 0) + ($hitung['dibuka'] ?? 0) + ($hitung['belum_mulai'] ?? 0)) > 0)
        <form method="POST" action="{{ route('wali.verifikasi.akhiri') }}" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-red-100 bg-white p-4 shadow-sm"
              data-confirm="Akhiri verifikasi? Semua link yang belum selesai dicabut dan tak bisa dibuka lagi, termasuk yang sudah terkirim lewat WhatsApp.">
            @csrf
            <p class="text-sm text-gray-600">Kampanye selesai? Cabut sekaligus semua link yang belum diselesaikan wali.</p>
            <button class="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">Akhiri verifikasi</button>
        </form>
    @endif

    {{-- Saringan --}}
    <form method="GET" class="mb-3 flex flex-wrap gap-2">
        @if ($status !== '')<input type="hidden" name="status" value="{{ $status }}">@endif
        <input type="search" name="q" value="{{ $q }}" placeholder="Cari nama keluarga, nama santri, atau NIS…" class="w-full max-w-sm rounded-lg border-gray-300 text-sm">
        <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cari</button>
    </form>

    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Keluarga</th><th class="px-4 py-3">Santri</th><th class="px-4 py-3">Status</th>@if ($bolehUbah)<th class="px-4 py-3 text-right">Aksi</th>@endif</tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php $w = $r['wali']; $v = $r['link']; @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $w->nama }}</div>
                            <div class="text-xs text-gray-500">{{ $w->telepon ?: 'tanpa nomor HP' }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            @foreach ($w->santri as $s)
                                <div>{{ $s->nama }}@if ($s->nis)<span class="text-xs text-gray-400"> · {{ $s->nis }}</span>@endif</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">
                            <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs {{ $warna[$r['status']] }}">{{ $label[$r['status']] }}</span>
                            @if ($v)
                                <div class="mt-1 text-[11px] text-gray-500">
                                    @if ($r['status'] === 'selesai')
                                        oleh {{ \App\Models\Wali::PERAN[$v->selesai_peran] ?? $v->selesai_peran }} · {{ $tgl($v->selesai_pada) }}
                                        <span class="block break-all">{{ $v->selesai_email }}</span>
                                    @else
                                        {{ $tgl($v->berlaku_mulai) }} – {{ $tgl($v->berlaku_sampai->copy()->subDay()) }}
                                        @if ($v->dibuka_terakhir)<span class="block">dibuka {{ $v->dibuka_terakhir->locale('id')->diffForHumans() }}</span>@endif
                                    @endif
                                </div>
                            @endif
                            @if ($r['pernah_selesai'] && $r['status'] !== 'selesai')
                                <div class="mt-0.5 text-[11px] text-emerald-700">pernah selesai {{ $tgl($r['pernah_selesai']) }}</div>
                            @endif
                        </td>
                        @if ($bolehUbah)
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-1.5">
                                    @if ($v && ! in_array($r['status'], ['selesai', 'kedaluwarsa'], true))
                                        @if (! $aktif)
                                            {{-- Nonaktif: kirim & salin disembunyikan — link tak bisa dibuka wali. --}}
                                        @elseif ($r['wa'])
                                            <a href="{{ route('wali.verifikasi.wa', $v->id) }}" target="_blank" rel="noopener"
                                               class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Kirim WA</a>
                                        @else
                                            <span class="text-xs text-red-600">Nomor HP tak sah</span>
                                        @endif
                                        @if ($aktif)
                                            {{-- Menyalin PESAN LENGKAP (sama dengan tombol Kirim WA), bukan link
                                                 telanjang — staf tinggal menempelkannya di WhatsApp. --}}
                                            <button type="button" x-data="{ ok: false }" title="Salin pesan WhatsApp beserta link-nya"
                                                    @click="navigator.clipboard.writeText(@js(\App\Services\PortalWali\LinkVerifikasi::pesanWa($v))); ok = true; setTimeout(() => ok = false, 1500)"
                                                    class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50" x-text="ok ? 'Tersalin' : 'Salin pesan'">Salin pesan</button>
                                        @endif
                                        <form method="POST" action="{{ route('wali.verifikasi.cabut', $v->id) }}" class="inline"
                                              data-confirm="Cabut link verifikasi keluarga {{ $w->nama }}? Link yang sudah terkirim tak bisa dibuka lagi.">
                                            @csrf
                                            <button class="rounded-lg border border-red-200 px-2.5 py-1 text-xs text-red-600 hover:bg-red-50">Cabut</button>
                                        </form>
                                    @elseif ($aktif && $r['status'] === 'selesai')
                                        {{-- Data perlu diperbarui lagi: link baru, link selesai ditutup. Riwayat
                                             selesainya tetap ada ("pernah selesai"), akun portalnya tetap berlaku. --}}
                                        <form method="POST" action="{{ route('wali.verifikasi.buat', $w->id) }}" class="inline"
                                              data-confirm="Verifikasi ulang keluarga {{ $w->nama }}? Link baru dibuat; mereka tetap bisa masuk portal seperti biasa.">
                                            @csrf
                                            <input type="hidden" name="mulai" :value="mulai">
                                            <button class="rounded-lg border border-brand px-2.5 py-1 text-xs font-semibold text-brand hover:bg-blue-50">Verifikasi ulang</button>
                                        </form>
                                    @elseif ($aktif)
                                        <form method="POST" action="{{ route('wali.verifikasi.buat', $w->id) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="mulai" :value="mulai">
                                            <button class="rounded-lg bg-brand px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-dark">{{ $v ? 'Buat link baru' : 'Buat link' }}</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $bolehUbah ? 4 : 3 }}" class="px-4 py-8 text-center text-gray-400">Tidak ada keluarga yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
    </div>
@endsection
