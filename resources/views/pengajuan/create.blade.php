@extends('layouts.app')

@php
    $pengajuan = $pengajuan ?? null;
    $perbaikan = (bool) $pengajuan;
    $jenis = $perbaikan ? $pengajuan->jenis : ($jenis ?? 'pembayaran');
    $judul = $perbaikan
        ? 'Perbaiki Pengajuan — ' . $pengajuan->nomor
        : ['pembayaran' => 'Buat Pengajuan Pembayaran', 'uang_muka' => 'Buat Pengajuan Uang Muka', 'penyelesaian_uang_muka' => 'Buat Penyelesaian Uang Muka'][$jenis];
    $labelRincian = ['pembayaran' => 'Rincian Akun yang Diajukan', 'uang_muka' => 'Rincian Uang Muka (akun Aset)', 'penyelesaian_uang_muka' => 'Rincian Realisasi (akun Beban)'][$jenis];
    $labelAkun = ['pembayaran' => 'Akun (Beban/Biaya)', 'uang_muka' => 'Akun Uang Muka', 'penyelesaian_uang_muka' => 'Akun Realisasi (Beban)'][$jenis];
    $kosong = ['kode_coa' => '', 'kode_unit' => '', 'keterangan' => '', 'nominal' => ''];
    $defaultRows = $perbaikan
        ? $pengajuan->details->map(fn ($d) => ['kode_coa' => $d->kode_coa, 'kode_unit' => $d->kode_unit, 'keterangan' => $d->keterangan, 'nominal' => (string) $d->nominal])->values()->all()
        : [$kosong];
    $initRows = array_values(old('details', $defaultRows));
    $opts = ['coa' => $coaOptions, 'unit' => $unitOptions];
    $aksi = $perbaikan ? route('pengajuan.update', $pengajuan->id) : route('pengajuan.store');
@endphp

@section('title', $judul)

