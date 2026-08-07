@extends('layouts.app')

@php
    $final = in_array($santri->status, \App\Services\Ppsb\Tahap::STATUS_FINAL, true) || $santri->status === 'aktif';
    $act = fn ($a) => route('santri.aksi', ['id' => $santri->id, 'aksi' => $a]);
@endphp

@section('title', 'Santri — ' . $santri->nama)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-3 flex items-center justify-between">
            {{-- Tujuannya ditentukan controller: halaman ini dipakai daftar Calon
                 Santri (PPSB) maupun Santri (Kependidikan). --}}
            <a href="{{ $kembali }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="flex items-center gap-2">
                @if (\App\Support\Akses::boleh('santri', 'ubah'))
                    <a href="{{ route('santri.edit', $santri->id) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">✏️ Ubah Data</a>
                @endif
                @if (\App\Support\Akses::boleh('rekap-pembayaran'))
                    <a href="{{ route('rekap_pembayaran.show', $santri->id) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">🧾 Rekap Pembayaran</a>
                @endif
                @if (\App\Support\Akses::boleh('dokumen-santri'))
                    <a href="{{ route('dokumen_santri.index', $santri->id) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">📎 Berkas Santri</a>
                @endif
            </div>
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">No. Pendaftaran</div><div class="font-semibold text-gray-900">{{ $santri->no_pendaftaran }}</div></div>
            <div><div class="text-xs text-gray-400">NIS</div><div>{{ $santri->nis ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Nama</div><div>{{ $santri->nama }} ({{ $santri->jenis_kelamin }})</div></div>
            <div><div class="text-xs text-gray-400">Status</div><span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $labelStatus }}</span></div>
            <div><div class="text-xs text-gray-400">Jenjang / Jalur</div><div>{{ $santri->jenjang?->nama ?? $santri->kode_jenjang ?? '—' }} · {{ $santri->jalurPendaftaran?->nama ?? ucfirst((string) $santri->jalur) }}</div></div>
            {{-- Tingkat bisa diubah di tempat: santri lama & hasil impor belum
                 punya nilainya, dan tak ada form sunting santri yang lengkap. --}}
            <div x-data="{ ubah: false }">
                <div class="text-xs text-gray-400">Tingkat</div>
                <div class="flex items-center gap-2" x-show="!ubah">
                    <span>{{ $santri->tingkat ? 'Tingkat '.$santri->tingkat : '—' }}</span>
                    @if (\App\Support\Akses::boleh('santri', 'ubah') && $opsiTingkat !== [])
                        <button type="button" @click="ubah = true" class="text-xs text-brand hover:underline">ubah</button>
                    @endif
                </div>
                @if (\App\Support\Akses::boleh('santri', 'ubah') && $opsiTingkat !== [])
                    <form x-show="ubah" x-cloak method="POST" action="{{ $act('set-tingkat') }}" class="flex items-center gap-1">
                        @csrf
                        <select name="tingkat" required class="rounded border-gray-300 py-1 text-sm">
                            @foreach ($opsiTingkat as $nomor => $labelTingkat)
                                <option value="{{ $nomor }}" @selected($santri->tingkat === $nomor)>{{ $labelTingkat }}</option>
                            @endforeach
                        </select>
                        <button class="text-xs font-semibold text-brand hover:underline">simpan</button>
                        <button type="button" @click="ubah = false" class="text-xs text-gray-400 hover:underline">batal</button>
                    </form>
                @endif
            </div>
            <div><div class="text-xs text-gray-400">Tahun Ajaran (angkatan)</div><div>{{ $santri->tahun_ajaran ?? '—' }}</div></div>
            {{-- Tahun BERJALAN, dipisahkan dari angkatan: angkatan tak pernah maju,
                 sedangkan yang ini maju tiap kenaikan. Bisa dikoreksi di tempat —
                 berkas impor yang kolom tahun ajarannya salah dulu tak punya jalan
                 diperbaiki sama sekali, dan santri yang selisihnya lebih dari satu
                 tahun akan terus dilewati Kenaikan Tingkat demi menjaga riwayatnya. --}}
            @if ($santri->status === 'aktif')
                <div x-data="{ ubahTa: false }">
                    <div class="text-xs text-gray-400">T.A Berjalan</div>
                    <div class="flex items-center gap-2" x-show="!ubahTa">
                        <span>{{ $santri->taBerjalan() ?? '—' }}</span>
                        @if (\App\Support\Akses::boleh('santri', 'ubah'))
                            <button type="button" @click="ubahTa = true" class="text-xs text-brand hover:underline">ubah</button>
                        @endif
                    </div>
                    @if (\App\Support\Akses::boleh('santri', 'ubah'))
                        <form x-show="ubahTa" x-cloak method="POST" action="{{ $act('set-tahun-berjalan') }}" class="flex items-center gap-1"
                              data-confirm="Koreksi tahun ajaran berjalan? Tingkat, jenjang, dan tagihan yang sudah terbit TIDAK ikut berubah.">
                            @csrf
                            <select name="tahun_ajaran_berjalan" required class="rounded border-gray-300 py-1 text-sm">
                                @foreach ($opsiTaBerjalan as $kodeTa)
                                    <option value="{{ $kodeTa }}" @selected($santri->taBerjalan() === $kodeTa)>{{ $kodeTa }}</option>
                                @endforeach
                            </select>
                            <button class="text-xs font-semibold text-brand hover:underline">simpan</button>
                            <button type="button" @click="ubahTa = false" class="text-xs text-gray-400 hover:underline">batal</button>
                        </form>
                    @endif
                </div>
            @endif
            <div><div class="text-xs text-gray-400">Gelombang</div><div>{{ $santri->gelombang ?? 'Tanpa Gelombang' }}</div></div>
            {{-- Tanggal lulus hanya bermakna bagi alumni. Dulu kolomnya terisi saat
                 kelulusan tapi TAK PERNAH ditampilkan di layar mana pun — tanggal
                 ijazah tersimpan diam-diam. --}}
            @if ($santri->status === 'alumni')
                <div>
                    <div class="text-xs text-gray-400">Tanggal Lulus</div>
                    <div class="font-medium text-purple-700">{{ $santri->tanggal_lulus ? $santri->tanggal_lulus->format('d/m/Y') : '—' }}</div>
                </div>
            @endif
            <div class="sm:col-span-2"><div class="text-xs text-gray-400">Wali</div><div>{{ $santri->wali?->nama ?? '—' }} · {{ $santri->wali?->telepon }}</div></div>
        </div>

        {{-- Sekolah asal (kontak dipakai saat verifikasi berkas pindahan) --}}
        @if ($santri->asal_sekolah || $santri->kepala_sekolah_asal || $santri->wali_kelas_asal)
            <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
                <div class="sm:col-span-4 text-sm font-semibold text-gray-700">Sekolah Asal</div>
                <div><div class="text-xs text-gray-400">Nama Sekolah</div><div>{{ $santri->asal_sekolah ?? '—' }}</div></div>
                <div><div class="text-xs text-gray-400">Alamat</div><div>{{ $santri->alamat_sekolah_asal ?? '—' }}</div></div>
                <div><div class="text-xs text-gray-400">Kepala Sekolah</div><div>{{ $santri->kepala_sekolah_asal ?? '—' }}<div class="text-xs text-gray-500">{{ $santri->cp_kepala_sekolah_asal ?? '—' }}</div></div></div>
                <div><div class="text-xs text-gray-400">Wali Kelas</div><div>{{ $santri->wali_kelas_asal ?? '—' }}<div class="text-xs text-gray-500">{{ $santri->cp_wali_kelas_asal ?? '—' }}</div></div></div>
            </div>
        @endif

        {{-- Detail Calon: penanggung jawab + hasil tes & wawancara --}}
        @php
            $w = $santri->wali;
            $kontak = $w?->kontak_utama;
            $labelKontak = ['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali'][$kontak] ?? ($kontak ?? '—');
            $emailPj = $kontak ? ($w?->{"email_{$kontak}"} ?? null) : null;
            $p = $santri->pendaftaran;
        @endphp
        <div class="mb-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-2 text-sm font-bold text-gray-800">Penanggung Jawab ({{ $labelKontak }})</h3>
                <dl class="grid grid-cols-[7rem_1fr] gap-x-2 gap-y-1.5 text-sm">
                    <dt class="text-gray-600">Nama</dt><dd class="break-words text-gray-800">: {{ $w?->nama ?? '—' }}</dd>
                    <dt class="text-gray-600">No. HP</dt><dd class="break-words text-gray-800">: {{ $w?->telepon ?? '—' }}</dd>
                    <dt class="text-gray-600">Email</dt><dd class="break-words text-gray-800">: {{ $emailPj ?? '—' }}</dd>
                </dl>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-2 text-sm font-bold text-gray-800">Hasil Tes &amp; Wawancara</h3>
                <dl class="grid grid-cols-[9rem_1fr] gap-x-2 gap-y-1.5 text-sm">
                    <dt class="text-gray-600">Baca Qur'an</dt><dd class="text-gray-800">: {{ $p && $p->nilai_baca !== null ? $p->nilai_baca : '—' }}</dd>
                    <dt class="text-gray-600">Akademik</dt><dd class="text-gray-800">: {{ $p && $p->nilai_akademik !== null ? $p->nilai_akademik : '—' }}</dd>
                    <dt class="text-gray-600">Wawancara Wali</dt><dd class="whitespace-pre-wrap break-words text-gray-800">: {{ $p?->wawancara_wali ?: '—' }}</dd>
                    <dt class="text-gray-600">Wawancara Calon</dt><dd class="whitespace-pre-wrap break-words text-gray-800">: {{ $p?->wawancara_santri ?: '—' }}</dd>
                    <dt class="text-gray-600">Catatan Panitia</dt><dd class="whitespace-pre-wrap break-words text-gray-800">: {{ $p?->catatan ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        {{-- Tagihan --}}
        <div class="mb-4 rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-700">Tagihan</div>
            <table class="min-w-full text-sm">
                {{-- Koreksi nominal hanya untuk pemegang hak `koreksi-tagihan`
                     (kepala keuangan). Ia mengubah piutang yang sudah dibukukan
                     dan menerbitkan jurnal penyesuaian, jadi kolomnya pun tak
                     ditampilkan bagi yang tak berwenang. --}}
                @php $bolehKoreksi = \App\Support\Akses::boleh('koreksi-tagihan', 'ubah'); @endphp
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-2">Jenis</th><th class="px-4 py-2">Keterangan</th><th class="px-4 py-2 text-right">Nominal</th><th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2">Status</th>@if ($bolehKoreksi)<th class="px-4 py-2"></th>@endif</tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($santri->tagihan as $t)
                        {{-- Setoran yang sudah dicatat tapi belum diverifikasi keuangan tidak
                             mengurangi `sisa`. Tanpa ditampilkan, tagihan yang baru dibayar
                             tampak seolah belum tersentuh — petugas bisa menagih dua kali. --}}
                        @php $tunggu = $menungguPerTagihan[$t->id] ?? null; @endphp
                        <tr><td class="px-4 py-2">{{ $t->kode_jenis }}</td><td class="px-4 py-2 text-gray-600">{{ $t->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($t->nominal)</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($t->sisa)
                                @if ($tunggu && (float) $tunggu > 0)
                                    <div class="text-[11px] font-normal text-amber-600">@rp($tunggu) menunggu verifikasi</div>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $t->status === 'lunas' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ ucfirst(str_replace('_',' ',$t->status)) }}</span>
                                @if ($tunggu && (float) $tunggu > 0)
                                    <div class="mt-0.5 text-[11px] text-gray-500">sudah disetor, menunggu keuangan</div>
                                @endif
                            </td>
                            @if ($bolehKoreksi)
                                <td class="px-4 py-2 text-right">
                                    @if ($t->status !== 'batal')
                                        <div x-data="{ buka: false }" class="relative inline-block">
                                            <button type="button" @click="buka = ! buka"
                                                    class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Koreksi</button>
                                            <form x-show="buka" x-cloak @click.outside="buka = false" method="POST"
                                                  action="{{ route('tagihan.koreksi', $t->id) }}"
                                                  class="absolute right-0 z-20 mt-1 w-80 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                                @csrf
                                                <p class="text-xs text-gray-600">
                                                    Nominal sekarang <b>@rp($t->nominal)</b>, sudah dibayar
                                                    <b>Rp {{ number_format((float) $t->nominal - (float) $t->sisa, 0, ',', '.') }}</b>.
                                                </p>
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-gray-600">Nominal baru</label>
                                                    {{-- `nominal_baru`, bukan `nominal`: di halaman ini
                                                         `name="nominal"` sudah berarti isian uang pangkal,
                                                         dan ketiadaannya dipakai sebagai bukti bahwa jalur
                                                         bebas uang pangkal memang tak menawarkannya. --}}
                                                    <x-input-rupiah name="nominal_baru" :value="$t->nominal" required />
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium text-gray-600">Alasan koreksi</label>
                                                    <input type="text" name="alasan" required maxlength="255"
                                                           placeholder="mis. salah baca berkas impor"
                                                           class="w-full rounded border-gray-300 text-xs">
                                                </div>
                                                {{-- Dua akibat yang tak diminta petugas secara langsung, dan
                                                     justru paling mudah luput — disebutkan SEBELUM ditekan. --}}
                                                <p class="text-[11px] leading-relaxed text-gray-500">
                                                    Menurunkan di bawah yang sudah dibayar boleh — kelebihannya masuk
                                                    <b>Dompet Wali</b> sebagai titipan. Bila tagihan ini punya jadwal
                                                    angsuran, jadwalnya digugurkan dan harus disusun ulang bersama walinya.
                                                </p>
                                                <button class="w-full rounded bg-brand px-2 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark">Simpan Koreksi</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $bolehKoreksi ? 6 : 5 }}" class="px-4 py-6 text-center text-gray-400">Belum ada tagihan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Aksi lifecycle --}}
        @if (! $final && \App\Support\Akses::boleh('santri', 'ubah'))
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-800">Proses Penerimaan</h3>
                <div class="flex flex-wrap gap-2">
                    @if ($santri->status === 'terbayar')
                        <form method="POST" action="{{ $act('verifikasi') }}">@csrf<button class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">Verifikasi Berkas</button></form>
                    @endif
                    @if ($santri->status === 'terverifikasi')
                        <form method="POST" action="{{ $act('seleksi') }}" class="w-full space-y-2 rounded-lg border border-gray-100 bg-gray-50 p-3">@csrf
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="block text-xs text-gray-600">Nilai Baca Qur'an
                                    <input type="number" step="0.01" name="nilai_baca" placeholder="0–100" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                                <label class="block text-xs text-gray-600">Nilai Akademik
                                    <input type="number" step="0.01" name="nilai_akademik" placeholder="0–100" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                                <label class="block text-xs text-gray-600">Wawancara Wali
                                    <textarea name="wawancara_wali" rows="2" class="mt-0.5 w-full rounded border-gray-300 text-sm"></textarea>
                                </label>
                                <label class="block text-xs text-gray-600">Wawancara Calon Santri
                                    <textarea name="wawancara_santri" rows="2" class="mt-0.5 w-full rounded border-gray-300 text-sm"></textarea>
                                </label>
                            </div>
                            <label class="block text-xs text-gray-600">Catatan Seleksi
                                <input type="text" name="catatan" placeholder="Catatan panitia" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                            </label>
                            <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">Selesai Seleksi</button>
                        </form>
                    @endif
                    @if ($santri->status === 'diseleksi')
                        <form method="POST" action="{{ $act('pengumuman') }}" class="flex items-center gap-2">@csrf
                            <input type="hidden" name="lulus" value="1">
                            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Umumkan: Diterima</button>
                        </form>
                        <form method="POST" action="{{ $act('pengumuman') }}">@csrf<input type="hidden" name="lulus" value="0"><button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Umumkan: Tidak Lulus</button></form>
                    @endif
                    @if ($santri->status === 'diterima')
                        <form method="POST" action="{{ $act('medcheck') }}" class="flex items-center gap-2">@csrf
                            <input type="hidden" name="lolos" value="1"><input type="hidden" name="dokumen_lengkap" value="1">
                            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Med Check: Lolos</button>
                        </form>
                        <form method="POST" action="{{ $act('medcheck') }}">@csrf<input type="hidden" name="lolos" value="0"><button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Med Check: Gagal</button></form>
                    @endif
                    {{-- Formnya muncul hanya bila MASIH ADA yang bisa diterbitkan. Dulu
                         syaratnya "belum ada tagihan uang pangkal", sehingga calon berjalur
                         bebas uang pangkal — yang tak pernah punya tagihan itu — terus
                         disodori form walau perlengkapannya sudah terbit. --}}
                    @if (in_array($santri->status, ['diterima', 'lolos_kesehatan'], true) && $bisaTagih['ada'])
                        {{-- Tagihkan uang pangkal: nominal diinput per calon (agar keringanan/potongan bisa) --}}
                        <form method="POST" action="{{ $act('tagih-uang-pangkal') }}" class="w-full space-y-2 rounded-lg border border-amber-200 bg-amber-50/40 p-3">@csrf
                            <div class="text-sm font-semibold text-gray-700">Tagihkan Uang Pangkal &amp; Perlengkapan</div>
                            <p class="text-xs text-gray-500">Masukkan nominal <b>NORMAL</b>. Bila gelombang berpotongan, tagihan terbit sebesar setelah potongan. <b>Belum menerbitkan jurnal</b> — yang dibayar sebelum daftar ulang diakui saat uang diterima, sisanya diakrualkan saat Daftar Ulang.</p>
                            @if ($potonganUangPangkal)
                                <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Potongan Gelombang {{ $santri->gelombang }} (T.A {{ $potonganUangPangkal->tahun_ajaran }}): <b>− @rp($potonganUangPangkal->potongan)</b>. Tagihan = nominal normal − potongan. Syarat pertahankan potongan: bayar <b>≥ 50%</b> dalam {{ $potonganUangPangkal->masa_berlaku_hari ?? 7 }} hari.
                                </div>
                            @endif
                            <div class="grid gap-2 sm:grid-cols-3">
                                @if ($bebasUangPangkal)
                                    {{-- Sel tarifnya bertanda BEBAS (mis. jalur Anak Karyawan atau
                                         OSS lanjutan): isiannya ditiadakan, bukan dibiarkan lalu
                                         ditolak saat disimpan. --}}
                                    <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                                        <b>Bebas uang pangkal.</b> Jalur {{ $santri->jalurPendaftaran?->nama ?? $santri->jalur }}
                                        tidak ditagih uang pangkal — yang terbit hanya biaya perlengkapan.
                                        <span class="mt-0.5 block text-[11px] text-emerald-700/80">
                                            <x-asal-tarif :bagian="$asalTarifUangPangkal['bagian']" :teks="$asalTarifUangPangkal['label']" />
                                        </span>
                                    </div>
                                @elseif (! $bisaTagih['uang_pangkal'])
                                    {{-- Sudah terbit: isiannya ditiadakan, bukan dibiarkan lalu
                                         ditolak 409 saat disimpan. Yang tersisa di form ini
                                         tinggal perlengkapannya. --}}
                                    <div class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                                        <b>Uang pangkal sudah ditagihkan.</b> Untuk mengubah nominalnya,
                                        pakai <i>Koreksi Nominal</i> di bawah — jangan terbitkan yang kedua.
                                    </div>
                                @else
                                    <label class="block text-xs text-gray-600">Nominal Normal <span class="text-red-500">*</span>
                                        <x-input-rupiah name="nominal" required placeholder="mis. 20.000.000"
                                                        :value="old('nominal', $nominalDefaultUangPangkal)" class="mt-0.5" />
                                        {{-- Asal angkanya selalu disebut: petugas harus bisa tahu sel
                                             tarif mana yang terpakai tanpa menebak. --}}
                                        <span class="mt-0.5 block text-[11px] {{ $nominalDefaultUangPangkal !== null ? 'text-gray-400' : 'text-red-600' }}">
                                            <x-asal-tarif :bagian="$asalTarifUangPangkal['bagian']" :teks="$asalTarifUangPangkal['label']" />
                                            @if ($nominalDefaultUangPangkal !== null) — boleh diubah bila calon ini berbeda. @endif
                                        </span>
                                    </label>
                                @endif
                                {{-- Perlengkapan TIDAK dipotong potongan gelombang: terbit utuh
                                     sebagai tagihan tersendiri, dengan jadwal termin sendiri. --}}
                                @if ($bisaTagih['perlengkapan'])
                                    <label class="block text-xs text-gray-600">Biaya Perlengkapan
                                        <x-input-rupiah name="nominal_perlengkapan" placeholder="kosongkan bila tidak dipungut"
                                                        :value="old('nominal_perlengkapan', $nominalDefaultPerlengkapan)" class="mt-0.5" />
                                        <span class="mt-0.5 block text-[11px] text-gray-400">
                                            Terbit sebagai tagihan terpisah dan <b>tidak dipotong</b> potongan gelombang.
                                            <x-asal-tarif :bagian="$asalTarifPerlengkapan['bagian']" :teks="$asalTarifPerlengkapan['label']" />
                                        </span>
                                    </label>
                                @endif
                                <label class="block text-xs text-gray-600">Jatuh Tempo
                                    <input type="date" name="jatuh_tempo" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                                <label class="block text-xs text-gray-600">Keterangan
                                    <input type="text" name="keterangan" placeholder="opsional" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                            </div>

                            {{-- SPP bulanan: DITAMPILKAN di sini, tidak diterbitkan. SPP baru
                                 ditagih setelah daftar ulang, tetapi inilah saat wali duduk
                                 membicarakan seluruh biayanya — angkanya harus terlihat.

                                 Isiannya baru ADA di DOM setelah "ubah" diklik (template x-if,
                                 bukan x-show): tanpa itu `nominal_spp` tak ikut terkirim, dan
                                 santrinya tetap mengikuti tarif jenjang — ikut naik saat tarif
                                 naik. "Batalkan Perubahan" membuang isiannya lagi dari DOM,
                                 jadi yang sudah terlanjur diketik pun tak jadi terkirim. --}}
                            @if ($sppSantri)
                                {{-- Warnanya sengaja BEDA SENDIRI di halaman ini: indigo pekat di
                                     atas dasar putih, di tengah form yang serba amber. Petugas
                                     harus menyadari ada satu angka lagi yang perlu dipastikan
                                     sebelum menekan Terbitkan — blok bernada sama dengan
                                     sekitarnya terlewat begitu saja. --}}
                                <div class="rounded-lg border-2 border-indigo-500 border-l-8 bg-white px-3 py-2.5 text-xs text-indigo-950 shadow-sm" x-data="{ ubahSpp: false }">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-sm font-bold uppercase tracking-wide text-indigo-700">SPP Bulanan</span>
                                        @if ($sppSantri['nominal'] !== null)
                                            <b class="text-sm">@rp($sppSantri['nominal'])</b>
                                        @else
                                            <b class="text-sm text-red-600">belum bisa ditentukan</b>
                                        @endif
                                        @if ($sppSantri['khusus'])
                                            <span class="rounded-full bg-indigo-600 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-white">khusus</span>
                                        @endif
                                        @if ($bolehUbahSpp)
                                            <button type="button" x-show="!ubahSpp" @click="ubahSpp = true"
                                                    class="rounded-md border border-indigo-500 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-100">ubah</button>
                                            <button type="button" x-show="ubahSpp" x-cloak @click="ubahSpp = false"
                                                    class="rounded-md border border-gray-300 bg-white px-2 py-0.5 text-[11px] font-medium text-gray-600 hover:bg-gray-50">Batalkan Perubahan</button>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-[11px] font-medium text-indigo-700">
                                        <x-asal-tarif :bagian="$sppSantri['bagian']" :teks="$sppSantri['label']" />

                                        @if ($sppSantri['keterangan'])
                                            — {{ $sppSantri['keterangan'] }}
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-gray-500">
                                        Tagihan SPP <b>tidak</b> ikut terbit sekarang — SPP mulai ditagih setelah daftar ulang.
                                        Yang tersimpan hanya nominalnya, dan itu dipakai penerbitan periode berikutnya.
                                    </p>

                                    @if ($bolehUbahSpp)
                                        <template x-if="ubahSpp">
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                <input type="hidden" name="ubah_spp" value="1">
                                                <label class="block text-xs text-gray-600">Nominal SPP Khusus
                                                    <x-input-rupiah name="nominal_spp" :value="$sppSantri['nominal']"
                                                                    placeholder="kosongkan = ikut tarif jenjang" class="mt-0.5" />
                                                    <span class="mt-0.5 block text-[11px] text-gray-400">
                                                        <b>0</b> = beasiswa penuh (tagihan tetap terbit senilai nol).
                                                        <b>Kosongkan</b> = kembali mengikuti tarif jenjang.
                                                    </span>
                                                </label>
                                                <label class="block text-xs text-gray-600">Alasan
                                                    <input type="text" name="keterangan_spp" value="{{ $sppSantri['keterangan'] }}"
                                                           placeholder="mis. beasiswa 50%" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                                </label>
                                            </div>
                                        </template>
                                    @endif
                                </div>
                            @endif

                            <button class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-amber-700">Terbitkan Tagihan</button>
                        </form>
                    @endif

                    {{-- Koreksi nominal uang pangkal (salah input) — selama belum diakrualkan --}}
                    @if ($koreksiUangPangkal)
                        @php $k = $koreksiUangPangkal; @endphp
                        <div class="w-full rounded-lg border border-gray-200 bg-gray-50 p-3" x-data="{ buka: false }">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm text-gray-700">
                                    <span class="font-semibold">Uang pangkal tertagih:</span> @rp($k['tagihan']->nominal)
                                    <span class="text-xs text-gray-500">(normal @rp($k['nominal_normal'])@if ($k['potongan']) − potongan @rp($k['potongan']->potongan)@endif · terbayar @rp($k['terbayar']) · sisa @rp($k['tagihan']->sisa))</span>
                                </div>
                                <button type="button" @click="buka = !buka"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                    <span x-text="buka ? 'Tutup' : '✏️ Koreksi Nominal'"></span>
                                </button>
                            </div>

                            <form x-show="buka" x-cloak method="POST" action="{{ $act('koreksi-uang-pangkal') }}" class="mt-3 space-y-2 border-t border-gray-200 pt-3"
                                  data-confirm="Koreksi nominal uang pangkal? Sisa tagihan dihitung ulang.">
                                @csrf
                                <p class="text-xs text-gray-500">
                                    Untuk memperbaiki <b>salah input nominal</b>. Masukkan nominal <b>NORMAL</b> yang benar; tagihan dihitung ulang setelah potongan dan sisa disesuaikan dengan yang sudah dibayar.
                                    @if ($k['potongan'])
                                        Potongan gelombang <b>@rp($k['potongan']->potongan)</b> tetap berlaku.
                                    @endif
                                </p>
                                @if ($k['menunggu'] > 0)
                                    <p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Ada {{ $k['menunggu'] }} pembayaran menunggu verifikasi keuangan — koreksi akan ditolak sampai itu diselesaikan.
                                    </p>
                                @endif
                                @if ($k['rencana_aktif'])
                                    <p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Santri ini punya rencana angsuran aktif. Setelah nominal dikoreksi, jadwal itu <b>dinonaktifkan</b> dan terminnya harus disusun ulang di menu Angsuran Uang Pangkal.
                                    </p>
                                @endif
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <label class="block text-xs text-gray-600">Nominal Normal yang Benar <span class="text-red-500">*</span>
                                        <x-input-rupiah name="nominal" required :value="old('nominal', $k['nominal_normal'])" class="mt-0.5" />
                                    </label>
                                    <label class="block text-xs text-gray-600">Jatuh Tempo
                                        <input type="date" name="jatuh_tempo" value="{{ old('jatuh_tempo', optional($k['tagihan']->jatuh_tempo)->format('Y-m-d')) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    </label>
                                    <label class="block text-xs text-gray-600">Alasan Koreksi <span class="text-red-500">*</span>
                                        <input type="text" name="alasan" required placeholder="mis. salah ketik nol" value="{{ old('alasan') }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    </label>
                                </div>
                                <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Simpan Koreksi</button>
                            </form>
                        </div>
                    @endif

                    {{-- Koreksi nominal biaya perlengkapan — pagar sama, tanpa urusan potongan --}}
                    @if ($koreksiPerlengkapan)
                        @php $kp = $koreksiPerlengkapan; @endphp
                        <div class="w-full rounded-lg border border-gray-200 bg-gray-50 p-3" x-data="{ buka: false }">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm text-gray-700">
                                    <span class="font-semibold">Biaya perlengkapan tertagih:</span> @rp($kp['tagihan']->nominal)
                                    <span class="text-xs text-gray-500">(terbayar @rp($kp['terbayar']) · sisa @rp($kp['tagihan']->sisa))</span>
                                </div>
                                <button type="button" @click="buka = !buka"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                    <span x-text="buka ? 'Tutup' : '✏️ Koreksi Perlengkapan'"></span>
                                </button>
                            </div>

                            <form x-show="buka" x-cloak method="POST" action="{{ $act('koreksi-perlengkapan') }}" class="mt-3 space-y-2 border-t border-gray-200 pt-3"
                                  data-confirm="Koreksi nominal biaya perlengkapan? Sisa tagihan dihitung ulang.">
                                @csrf
                                <p class="text-xs text-gray-500">
                                    Untuk memperbaiki <b>salah input nominal</b>. Biaya perlengkapan tidak dipotong potongan gelombang, jadi nominal yang diketik langsung menjadi nominal tagihannya.
                                </p>
                                @if ($kp['menunggu'] > 0)
                                    <p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Ada {{ $kp['menunggu'] }} pembayaran menunggu verifikasi keuangan — koreksi akan ditolak sampai itu diselesaikan.
                                    </p>
                                @endif
                                @if ($kp['rencana_aktif'])
                                    <p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        Ada rencana angsuran perlengkapan yang aktif. Setelah nominal dikoreksi, jadwal itu <b>dinonaktifkan</b> dan terminnya harus disusun ulang.
                                    </p>
                                @endif
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <label class="block text-xs text-gray-600">Nominal yang Benar <span class="text-red-500">*</span>
                                        <x-input-rupiah name="nominal" required :value="old('nominal', $kp['tagihan']->nominal)" class="mt-0.5" />
                                    </label>
                                    <label class="block text-xs text-gray-600">Jatuh Tempo
                                        <input type="date" name="jatuh_tempo" value="{{ old('jatuh_tempo', optional($kp['tagihan']->jatuh_tempo)->format('Y-m-d')) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    </label>
                                    <label class="block text-xs text-gray-600">Alasan Koreksi <span class="text-red-500">*</span>
                                        <input type="text" name="alasan" required placeholder="mis. salah ketik nol" value="{{ old('alasan') }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    </label>
                                </div>
                                <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Simpan Koreksi</button>
                            </form>
                        </div>
                    @endif
                    @if ($santri->status === 'lolos_kesehatan')
                        {{-- Labelnya sengaja TIDAK menyebut "daftar ulang": "daftar ulang"
                             juga nama sebuah BIAYA tahunan, dan satu nama untuk dua hal
                             berbeda membuat petugas ragu tombol ini menerbitkan tagihan
                             atau tidak.

                             Tombol ini MENANDAI SIAP, bukan mengaktifkan. Jurnal akrual
                             uang pangkal & perlengkapan baru terbit saat aktivasinya
                             menyala — lihat SantriService::siapkanAktivasi(). --}}
                        <form method="POST" action="{{ $act('siap-aktivasi') }}"
                              data-confirm="Tandai siap diaktifkan? Santri BELUM menjadi aktif sekarang — aktivasinya (beserta jurnal akrualnya) berlaku saat T.A {{ $santri->tahun_ajaran }} dimulai.">
                            @csrf
                            <button class="rounded-lg bg-brand-dark px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Siap di Aktifkan</button>
                        </form>
                    @endif

                    @if ($santri->status === 'siap_aktivasi')
                        {{-- Tombol manual: santri yang masuk di TENGAH tahun ajaran harus
                             bisa aktif hari itu juga; menunggu 1 Juli berikutnya akan
                             menahannya setahun penuh. --}}
                        <form method="POST" action="{{ $act('aktifkan-sekarang') }}"
                              data-confirm="Aktifkan sekarang juga, tanpa menunggu T.A {{ $santri->tahun_ajaran }} dimulai? Jurnal akrual uang pangkal & perlengkapan akan terbit.">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700">Aktifkan Sekarang</button>
                        </form>
                    @endif

                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">Mengundurkan Diri</button>
                        <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ $act('undur-diri') }}"
                              class="absolute left-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                            @csrf
                            <input type="text" name="alasan" required placeholder="Alasan" class="w-full rounded border-gray-300 text-sm">
                            <button class="w-full rounded bg-gray-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-800">Konfirmasi</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- Santri AKTIF: kenaikan jenjang LEWAT PROSES PPSB.
             Yang bergerak adalah status PENDAFTARAN, bukan status santri —
             ia tetap aktif & tetap ditagih SPP sampai kenaikannya dieksekusi. --}}
        @if ($lanjutan && \App\Support\Akses::boleh('santri', 'ubah'))
            @php $p = $lanjutan['berjalan']; @endphp
            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-800">Jenjang Lanjutan</h3>

                @if (! $p)
                    {{-- Belum ada siklus: tawarkan membukanya. --}}
                    @if ($lanjutan['sasaran'] && ! $lanjutan['sasaran']['alasan'])
                        <p class="mt-1 text-xs text-gray-500">
                            Naik ke <b>{{ $lanjutan['sasaran']['nama_jenjang'] ?: $lanjutan['sasaran']['kode_jenjang'] }}</b>,
                            jalur <b>{{ $lanjutan['sasaran']['nama_jalur'] ?? $lanjutan['sasaran']['kode_jalur'] }}</b>. Prosesnya melewati PPSB (seleksi &amp; med check);
                            <b>tahap berkas dilewati</b> karena dokumennya sudah ada.
                        </p>
                        <form method="POST" action="{{ route('pendaftaran_lanjutan.store', $santri->id) }}"
                              class="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3">
                            @csrf
                            <label class="block text-xs text-gray-600">Tahun Ajaran Tujuan <span class="text-red-500">*</span>
                                <select name="tahun_ajaran" required class="mt-0.5 w-40 rounded border-gray-300 text-sm">
                                    @foreach ($lanjutan['opsiTa'] as $kode => $label)
                                        <option value="{{ $kode }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block flex-1 text-xs text-gray-600">Catatan
                                <input type="text" name="catatan" placeholder="opsional" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                            </label>
                            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                                Daftarkan ke Jenjang Lanjutan
                            </button>
                        </form>
                    @else
                        <p class="mt-1 text-xs text-amber-700">
                            {{ $lanjutan['sasaran']['alasan'] ?? 'Jenjang ini tidak punya jenjang lanjutan — santrinya menjadi alumni, bukan naik.' }}
                        </p>
                    @endif
                @else
                    @php $act = fn ($a) => route('pendaftaran_lanjutan.aksi', ['id' => $santri->id, 'pendaftaran' => $p->id, 'aksi' => $a]); @endphp
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $p->nomor }} &middot; ke <b>{{ $p->jenjang?->nama ?? $p->kode_jenjang }}</b> jalur <b>{{ $p->jalur?->nama ?? $p->kode_jalur }}</b> &middot;
                        T.A <b>{{ $p->tahun_ajaran }}</b> &middot; tahap
                        <span class="rounded bg-brand/10 px-1.5 py-0.5 font-semibold text-brand">{{ $p->labelStatus() }}</span>
                    </p>

                    <div class="mt-3 flex flex-wrap items-start gap-2 border-t border-gray-100 pt-3">
                        @if ($p->status === 'calon')
                            <p class="text-xs text-amber-700">
                                Menunggu biaya registrasi jenjang lanjutan dilunasi &amp; diverifikasi keuangan.
                                Tahapnya maju sendiri begitu tagihan itu lunas.
                            </p>
                        @elseif ($p->status === 'terbayar')
                            <form method="POST" action="{{ $act('seleksi') }}" class="w-full space-y-2">@csrf
                                <div class="grid gap-2 sm:grid-cols-4">
                                    <label class="block text-xs text-gray-600">Nilai Baca
                                        <input type="number" step="0.01" name="nilai_baca" class="mt-0.5 w-full rounded border-gray-300 text-sm"></label>
                                    <label class="block text-xs text-gray-600">Nilai Akademik
                                        <input type="number" step="0.01" name="nilai_akademik" class="mt-0.5 w-full rounded border-gray-300 text-sm"></label>
                                    <label class="block text-xs text-gray-600 sm:col-span-2">Catatan Wawancara
                                        <input type="text" name="wawancara_santri" class="mt-0.5 w-full rounded border-gray-300 text-sm"></label>
                                </div>
                                <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Seleksi</button>
                            </form>
                        @elseif ($p->status === 'diseleksi')
                            <form method="POST" action="{{ $act('pengumuman') }}">@csrf
                                <input type="hidden" name="lulus" value="1">
                                <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Diterima</button>
                            </form>
                            <form method="POST" action="{{ $act('pengumuman') }}">@csrf
                                <input type="hidden" name="lulus" value="0">
                                <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Tidak Lulus</button>
                            </form>
                        @elseif ($p->status === 'diterima')
                            <form method="POST" action="{{ $act('medcheck') }}">@csrf
                                <input type="hidden" name="lolos" value="1">
                                <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Med Check: Lolos</button>
                            </form>
                            <form method="POST" action="{{ $act('medcheck') }}">@csrf
                                <input type="hidden" name="lolos" value="0">
                                <button class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Med Check: Gagal</button>
                            </form>
                        @elseif ($p->status === 'lolos_kesehatan')
                            {{-- LANGKAH TERAKHIR: barulah data santri berubah. --}}
                            <form method="POST" action="{{ $act('naik') }}" class="w-full space-y-2"
                                  data-confirm="Eksekusi kenaikan? Jenjang, tingkat, & jalur santri berubah, dan uang pangkal + perlengkapan ditagihkan.">
                                @csrf
                                <p class="text-xs text-gray-500">
                                    Sekali dieksekusi: jenjang, tingkat, jalur, &amp; tahun ajaran berjalan santri berubah,
                                    riwayat tingkatnya ditulis, lalu uang pangkal &amp; perlengkapan ditagihkan dengan tarif
                                    <b>{{ $p->jenjang?->nama ?? $p->kode_jenjang }} T.A {{ $p->tahun_ajaran }}</b>.
                                </p>
                                <div class="grid gap-2 sm:grid-cols-4">
                                    <label class="block text-xs text-gray-600">Tingkat Baru <span class="text-red-500">*</span>
                                        <select name="tingkat" required class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                            @foreach ($lanjutan['opsiTingkat'] as $t => $labelT)
                                                <option value="{{ $t }}" @selected($t === 1)>{{ $labelT }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @php $tu = $lanjutan['tarif']['uang_pangkal'] ?? null; $tp = $lanjutan['tarif']['perlengkapan'] ?? null; @endphp
                                    @if (($tu['status'] ?? null) !== 'bebas')
                                        <label class="block text-xs text-gray-600">Uang Pangkal
                                            <x-input-rupiah name="nominal_uang_pangkal" :value="$tu['nominal'] ?? ''" class="mt-0.5" />
                                        </label>
                                    @endif
                                    <label class="block text-xs text-gray-600">Perlengkapan
                                        <x-input-rupiah name="nominal_perlengkapan" :value="($tp['status'] ?? null) === 'ada' ? $tp['nominal'] : ''"
                                                        placeholder="kosongkan bila tak dipungut" class="mt-0.5" />
                                    </label>
                                    <label class="block text-xs text-gray-600">Jatuh Tempo
                                        <input type="date" name="jatuh_tempo" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    </label>
                                </div>
                                @foreach (['uang_pangkal' => $tu, 'perlengkapan' => $tp] as $namaK => $t)
                                    @if ($t)
                                        <p class="text-[11px] {{ $t['status'] === 'kosong' ? 'text-red-600' : 'text-gray-400' }}">
                                            {{ ucfirst(str_replace('_', ' ', $namaK)) }}: {{ $t['label'] }}
                                        </p>
                                    @endif
                                @endforeach
                                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                                    Eksekusi Kenaikan
                                </button>
                            </form>
                        @endif

                        <form method="POST" action="{{ $act('batal') }}" class="ml-auto flex items-end gap-2"
                              data-confirm="Batalkan pendaftaran lanjutan ini?">
                            @csrf
                            <input type="text" name="alasan" required placeholder="alasan pembatalan"
                                   class="w-56 rounded border-gray-300 text-xs">
                            <button class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs hover:bg-gray-50">Batalkan</button>
                        </form>
                    </div>
                @endif

                @if ($lanjutan['riwayat']->isNotEmpty())
                    <div class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">
                        <div class="mb-1 font-medium text-gray-600">Riwayat pendaftaran lanjutan</div>
                        @foreach ($lanjutan['riwayat'] as $r)
                            <div>{{ $r->nomor }} &middot; {{ $r->jenjang?->nama ?? $r->kode_jenjang }} T.A {{ $r->tahun_ajaran }} &middot; {{ $r->labelStatus() }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Santri AKTIF: pengunduran diri (status → Keluar) --}}
        @if ($keluarAktif && \App\Support\Akses::boleh('santri', 'ubah'))
            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" x-data="{ buka: false }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">Pengunduran Diri Santri Aktif</h3>
                        <p class="text-xs text-gray-500">Status menjadi <b>Keluar</b>. Sisa kewajiban uang pangkal dihapuskan dan akrualnya dibalik.</p>
                    </div>
                    <button type="button" @click="buka = !buka"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">
                        <span x-text="buka ? 'Tutup' : 'Mengundurkan Diri'"></span>
                    </button>
                </div>

                <form x-show="buka" x-cloak method="POST" action="{{ $act('undur-diri') }}" class="mt-3 space-y-2 border-t border-gray-100 pt-3"
                      data-confirm="Keluarkan santri ini? Sisa uang pangkal dihapuskan dan jurnal akrualnya dibalik.">
                    @csrf
                    @if ((float) $keluarAktif['sisa'] > 0)
                        <div class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Sisa uang pangkal <b>@rp($keluarAktif['sisa'])</b> akan dihapuskan.
                            @if ($keluarAktif['akrual'])
                                Jurnal pembalik terbit sebesar sisa itu (Debit Pendapatan, Kredit Piutang) — pembayaran yang sudah diterima tetap diakui.
                            @endif
                        </div>
                    @else
                        <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                            Tidak ada sisa uang pangkal — tidak ada jurnal pembalik yang perlu diterbitkan.
                        </div>
                    @endif
                    @if ($keluarAktif['menunggu'] > 0)
                        <p class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                            Ada {{ $keluarAktif['menunggu'] }} pembayaran uang pangkal menunggu verifikasi keuangan — pengunduran diri akan ditolak sampai itu diselesaikan.
                        </p>
                    @endif
                    <p class="text-xs text-gray-400">Tagihan lain (SPP, tagihan kegiatan, dll.) tidak ikut dibatalkan.</p>
                    <label class="block text-xs text-gray-600">Alasan <span class="text-red-500">*</span>
                        <input type="text" name="alasan" required placeholder="mis. pindah domisili" value="{{ old('alasan') }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                    </label>
                    <button class="rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Konfirmasi Pengunduran Diri</button>
                </form>
            </div>
        @endif
    </div>
@endsection
