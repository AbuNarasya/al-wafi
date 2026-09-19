@extends('layouts.app')

@section('title', 'Tutup Buku Periode')

@php $NAMA_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']; @endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tahun</label>
                <input type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100"
                       class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
            </div>
            <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Tampilkan</button>
        </form>
        @if ($status['tahun_ditutup'])
            <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">🔒 Tahun {{ $tahun }} sudah ditutup buku ({{ $status['referensi_tutup_tahun'] }})</span>
        @endif
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- Grid 12 bulan --}}
    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($status['bulan'] as $b)
            <div class="rounded-xl border {{ $b['status'] === 'closed' ? 'border-gray-300 bg-gray-50' : 'border-gray-200 bg-white' }} p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-800">{{ $NAMA_BULAN[$b['bulan']] }}</span>
                    @if ($b['status'] === 'closed')
                        <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-600">Ditutup</span>
                    @else
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Terbuka</span>
                    @endif
                </div>
                @if ($b['closed_at'])<div class="mt-1 text-[10px] text-gray-400">oleh {{ $b['nama_closed_by'] ?? '—' }}</div>@endif
                <div class="mt-2">
                    @if ($b['status'] === 'closed')
                        {{-- Tak ada lagi tombol yang langsung membuka. Yang ada
                             hanya permohonan beralasan; keputusannya milik
                             direktur keuangan. --}}
                        @if ($bolehAjukan)
                            <div x-data="{ buka: false }">
                                <button type="button" @click="buka = !buka" class="text-xs text-indigo-600 hover:underline">Ajukan pembukaan</button>
                                <form x-show="buka" x-cloak method="POST" action="{{ route('period_close.ajukan_buka') }}" class="mt-2 space-y-2">
                                    @csrf
                                    <input type="hidden" name="lingkup" value="bulan">
                                    <input type="hidden" name="tahun" value="{{ $tahun }}">
                                    <input type="hidden" name="bulan" value="{{ $b['bulan'] }}">
                                    <textarea name="alasan" rows="2" required placeholder="Alasan pembukaan (wajib)"
                                              class="w-full rounded border border-gray-300 px-2 py-1 text-xs"></textarea>
                                    <button class="w-full rounded bg-indigo-600 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-700">Kirim Permohonan</button>
                                </form>
                            </div>
                        @else
                            <span class="text-xs text-gray-400">Terkunci</span>
                        @endif
                    @else
                        <form method="POST" action="{{ route('period_close.tutup_bulan') }}" onsubmit="return confirm('Tutup {{ $NAMA_BULAN[$b['bulan']] }} {{ $tahun }}?')">
                            @csrf<input type="hidden" name="tahun" value="{{ $tahun }}"><input type="hidden" name="bulan" value="{{ $b['bulan'] }}">
                            <button class="text-xs text-gray-600 hover:text-gray-800 hover:underline">Tutup bulan</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Tutup buku tahunan --}}
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/60 p-5">
        <h3 class="mb-2 text-sm font-semibold text-gray-800">Tutup Buku Tahunan {{ $tahun }}</h3>
        <p class="mb-3 text-xs text-gray-500">Menol-kan seluruh akun Pendapatan &amp; Beban tahun ini; laba/rugi bersih dipindah ke Laba Ditahan (jurnal TUTUP-{{ $tahun }}, 31 Des).</p>
        @if ($status['tahun_ditutup'])
            @if ($bolehAjukan)
                <form method="POST" action="{{ route('period_close.ajukan_buka') }}" class="max-w-lg space-y-2">
                    @csrf
                    <input type="hidden" name="lingkup" value="tahun">
                    <input type="hidden" name="tahun" value="{{ $tahun }}">
                    <textarea name="alasan" rows="2" required placeholder="Alasan pembatalan tutup buku tahunan (wajib)"
                              class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm"></textarea>
                    <button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                        Ajukan Pembukaan Tutup Buku {{ $tahun }}
                    </button>
                </form>
            @else
                <p class="text-xs text-gray-500">Tutup buku tahunan hanya bisa dibatalkan lewat permohonan admin keuangan.</p>
            @endif
        @else
            <form method="POST" action="{{ route('period_close.tutup_tahun') }}" onsubmit="return confirm('Tutup buku tahunan {{ $tahun }}?')" class="flex flex-wrap items-end gap-3">
                @csrf<input type="hidden" name="tahun" value="{{ $tahun }}">
                <div class="min-w-[18rem]"><x-field name="kode_coa_laba_ditahan" label="Akun Laba Ditahan (Ekuitas)" :options="$coaOptions" required /></div>
                <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Tutup Buku {{ $tahun }}</button>
            </form>
        @endif
    </div>

    {{-- Daftar permohonan: yang menunggu di atas, riwayatnya di bawah. Alasan
         pemohon SELALU ditampilkan — itulah yang akan dibaca berbulan-bulan
         kemudian saat angka periode itu dipersoalkan. --}}
    <div class="mt-6 rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-800">
            Permohonan Pembukaan Periode
            <span class="ml-1 text-xs font-normal text-gray-500">— diajukan admin keuangan, diputuskan direktur keuangan</span>
        </div>
        <table class="min-w-full divide-y divide-gray-200 text-xs">
            <thead class="bg-gray-50 text-left font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-2">Periode</th><th class="px-4 py-2">Alasan</th>
                    <th class="px-4 py-2">Pemohon</th><th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($permohonan as $p)
                    <tr class="{{ $p->status === 'diajukan' ? 'bg-amber-50/40' : '' }}">
                        <td class="px-4 py-2 whitespace-nowrap font-medium text-gray-800">{{ $p->labelPeriode() }}</td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ $p->alasan }}
                            @if ($p->catatan_keputusan)
                                <div class="mt-0.5 text-[11px] text-gray-400">Catatan keputusan: {{ $p->catatan_keputusan }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500">
                            {{ $p->pemohon?->nama ?? '—' }}
                            <div class="text-[11px] text-gray-400">{{ $p->diajukan_pada?->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-2">
                            {{-- Kelas Tailwind ditulis UTUH per cabang, bukan dirakit
                                 dari variabel: pemindai Tailwind membaca teks, dan
                                 `bg-{$warna}-100` tak pernah ada di berkas hasil
                                 pindaiannya — warnanya akan hilang di produksi. --}}
                            @if ($p->status === 'diajukan')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">Diajukan</span>
                            @elseif ($p->status === 'disetujui')
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Disetujui</span>
                            @else
                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-medium text-red-700">Ditolak</span>
                            @endif
                            @if ($p->pemutus)
                                <div class="text-[11px] text-gray-400">oleh {{ $p->pemutus->nama }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if ($p->status === 'diajukan' && $bolehPutuskan)
                                @if ($p->diajukan_oleh === $idSaya)
                                    {{-- Inti "dua tangan": dikatakan di layar, bukan hanya ditolak di server. --}}
                                    <span class="text-[11px] text-gray-400">Permohonan Anda sendiri — harus diputuskan orang lain</span>
                                @else
                                    <div x-data="{ buka: false }" class="inline-block text-left">
                                        <button type="button" @click="buka = !buka" class="text-brand hover:underline">Putuskan</button>
                                        <div x-show="buka" x-cloak class="mt-2 w-64 space-y-2 rounded-lg border border-gray-200 p-2">
                                            <form method="POST" action="{{ route('period_close.setujui_buka', $p->id) }}" class="space-y-1">
                                                @csrf
                                                <input type="text" name="catatan_keputusan" placeholder="Catatan (opsional)" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                                <button class="w-full rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Setujui &amp; Buka</button>
                                            </form>
                                            <form method="POST" action="{{ route('period_close.tolak_buka', $p->id) }}" class="space-y-1">
                                                @csrf
                                                <input type="text" name="catatan_keputusan" required placeholder="Alasan penolakan (wajib)" class="w-full rounded border border-gray-300 px-2 py-1 text-xs">
                                                <button class="w-full rounded border border-red-300 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">Tolak</button>
                                            </form>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Belum ada permohonan pembukaan periode.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
