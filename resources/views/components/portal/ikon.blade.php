@props(['nama', 'kelas' => 'h-6 w-6'])
{{-- Ikon garis Portal Wali (24×24, gaya SANAD Wali). Nama yang tak dikenal = lingkaran. --}}
@php
    $jalur = [
        'beranda' => 'M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1z',
        'dompet' => 'M3 7.5A2.5 2.5 0 015.5 5H18v4M3 7.5V17a2 2 0 002 2h15V9H5.5A2.5 2.5 0 013 7.5zM16 14h.01',
        'tagihan' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3',
        'profil' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM5 20a7 7 0 0114 0',
        'data' => 'M3 5h18v14H3zM9 12a2 2 0 100-4 2 2 0 000 4zM5.5 16a3.5 3.5 0 017 0M15 9h3M15 13h3',
        'lonceng' => 'M6 9a6 6 0 1112 0c0 6 3 8 3 8H3s3-2 3-8zM10 21a2 2 0 004 0',
        'anak' => 'M9 11a4 4 0 100-8 4 4 0 000 8zM2 21a7 7 0 0114 0M16 4a3.5 3.5 0 010 7M22 21a6 6 0 00-4-5.6',
        'psb' => 'M9 3h6v3H9zM7 4.5H5V21h14V4.5h-2M9 11h6M9 15h4',
        'isi' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 8v8M8 12h8',
        'riwayat' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2',
        'absensi' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM8 12l3 3 5-6',
        'kembali' => 'M15 18l-6-6 6-6',
        'kanan' => 'M9 6l6 6-6 6',
        'perangkat' => 'M8 2h8a1 1 0 011 1v18a1 1 0 01-1 1H8a1 1 0 01-1-1V3a1 1 0 011-1zM11 18h2',
        'keluar' => 'M15 4h4v16h-4M10 8l4 4-4 4M14 12H4',
        'bulan' => 'M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z',
        'bank' => 'M3 10l9-6 9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 21h18',
        'unggah' => 'M12 16V4M7 9l5-5 5 5M5 20h14',
        'otomatis' => 'M20 11a8 8 0 10-2.3 5.7M20 4v7h-7',
        'kartu' => 'M3 6h18v12H3zM3 10h18M7 15h4',
    ];
@endphp
<svg {{ $attributes->merge(['class' => $kelas]) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $jalur[$nama] ?? 'M12 21a9 9 0 100-18 9 9 0 000 18z' }}"/></svg>
