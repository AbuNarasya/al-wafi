@extends('layouts.app')

@section('title', 'Pengaturan Perusahaan')

@section('content')
    <div class="mx-auto max-w-2xl">
        <form method="POST" action="{{ route('company_settings.update') }}"
              class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            @csrf @method('PUT')

            <x-field name="nama_perusahaan" label="Nama Perusahaan" :value="$s->nama_perusahaan" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="jenis_perusahaan" label="Jenis Perusahaan" :value="$s->jenis_perusahaan ?? 'Yayasan'" required />
                <x-field name="mata_uang" label="Mata Uang" :value="$s->mata_uang ?? 'IDR'" required />
            </div>

            <x-field name="alamat" label="Alamat" :value="$s->alamat" textarea />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="telepon" label="Telepon" :value="$s->telepon" />
                <x-field name="email" label="Email" type="email" :value="$s->email" />
            </div>

            <x-field name="npwp" label="NPWP" :value="$s->npwp" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="periode_awal_pembukuan" label="Periode Awal Pembukuan" type="date"
                         :value="optional($s->periode_awal_pembukuan)->format('Y-m-d')" required
                         hint="Dasar Laba/Rugi Tahun Berjalan di Neraca." />
                <x-field name="bulan_awal_anggaran" label="Bulan Awal Anggaran" :value="$s->bulan_awal_anggaran ?? 1"
                         :options="[1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember']" />
            </div>

            {{-- Isian dompet & jajan santri hanya di instans Al Wafi (config/instans.php `pengaturan_santri`). --}}
            @php $isianSantri = \App\Support\Instans::fitur('pengaturan_santri'); @endphp
            @if ($isianSantri)
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="topup_tunai_dompet_santri" value="0">
                <input type="checkbox" name="topup_tunai_dompet_santri" value="1"
                       @checked(old('topup_tunai_dompet_santri', $s->topup_tunai_dompet_santri ?? true))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                Izinkan setor tunai langsung ke Dompet Santri
            </label>
            @endif

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <x-field name="kode_unit_neraca" label="Unit Penampung Neraca"
                         :value="$s->kode_unit_neraca"
                         :options="['' => '— Tidak dipusatkan —'] + $opsiUnit" />
                <p class="mt-2 text-xs text-gray-500">
                    Seluruh baris <b>hutang</b> dan <b>kas/bank</b> dibukukan ke unit ini, modul mana pun asalnya.
                    Baris beban &amp; aset tetap mengikuti unit yang mengajukan, sehingga laba rugi per unit tidak berubah.
                    Neraca memang hanya dibaca di tingkat yayasan.
                    <br>
                    Dikosongkan = tiap baris mengikuti unit dokumennya, seperti sebelum pengaturan ini ada.
                </p>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <x-field name="kode_coa_grni" label="Akun Barang Diterima Belum Ditagih"
                         :value="$s->kode_coa_grni"
                         :options="['' => '— belum ditetapkan —'] + $opsiGrni" />
                <p class="mt-2 text-xs text-gray-500">
                    Dikredit saat <b>Penerimaan Barang</b> menambah stok, lalu didebet saat invoice vendor menagihnya.
                    Saldonya = nilai barang yang sudah di gudang tetapi tagihannya belum datang. Wajib diisi sebelum penerimaan pertama.
                </p>
            </div>

            @if ($isianSantri)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">Batas belanja kartu harian (bawaan)</label>
                <div class="max-w-xs"><x-input-rupiah name="batas_jajan_harian" :value="$s->batas_jajan_harian ?? 20000" /></div>
                <p class="mt-2 text-xs text-gray-500">
                    Untuk santri yang batasnya belum diatur wali. Belanja kartu sampai batas ini cukup tempel kartu;
                    di atasnya santri mengetik PIN belanja yang dipasang walinya.
                </p>
            </div>

            {{-- Hanya selama toko masih menumpang di ERP ini: sesudah Wafi Mart berdiri di instans sendiri
                 (WAFIMART_TERPISAH), grup menu WAFI MART disembunyikan dan isian ini tak dipakai lagi. --}}
            @unless (\App\Support\Instans::tokoTerpisah())
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <x-field name="kode_unit_wafimart" label="Unit Bisnis Wafi Mart"
                         :value="$s->kode_unit_wafimart"
                         :options="['' => '— belum ditetapkan —'] + $opsiUnit" />
                <p class="mt-2 text-xs text-gray-500">
                    Laporan keuangan di grup menu <b>WAFI MART</b> (Laba Rugi, Neraca Saldo, Arus Kas, Buku Besar) langsung
                    tersaring unit ini. Neraca Wafi Mart sendiri belum tersedia: kas &amp; hutang masih dipusatkan ke unit
                    penampung neraca — baru terpisah saat Wafi Mart berdiri di instans sendiri.
                </p>
            </div>
            @endunless
            @endif

            <x-field name="keterangan" label="Keterangan" :value="$s->keterangan" textarea />

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>

        {{-- Biaya Virtual Account Midtrans (24 Okt 2026) — lihat App\Services\Midtrans\TarifVa. --}}
        @if ($biayaVa)
            <div class="mt-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Biaya Virtual Account (Midtrans)</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Biaya layanan VA yang dipungut dari wali. Harus <b>sama</b> dengan tarif yang dipotong Midtrans (termasuk PPN),
                    supaya Rekening Midtrans di buku besar tetap cocok dengan saldo Midtrans. VA yang sudah terbit tidak berubah.
                </p>

                <div class="mt-4 rounded-lg bg-gray-50 px-4 py-3 text-sm">
                    Berlaku sekarang: <b>@rp($biayaVa['berlaku'])</b> per VA
                    @if ($biayaVa['riwayat']->isEmpty())
                        <span class="text-gray-500">— masih dari pengaturan server, belum pernah diubah di sini.</span>
                    @endif
                </div>

                @foreach ($biayaVa['terjadwal'] as $j)
                    <div class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                        <span>Terjadwal: <b>@rp($j->biaya)</b> mulai {{ \Illuminate\Support\Carbon::parse($j->berlaku_mulai)->translatedFormat('j F Y') }}</span>
                        <form method="POST" action="{{ route('company_settings.biaya_va.batal', $j->id) }}" data-confirm="Batalkan tarif terjadwal ini?">
                            @csrf @method('DELETE')
                            <button class="text-xs font-semibold text-amber-800 underline">Batalkan</button>
                        </form>
                    </div>
                @endforeach

                <form method="POST" action="{{ route('company_settings.biaya_va') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Biaya VA baru (termasuk PPN)</label>
                        <x-input-rupiah name="biaya_va" :value="old('biaya_va')" required placeholder="mis. 4440" />
                    </div>
                    <x-field name="berlaku_mulai" label="Berlaku mulai" type="date" :value="old('berlaku_mulai', now()->toDateString())" required />
                    <div class="sm:col-span-2">
                        <x-field name="catatan_va" label="Catatan (mis. dasar perubahan dari Midtrans)" :value="old('catatan_va')" />
                    </div>
                    <div class="flex justify-end sm:col-span-2">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan biaya VA</button>
                    </div>
                </form>

                @if ($biayaVa['riwayat']->isNotEmpty())
                    <table class="mt-6 w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr><th class="py-2">Berlaku mulai</th><th class="py-2 text-right">Biaya</th><th class="py-2">Dicatat</th><th class="py-2">Catatan</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($biayaVa['riwayat'] as $r)
                                <tr>
                                    <td class="py-2">{{ \Illuminate\Support\Carbon::parse($r->berlaku_mulai)->translatedFormat('j M Y H:i') }}</td>
                                    <td class="py-2 text-right tabular-nums">@rp($r->biaya)</td>
                                    <td class="py-2 text-gray-600">{{ $r->nama_pencatat ?? '—' }}</td>
                                    <td class="py-2 text-gray-600">{{ $r->catatan ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif
    </div>
@endsection
