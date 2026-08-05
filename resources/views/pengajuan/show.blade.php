@extends('layouts.app')

@php
    $user = auth()->user();
    $bolehVerifikasi = $user->tim_keuangan && $instance && $instance->status === 'disetujui' && ! $instance->posted && $rec->status === 'diajukan';
    $statusColor = [
        'diajukan' => 'bg-amber-100 text-amber-700', 'disetujui' => 'bg-blue-100 text-blue-700',
        'diverifikasi' => 'bg-indigo-100 text-indigo-700', 'diposting' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-red-100 text-red-700', 'dibatalkan' => 'bg-gray-100 text-gray-500',
    ][$rec->status] ?? 'bg-gray-100 text-gray-500';
@endphp

@section('title', 'Pengajuan ' . $rec->nomor)

@section('content')
    <div class="mx-auto max-w-4xl">
        @php $disetujuiPenuh = $instance && $instance->status === 'disetujui' && $rec->status !== 'void'; @endphp
        <div class="mb-4 flex items-center justify-between gap-2">
            <a href="{{ route('pengajuan.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali</a>
            <div class="flex items-center gap-2">
            @if ($disetujuiPenuh)
                {{-- Cetak hanya bila sudah disetujui SELURUH approver (cegah "surat sakti"). --}}
                <a href="{{ route('pengajuan.cetak', $rec->id) }}" target="_blank"
                   class="rounded-lg border border-brand px-3 py-1.5 text-sm font-medium text-brand hover:bg-brand-soft">🖨 Cetak</a>
            @endif
            @if (in_array($rec->status, ['diajukan', 'ditolak'], true) && ($user->is_admin || $user->id_pengguna === $rec->id_pengguna))
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100">Batalkan</button>
                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('pengajuan.void', $rec->id) }}"
                          class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                        @csrf @method('DELETE')
                        <label class="block text-xs font-medium text-gray-600">Alasan pembatalan</label>
                        <input type="text" name="alasan" required maxlength="255" class="w-full rounded border-gray-300 text-sm">
                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">Konfirmasi Batal</button>
                    </form>
                </div>
            @endif
            </div>
        </div>

        <div class="mb-4 grid gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:grid-cols-4">
            <div><div class="text-xs text-gray-400">Nomor</div><div class="font-semibold text-gray-900">{{ $rec->nomor }}</div></div>
            <div><div class="text-xs text-gray-400">Tanggal</div><div>{{ $rec->tanggal->format('d M Y') }}</div></div>
            <div><div class="text-xs text-gray-400">Bagian</div><div>{{ $rec->bagian?->nama_bagian ?? $rec->kode_bagian }}</div></div>
            <div><div class="text-xs text-gray-400">Status</div><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">{{ ucfirst(str_replace('_', ' ', $rec->status)) }}</span></div>
            <div><div class="text-xs text-gray-400">Pemohon</div><div>{{ $rec->pemohon?->nama ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-400">Total</div><div class="font-semibold tabular-nums">@rp($rec->nominal)</div></div>
            <div class="sm:col-span-2"><div class="text-xs text-gray-400">Keterangan</div><div class="text-gray-700">{{ $rec->keterangan }}</div></div>
            <div class="sm:col-span-2">
                <div class="text-xs text-gray-400">Akun Hutang</div>
                <div>@if ($rec->kode_coa_hutang)<span class="text-gray-700">{{ $rec->kode_coa_hutang }}{{ $rec->coaHutang?->nama_coa ? ' — '.$rec->coaHutang->nama_coa : '' }}</span>@else<span class="text-amber-600">belum ditentukan keuangan</span>@endif</div>
            </div>
            <div class="sm:col-span-2">
                <div class="text-xs text-gray-400">Rekening Tujuan Pembayaran</div>
                @if ($rec->punyaRekeningTujuan())
                    <div class="text-gray-700">
                        <span class="font-medium">{{ $rec->bank_tujuan }}</span>
                        <span class="font-mono">{{ $rec->no_rekening_tujuan }}</span>
                        <span class="text-gray-500">a.n. {{ $rec->atas_nama_tujuan }}</span>
                    </div>
                @else
                    <div class="text-gray-400">tidak dicantumkan (mis. dibayar tunai)</div>
                @endif
            </div>
        </div>

        {{-- Jejak penyuntingan rekening oleh keuangan. Sengaja tampil menonjol
             dan tak pernah bisa dihapus: pemohon harus bisa melihat bila nomor
             rekening tujuannya diganti orang lain. --}}
        @if ($rec->riwayatRekening->isNotEmpty())
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold text-amber-900">Rekening tujuan pernah diubah</h3>
                <ul class="space-y-2 text-sm text-amber-900">
                    @foreach ($rec->riwayatRekening as $h)
                        <li>
                            <span class="font-mono text-xs">{{ $h->created_at?->format('d M Y H:i') }}</span> —
                            oleh <b>{{ $h->pengubah?->nama ?? $h->id_pengguna }}</b>:
                            <span class="line-through decoration-amber-400">{{ $h->bank_lama ? "{$h->bank_lama} {$h->no_rekening_lama} a.n. {$h->atas_nama_lama}" : '(kosong)' }}</span>
                            &rarr;
                            <b>{{ $h->bank_baru ? "{$h->bank_baru} {$h->no_rekening_baru} a.n. {$h->atas_nama_baru}" : '(kosong)' }}</b>
                            <div class="text-xs text-amber-700">Alasan: {{ $h->alasan }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Rincian --}}
        <div class="mb-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rec->details as $d)
                        <tr>
                            <td class="px-4 py-2">{{ $d->kode_coa }} — {{ $d->nama_coa }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $d->unit?->nama_unit ?? $d->kode_unit }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $d->keterangan }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">@rp($d->nominal)</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Rantai persetujuan — satu daftar: pengajuan, tiap tahap berikut
             penyetujunya, lalu verifikasi keuangan. --}}
        @if ($timeline)
            <div class="mb-4">@include('pengajuan._timeline', ['t' => $timeline, 'verifikasi' => true, 'timKeuangan' => $timKeuangan])</div>
        @else
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-400 shadow-sm">Belum ada rantai persetujuan untuk dokumen ini.</div>
        @endif

        {{-- Keputusan persetujuan, langsung dari halaman dokumen. Hanya muncul
             untuk penyetuju tahap yang SEDANG berjalan — kewenangannya dinilai
             ApprovalService, aturan yang sama dengan yang menjaga rutenya. --}}
        @if ($bolehMemutuskan && $instance)
            <div class="mb-4 rounded-xl border border-brand/30 bg-brand-soft/40 p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900">Keputusan Anda</h3>
                <p class="mb-3 mt-0.5 text-xs text-gray-500">
                    Anda penyetuju tahap yang sedang berjalan. Menyetujui meneruskan pengajuan ke tahap berikutnya;
                    menolak mengembalikannya ke pemohon untuk diperbaiki.
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ route('approvals.approve', $instance->id) }}"
                          {{-- Angkanya dirakit di sini, BUKAN dengan @rp: direktif itu
                               mengeluarkan <span class="rp">, dan kutipnya menutup
                               atribut ini lebih awal sehingga markupnya bocor ke layar. --}}
                          data-confirm="Setujui pengajuan {{ $rec->nomor }} sebesar Rp {{ number_format((float) $rec->nominal, 0, ',', '.') }}?"
                          class="space-y-2 rounded-lg border border-gray-200 bg-white p-3">
                        @csrf
                        <input type="hidden" name="kembali" value="dokumen">
                        {{-- Isian polos, bukan x-field: halaman ini sudah punya
                             isian bernama "catatan" di blok Verifikasi Keuangan,
                             dan dua id yang sama membuat labelnya salah tunjuk. --}}
                        <label for="catatan_persetujuan" class="mb-1 block text-sm font-medium text-gray-700">Catatan (opsional)</label>
                        <input id="catatan_persetujuan" name="catatan" type="text"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                        <button class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Setujui
                        </button>
                    </form>

                    <form method="POST" action="{{ route('approvals.reject', $instance->id) }}"
                          data-confirm="Tolak pengajuan {{ $rec->nomor }} dan kembalikan ke pemohon?"
                          class="space-y-2 rounded-lg border border-gray-200 bg-white p-3">
                        @csrf
                        <input type="hidden" name="kembali" value="dokumen">
                        <x-field name="alasan" label="Alasan penolakan" :value="old('alasan')" required
                                 hint="Wajib — pemohon menerima alasan ini beserta pemberitahuannya." />
                        <button class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            Tolak
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <div>
            {{-- Verifikasi keuangan --}}
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="mb-3 text-sm font-semibold text-gray-800">Verifikasi Keuangan</h3>
                @php
                    $catatanJenis = [
                        'pembayaran' => 'Verifikasi memicu pengakuan biaya (Beban ke Hutang Pengajuan); pembayarannya menyusul lewat Kas Keluar.',
                        'uang_muka' => 'Uang muka cash basis: verifikasi hanya menandai siap dibayar — belum ada jurnal. Jurnal (Uang Muka/Kas) terjadi saat Kas Keluar melunasinya.',
                        'penyelesaian_uang_muka' => 'Penyelesaian: verifikasi langsung memposting (Uang Muka dibersihkan, beban diakui, selisih via kas) & mengurangi outstanding. Tanpa Kas Keluar.',
                    ][$rec->jenis] ?? '';
                    $btnLabel = $rec->jenis === 'uang_muka' ? 'Verifikasi' : ($rec->jenis === 'penyelesaian_uang_muka' ? 'Verifikasi & Posting Penyelesaian' : 'Verifikasi &amp; Posting');
                @endphp
                @if ($rec->status === 'diposting')
                    <p class="text-sm text-brand">Sudah diverifikasi &amp; diposting. Bayar lewat menu Kas Keluar.</p>
                @elseif ($rec->status === 'diverifikasi')
                    <p class="text-sm text-brand">Uang muka sudah diverifikasi (<b>siap dibayar</b>). Bayar lewat menu <b>Kas Keluar</b> (jenis Uang Muka) — jurnal Uang Muka/Kas terbit saat itu.</p>
                @elseif ($bolehVerifikasi)
                    <p class="mb-3 text-sm text-gray-500">Rantai persetujuan selesai. {{ $catatanJenis }}</p>
                    <form method="POST" action="{{ route('pengajuan.verifikasi', $rec->id) }}" class="space-y-3">
                        @csrf

                        {{-- §4.f — Koreksi Akun (opsional) oleh keuangan. Nominal & unit tetap. --}}
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-sm font-medium text-gray-700">Koreksi Akun (opsional)</div>
                            <p class="mb-2 mt-0.5 text-xs text-gray-400">Biarkan kosong bila akunnya sudah benar. Pemohon &amp; atasannya diberi tahu, beserta status anggaran akun yang baru. <b>Nominal &amp; unit tidak berubah.</b></p>
                            <div class="space-y-2">
                                @foreach ($rec->details as $d)
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1.3fr_auto] sm:items-center">
                                        <div class="text-xs">
                                            <div class="text-gray-500">{{ $d->unit?->nama_unit ?? $d->kode_unit }}</div>
                                            <div class="font-medium text-gray-800">{{ $d->kode_coa }} — {{ $d->nama_coa }}</div>
                                        </div>
                                        <x-search-select name="koreksi[{{ $d->id }}]" :options="['' => '— biarkan —'] + $coaOptions" :value="''" placeholder="— biarkan —" />
                                        <div class="text-right text-xs tabular-nums text-gray-500">@rp($d->nominal)</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @if ($rec->jenis === 'pembayaran')
                            <x-field name="kode_coa_hutang" label="Akun Hutang Pengajuan (Liabilitas, kelompok 2)" :value="old('kode_coa_hutang')" :options="['' => '— pilih —'] + $hutangOptions" required
                                     hint="Menahan kewajiban sampai dibayar Kas Keluar (menghindari biaya tercatat dua kali)." />
                        @elseif ($rec->jenis === 'penyelesaian_uang_muka')
                            {{-- Arah selisih menentukan isian yang diminta. Kurang bayar
                                 TIDAK menyentuh kas: uangnya belum keluar, jadi yang
                                 dicatat kewajiban, dan pelunasannya lewat Kas Keluar. --}}
                            @php $kurang = \App\Support\Money::gtZero($selisihPenyelesaian); @endphp
                            @if ($kurang)
                                <div class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Realisasi melampaui uang muka sebesar <b>@rp($selisihPenyelesaian)</b>.
                                    Kekurangan ini <b>tidak langsung mengurangi kas</b> — ia ditahan sebagai kewajiban,
                                    lalu dibayar lewat <b>Kas Keluar</b> (dan muncul di Perintah Pembayaran).
                                </div>
                                <x-field name="kode_coa_hutang" label="Akun Hutang Penampung Kekurangan (Liabilitas, kelompok 2)" :value="old('kode_coa_hutang')" :options="['' => '— pilih —'] + $hutangOptions" required
                                         hint="Menahan kekurangannya sampai dibayar Kas Keluar, supaya kas tak berkurang sebelum uangnya benar-benar keluar." />
                            @elseif (\App\Support\Money::isNegative($selisihPenyelesaian))
                                <div class="rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                                    Uang muka melebihi realisasi sebesar <b>@rp(\App\Support\Money::sub('0', $selisihPenyelesaian))</b>.
                                    Kelebihannya kembali sekarang, jadi kasnya diakui langsung saat posting.
                                </div>
                                <x-field name="kode_rekening" label="Kas/Rekening Penerima Pengembalian" :value="old('kode_rekening')" :options="['' => '— pilih —'] + $rekeningOptions" required
                                         hint="Ke mana kelebihan uang muka dikembalikan." />
                            @else
                                <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                                    Realisasi <b>persis sebesar</b> uang mukanya — tak ada selisih, tak ada kas yang berpindah.
                                </p>
                            @endif
                        @else
                            <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">Uang muka <b>cash basis</b> — tidak memakai akun hutang. Tekan <b>Verifikasi</b> untuk menandai <i>siap dibayar</i>; jurnal terbit saat Kas Keluar.</p>
                        @endif
                        {{-- Rekening tujuan (opsional) — perubahannya berjejak. --}}
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-sm font-medium text-gray-700">Rekening Tujuan Pembayaran</div>
                            <p class="mb-2 mt-0.5 text-xs text-gray-400">
                                Biarkan apa adanya bila sudah benar. Setiap perubahan <b>dicatat permanen</b> (nilai lama, nilai baru, alasan, dan nama Anda) serta <b>diberitahukan kepada pemohon</b>.
                            </p>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <x-field name="bank_tujuan" label="Nama Bank" :value="old('bank_tujuan', $rec->bank_tujuan)" />
                                <x-field name="no_rekening_tujuan" label="Nomor Rekening" :value="old('no_rekening_tujuan', $rec->no_rekening_tujuan)" />
                                <x-field name="atas_nama_tujuan" label="Atas Nama" :value="old('atas_nama_tujuan', $rec->atas_nama_tujuan)" />
                            </div>
                            <div class="mt-3">
                                <x-field name="alasan_rekening" label="Alasan perubahan rekening" :value="old('alasan_rekening')"
                                         hint="Wajib diisi HANYA bila Anda mengubah salah satu isian di atas." />
                            </div>
                        </div>

                        <x-field name="catatan" label="Catatan" :value="old('catatan')" />
                        <div class="flex justify-end">
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{!! $btnLabel !!}</button>
                        </div>
                    </form>
                @elseif (! $user->tim_keuangan)
                    <p class="text-sm text-gray-400">Verifikasi hanya dilakukan tim keuangan.</p>
                @else
                    <p class="text-sm text-gray-400">Menunggu rantai persetujuan selesai sebelum bisa diverifikasi.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
