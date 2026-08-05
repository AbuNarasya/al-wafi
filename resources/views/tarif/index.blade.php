@extends('layouts.app')

@section('title', 'Tarif')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Besaran biaya per <b>tahun ajaran &times; jenjang &times; jalur</b>. Satu layar untuk satu jenjang.
    </p>
    <p class="mb-4 text-sm text-gray-500">
        Akun &amp; unit bisnisnya tidak diatur di sini, melainkan di
        <a href="{{ route('jenis_biaya.index') }}" class="font-medium text-brand hover:underline">Setting Awal &rarr; Jenis Biaya</a>.
    </p>

    {{-- Pemilih T.A & jenjang: GET biasa, tanpa Alpine — halaman ini dimuat ulang
         penuh tiap pindah jenjang, jadi tak ada gunanya menyimpan keadaan di sisi
         peramban. --}}
    <form method="GET" action="{{ route('tarif.index') }}" class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Ajaran</label>
            <select name="ta" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                @foreach ($opsiTa as $kode => $label)
                    <option value="{{ $kode }}" @selected($kode === $ta)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-gray-600">Jenjang</label>
            <select name="jenjang" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                @foreach ($opsiJenjang as $kode => $label)
                    <option value="{{ $kode }}" @selected($kode === $jenjang)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <noscript><button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
    </form>

    @if (! $grid)
        <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
            Tahun ajaran atau jenjang belum ada. Isi dulu di Setting Awal.
        </div>
    @else
        <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-600">
            <b>Tiga keadaan tiap sel</b> &mdash; bedanya penting:
            <span class="ml-1 rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">angka</span> tarif berlaku &middot;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">Bebas</span> sengaja tidak dipungut, tagihannya tidak terbit &middot;
            <span class="rounded bg-white px-1.5 py-0.5 font-medium text-gray-800">dikosongkan</span> belum diisi &mdash; penagihan akan berhenti dan meminta sel ini dilengkapi.
            <br>
            Tiap jalur pendaftaran disebut <b>sendiri-sendiri</b> — tidak ada baris "semua jalur", karena jalur selalu dipilih saat registrasi.
            Jalur yang selnya dikosongkan <b>tidak</b> ikut jalur lain: penagihannya berhenti sampai sel itu diisi.
        </div>

        <form method="POST" action="{{ route('tarif.simpan') }}">
            @csrf @method('PUT')
            <input type="hidden" name="tahun_ajaran" value="{{ $ta }}">
            <input type="hidden" name="kode_jenjang" value="{{ $jenjang }}">

            {{-- Biaya santri yang SUDAH bersekolah: satu angka per jenjang, jalur
                 tak berperan lagi. Sengaja di luar matriks jalur — dulu keduanya
                 jadi kolom grid dan menyisakan satu sel mati di tiap baris jalur. --}}
            <div class="mb-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-1 text-sm font-semibold text-gray-700">Biaya santri aktif &mdash; satu angka untuk seluruh jalur</div>
                <p class="mb-3 text-xs text-gray-500">
                    Jalur pendaftaran hanya bermakna saat santri masuk, jadi tarif ini tidak dibedakan per jalur.
                    Kekhususan per santri ditangani nominal khusus (SPP) atau angka yang bisa ditimpa saat menerbitkan (daftar ulang).
                </p>
                <div class="flex flex-wrap items-start gap-8">
                    {{-- SPP: satu angka per jenjang. --}}
                    @php $selSpp = $grid['umum']['spp']; @endphp
                    <div x-data="{ bebas: {{ $selSpp['bebas'] ? 'true' : 'false' }} }">
                        <label class="mb-1 block text-xs font-medium text-gray-600">
                            {{ $perilakuUmum['spp'] }} <span class="text-gray-400">· per bulan</span>
                        </label>
                        <x-tarif-sel name="umum[spp]" :sel="$selSpp" />
                    </div>

                    {{-- Daftar ulang: SATU ANGKA PER KENAIKAN TINGKAT. Selnya disimpan
                         pada tingkat TUJUAN (2 … terakhir) karena penagihannya menyusul
                         SESUDAH kenaikan dieksekusi — saat itu tingkat santri sudah
                         yang baru. Tingkat 1 tak punya sel: ia bukan hasil kenaikan. --}}
                    <div>
                        <div class="mb-1 text-xs font-medium text-gray-600">
                            {{ $perilakuUmum['daftar_ulang'] }}
                            <span class="text-gray-400">· ditagih setelah NAIK tingkat, berbeda tiap kenaikan</span>
                        </div>
                        @if ($grid['tingkat_kenaikan'] === [])
                            <p class="text-xs text-amber-700">
                                Jumlah tingkat jenjang ini belum diisi (atau hanya satu tingkat), jadi tak ada kenaikan
                                tingkat yang bisa ditarifkan. Lengkapi dulu di
                                <a href="{{ route('jenjang.index') }}" class="font-medium underline">Setting Awal &rarr; Jenjang</a>.
                            </p>
                        @else
                            <p class="mb-2 max-w-2xl text-[11px] text-gray-500">
                                Tidak ditagih daftar ulang: santri di <b>tingkat 1</b> (belum pernah naik) dan santri pada
                                <b>tahun masuknya</b> &mdash; termasuk pindahan dari luar yang langsung masuk ke tingkat 2 atau lebih.
                                Mereka membayar registrasi, uang pangkal, &amp; perlengkapan.
                            </p>
                            <div class="flex flex-wrap gap-4">
                                @foreach ($grid['tingkat_kenaikan'] as $t)
                                    @php $sel = $grid['umum']['daftar_ulang'][$t]; @endphp
                                    <div x-data="{ bebas: {{ $sel['bebas'] ? 'true' : 'false' }} }">
                                        <label class="mb-1 block text-[11px] font-medium text-gray-600">
                                            Tingkat {{ $t - 1 }} &rarr; {{ $t }}
                                        </label>
                                        <x-tarif-sel :name="'umum[daftar_ulang]['.$t.']'" :sel="$sel" lebar="w-36" />
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mb-1 text-sm font-semibold text-gray-700">Biaya masuk &mdash; dibedakan per jalur pendaftaran</div>
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Jalur</th>
                            @foreach ($perilakuJalur as $kode => $label)
                                <th class="px-4 py-3 text-right">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($grid['jalur'] as $baris)
                            @php $kunci = $baris['kode'] ?? '-'; @endphp
                            <tr class="{{ $baris['kode'] === null ? 'bg-amber-50/40' : '' }}">
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium text-gray-900">{{ $baris['nama'] }}</div>
                                    @if ($baris['bebas_up'])
                                        <div class="mt-0.5 text-[11px] text-gray-500">jalur bertanda bebas uang pangkal</div>
                                    @endif
                                </td>
                                @foreach ($perilakuJalur as $p => $label)
                                    @php $sel = $baris['sel'][$p]; @endphp
                                    {{-- Isi sel dirata-KANAN sama seperti judul kolomnya.
                                         Sebelumnya isian mengambang di kiri sel sementara
                                         judulnya text-right, jadi keduanya tak sejajar. --}}
                                    <td class="px-4 py-3 align-top" x-data="{ bebas: {{ $sel['bebas'] ? 'true' : 'false' }} }">
                                        <div class="flex flex-col items-end">
                                            <x-tarif-sel :name="'sel['.$kunci.']['.$p.']'" :sel="$sel" lebar="w-36" />
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (\App\Support\Akses::boleh('tarif', 'ubah'))
                <div class="mt-4 flex justify-end">
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
                        Simpan Tarif {{ $jenjang ?: 'tanpa jenjang' }} &mdash; T.A {{ $ta }}
                    </button>
                </div>
            @endif
        </form>

        @if (\App\Support\Akses::boleh('tarif', 'ubah'))
            {{-- Tombolnya sengaja DI LUAR form grid: form bersarang tidak sah di
                 HTML, dan satu tombol per baris jalur akan memaksa itu. Tiap
                 tombol mengirim kode jalurnya lewat atribut value. --}}
            <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                {{-- Nama jenjang, bukan kodenya: $opsiJenjang berisi [kode => nama]. --}}
                <div class="mb-1 text-sm font-semibold text-gray-700">Jalur yang berlaku di {{ $jenjang ? ($opsiJenjang[$jenjang] ?? $jenjang) : 'jenjang ini' }}</div>
                <p class="mb-3 text-xs text-gray-500">
                    Jalur yang tak pernah ada di jenjang ini sebaiknya dinonaktifkan supaya barisnya tidak
                    ikut memenuhi grid &mdash; mis. SDTQ tidak punya jalur OSS maupun jalur lanjutan.
                    Berlaku untuk <b>T.A {{ $ta }}</b> saja; tombol Salin akan membawanya ke tahun berikutnya.
                </p>

                <form method="POST" action="{{ route('tarif.jalur') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="tahun_ajaran" value="{{ $ta }}">
                    <input type="hidden" name="kode_jenjang" value="{{ $jenjang }}">
                    <input type="hidden" name="aksi" value="nonaktifkan">
                    @foreach ($grid['jalur'] as $baris)
                        @continue(! $baris['kode'])
                        <button name="kode_jalur" value="{{ $baris['kode'] }}"
                                class="rounded-full border border-gray-300 px-3 py-1 text-xs hover:border-red-300 hover:bg-red-50 hover:text-red-700">
                            {{ $baris['nama'] }} <span class="text-gray-400">&times;</span>
                        </button>
                    @endforeach
                </form>

                @if ($grid['nonaktif'] !== [])
                    <div class="mt-4 border-t border-gray-100 pt-3">
                        <div class="mb-2 text-xs font-medium text-gray-600">Dinonaktifkan di jenjang &amp; T.A ini</div>
                        <form method="POST" action="{{ route('tarif.jalur') }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="hidden" name="tahun_ajaran" value="{{ $ta }}">
                            <input type="hidden" name="kode_jenjang" value="{{ $jenjang }}">
                            <input type="hidden" name="aksi" value="aktifkan">
                            @foreach ($grid['nonaktif'] as $n)
                                <button name="kode_jalur" value="{{ $n['kode'] }}"
                                        class="rounded-full border border-dashed border-gray-300 px-3 py-1 text-xs text-gray-500 line-through hover:border-brand hover:text-brand hover:no-underline">
                                    {{ \App\Support\Referensi::label($n['kode'], $n['nama']) }}
                                </button>
                            @endforeach
                        </form>
                        <p class="mt-2 text-[11px] text-gray-400">Klik untuk memberlakukannya kembali.</p>
                    </div>
                @endif
            </div>
        @endif

        @if (\App\Support\Akses::boleh('tarif', 'buat'))
            <form method="POST" action="{{ route('tarif.salin') }}"
                  class="mt-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                @csrf
                <div class="mb-2 text-sm font-semibold text-gray-700">Salin tarif dari tahun ajaran lain</div>
                <p class="mb-3 text-xs text-gray-500">
                    Sel yang di tahun tujuan <b>sudah terisi tidak ditimpa</b>, jadi penyalinan boleh diulang tanpa
                    menghapus penyesuaian yang sudah dikerjakan.
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Dari T.A</label>
                        <select name="sumber" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            @foreach ($opsiTa as $kode => $label)
                                <option value="{{ $kode }}" @selected($kode !== $ta && $loop->first)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600">Ke T.A</label>
                        <select name="tujuan" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                            @foreach ($opsiTa as $kode => $label)
                                <option value="{{ $kode }}" @selected($kode === $ta)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="kode_jenjang" value="{{ $jenjang }}">
                    <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                        <input type="hidden" name="semua_jenjang" value="0">
                        <input type="checkbox" name="semua_jenjang" value="1" checked
                               class="rounded border-gray-300 text-brand focus:ring-brand">
                        Semua jenjang (lepas centang untuk {{ $opsiJenjang[$jenjang] ?? $jenjang }} saja)
                    </label>
                    <button class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-50">Salin</button>
                </div>
            </form>
        @endif
    @endif
@endsection
