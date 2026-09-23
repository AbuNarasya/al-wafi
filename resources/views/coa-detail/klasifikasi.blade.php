@extends('layouts.app')

@section('title', 'Klasifikasi Arus Kas')

@section('content')
    @php
        $kelompok = ['1' => 'Aset', '2' => 'Liabilitas', '3' => 'Ekuitas'];
        $pilihan = ['' => '— belum ditentukan —'] + collect(\App\Services\Reports\ReportsService::KLASIFIKASI_ARUS)
            ->except('belum')->all();
        $bolehUbah = \App\Support\Akses::boleh('coa-detail', 'ubah');
    @endphp

    <p class="mb-1 max-w-3xl text-sm text-gray-500">
        Menentukan kelompok tiap akun neraca pada <b>Laporan Arus Kas</b>. Akun yang dibiarkan kosong
        tetap muncul di laporan, tetapi terkumpul di kelompok <i>Belum Diklasifikasikan</i> —
        laporannya tetap benar, hanya rinciannya tumpul.
    </p>
    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Pendapatan &amp; Beban tidak ada di sini: keduanya selalu <i>Aktivitas Operasi</i>.
        Isian per akun beserta kolom lainnya ada di
        <a href="{{ route('coa.index') }}" class="font-medium text-brand hover:underline">Chart of Account</a>.
    </p>

    @if ($belum > 0)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
            <b>{{ $belum }} akun</b> belum diklasifikasikan.
            Petunjuk cepat: piutang, uang muka, dan seluruh hutang jangka pendek &rarr; <b>Operasi</b> ·
            aset tetap beserta akumulasi depresiasinya &rarr; <b>Investasi</b> ·
            pinjaman &amp; kewajiban jangka panjang &rarr; <b>Pendanaan</b>.
        </div>
    @endif

    <form method="POST" action="{{ route('coa.klasifikasi.simpan') }}" data-confirm="Simpan klasifikasi arus kas untuk akun yang diubah?">
        @csrf @method('PUT')

        @foreach ($kelompok as $akar => $namaKelompok)
            @php($daftar = $akun[$akar] ?? collect())
            @continue($daftar->isEmpty())

            <div class="mb-6 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3" colspan="3">{{ $namaKelompok }} — {{ $daftar->count() }} akun</th>
                        </tr>
                        <tr class="bg-white text-[11px]">
                            <th class="px-4 py-2 font-medium">Kode</th>
                            <th class="px-4 py-2 font-medium">Nama Akun</th>
                            <th class="px-4 py-2 font-medium" style="width:16rem">Klasifikasi Arus Kas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($daftar as $a)
                            @php($isKas = $kas->has($a->kode_coa))
                            <tr class="hover:bg-gray-50 {{ $isKas ? 'bg-gray-50/60' : '' }}">
                                <td class="whitespace-nowrap px-4 py-2 font-mono text-xs text-gray-500">{{ $a->kode_coa }}</td>
                                <td class="px-4 py-2">
                                    {{ $a->nama_coa }}
                                    @if ($isKas)
                                        {{-- Laporan Arus Kas menjelaskan perubahan saldo akun kas,
                                             jadi akun kas itu sendiri tak masuk kelompok mana pun —
                                             ia memang dikecualikan oleh laporannya. Ditandai, bukan
                                             disembunyikan: akun yang hilang dari daftar hanya akan
                                             dicari orang dan dikira rusak. --}}
                                        <span class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-medium text-gray-600">rekening kas — tak perlu diisi</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    {{-- Rekening kas DIKUNCI. Sisa nilai dari sebelum akun itu
                                         terdaftar sebagai rekening kas sengaja TIDAK ikut dikunci:
                                         mengunci yang terlanjur terisi membuatnya mustahil
                                         dikosongkan dari layar mana pun. --}}
                                    @php($kunci = $isKas && $a->klasifikasi_arus_kas === null)
                                    <select name="klasifikasi[{{ $a->kode_coa }}]" @disabled(! $bolehUbah || $kunci)
                                            @if ($kunci) title="Rekening kas tidak diklasifikasikan — Laporan Arus Kas justru menjelaskan perubahan saldonya." @endif
                                            class="w-full rounded-lg border border-gray-400 px-3 py-1.5 text-sm focus:border-brand focus:ring-1 focus:ring-brand disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 {{ $a->klasifikasi_arus_kas === null && ! $isKas ? 'border-amber-400 bg-amber-50/60' : '' }}">
                                        @foreach ($pilihan as $nilai => $label)
                                            <option value="{{ $nilai }}" @selected((string) $a->klasifikasi_arus_kas === (string) $nilai)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isKas && $a->klasifikasi_arus_kas !== null)
                                        <p class="mt-1 text-xs text-amber-700">
                                            Rekening kas seharusnya tak berklasifikasi. Pilih
                                            &quot;belum ditentukan&quot; lalu Simpan untuk mengosongkannya.
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        @if ($bolehUbah)
            {{-- Menempel di dasar layar: daftarnya puluhan baris, dan tombol simpan
                 yang hanya ada di ujung bawah membuat orang menggulung jauh hanya
                 untuk menyimpan satu perubahan di baris pertama. --}}
            <div class="sticky bottom-0 -mx-4 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6">
                <div class="flex items-center justify-end gap-3">
                    <span class="text-xs text-gray-500">Perubahan baru tersimpan setelah tombol ini ditekan.</span>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan Klasifikasi</button>
                </div>
            </div>
        @endif
    </form>
@endsection
