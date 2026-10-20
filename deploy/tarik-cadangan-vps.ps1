# Tarik cadangan database VPS ke laptop (Penjadwal Tugas Windows, tiap pagi; yang terlewat dijalankan
# saat laptop menyala). Server membuat cadangannya sendiri tiap malam lewat deploy/cadangan-db-vps.sh.
# Folder tujuan menyimpan semua yang pernah ditarik; berkas harian di laptop dibuang sesudah 120 hari.
# CATATAN: berkas ini sengaja ASCII saja - PowerShell 5.1 membaca berkas tanpa BOM sebagai ANSI, dan
# satu tanda pisah panjang cukup untuk membuat seluruh skrip gagal diurai.
$ErrorActionPreference = 'Stop'
$Server  = 'deploy@31.97.108.145'
$Kunci   = Join-Path $env:USERPROFILE '.ssh\alwafi_ed25519'
$Tujuan  = 'D:\Works\AL WAFI Reborn\cadangan\vps'
$Log     = Join-Path $Tujuan 'tarik.log'
# OpenSSH milik Git, BUKAN bawaan Windows: ssh.exe Windows 7.7 di laptop ini tak pernah keluar sesudah
# perintahnya selesai bila dijalankan tanpa konsol (Penjadwal Tugas) - tugasnya menggantung selamanya.
$Scp     = 'C:\Program Files\Git\usr\bin\scp.exe'
$Ssh     = 'C:\Program Files\Git\usr\bin\ssh.exe'

New-Item -ItemType Directory -Force -Path $Tujuan | Out-Null
function Catat($pesan) { "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $pesan" | Add-Content -Path $Log -Encoding utf8 }

try {
    # Hanya berkas yang BELUM ada di laptop: jalur VPS -> laptop lambat (~33 KB/detik, diukur 19 Okt 2026),
    # jadi menyalin ulang semuanya tiap pagi tak pernah selesai.
    $baru = 0
    foreach ($lapis in 'harian', 'mingguan', 'bulanan') {
        $dir = Join-Path $Tujuan $lapis
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
        $daftar = & $Ssh -n -o BatchMode=yes -i $Kunci $Server "ls -1 /var/backups/alwafi/$lapis/ 2>/dev/null"
        if ($LASTEXITCODE -ne 0 -and $lapis -eq 'harian') { throw "tak bisa membaca daftar cadangan di server (kode $LASTEXITCODE)" }
        foreach ($nama in @($daftar | Where-Object { $_ -like '*.dump' })) {
            if (Test-Path (Join-Path $dir $nama)) { continue }
            $sementara = Join-Path $dir "$nama.part"
            & $Scp -q -p -B -i $Kunci "${Server}:/var/backups/alwafi/$lapis/$nama" $sementara
            if ($LASTEXITCODE -ne 0) { Remove-Item $sementara -ErrorAction SilentlyContinue; throw "gagal menarik $lapis/$nama (kode $LASTEXITCODE)" }
            Move-Item $sementara (Join-Path $dir $nama)
            $baru++
        }
    }
    Get-ChildItem (Join-Path $Tujuan 'harian') -Filter *.dump |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-120) } | Remove-Item -Force
    $terbaru = Get-ChildItem (Join-Path $Tujuan 'harian') -Filter *.dump | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    $mb = [math]::Round($terbaru.Length / 1MB, 1)
    Catat ("OK - {0} berkas baru; terbaru: {1} ({2} MB, {3})" -f $baru, $terbaru.Name, $mb, $terbaru.LastWriteTime)
} catch {
    Catat ("GAGAL - {0}" -f $_.Exception.Message)
    exit 1
}
