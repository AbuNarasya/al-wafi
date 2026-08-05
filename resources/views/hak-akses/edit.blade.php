@extends('layouts.app')

@section('title', 'Hak Akses — ' . $user->nama)

@php
    // Daftar modul terurut (grup→sub) sudah disiapkan controller. Precompute
    // indeks per grup & per sub-grup agar tombol massal "penuh/kosongkan" bisa
    // menyentuh seluruh baris dalam blok itu.
    $flat = $modul->values();
    $grupIdx = [];
    $subIdx = [];
    foreach ($flat as $i => $m) {
        $grupIdx[$m['grup']][] = $i;
        $subIdx[$m['grup'] . '||' . ($m['sub'] ?? '')][] = $i;
    }
    $gPrev = null;
    $sPrev = null;
@endphp

@section('content')
    <a href="{{ route('users.index') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke Pengguna</a>

    @if ($user->is_admin)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            ⚠️ <strong>{{ $user->nama }}</strong> adalah <b>administrator</b> — ia melewati seluruh matriks di bawah, jadi centang di sini tidak berpengaruh untuknya.
            Pengaturan tetap tersimpan. Status administrator sengaja hanya bisa diubah langsung di database.
        </div>
    @endif

    @if (($belumPernahDiatur ?? false) && ! $user->is_admin)
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            Hak akses <strong>{{ $user->username }}</strong> belum pernah diatur, jadi saat ini ia <b>tidak bisa membuka apa pun</b> —
            bahkan sidebar-nya kosong. Centang modul yang ia perlukan lalu tekan <b>Simpan Hak Akses</b>.
        </div>
    @endif

    <div class="mb-3 space-y-2 text-xs text-gray-500">
        <p class="text-sm text-gray-600">Tentukan modul apa saja yang boleh diakses <strong>{{ $user->username }}</strong>, beserta aksinya. <b>Tidak dicentang = tidak punya akses.</b></p>
        <p class="rounded bg-gray-50 px-3 py-2"><b>Lihat</b> dan <b>Menu</b> sengaja dipisah. <b>Lihat</b> = boleh membaca datanya. <b>Menu</b> = tampil di sidebar. Bila sebuah modul hanya dipakai sebagai sumber dropdown di modul lain, centang <b>Lihat</b> saja dan matikan <b>Menu</b>-nya.</p>
    </div>

    <form method="POST" action="{{ route('hak_akses.update', $user) }}">
        @csrf @method('PUT')

        <div class="mb-3 flex items-center justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Hak Akses</button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table data-matriks class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left">Modul</th>
                        <th class="px-3 py-3 text-center" title="Boleh membaca seluruh datanya">Lihat</th>
                        <th class="px-3 py-3 text-center" title="Membuat data baru">Buat</th>
                        <th class="px-3 py-3 text-center" title="Menyunting data yang ada">Ubah</th>
                        <th class="px-3 py-3 text-center" title="Menghapus atau membatalkan dokumen">Hapus</th>
                        <th class="border-l border-gray-300 px-3 py-3 text-center" title="Tampil di sidebar. Matikan bila modulnya hanya sumber dropdown.">Menu</th>
                        <th class="px-3 py-3 text-center">Semua</th>
                    </tr>
                </thead>
                <tbody x-data="hakAkses(@js($flat))">
                    @foreach ($flat as $i => $m)
                        @php
                            $newGrup = $m['grup'] !== $gPrev;
                            if ($newGrup) { $gPrev = $m['grup']; $sPrev = null; }
                            $sub = $m['sub'] ?? null;
                            $newSub = $sub && $sub !== $sPrev;
                            if ($newSub) { $sPrev = $sub; }
                            $gi = implode(',', $grupIdx[$m['grup']]);
                            $si = $sub ? implode(',', $subIdx[$m['grup'] . '||' . $sub]) : '';
                        @endphp

                        @if ($newGrup)
                            <tr class="border-t-2 border-gray-200 bg-gray-100">
                                <td class="px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-gray-700">{{ $m['grup'] }}</td>
                                <td colspan="5"></td>
                                <td class="px-3 py-1.5 text-center">
                                    <button type="button" class="text-[11px] text-brand hover:underline"
                                            @click="setBlok([{{ $gi }}], !blokPenuh([{{ $gi }}]))"
                                            x-text="blokPenuh([{{ $gi }}]) ? 'kosongkan' : 'penuh'"></button>
                                </td>
                            </tr>
                        @endif

                        @if ($newSub)
                            <tr class="bg-gray-50">
                                <td class="py-1 pl-8 pr-3 text-xs font-semibold text-gray-500">{{ $sub }}</td>
                                <td colspan="5"></td>
                                <td class="px-3 py-1 text-center">
                                    <button type="button" class="text-[11px] text-brand hover:underline"
                                            @click="setBlok([{{ $si }}], !blokPenuh([{{ $si }}]))"
                                            x-text="blokPenuh([{{ $si }}]) ? 'kosongkan' : 'penuh'"></button>
                                </td>
                            </tr>
                        @endif

                        <tr class="border-t border-gray-100 hover:bg-gray-50/60">
                            <td class="py-2 pr-3 {{ $sub ? 'pl-14' : 'pl-8' }}">
                                <span class="font-medium text-gray-800">{{ $m['nama'] }}</span>
                                <span class="ml-2 font-mono text-[10px] text-gray-400">{{ $m['kode'] }}</span>
                            </td>
                            @foreach (['lihat', 'buat', 'ubah', 'hapus'] as $aksi)
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" name="hak[{{ $m['kode'] }}][{{ $aksi }}]" value="1"
                                           :checked="rows[{{ $i }}].{{ $aksi }}"
                                           @change="setAksi({{ $i }}, '{{ $aksi }}', $event.target.checked)"
                                           class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand">
                                </td>
                            @endforeach
                            <td class="border-l border-gray-200 px-3 py-2 text-center">
                                <input type="checkbox" name="hak[{{ $m['kode'] }}][menu]" value="1"
                                       :checked="rows[{{ $i }}].menu" :disabled="!rows[{{ $i }}].lihat"
                                       :title="rows[{{ $i }}].lihat ? 'Tampil di sidebar' : 'Centang &quot;Lihat&quot; dulu.'"
                                       @change="setAksi({{ $i }}, 'menu', $event.target.checked)"
                                       class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand disabled:opacity-40">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" class="text-xs text-brand hover:underline"
                                        @click="setBaris({{ $i }}, !penuh(rows[{{ $i }}]))"
                                        x-text="penuh(rows[{{ $i }}]) ? 'kosongkan' : 'penuh'"></button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 flex justify-end">
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Hak Akses</button>
        </div>
    </form>

    <script>
        function hakAkses(rows) {
            return {
                rows,
                /** "Penuh" = seluruh aksi + tampil di menu. */
                penuh(r) { return r.lihat && r.buat && r.ubah && r.hapus && r.menu; },
                /**
                 * Cermin aturan server: aksi tulis/menu WAJIB disertai "lihat".
                 * Matikan "lihat" → matikan sisanya; nyalakan aksi lain → paksa "lihat".
                 */
                setAksi(i, aksi, nilai) {
                    const r = this.rows[i];
                    r[aksi] = nilai;
                    if (aksi === 'lihat' && !nilai) { r.buat = false; r.ubah = false; r.hapus = false; r.menu = false; }
                    if (aksi !== 'lihat' && nilai) r.lihat = true;
                },
                /** Kolom "Semua" per baris — termasuk menu. */
                setBaris(i, nilai) {
                    const r = this.rows[i];
                    r.lihat = nilai; r.buat = nilai; r.ubah = nilai; r.hapus = nilai; r.menu = nilai;
                },
                /** Tombol massal grup/sub "penuh/kosongkan" — mencakup SEMUA aksi + menu. */
                setBlok(indices, nilai) {
                    indices.forEach((i) => { const r = this.rows[i]; r.lihat = nilai; r.buat = nilai; r.ubah = nilai; r.hapus = nilai; r.menu = nilai; });
                },
                blokPenuh(indices) { return indices.every((i) => this.penuh(this.rows[i])); },
            };
        }
    </script>
@endsection
