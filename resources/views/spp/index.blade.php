@extends('layouts.app')

@section('title', 'SPP')

@php $bolehUbah = \App\Support\Akses::boleh('spp', 'ubah') || \App\Support\Akses::boleh('spp', 'buat'); @endphp

@section('content')
    <div x-data="{ khusus: false, prabayar: false }">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold text-gray-900">SPP</h2>
            @if ($bolehUbah)
                <div class="flex gap-2">
                    <button type="button" @click="khusus = true" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Nominal Khusus Santri</button>
                    <button type="button" @click="prabayar = true" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Setoran Prabayar</button>
                </div>
            @endif
        </div>
        {{-- Tarif SPP kini terpusat di master Jenis Biaya (tipe `spp`), bukan lagi di halaman ini. --}}
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800">
            Tarif SPP per jenjang diatur di
            <a href="{{ route('jenis_biaya.index') }}" class="font-semibold underline">PPSB → Jenis Biaya</a>
            (jenis bertipe <b>SPP</b>). Nominal khusus per santri di bawah tetap mengalahkan tarif jenjang.
        </div>

        <div>
        {{-- Generate tagihan SPP --}}
        <div>
            <h3 class="mb-3 text-sm font-semibold text-gray-800">Terbitkan Tagihan SPP</h3>
            <form method="GET" action="{{ route('spp.index') }}" class="mb-3 flex items-end gap-2 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div><label class="mb-1 block text-xs font-medium text-gray-500">Periode (YYYY-MM)</label>
                    <input type="month" name="periode" value="{{ $periode }}" class="rounded-lg border-gray-300 px-3 py-1.5 text-sm"></div>
                <button class="rounded-lg bg-gray-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-900">Pratinjau</button>
            </form>

            @if ($pratinjau !== null)
                @php
                    $siap = collect($pratinjau)->where('status', 'siap');
                    $sudah = collect($pratinjau)->where('status', 'sudah_ada');
                    $tanpa = collect($pratinjau)->where('status', 'tanpa_tarif');
                @endphp
                <div class="mb-3 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-full bg-emerald-100 px-3 py-1 font-medium text-emerald-700">{{ $siap->count() }} siap terbit</span>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-600">{{ $sudah->count() }} sudah ada</span>
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-amber-700">{{ $tanpa->count() }} tanpa tarif</span>
                </div>

                <div class="max-h-72 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($pratinjau as $p)
                                <tr>
                                    <td class="px-4 py-1.5">{{ $p['nama'] }}</td>
                                    <td class="px-4 py-1.5 text-right tabular-nums">@if ($p['nominal'])@rp($p['nominal'])@else—@endif</td>
                                    <td class="px-4 py-1.5">
                                        <span class="rounded-full px-2 py-0.5 text-xs {{ $p['status'] === 'siap' ? 'bg-emerald-100 text-emerald-700' : ($p['status'] === 'sudah_ada' ? 'bg-gray-100 text-gray-500' : 'bg-amber-100 text-amber-700') }}">{{ str_replace('_', ' ', $p['status']) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($siap->count() > 0 && $bolehUbah)
                    <form method="POST" action="{{ route('spp.generate') }}" class="mt-3 flex items-end gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4"
                          onsubmit="return confirm('Terbitkan {{ $siap->count() }} tagihan SPP periode {{ $periode }}?')">
                        @csrf
                        <input type="hidden" name="periode" value="{{ $periode }}">
                        <div><label class="mb-1 block text-xs font-medium text-emerald-700">Tanggal Terbit</label>
                            <input type="date" name="tanggal" value="{{ now()->toDateString() }}" class="rounded-lg border-emerald-300 px-3 py-1.5 text-sm"></div>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terbitkan {{ $siap->count() }} Tagihan</button>
                    </form>
                @endif
            @else
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-sm text-gray-400">Pilih periode lalu klik Pratinjau untuk melihat calon tagihan.</div>
            @endif
        </div>
        </div>

        {{-- Modal: Nominal Khusus Santri --}}
        <div x-show="khusus" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="khusus = false">
            <div class="my-10 w-full max-w-md rounded-xl bg-white shadow-xl" x-data="{ santri: '' }">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">Nominal SPP Khusus</h3>
                    <button type="button" @click="khusus = false" class="text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                <form method="POST" :action="`{{ url('kesantrian/spp/santri') }}/${santri}/nominal`" class="space-y-3 p-5">
                    @csrf @method('PUT')
                    <p class="rounded bg-slate-50 px-3 py-2 text-xs text-gray-500">Jalur beasiswa/keringanan/tahfizh. Nominal <b>0</b> = beasiswa penuh (tagihan tetap terbit senilai nol). <b>Kosongkan</b> nominal = kembali ke tarif jenjang.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Santri</label>
                        <select x-model="santri" required class="w-full rounded border-gray-300 px-3 py-2 text-sm">
                            <option value="">— pilih santri —</option>
                            @foreach ($santriOptions as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Nominal SPP</label><input type="number" name="nominal_spp" step="0.01" min="0" class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Alasan</label><input type="text" name="keterangan_spp" placeholder="mis. beasiswa 50%" class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" @click="khusus = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                        <button :disabled="!santri" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Simpan</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal: Setoran Prabayar --}}
        <div x-show="prabayar" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" @click.self="prabayar = false">
            <div class="my-10 w-full max-w-md rounded-xl bg-white shadow-xl" x-data="{ sumber: 'kas' }">
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                    <h3 class="font-semibold text-gray-800">Setoran SPP / Prabayar</h3>
                    <button type="button" @click="prabayar = false" class="text-gray-400 hover:text-gray-700">&times;</button>
                </div>
                <form method="POST" action="{{ route('spp.prabayar') }}" class="space-y-3 p-5">
                    @csrf <input type="hidden" name="tanggal" value="{{ now()->toDateString() }}">
                    <p class="rounded bg-slate-50 px-3 py-2 text-xs text-gray-500">Melunasi <b>tunggakan tertua</b> lebih dulu; kelebihannya menjadi saldo prabayar (Pendapatan Diterima Dimuka), otomatis dipakai saat tagihan berikutnya terbit.</p>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Santri</label>
                        <select name="id_santri" required class="w-full rounded border-gray-300 px-3 py-2 text-sm">
                            <option value="">— pilih santri —</option>
                            @foreach ($santriOptions as $id => $nm)<option value="{{ $id }}">{{ $nm }}</option>@endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Nominal</label><input type="number" name="nominal" step="0.01" min="0" required class="w-full rounded border-gray-300 px-3 py-2 text-sm"></div>
                        <div><label class="mb-1 block text-xs font-medium text-gray-600">Sumber Dana</label>
                            <select name="sumber" x-model="sumber" class="w-full rounded border-gray-300 px-3 py-2 text-sm"><option value="kas">Setoran tunai / transfer</option><option value="dompet_wali">Dompet Wali</option></select></div>
                    </div>
                    <div x-show="sumber === 'kas'">
                        <label class="mb-1 block text-xs font-medium text-gray-600">Kas/Rekening</label>
                        <select name="kode_rekening" class="w-full rounded border-gray-300 px-3 py-2 text-sm"><option value="">— pilih —</option>@foreach ($rekeningOptions as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
                        <button type="button" @click="prabayar = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</button>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Terima Setoran</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
