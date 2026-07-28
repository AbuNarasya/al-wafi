@extends('layouts.app')

@section('title', 'Rencana Angsuran Baru')

@section('content')
    <div class="mx-auto max-w-2xl">
        <a href="{{ route('angsuran_uang_pangkal.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if ($santriData->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">
                Tidak ada santri dengan tagihan uang pangkal yang belum punya rencana angsuran aktif.
            </div>
        @else
            <form method="POST" action="{{ route('angsuran_uang_pangkal.store') }}" x-data="angsuran(@js($santriData))"
                  class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                @csrf

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Santri (punya tagihan uang pangkal) <span class="text-red-500">*</span></label>
                    <select name="id_santri" x-model.number="santriId" required class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm">
                        <option value="">— pilih santri —</option>
                        <template x-for="s in list" :key="s.id_santri"><option :value="s.id_santri" x-text="s.nama"></option></template>
                    </select>
                    <p class="mt-1 text-xs text-brand" x-show="selected" x-cloak>Total uang pangkal: <span x-text="fmt(total)"></span></p>
                </div>

                <x-field name="disepakati_pada" label="Disepakati Pada" type="date" :value="old('disepakati_pada', now()->toDateString())" required />

                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-2 py-2">Termin</th><th class="px-2 py-2">Jatuh Tempo</th><th class="px-2 py-2">Keterangan</th><th class="px-2 py-2 text-right">Nominal</th><th></th></tr></thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-t border-gray-100">
                                    <td class="px-2 py-1.5" x-text="i + 1"></td>
                                    <td class="px-2 py-1.5"><input type="date" :name="`termin[${i}][jatuh_tempo]`" x-model="row.jatuh_tempo" required class="rounded border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="text" :name="`termin[${i}][keterangan]`" x-model="row.keterangan" class="w-full rounded border-gray-300 text-sm"></td>
                                    <td class="px-2 py-1.5"><input type="number" step="0.01" min="0" :name="`termin[${i}][nominal]`" x-model="row.nominal" required class="w-32 rounded border-gray-300 text-right text-sm"></td>
                                    <td class="px-2 py-1.5 text-center"><button type="button" @click="hapus(i)" x-show="rows.length > 1" class="text-red-500">&times;</button></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr class="border-t border-gray-200 font-medium">
                                <td class="px-2 py-2" colspan="3"><button type="button" @click="tambah()" class="text-sm text-brand hover:underline">+ Tambah termin</button></td>
                                <td class="px-2 py-2 text-right tabular-nums" x-text="fmt(sumTermin)"></td><td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="text-sm" x-show="selected" x-cloak>
                    <span x-show="Math.abs(sumTermin - total) < 0.005" class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">✓ Jumlah termin cocok dengan total</span>
                    <span x-show="Math.abs(sumTermin - total) >= 0.005" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-700" x-text="'Selisih: ' + fmt(sumTermin - total)"></span>
                </div>

                <x-field name="catatan" label="Catatan" :value="old('catatan')" textarea />

                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                    <a href="{{ route('angsuran_uang_pangkal.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                    <button type="submit" :disabled="!selected || Math.abs(sumTermin - total) >= 0.005"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">Simpan Rencana</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        function angsuran(list) {
            return {
                list, santriId: '', rows: [{ jatuh_tempo: '', keterangan: '', nominal: '' }],
                get selected() { return this.list.find(s => s.id_santri === this.santriId) || null; },
                get total() { return this.selected?.total || 0; },
                get sumTermin() { return this.rows.reduce((s, r) => s + (parseFloat(r.nominal) || 0), 0); },
                tambah() { this.rows.push({ jatuh_tempo: '', keterangan: '', nominal: '' }); },
                hapus(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
                fmt(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
