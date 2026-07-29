@extends('layouts.app')

@php
    $final = in_array($santri->status, \App\Services\Ppsb\Tahap::STATUS_FINAL, true) || $santri->status === 'aktif';
    $act = fn ($a) => route('santri.aksi', ['id' => $santri->id, 'aksi' => $a]);
@endphp

@section('title', 'Santri — ' . $santri->nama)

@section('content')
    <div class="mx-auto max-w-4xl">
        <div class="mb-3 flex items-center justify-between">
            <a href="{{ route('santri.calon') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="flex items-center gap-2">
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
            <div><div class="text-xs text-gray-400">Jenjang / Jalur</div><div>{{ $santri->kode_jenjang ?? '—' }} · {{ ucfirst($santri->jalur) }}</div></div>
            <div><div class="text-xs text-gray-400">Tahun Ajaran</div><div>{{ $santri->tahun_ajaran ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Gelombang</div><div>{{ $santri->gelombang ?? 'Tanpa Gelombang' }}</div></div>
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
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="px-4 py-2">Jenis</th><th class="px-4 py-2">Keterangan</th><th class="px-4 py-2 text-right">Nominal</th><th class="px-4 py-2 text-right">Sisa</th><th class="px-4 py-2">Status</th></tr></thead>
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
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Belum ada tagihan.</td></tr>
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
                    @if (in_array($santri->status, ['diterima', 'lolos_kesehatan'], true) && ! $sudahAdaUangPangkal)
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
                                <label class="block text-xs text-gray-600">Nominal Normal <span class="text-red-500">*</span>
                                    <input type="number" step="0.01" min="0" name="nominal" required placeholder="mis. 20000000"
                                           value="{{ old('nominal', $nominalDefaultUangPangkal) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    @if ($nominalDefaultUangPangkal !== null)
                                        <span class="mt-0.5 block text-[11px] text-gray-400">Terisi dari master Jenis Biaya — boleh diubah bila calon ini berbeda.</span>
                                    @endif
                                </label>
                                {{-- Perlengkapan TIDAK dipotong potongan gelombang: terbit utuh
                                     sebagai tagihan tersendiri, dengan jadwal termin sendiri. --}}
                                <label class="block text-xs text-gray-600">Biaya Perlengkapan
                                    <input type="number" step="0.01" min="0" name="nominal_perlengkapan" placeholder="kosongkan bila tidak dipungut"
                                           value="{{ old('nominal_perlengkapan', $nominalDefaultPerlengkapan) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                    <span class="mt-0.5 block text-[11px] text-gray-400">
                                        Terbit sebagai tagihan terpisah dan <b>tidak dipotong</b> potongan gelombang.
                                        @if ($nominalDefaultPerlengkapan !== null) Terisi dari master Jenis Biaya. @endif
                                    </span>
                                </label>
                                <label class="block text-xs text-gray-600">Jatuh Tempo
                                    <input type="date" name="jatuh_tempo" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                                <label class="block text-xs text-gray-600">Keterangan
                                    <input type="text" name="keterangan" placeholder="opsional" class="mt-0.5 w-full rounded border-gray-300 text-sm">
                                </label>
                            </div>
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
                                        <input type="number" step="0.01" min="0" name="nominal" required value="{{ old('nominal', $k['nominal_normal']) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
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
                                        <input type="number" step="0.01" min="0" name="nominal" required value="{{ old('nominal', $kp['tagihan']->nominal) }}" class="mt-0.5 w-full rounded border-gray-300 text-sm">
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
                    @if (in_array($santri->status, ['diterima', 'lolos_kesehatan'], true))
                        <form method="POST" action="{{ $act('daftar-ulang') }}">@csrf<button class="rounded-lg bg-brand-dark px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Daftar Ulang → Aktif</button></form>
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
