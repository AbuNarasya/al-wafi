@extends('layouts.app')

@section('title', 'Daftarkan Calon Santri')

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('santri.calon') }}" class="mb-3 inline-block text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>

        @if (empty($waliOptions))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                Belum ada wali aktif. Tambahkan <a href="{{ route('wali.create') }}" class="underline">Wali</a> terlebih dahulu.
            </div>
        @endif

        @if (empty($taOptions))
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                Belum ada tahun ajaran aktif. Tambahkan <a href="{{ route('tahun_ajaran.create') }}" class="underline">Tahun Ajaran</a> terlebih dahulu — pendaftaran membutuhkannya.
            </div>
        @endif

        <form method="POST" action="{{ route('santri.store') }}" class="space-y-4 rounded-xl border border-gray-200 bg-white p-6 shadow-sm"
              x-data="{
                  ta: @js(old('tahun_ajaran', $taDefault ?? '')),
                  jalur: @js(old('jalur', '')),
                  jalurMap: @js($jalurPerTa),
                  opsiJalur() { return this.jalurMap[this.ta] ?? [] },
              }"
              x-init="$watch('ta', () => { if (!opsiJalur().some(o => o.v === jalur)) jalur = opsiJalur()[0]?.v ?? '' })">
            @csrf

            <x-field name="id_wali" label="Wali / Keluarga" :value="old('id_wali')" :options="['' => '— pilih wali —'] + $waliOptions" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="nama" label="Nama Calon Santri" :value="old('nama', $santri->nama)" required />
                <x-field name="jenis_kelamin" label="Jenis Kelamin" :value="old('jenis_kelamin', $santri->jenis_kelamin)" :options="['L' => 'Laki-laki', 'P' => 'Perempuan']" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tempat_lahir" label="Tempat Lahir" :value="old('tempat_lahir')" />
                <x-field name="tanggal_lahir" label="Tanggal Lahir" type="date" :value="old('tanggal_lahir')" />
                <x-field name="nisn" label="NISN" :value="old('nisn')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="kode_jenjang" label="Jenjang" :value="old('kode_jenjang')"
                         :options="['' => '— pilih jenjang —'] + \App\Support\Referensi::jenjang()" />
                <x-field name="asal_sekolah" label="Asal Sekolah" :value="old('asal_sekolah')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="alamat_sekolah_asal" label="Alamat Sekolah Asal" :value="old('alamat_sekolah_asal')" textarea />
                <x-field name="kepala_sekolah_asal" label="Nama Kepala Sekolah Asal" :value="old('kepala_sekolah_asal')" />
                <x-field name="cp_kepala_sekolah_asal" label="CP Kepala Sekolah Asal" :value="old('cp_kepala_sekolah_asal')" placeholder="mis. 0812xxxxxxx" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Tahun Ajaran <span class="text-red-500">*</span></label>
                    <select name="tahun_ajaran" x-model="ta" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih tahun ajaran —</option>
                        @foreach ($taOptions as $kodeTa)
                            <option value="{{ $kodeTa }}">{{ $kodeTa }}{{ $kodeTa === ($taDefault ?? null) ? ' (default)' : '' }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Menentukan jenis biaya, jalur, potongan gelombang, dan target yang berlaku.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Jalur <span class="text-red-500">*</span></label>
                    <select name="jalur" x-model="jalur" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <template x-if="opsiJalur().length === 0"><option value="">— belum ada jalur untuk T.A ini —</option></template>
                        <template x-for="o in opsiJalur()" :key="o.v"><option :value="o.v" x-text="o.l" :selected="o.v === jalur"></option></template>
                    </select>
                    <p class="mt-1 text-xs text-gray-400" x-show="ta && opsiJalur().length === 0" x-cloak>
                        Tambahkan jalur untuk tahun ajaran ini di menu PPSB → Jalur Pendaftaran.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3" x-data="{ sumber: '{{ old('sumber_informasi') }}' }">
                {{-- Gelombang dipilih SADAR: bernomor, atau "Tanpa Gelombang" untuk pindahan & kasus khusus. --}}
                <div x-data="{ mode: @js(old('gelombang_mode', '')) }">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Gelombang <span class="text-red-500">*</span></label>
                    <select name="gelombang_mode" x-model="mode" required
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">— pilih —</option>
                        <option value="nomor">Gelombang ke-…</option>
                        <option value="tanpa">Tanpa Gelombang</option>
                    </select>
                    <input type="number" name="gelombang" min="1" x-show="mode === 'nomor'" x-cloak
                           :required="mode === 'nomor'" value="{{ old('gelombang') }}" placeholder="nomor gelombang"
                           class="mt-2 w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    <p class="mt-1 text-xs text-gray-400" x-show="mode === 'tanpa'" x-cloak>
                        Untuk santri pindahan &amp; kasus di luar skema gelombang — tidak mendapat potongan gelombang.
                    </p>
                    @error('gelombang_mode')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('gelombang')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Sumber Informasi</label>
                    {{-- Pilihan dari master Sumber Informasi (PPSB → Setting Awal);
                         isian teks bebas muncul untuk sumber yang ditandai
                         "minta keterangan", bukan lagi khusus kode "lainnya". --}}
                    @php $sumberMaster = \App\Models\SumberInformasi::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')->get(); @endphp
                    <select name="sumber_informasi" x-model="sumber"
                            class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                        <option value="">—</option>
                        @foreach ($sumberMaster as $s)
                            <option value="{{ $s->kode }}" @selected(old('sumber_informasi') === $s->kode)>{{ $s->nama }}{{ $s->butuh_keterangan ? ' (sebutkan)' : '' }}</option>
                        @endforeach
                    </select>
                    <div x-show="@js($sumberMaster->where('butuh_keterangan', true)->pluck('kode')->values()->all()).includes(sumber)" x-cloak class="mt-2">
                        <input type="text" name="sumber_informasi_lain" value="{{ old('sumber_informasi_lain') }}"
                               placeholder="Sebutkan sumber informasi"
                               class="w-full rounded-lg border border-gray-400 px-3 py-2 text-sm focus:border-brand focus:ring-1 focus:ring-brand">
                    </div>
                </div>
            </div>

            <p class="text-xs text-gray-400">Tagihan registrasi otomatis diterbitkan dari master Jenis Biaya (tipe registrasi) sesuai tahun ajaran &amp; jenjang yang dipilih.</p>

            <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-4">
                <a href="{{ route('santri.calon') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">Batal</a>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Daftarkan</button>
            </div>
        </form>
    </div>
@endsection
