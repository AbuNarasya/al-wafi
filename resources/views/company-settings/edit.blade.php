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

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="topup_tunai_dompet_santri" value="0">
                <input type="checkbox" name="topup_tunai_dompet_santri" value="1"
                       @checked(old('topup_tunai_dompet_santri', $s->topup_tunai_dompet_santri ?? true))
                       class="rounded border-gray-300 text-brand focus:ring-brand">
                Izinkan setor tunai langsung ke Dompet Santri
            </label>

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

            <x-field name="keterangan" label="Keterangan" :value="$s->keterangan" textarea />

            <div class="flex justify-end border-t border-gray-100 pt-4">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        </form>
    </div>
@endsection
