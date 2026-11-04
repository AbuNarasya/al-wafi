{{-- Penjemput sebuah izin (staf: detail & pos). Diwakilkan → identitas + foto untuk dicocokkan sebelum santri dilepas. --}}
@if ($z->penjemput_jenis === 'wakil')
    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">Diwakilkan</span>
@elseif ($z->penjemput_jenis === 'wali')
    <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-semibold text-emerald-800">Dijemput wali</span>
@endif
{{ $z->penjemput_nama ?: '—' }}{{ $z->penjemput_hubungan ? ' ('.$z->penjemput_hubungan.')' : '' }}{{ $z->penjemput_hp ? ' · '.$z->penjemput_hp : '' }}
@if ($z->penjemput_identitas)<span class="block text-gray-600">NIK / identitas: <span class="font-mono">{{ $z->penjemput_identitas }}</span></span>@endif
@if ($z->penjemput_keterangan)<span class="block text-gray-600">{{ $z->penjemput_keterangan }}</span>@endif
@if ($z->penjemput_foto_path)
    <a href="{{ route('perizinan.foto_penjemput', $z->id) }}" target="_blank" rel="noopener" class="block text-brand hover:underline">Lihat foto identitas penjemput</a>
@endif
