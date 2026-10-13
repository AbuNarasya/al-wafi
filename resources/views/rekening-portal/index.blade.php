@extends('layouts.app')

@section('title', 'Rekening Portal Wali')

@section('content')
    @php $bolehUbah = \App\Support\Akses::boleh('bank-accounts', 'ubah'); @endphp

    <p class="mb-4 max-w-3xl text-sm text-gray-500">
        Rekening yang dicentang muncul di Portal Wali sebagai tujuan <b>transfer bank langsung</b> &mdash; untuk mengisi
        Dompet Wali dan membayar tagihan calon santri. Pembayaran lewat transfer menunggu verifikasi keuangan.
        Yang tampil di sini hanya rekening <b>bank</b> yang aktif; kas tunai tak bisa ditransfer.
    </p>

    <form method="POST" action="{{ route('rekening_portal.simpan') }}"
          data-confirm="Simpan pilihan rekening untuk Portal Wali? Wali langsung melihat perubahannya.">
        @csrf
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="w-16 px-4 py-3 text-center">Tampil</th>
                        <th class="px-4 py-3">Rekening</th>
                        <th class="px-4 py-3">Bank / No. Rek</th>
                        <th class="px-4 py-3">Atas nama (dilihat wali)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-center">
                                <input type="checkbox" name="tampil[]" value="{{ $r->kode_coa }}" @checked(in_array($r->kode_coa, old('tampil', $rows->where('tampil_di_portal', true)->pluck('kode_coa')->all()), true))
                                       @disabled(! $bolehUbah || ! $r->no_rekening)
                                       class="rounded border-gray-300 text-brand focus:ring-brand">
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ $r->nama_rekening }}</div>
                                <div class="text-xs text-gray-400">{{ $r->kode_coa }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ $r->nama_bank ?: '—' }}
                                @if ($r->no_rekening)
                                    <div class="text-xs text-gray-500">{{ $r->no_rekening }}</div>
                                @else
                                    <div class="text-xs text-amber-700">Belum ada nomor rekening</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <input name="atas_nama[{{ $r->kode_coa }}]" value="{{ old('atas_nama.'.$r->kode_coa, $r->atas_nama) }}"
                                       placeholder="{{ $namaLembaga }}" @disabled(! $bolehUbah)
                                       class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">Belum ada rekening bank aktif. Tambahkan di Kas &amp; Rekening.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400">Atas nama yang dikosongkan ditampilkan sebagai nama lembaga ({{ $namaLembaga }}).</p>

        @if ($bolehUbah && $rows->isNotEmpty())
            <div class="mt-4 flex justify-end">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Simpan</button>
            </div>
        @endif
    </form>
@endsection