@section('content')
    <div class="mx-auto max-w-5xl">
        <a href="{{ route('pengajuan.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @php $pemohonBagian = auth()->user()->bagian?->nama_bagian ?? auth()->user()->kode_bagian; @endphp

        @if ($perbaikan)
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                Pengajuan ini <b>ditolak</b> dan dikembalikan kepada Anda. Perbaiki lalu simpan — rantai persetujuan <b>belum</b> berjalan sampai Anda menekan <b>Ajukan Ulang</b> di halaman Status Pengajuan. Nomor dokumennya tetap, dan riwayat penolakannya tersimpan.
            </div>
        @else
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-800">
                Hanya <strong>Staff</strong> yang boleh mengajukan. Pengajuan otomatis masuk rantai persetujuan (Mudir Bagian → Mudir Umum → eskalasi bila overbudget).
            </div>
        @endif

        @unless ($pemohonBagian)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                Akun Anda belum ditempatkan di bagian mana pun, jadi pengajuan tidak bisa dibebankan ke anggaran manapun. Minta administrator mengisi <b>Bagian</b> pada akun Anda di menu Pengguna.
            </div>
        @endunless

        <form method="POST" action="{{ $aksi }}" x-data="pengajuan(@js($initRows), @js($opts))"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($perbaikan) @method('PUT') @endif
            <input type="hidden" name="jenis" value="{{ $jenis }}">

            @if ($jenis === 'penyelesaian_uang_muka')
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Uang Muka (milik Anda) <span class="text-red-500">*</span></label>
                    <select name="id_uang_muka" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                        <option value="">— pilih uang muka outstanding —</option>
                        @foreach ($uangMukaList as $um)
                            <option value="{{ $um['id'] ?? $um->id ?? '' }}" @selected((string) old('id_uang_muka') === (string) ($um['id'] ?? $um->id ?? ''))>
                                {{ $um['nomor_ref'] ?? $um->nomor_ref ?? '' }} — sisa @rp($um['sisa'] ?? $um->sisa ?? 0)
                            </option>
                        @endforeach
                    </select>
                    @if ($uangMukaList->isEmpty())
                        <p class="mt-1 text-xs text-amber-600">Tidak ada uang muka outstanding milik Anda.</p>
                    @endif
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Bagian TIDAK dipilih: melekat pada profil pemohon (server yang mengambilnya). Ditampilkan agar jelas dibebankan ke mana. --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Bagian (pemohon)</label>
                    <div class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        {{ $pemohonBagian ?? '— belum ditentukan —' }}
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Melekat pada profil Anda; pengajuan dibebankan ke anggaran bagian ini.</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">No. Pengajuan{{ $perbaikan ? '' : ' (otomatis)' }}</label>
                    <div class="w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-700">{{ $perbaikan ? $pengajuan->nomor : $nomorPreview }}</div>
                    @unless ($perbaikan)<p class="mt-1 text-xs text-gray-400">Nomor final ditetapkan saat diajukan.</p>@endunless
                </div>
                <x-field name="tanggal" label="Tanggal" type="date" :value="old('tanggal', now()->toDateString())" required />
                <x-field name="referensi" label="Referensi" :value="old('referensi')" />
                <x-field name="keterangan" label="Keterangan" :value="old('keterangan')" required />
            </div>

            <div class="text-sm font-medium text-gray-700">{{ $labelRincian }}</div>
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="w-full min-w-[48rem] text-sm">
                    <colgroup><col style="width:32%"><col style="width:26%"><col style="width:26%"><col style="width:14%"><col style="width:2%"></colgroup>
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr><th class="px-2 py-2">{{ $labelAkun }}</th><th class="px-2 py-2">Unit Bisnis</th><th class="px-2 py-2">Keterangan</th><th class="px-2 py-2 text-right">Nominal</th><th></th></tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="i">
                            <tr class="border-t border-gray-100 align-top">
                                <td class="px-2 py-1.5"><x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" /></td>
                                <td class="px-2 py-1.5"><x-search-cell name="`details[${i}][kode_unit]`" model="row.kode_unit" options="unitOpts" placeholder="— pilih unit —" /></td>
                                <td class="px-2 py-1.5"><input type="text" :name="`details[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border border-gray-400 px-2 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand"></td>
                                <td class="px-2 py-1.5"><input type="text" inputmode="numeric" :value="fmtRupiah(row.nominal)" @input="row.nominal = ketikRupiah($event)" class="w-full rounded border border-gray-400 px-2 py-1.5 text-right text-sm tabular-nums focus:border-brand focus:ring-1 focus:ring-brand"><input type="hidden" :name="`details[${i}][nominal]`" :value="row.nominal"></td>
                                <td class="px-1 py-1.5 text-center"><button type="button" @click="hapus(i)" x-show="rows.length > 1" class="text-red-500 hover:text-red-700">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="border-t border-gray-200 font-medium">
                            <td class="px-2 py-2" colspan="3"><button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah baris</button></td>
                            <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(total)"></td><td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Ringkasan pembagian per unit — pemohon bisa memeriksa sebelum mengajukan. --}}
            <div x-show="ringkasUnit.length > 1" x-cloak class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
                Pembagian per unit:
                <template x-for="([kode, nom], i) in ringkasUnit" :key="kode">
                    <span><span x-show="i > 0"> · </span><b x-text="unitLabel(kode)"></b> <span x-text="fmt(nom)"></span></span>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('pengajuan.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button type="submit" :disabled="total <= 0"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">{{ $perbaikan ? 'Simpan Perbaikan' : 'Ajukan' }}</button>
            </div>
        </form>
    </div>

    <script>
        function pengajuan(initRows, opts) {
            return {
                rows: initRows.length ? initRows : [{}],
                coaOpts: opts.coa || [],
                unitOpts: opts.unit || [],
                tambah() { this.rows.push({ kode_coa: '', kode_unit: '', keterangan: '', nominal: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                get total() { return this.rows.reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                get ringkasUnit() {
                    const m = {};
                    this.rows.forEach((r) => { const n = parseFloat(r.nominal) || 0; if (r.kode_unit && n > 0) m[r.kode_unit] = (m[r.kode_unit] || 0) + n; });
                    return Object.entries(m);
                },
                unitLabel(kode) { const o = this.unitOpts.find((u) => String(u.v) === String(kode)); return o ? o.l : kode; },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
