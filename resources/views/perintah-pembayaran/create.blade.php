@extends('layouts.app')

@section('title', 'Perintah Pembayaran Baru')

@php
    // Kewajiban DIPILIH, tidak diketik — nominalnya terisi sebesar sisa dan
    // hanya bisa dikecilkan. Data mentahnya dioper ke Alpine agar penjumlahan
    // & batas nominal berjalan tanpa bolak-balik ke server.
    $data = collect($kewajiban)->map(fn ($k) => [
        'sumber' => $k['sumber'],
        'id' => $k['id_dokumen'],
        'nomor' => $k['nomor_dokumen'],
        'pihak' => $k['pihak'] ?? '',
        'ket' => $k['keterangan'] ?? '',
        'unit' => $k['kode_unit'] ?? '',
        'tempo' => $k['jatuh_tempo'] ? \Illuminate\Support\Carbon::parse($k['jatuh_tempo'])->format('d/m/Y') : '',
        'sisa' => (float) $k['sisa'],
        'kunci' => $k['terkunci_di'],
    ])->values()->all();
    $labelSumber = \App\Models\PerintahPembayaranDetail::SUMBER;
@endphp

@section('content')
<div x-data="susunPerintah(@js($data), {{ (float) $dana['dana_bebas'] }})">
    <form method="POST" action="{{ route('perintah_pembayaran.store') }}" class="space-y-4">
        @csrf

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <a href="{{ route('perintah_pembayaran.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <x-field name="tanggal_usulan" label="Usulan Tanggal Bayar" type="date" :value="old('tanggal_usulan')" />
                <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" required placeholder="mis. Pembayaran termin I Agustus" />
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Yang menetapkan tanggal &amp; metode pembayaran adalah pejabat saat mengotorisasi. Isian di sini hanya usulan.
            </p>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2">
                <span class="text-sm font-medium text-gray-700">Pilih kewajiban</span>
                <select x-model="fSumber" class="ml-auto rounded-lg border border-gray-300 px-2 py-1 text-xs">
                    <option value="">Semua sumber</option>
                    @foreach ($labelSumber as $k => $v)
                        <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                </select>
                <input type="text" x-model="cari" placeholder="Cari no. dokumen / pihak…"
                       class="rounded-lg border border-gray-300 px-2 py-1 text-xs">
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="w-10 px-3 py-2"></th>
                            <th class="px-3 py-2">Sumber</th>
                            <th class="px-3 py-2">Dokumen</th>
                            <th class="px-3 py-2">Pihak</th>
                            <th class="px-3 py-2">Keterangan</th>
                            <th class="px-3 py-2">Unit</th>
                            <th class="px-3 py-2">Jatuh Tempo</th>
                            <th class="px-3 py-2 text-right">Sisa</th>
                            <th class="px-3 py-2 text-right">Dibayar Kali Ini</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(k, i) in tersaring" :key="k.sumber + ':' + k.id">
                            <tr :class="k.kunci ? 'bg-red-50' : (pilih[kunciBaris(k)] ? 'bg-brand-soft/40' : '')">
                                <td class="px-3 py-2">
                                    <input type="checkbox" :disabled="!!k.kunci" :checked="!!pilih[kunciBaris(k)]"
                                           @change="toggle(k, $event.target.checked)"
                                           class="rounded border-gray-300 text-brand focus:ring-brand disabled:opacity-40">
                                </td>
                                <td class="px-3 py-2 text-gray-600" x-text="labelSumber[k.sumber] || k.sumber"></td>
                                <td class="px-3 py-2 font-mono text-xs" x-text="k.nomor"></td>
                                <td class="px-3 py-2" x-text="k.pihak"></td>
                                <td class="px-3 py-2 text-gray-600" x-text="k.ket"></td>
                                <td class="px-3 py-2 text-gray-500" x-text="k.unit"></td>
                                <td class="px-3 py-2 text-gray-500" x-text="k.tempo || '—'"></td>
                                <td class="px-3 py-2 text-right tabular-nums" x-text="rp(k.sisa)"></td>
                                <td class="px-3 py-2 text-right">
                                    <template x-if="k.kunci">
                                        <span class="text-xs text-red-700" x-text="'sudah di ' + k.kunci"></span>
                                    </template>
                                    <template x-if="!k.kunci && pilih[kunciBaris(k)]">
                                        <input type="text" inputmode="numeric"
                                               :value="fmtRupiah(pilih[kunciBaris(k)].nominal)"
                                               @input="setNominal(k, ketikRupiah($event))"
                                               class="w-32 rounded border border-gray-400 px-2 py-1 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand">
                                    </template>
                                    <template x-if="!k.kunci && !pilih[kunciBaris(k)]">
                                        <span class="text-xs text-gray-300">—</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="tersaring.length === 0">
                            <td colspan="9" class="px-4 py-12 text-center text-gray-400">Tidak ada kewajiban yang cocok.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 bg-gray-50 px-4 py-3">
                <span class="text-sm"><b x-text="jumlahDipilih"></b> kewajiban dipilih · total
                    <b class="tabular-nums" x-text="rp(total)"></b></span>
                <span x-show="total > danaBebas" x-cloak class="text-xs font-medium text-red-700">
                    Melebihi dana yang bisa dipakai (<span x-text="rp(danaBebas)"></span>) — pejabat tak akan bisa mengotorisasinya.
                </span>
                <div class="ml-auto flex gap-2">
                    <a href="{{ route('perintah_pembayaran.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button :disabled="jumlahDipilih === 0"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Simpan Draf</button>
                </div>
            </div>
        </div>

        {{-- Baris terpilih dikirim sebagai isian tersembunyi. --}}
        <template x-for="(v, k) in pilih" :key="k">
            <div>
                <input type="hidden" :name="`detail[${k}][sumber]`" :value="v.sumber">
                <input type="hidden" :name="`detail[${k}][id_dokumen]`" :value="v.id">
                <input type="hidden" :name="`detail[${k}][nominal]`" :value="v.nominal">
            </div>
        </template>
    </form>
</div>

<script>
    function susunPerintah(data, danaBebas) {
        return {
            data, danaBebas,
            labelSumber: @js($labelSumber),
            pilih: {},
            cari: '',
            fSumber: '',

            kunciBaris(k) { return k.sumber + '-' + k.id; },

            get tersaring() {
                const q = this.cari.trim().toLowerCase();
                return this.data.filter((k) =>
                    (!this.fSumber || k.sumber === this.fSumber) &&
                    (!q || (k.nomor + ' ' + k.pihak + ' ' + k.ket).toLowerCase().includes(q)));
            },
            get jumlahDipilih() { return Object.keys(this.pilih).length; },
            get total() { return Object.values(this.pilih).reduce((s, v) => s + (parseFloat(v.nominal) || 0), 0); },

            toggle(k, on) {
                const key = this.kunciBaris(k);
                if (on) this.pilih[key] = { sumber: k.sumber, id: k.id, nominal: k.sisa, sisa: k.sisa };
                else delete this.pilih[key];
            },
            /** Tak pernah boleh melebihi sisa kewajibannya — dibatasi di sini
                supaya orang tak sempat mengetik angka yang pasti ditolak server. */
            setNominal(k, nilai) {
                const key = this.kunciBaris(k);
                if (!this.pilih[key]) return;
                const n = parseFloat(nilai) || 0;
                this.pilih[key].nominal = Math.min(n, k.sisa);
            },
            rp(n) { return 'Rp ' + (Math.round(n || 0)).toLocaleString('id-ID'); },
        };
    }
</script>
@endsection
