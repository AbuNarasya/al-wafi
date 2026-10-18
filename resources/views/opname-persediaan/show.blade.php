@extends('layouts.app')

@section('title', 'Opname ' . $doc->nomor)

@section('content')
    @php
        $angka = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $draft = $doc->status === 'draft';
        $bisaHitung = $draft && \App\Support\Akses::boleh('opname-persediaan', 'buat');
        $data = $baris->map(fn ($d) => [
            'k' => $d->kode_persediaan,
            'n' => $d->persediaan?->nama_persediaan,
            's' => $d->persediaan?->satuan,
            'f' => $angka($d->stok_fisik),
            't' => $tampilkanTercatat ? $angka($d->stok_tercatat) : null,
            'v' => $d->nilai_selisih,
        ])->all();
    @endphp
    <div class="mx-auto max-w-5xl space-y-4" x-data="opname(@js($data))">
        <div class="flex items-center justify-between">
            <a href="{{ route('opname_persediaan.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            @if (in_array($doc->status, ['draft', 'diajukan'], true) && \App\Support\Akses::boleh('opname-persediaan', 'hapus'))
                <form method="POST" action="{{ route('opname_persediaan.batal', $doc->id_opname) }}" onsubmit="return confirm('Batalkan lembar opname ini?')">
                    @csrf @method('DELETE')
                    <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Batalkan</button>
                </form>
            @endif
        </div>

        <div class="grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor</div><div class="font-mono font-semibold">{{ $doc->nomor }}</div></div>
            <div><div class="text-xs text-gray-400">Lokasi</div><div>{{ $doc->lokasi?->nama }}{{ $doc->kategori ? ' · '.$doc->kategori->nama : '' }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $doc->tanggal->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div><div class="font-medium">{{ \App\Models\OpnamePersediaan::STATUS[$doc->status] }}</div></div>
            <div><div class="text-xs text-gray-400">Dibuat</div><div>{{ $doc->pembuat?->nama ?? '—' }}</div></div>
            @if ($doc->pengaju)<div><div class="text-xs text-gray-400">Diajukan</div><div>{{ $doc->pengaju->nama }}</div></div>@endif
            @if ($doc->penyetuju)<div><div class="text-xs text-gray-400">Disetujui</div><div>{{ $doc->penyetuju->nama }} · {{ $doc->disetujui_pada?->format('d/m/Y H:i') }}</div></div>@endif
            <div><div class="text-xs text-gray-400">Bagian penanggung</div><div>{{ $doc->bagian?->nama_bagian ?? '—' }}</div></div>
            @if ($doc->catatan_putusan)<div class="sm:col-span-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ $doc->catatan_putusan }}</div>@endif
        </div>

        @if ($draft && ! $tampilkanTercatat)
            <p class="rounded-lg bg-blue-50 px-4 py-2 text-xs text-blue-800">
                <b>Hitung buta:</b> stok menurut sistem sengaja tak ditampilkan — hitung yang ada di rak apa adanya.
                Kosongkan barang yang belum dihitung; boleh disimpan bertahap.
            </p>
        @endif

        <form method="POST" action="{{ route('opname_persediaan.hitung', $doc->id_opname) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="hitungan" :value="JSON.stringify(hitungan())">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <input type="text" x-model="cari" placeholder="Cari barang…" class="rounded-lg border-gray-300 text-sm">
                <div class="text-sm text-gray-600">Dihitung <b x-text="jumlahDihitung()"></b> dari {{ count($data) }} barang</div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Barang</th>
                            @if ($tampilkanTercatat)<th class="px-4 py-3 text-right">Tercatat</th>@endif
                            <th class="px-4 py-3 text-right">Fisik</th>
                            @if ($tampilkanTercatat)<th class="px-4 py-3 text-right">Selisih</th>@endif
                            @if ($doc->status === 'disetujui')<th class="px-4 py-3 text-right">Nilai Selisih</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="r in tampil()" :key="r.k">
                            <tr>
                                <td class="px-4 py-2"><span x-text="r.n"></span> <span class="text-xs text-gray-400" x-text="'(' + r.s + ')'"></span></td>
                                @if ($tampilkanTercatat)<td class="px-4 py-2 text-right tabular-nums text-gray-500" x-text="r.f === '' ? '—' : r.t"></td>@endif
                                <td class="px-4 py-2 text-right">
                                    @if ($bisaHitung)
                                        <input type="number" step="0.0001" min="0" x-model="r.f" class="w-28 rounded border-gray-300 text-right text-sm" placeholder="—">
                                    @else
                                        <span class="tabular-nums" x-text="r.f === '' ? '—' : r.f"></span>
                                    @endif
                                </td>
                                @if ($tampilkanTercatat)
                                    <td class="px-4 py-2 text-right tabular-nums" :class="selisih(r) > 0 ? 'text-emerald-700' : (selisih(r) < 0 ? 'text-red-600' : 'text-gray-400')"
                                        x-text="r.f === '' || r.t === '' ? '—' : (selisih(r) > 0 ? '+' : '') + selisih(r)"></td>
                                @endif
                                @if ($doc->status === 'disetujui')
                                    <td class="px-4 py-2 text-right tabular-nums" x-text="r.v === null ? '—' : fmt(r.v)"></td>
                                @endif
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            @if ($bisaHitung)
                <div class="flex flex-wrap items-end justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4">
                    <div class="w-64"><x-field name="kode_bagian" label="Bagian penanggung selisih" :value="$doc->kode_bagian" :options="$bagianOptions" /></div>
                    <div class="flex gap-2">
                        <button class="rounded-lg border border-brand px-4 py-2 text-sm font-medium text-brand hover:bg-brand-soft">Simpan Hitungan</button>
                        <button name="ajukan" value="1" onclick="return confirm('Ajukan opname ini? Hitungan terkunci sampai diputus.')"
                                class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan &amp; Ajukan</button>
                    </div>
                </div>
            @endif
        </form>

        @if ($doc->status === 'diajukan' && \App\Support\Akses::boleh('persetujuan-persediaan', 'ubah'))
            <form method="POST" action="{{ route('opname_persediaan.putus', $doc->id_opname) }}" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                @csrf
                <div class="font-semibold text-amber-900">Persetujuan</div>
                <p class="text-amber-800">Disetujui = selisih tiap barang menggerakkan stok {{ $doc->lokasi?->nama }} dan dijurnal ke akun Selisih Persediaan.</p>
                <input type="text" name="catatan" placeholder="Alasan bila ditolak" class="w-full rounded border-gray-300 text-sm">
                <div class="flex gap-2">
                    <button name="putusan" value="tolak" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Tolak — hitung ulang</button>
                    <button name="putusan" value="setujui" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Setujui &amp; Bukukan</button>
                </div>
            </form>
        @endif
    </div>

    <script>
        function opname(data) {
            return {
                rows: data, cari: '',
                tampil() {
                    const q = this.cari.trim().toLowerCase();
                    return q ? this.rows.filter(r => (r.n || '').toLowerCase().includes(q)) : this.rows;
                },
                // Hanya yang terisi atau sengaja dikosongkan yang dikirim — kosong = belum dihitung.
                hitungan() { const h = {}; this.rows.forEach(r => { h[r.k] = r.f === null ? '' : String(r.f); }); return h; },
                jumlahDihitung() { return this.rows.filter(r => r.f !== '' && r.f !== null).length; },
                selisih(r) { return Math.round(((parseFloat(r.f) || 0) - (parseFloat(r.t) || 0)) * 10000) / 10000; },
                fmt(n) { return 'Rp ' + Math.round(parseFloat(n) || 0).toLocaleString('id-ID'); },
            };
        }
    </script>
@endsection
