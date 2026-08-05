<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankLoan;
use App\Models\CashOut;
use App\Models\Invoice;
use App\Models\PengajuanPembayaran;
use App\Models\PerintahPembayaran;
use App\Models\PerintahPembayaranDetail;
use App\Services\Ledger\DocNumber;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PERINTAH PEMBAYARAN — daur hidup dokumennya.
 *
 * Dua keputusan membingkai dokumen ini, dan keduanya sengaja TERPISAH:
 *
 *   OTORISASI  — pejabat menetapkan apa yang boleh dibayar, kapan, dengan metode
 *                apa. Boleh menyetujui sebagian, menunda, mengurangi nominal,
 *                bahkan menambah kewajiban lain.
 *   PENUTUPAN  — "PP Sudah Selesai". Menetapkan titik saat perintah dianggap
 *                tuntas; sisanya dinyatakan BATAL DIBAYAR DARI PP INI.
 *
 * PP TIDAK PERNAH MENJURNAL. Uang tetap tercatat sekali saja, di Kas Keluar.
 * Karena itu kesalahan terburuk modul ini hanyalah perintah yang tak dieksekusi
 * — bukan laporan keuangan yang salah.
 */
class PerintahPembayaranService
{
    /**
     * Sumber kewajiban yang boleh ditunjuk.
     *
     * TIDAK ADA "lain-lain": kewajiban yang belum berdokumen (gaji, pajak) harus
     * lebih dulu menjadi Pengajuan Pembayaran, yang sudah punya rantai
     * persetujuannya sendiri. Dengan begitu tiap rupiah yang dibayar pasti punya
     * dokumen kewajiban di belakangnya.
     *
     * Uang muka TIDAK berdiri sendiri di sini — ia Pengajuan ber-`jenis`
     * `uang_muka`; `operational_advances` justru lahir SESUDAH pengajuan itu
     * dibayar, jadi menjadikannya sumber terpisah akan menagih hal yang sama dua
     * kali.
     */
    public const SUMBER = ['pengajuan', 'invoice', 'bank_loan'];

    // ---- Menyusun ----

    /**
     * Kewajiban yang tersedia untuk diajukan.
     *
     * Yang sedang terkunci di PP lain tetap ikut ditampilkan — dengan penanda
     * nomor PP-nya — supaya penyusun tahu ia SEDANG diproses, bukan hilang tanpa
     * jejak. Menyembunyikannya hanya memancing orang membuat pengajuan kedua.
     *
     * @return list<array<string,mixed>>
     */
    public function kewajibanTersedia(?int $kecualiPp = null): array
    {
        $terkunci = $this->kewajibanTerkunci($kecualiPp);
        $kunci = fn (string $sumber, $id) => $terkunci["{$sumber}:{$id}"] ?? null;
        // Nama unit dibawa sekalian — layar penyusunan PP menyebut NAMA unit.
        $namaUnit = \App\Models\BusinessUnit::pluck('nama_unit', 'kode_unit');
        $unit = fn (?string $kode) => $kode ? ($namaUnit[$kode] ?? $kode) : null;

        $rows = [];

        foreach (PengajuanPembayaran::whereIn('status', ['diposting', 'diverifikasi'])
            ->where('sisa_hutang', '>', 0)->orderBy('id')->get() as $p) {
            $rows[] = [
                'sumber' => 'pengajuan', 'id_dokumen' => (int) $p->id, 'nomor_dokumen' => $p->nomor,
                'pihak' => $p->keterangan, 'keterangan' => $p->referensi ?: $p->keterangan,
                'kode_unit' => null, 'nama_unit' => null, 'jatuh_tempo' => null,
                'sisa' => Money::of($p->sisa_hutang), 'terkunci_di' => $kunci('pengajuan', $p->id),
            ];
        }

        foreach (Invoice::where('status', '!=', 'void')->where('sisa_hutang', '>', 0)
            ->with('vendor')->orderBy('id_invoice')->get() as $i) {
            $rows[] = [
                'sumber' => 'invoice', 'id_dokumen' => (int) $i->id_invoice, 'nomor_dokumen' => $i->nomor_invoice,
                'pihak' => $i->vendor?->nama_vendor ?? $i->kode_vendor, 'keterangan' => $i->keterangan,
                'kode_unit' => $i->kode_unit, 'nama_unit' => $unit($i->kode_unit), 'jatuh_tempo' => $i->tanggal_jatuh_tempo,
                'sisa' => Money::of($i->sisa_hutang), 'terkunci_di' => $kunci('invoice', $i->id_invoice),
            ];
        }

        foreach (BankLoan::where('status', 'aktif')->orderBy('id')->get() as $l) {
            if (! Money::gtZero($l->sisa_pokok)) {
                continue;
            }
            $rows[] = [
                'sumber' => 'bank_loan', 'id_dokumen' => (int) $l->id, 'nomor_dokumen' => $l->nomor_kontrak ?: "Pembiayaan #{$l->id}",
                'pihak' => $l->nama_bank, 'keterangan' => $l->keterangan,
                'kode_unit' => null, 'nama_unit' => null, 'jatuh_tempo' => $l->tanggal_jatuh_tempo,
                'sisa' => Money::of($l->sisa_pokok), 'terkunci_di' => $kunci('bank_loan', $l->id),
            ];
        }

        return $rows;
    }

    /** Sisa kewajiban menurut DOKUMEN ASALNYA — bukan menurut angka di PP. */
    public function sisaKewajiban(string $sumber, int $idDokumen): string
    {
        return match ($sumber) {
            'pengajuan' => Money::of(PengajuanPembayaran::find($idDokumen)?->sisa_hutang ?? '0'),
            'invoice' => Money::of(Invoice::find($idDokumen)?->sisa_hutang ?? '0'),
            'bank_loan' => Money::of(BankLoan::find($idDokumen)?->sisa_pokok ?? '0'),
            default => '0',
        };
    }

    public function buat(array $data, int $idPengguna): PerintahPembayaran
    {
        $baris = $this->siapkanBaris($data['detail'] ?? []);

        return DB::transaction(function () use ($data, $baris, $idPengguna) {
            $base = DocNumber::docBase('PP', $data['tanggal']);
            $last = PerintahPembayaran::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

            $pp = PerintahPembayaran::create([
                'nomor' => DocNumber::nextDocNumber($base, $last),
                'tanggal' => $data['tanggal'],
                'keterangan' => $data['keterangan'],
                'tanggal_usulan' => $data['tanggal_usulan'] ?? null,
                'status' => 'draf',
                'disusun_oleh' => $idPengguna,
                'total_diajukan' => array_reduce($baris, fn ($t, $b) => Money::add($t, $b['nominal_diajukan']), '0'),
            ]);

            $this->tulisBaris($pp, $baris);

            return $pp->refresh();
        });
    }

    /** Draf → menunggu otorisasi. */
    public function ajukan(int $id, int $idPengguna): PerintahPembayaran
    {
        $pp = $this->ambil($id);
        if ($pp->status !== 'draf') {
            throw new AppException(422, "Perintah ini sudah berstatus \"".(PerintahPembayaran::STATUS[$pp->status] ?? $pp->status)."\".");
        }
        if ($pp->detail()->count() === 0) {
            throw new AppException(422, 'Tidak ada kewajiban yang diajukan.');
        }

        $pp->update(['status' => 'menunggu']);

        return $pp->refresh();
    }

    // ---- Otorisasi ----

    /**
     * Otorisasi PARSIAL.
     *
     * $data['baris'] = [id_detail => nominal_diotorisasi]; nol berarti DITUNDA.
     * $data['tambahan'] = baris baru yang disisipkan pejabat (opsional).
     *
     * Nominal boleh dikurangi — pejabat ikut menentukan besaran, bukan sekadar
     * menyetujui. Yang tak boleh: melebihi sisa kewajiban di dokumen asalnya,
     * dan melebihi dana yang benar-benar bebas dipakai.
     */
    public function otorisasi(int $id, array $data, int $idPengguna): PerintahPembayaran
    {
        $pp = $this->ambil($id);
        if ($pp->status !== 'menunggu') {
            throw new AppException(422, 'Hanya perintah berstatus "Menunggu Otorisasi" yang bisa diotorisasi.');
        }
        // EMPAT MATA. Tanpa ini, satu orang bisa menyusun perintah lalu
        // menyetujui pembayarannya sendiri — dan seluruh lapisan otorisasi ini
        // kehilangan artinya.
        if ((int) $pp->disusun_oleh === $idPengguna) {
            throw new AppException(422, 'Penyusun perintah tidak boleh mengotorisasi perintahnya sendiri. Mintakan ke pejabat lain yang berwenang.');
        }
        if (empty($data['tanggal_bayar'])) {
            throw new AppException(422, 'Tanggal bayar wajib ditetapkan.');
        }
        if (! array_key_exists($data['metode'] ?? '', PerintahPembayaran::METODE)) {
            throw new AppException(422, 'Metode pembayaran belum dipilih.');
        }

        $tambahan = $this->siapkanBaris($data['tambahan'] ?? [], $id);
        $keputusan = $data['baris'] ?? [];

        return DB::transaction(function () use ($pp, $data, $keputusan, $tambahan, $idPengguna) {
            if ($tambahan) {
                $this->tulisBaris($pp, $tambahan, true);
            }

            $total = '0';
            foreach ($pp->detail()->get() as $d) {
                // Baris tambahan pejabat dianggap disetujui penuh — ia baru saja
                // memilihnya sendiri, jadi tak perlu menyetujuinya dua kali.
                $nominal = $d->ditambahkan_pengotorisasi
                    ? Money::of($d->nominal_diajukan)
                    : Money::of($keputusan[$d->id] ?? '0');

                if (Money::gt($nominal, $d->nominal_diajukan) && ! $d->ditambahkan_pengotorisasi) {
                    throw new AppException(422, "Baris {$d->nomor_dokumen}: nominal otorisasi melebihi yang diajukan.");
                }
                $sisaAsli = $this->sisaKewajiban($d->sumber, $d->id_dokumen);
                if (Money::gt($nominal, $sisaAsli)) {
                    throw new AppException(422, "Baris {$d->nomor_dokumen}: sisa kewajibannya kini {$sisaAsli} — sudah berubah sejak perintah ini disusun.");
                }

                $ditunda = ! Money::gtZero($nominal);
                $d->update([
                    'nominal_diotorisasi' => $ditunda ? '0' : $nominal,
                    'sisa' => $ditunda ? '0' : $nominal,
                    // `ditunda` MELEPAS kuncinya (lihat indeks parsial), sehingga
                    // kewajibannya bebas diajukan lagi di PP berikutnya.
                    'status_baris' => $ditunda ? 'ditunda' : 'disetujui',
                    'alasan' => $data['alasan'][$d->id] ?? $d->alasan,
                ]);
                $total = Money::add($total, $ditunda ? '0' : $nominal);
            }

            if (! Money::gtZero($total)) {
                throw new AppException(422, 'Tidak ada satu pun baris yang disetujui. Bila memang tak ada yang dibayar, tolak perintahnya.');
            }

            // Dana bebas: komitmen PP INI dikecualikan, kalau tidak ia
            // menghalangi dirinya sendiri saat diotorisasi ulang.
            $bebas = (new DanaBebasService)->danaBebasKecuali((int) $pp->kode_transaksi);
            if (Money::gt($total, $bebas)) {
                throw new AppException(422, "Total otorisasi {$total} melebihi dana yang bisa dipakai ({$bebas}). "
                    .'Saldo kas dikurangi titipan dan perintah lain yang sudah diotorisasi tetapi belum dibayar.');
            }

            $pp->update([
                'tanggal_bayar' => $data['tanggal_bayar'],
                'metode' => $data['metode'],
                'kode_rekening_rencana' => $data['kode_rekening_rencana'] ?? null,
                'catatan_otorisasi' => $data['catatan'] ?? null,
                'total_diotorisasi' => $total,
                'status' => 'diotorisasi',
                'diotorisasi_oleh' => $idPengguna,
                'diotorisasi_pada' => now(),
            ]);

            return $pp->refresh();
        });
    }

    public function tolak(int $id, string $alasan, int $idPengguna): PerintahPembayaran
    {
        $pp = $this->ambil($id);
        if ($pp->status !== 'menunggu') {
            throw new AppException(422, 'Hanya perintah berstatus "Menunggu Otorisasi" yang bisa ditolak.');
        }
        if ((int) $pp->disusun_oleh === $idPengguna) {
            throw new AppException(422, 'Penyusun perintah tidak boleh menolak perintahnya sendiri.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($pp, $alasan, $idPengguna) {
            // Seluruh baris dilepas — kewajibannya bebas diajukan lagi.
            $pp->detail()->update(['status_baris' => 'batal', 'sisa' => '0']);
            $pp->update([
                'status' => 'ditolak', 'alasan_tolak' => $alasan,
                'diotorisasi_oleh' => $idPengguna, 'diotorisasi_pada' => now(),
                'total_diotorisasi' => '0',
            ]);

            return $pp->refresh();
        });
    }

    // ---- Penutupan ----

    /**
     * "PP SUDAH SELESAI" — keputusan kedua, sejajar otorisasi.
     *
     * Seluruh baris yang belum direalisasikan dinyatakan BATAL DIBAYAR DARI PP
     * INI: kewajibannya tetap utuh, kuncinya dilepas, riwayatnya tetap terbaca.
     *
     * PP tak pernah menutup sendiri, bahkan ketika semuanya sudah lunas —
     * supaya ada satu titik yang bisa disebut "inilah saat perintah ini tuntas",
     * dan pertanyaan "kenapa sisanya tak dibayar?" punya jawaban tertulis.
     */
    public function tutup(int $id, ?string $alasan, int $idPengguna): PerintahPembayaran
    {
        $pp = $this->ambil($id);
        if (! in_array($pp->status, ['menunggu', 'diotorisasi', 'sebagian', 'terbayar'], true)) {
            throw new AppException(422, "Perintah berstatus \"".(PerintahPembayaran::STATUS[$pp->status] ?? $pp->status)."\" tidak bisa ditutup.");
        }

        $bersisa = $pp->detail()
            ->whereIn('status_baris', PerintahPembayaranDetail::STATUS_MENGUNCI)
            ->where('sisa', '>', 0)->get();

        if ($bersisa->isNotEmpty() && trim((string) $alasan) === '') {
            $n = $bersisa->count();
            throw new AppException(422, "Masih ada {$n} kewajiban yang belum dibayar. Alasan penutupan wajib diisi — ia yang menjawab \"kenapa sisanya tidak dibayar?\".");
        }

        return DB::transaction(function () use ($pp, $bersisa, $alasan, $idPengguna) {
            foreach ($bersisa as $d) {
                $d->update([
                    'status_baris' => 'batal',
                    'sisa' => '0',
                    'alasan' => $d->alasan ?: $alasan,
                ]);
            }
            $pp->update([
                'status' => 'selesai',
                'ditutup_oleh' => $idPengguna,
                'ditutup_pada' => now(),
                'alasan_tutup' => $alasan,
            ]);

            return $pp->refresh();
        });
    }

    // ---- Realisasi lewat Kas Keluar ----

    /**
     * Penjagaan yang dipanggil Kas Keluar SEBELUM apa pun diposting.
     *
     * Dua hal: (a) baris yang mengaku berasal dari PP memang sah, dan (b) KUNCI
     * KERAS — kewajiban yang sedang berada di PP hidup tak boleh dibayar dari
     * jalur lain. Kuncinya melekat pada DOKUMEN tertentu, bukan pada jenisnya:
     * kewajiban yang tak sedang di PP mana pun tetap bebas dibayar langsung.
     *
     * @param  list<array<string,mixed>>  $details  baris mentah dari pemanggil
     */
    public function assertRealisasiSah(array $details, ?int $idPerintah, ?int $idBankLoan = null): void
    {
        $pp = null;
        if ($idPerintah) {
            $pp = $this->ambil($idPerintah);
            if (! $pp->bolehDibayar()) {
                throw new AppException(422, "Perintah {$pp->nomor} belum diotorisasi atau sudah ditutup, jadi belum bisa direalisasikan.");
            }
        }

        $terkunci = $this->kewajibanTerkunci();
        $perDetail = [];

        // Angsuran pembiayaan ditandai di KEPALA voucher, bukan di barisnya —
        // jadi kuncinya diperiksa di sini, kalau tidak jalur ini jadi pintu
        // belakang yang lolos dari penjagaan.
        if ($idBankLoan && ! $idPerintah) {
            $nomorPp = $terkunci["bank_loan:{$idBankLoan}"] ?? null;
            if ($nomorPp !== null) {
                throw new AppException(422, "Pembiayaan ini sedang berada di perintah pembayaran {$nomorPp}. "
                    .'Bayarkan lewat perintah itu, atau keluarkan dulu dari sana bila memang mendesak.');
            }
        }

        foreach ($details as $l) {
            $idDetail = $l['id_perintah_detail'] ?? null;

            if ($idDetail) {
                if (! $pp) {
                    throw new AppException(422, 'Ada baris yang menunjuk perintah pembayaran, tetapi vouchernya tidak terkait perintah mana pun.');
                }
                $d = PerintahPembayaranDetail::find($idDetail);
                if (! $d || (int) $d->kode_transaksi !== (int) $pp->kode_transaksi) {
                    throw new AppException(422, "Baris perintah tidak ditemukan di {$pp->nomor}.");
                }
                if ($d->status_baris !== 'disetujui') {
                    throw new AppException(422, "Baris {$d->nomor_dokumen} tidak disetujui pada {$pp->nomor}, jadi tak boleh dibayar dari sini.");
                }
                $nominal = Money::of($l['nominal'] ?? '0');
                $perDetail[$idDetail] = Money::add($perDetail[$idDetail] ?? '0', $nominal);
                if (Money::gt($perDetail[$idDetail], $d->sisa)) {
                    throw new AppException(422, "Baris {$d->nomor_dokumen}: pembayaran melebihi sisa yang diotorisasi ({$d->sisa}).");
                }

                continue;
            }

            // Tanpa penunjuk PP → periksa apakah kewajibannya sedang terkunci.
            [$sumber, $idDok] = $this->kenaliKewajiban($l);
            if ($sumber === null) {
                continue;
            }
            $nomorPp = $terkunci["{$sumber}:{$idDok}"] ?? null;
            if ($nomorPp !== null) {
                throw new AppException(422, "Kewajiban ini sedang berada di perintah pembayaran {$nomorPp}. "
                    .'Bayarkan lewat perintah itu, atau keluarkan dulu dari sana bila memang mendesak.');
            }
        }
    }

    /**
     * Catat realisasi ke baris PP. Dipanggil Kas Keluar DI DALAM transaksinya,
     * setelah jurnalnya terbit.
     *
     * @param  list<array{id_perintah_detail:?int,nominal:string}>  $baris
     */
    public function terapkanRealisasi(array $baris): void
    {
        $ppTersentuh = [];

        foreach ($baris as $b) {
            if (empty($b['id_perintah_detail'])) {
                continue;
            }
            $d = PerintahPembayaranDetail::find($b['id_perintah_detail']);
            if (! $d) {
                continue;
            }
            $d->update([
                'terbayar' => Money::add($d->terbayar, $b['nominal']),
                'sisa' => Money::sub($d->sisa, $b['nominal']),
            ]);
            $ppTersentuh[(int) $d->kode_transaksi] = true;
        }

        foreach (array_keys($ppTersentuh) as $id) {
            $this->segarkanStatus($id);
        }
    }

    /**
     * Kembalikan realisasi saat Kas Keluar di-void.
     *
     * WAJIB ADA. Tanpa ini, membatalkan Kas Keluar membuat kewajiban tampak
     * lunas di PP padahal uangnya sudah ditarik kembali — rusak tanpa gejala,
     * dan baru ketahuan saat vendor menagih lagi.
     *
     * @param  list<array{id_perintah_detail:?int,nominal:string}>  $baris
     */
    public function batalkanRealisasi(array $baris): void
    {
        $ppTersentuh = [];

        foreach ($baris as $b) {
            if (empty($b['id_perintah_detail'])) {
                continue;
            }
            $d = PerintahPembayaranDetail::find($b['id_perintah_detail']);
            if (! $d) {
                continue;
            }
            $terbayar = Money::sub($d->terbayar, $b['nominal']);
            $d->update([
                'terbayar' => Money::isNegative($terbayar) ? '0' : $terbayar,
                // Sisa dikembalikan HANYA bila barisnya masih hidup. Bila PP-nya
                // sudah ditutup, barisnya berstatus `batal` dan sisanya memang
                // sudah dinyatakan tak akan dibayar dari sini.
                'sisa' => in_array($d->status_baris, PerintahPembayaranDetail::STATUS_MENGUNCI, true)
                    ? Money::add($d->sisa, $b['nominal'])
                    : Money::of($d->sisa),
            ]);
            $ppTersentuh[(int) $d->kode_transaksi] = true;
        }

        foreach (array_keys($ppTersentuh) as $id) {
            $this->segarkanStatus($id);
        }
    }

    /**
     * Status PP mengikuti realisasinya — TAPI tak pernah sampai `selesai`.
     * Penutupan selalu keputusan sadar (lihat tutup()).
     */
    private function segarkanStatus(int $id): void
    {
        $pp = PerintahPembayaran::find($id);
        if (! $pp || ! in_array($pp->status, ['diotorisasi', 'sebagian', 'terbayar'], true)) {
            return;
        }

        $hidup = $pp->detail()->whereIn('status_baris', PerintahPembayaranDetail::STATUS_MENGUNCI)->get();
        $adaSisa = $hidup->contains(fn ($d) => Money::gtZero($d->sisa));
        $adaTerbayar = $pp->detail()->get()->contains(fn ($d) => Money::gtZero($d->terbayar));

        $pp->update(['status' => match (true) {
            ! $adaTerbayar => 'diotorisasi',
            $adaSisa => 'sebagian',
            default => 'terbayar', // lunas, tinggal menunggu tombol "PP Sudah Selesai"
        }]);
    }

    /**
     * Kenali kewajiban yang dibayar sebuah baris Kas Keluar.
     *
     * @return array{0:?string,1:?int}
     */
    private function kenaliKewajiban(array $baris): array
    {
        if (! empty($baris['id_invoice'])) {
            return ['invoice', (int) $baris['id_invoice']];
        }
        if (! empty($baris['id_pengajuan'])) {
            return ['pengajuan', (int) $baris['id_pengajuan']];
        }
        if (! empty($baris['id_bank_loan'])) {
            return ['bank_loan', (int) $baris['id_bank_loan']];
        }

        return [null, null];
    }

    // ---- Riwayat ----

    /**
     * Riwayat pengajuan sebuah kewajiban lintas PP — diturunkan dari baris yang
     * sudah ada, TANPA tabel tersendiri.
     *
     * Inilah yang mencegah satu kewajiban ditunda berkali-kali tanpa ada yang
     * menyadarinya.
     *
     * @return list<array<string,mixed>>
     */
    public function riwayat(string $sumber, int $idDokumen, ?int $kecualiPp = null): array
    {
        return PerintahPembayaranDetail::with('perintah')
            ->where('sumber', $sumber)->where('id_dokumen', $idDokumen)
            ->when($kecualiPp, fn ($q) => $q->where('kode_transaksi', '!=', $kecualiPp))
            ->get()
            ->sortBy(fn ($d) => $d->perintah?->tanggal)
            ->map(fn ($d) => [
                'nomor_pp' => $d->perintah?->nomor,
                'tanggal' => $d->perintah?->tanggal,
                'diajukan' => Money::of($d->nominal_diajukan),
                'diotorisasi' => Money::of($d->nominal_diotorisasi),
                'terbayar' => Money::of($d->terbayar),
                'status' => $d->status_baris,
                'alasan' => $d->alasan,
            ])->values()->all();
    }

    // ---- Kepatuhan realisasi ----

    /**
     * Diotorisasi versus yang benar-benar terjadi.
     *
     * Tanpa laporan ini, penyimpangan memang tercatat tetapi tak pernah ada yang
     * melihatnya — dan yang paling perlu tertangkap justru yang paling sunyi:
     * perintah yang sudah diotorisasi, lewat tanggalnya, tapi belum dibayar
     * sepeser pun.
     *
     * Metode & rekening dibandingkan apa adanya: keduanya BOLEH berbeda dari
     * rencana (bank gangguan, saldo tak cukup itu kenyataan sehari-hari), yang
     * penting selisihnya terlihat.
     *
     * @return list<array<string,mixed>>
     */
    public function kepatuhan(array $filter = []): array
    {
        $hariIni = Carbon::today();

        $pp = PerintahPembayaran::with(['detail', 'rekeningRencana'])
            ->whereNotIn('status', ['draf', 'menunggu', 'ditolak'])
            ->when($filter['dari'] ?? null, fn ($q, $v) => $q->whereDate('tanggal_bayar', '>=', $v))
            ->when($filter['sampai'] ?? null, fn ($q, $v) => $q->whereDate('tanggal_bayar', '<=', $v))
            ->orderByDesc('kode_transaksi')->get();

        if ($pp->isEmpty()) {
            return [];
        }

        // Realisasi diambil dari Kas Keluar yang AKTIF — yang sudah di-void
        // memang tak pernah benar-benar terjadi.
        $realisasi = CashOut::whereIn('id_perintah', $pp->pluck('kode_transaksi'))
            ->where('status', 'aktif')
            ->orderBy('tanggal')->orderBy('kode_transaksi')
            ->get(['kode_transaksi', 'id_perintah', 'nomor_transaksi', 'tanggal', 'kode_rekening', 'metode', 'nominal'])
            ->groupBy('id_perintah');

        return $pp->map(function ($p) use ($realisasi, $hariIni) {
            $kk = $realisasi[$p->kode_transaksi] ?? collect();

            $terealisasi = $p->detail->reduce(fn ($t, $d) => Money::add($t, $d->terbayar), '0');
            $sisa = $p->detail
                ->whereIn('status_baris', PerintahPembayaranDetail::STATUS_MENGUNCI)
                ->reduce(fn ($t, $d) => Money::add($t, $d->sisa), '0');

            $pertama = $kk->first();
            // DIBULATKAN KE INT. diffInDays mengembalikan float, dan pembanding
            // "tepat waktu" di layar memakai !== 0 — float 0.0 akan lolos dari
            // pembanding itu dan menandai realisasi tepat waktu sebagai selisih.
            $selisihHari = ($pertama && $p->tanggal_bayar)
                ? (int) $p->tanggal_bayar->diffInDays(Carbon::parse($pertama->tanggal), false)
                : null;

            $rekeningDipakai = $kk->pluck('kode_rekening')->unique()->values();
            $metodeDipakai = $kk->pluck('metode')->filter()->unique()->values();

            return [
                'kode_transaksi' => (int) $p->kode_transaksi,
                'nomor' => $p->nomor,
                'keterangan' => $p->keterangan,
                'status' => $p->status,
                'tanggal_bayar' => $p->tanggal_bayar,
                'diotorisasi' => Money::of($p->total_diotorisasi),
                'terealisasi' => $terealisasi,
                'sisa' => $sisa,
                'jumlah_voucher' => $kk->count(),
                // null = belum ada realisasi; angka negatif = lebih awal dari rencana.
                'selisih_hari' => $selisihHari,
                'terlambat_hari' => (! $pertama && $p->tanggal_bayar && $p->tanggal_bayar->lt($hariIni) && Money::gtZero($sisa))
                    ? (int) $p->tanggal_bayar->diffInDays($hariIni)
                    : null,
                'rekening_rencana' => $p->kode_rekening_rencana,
                'rekening_dipakai' => $rekeningDipakai->all(),
                'rekening_beda' => $p->kode_rekening_rencana !== null
                    && $rekeningDipakai->isNotEmpty()
                    && $rekeningDipakai->contains(fn ($r) => $r !== $p->kode_rekening_rencana),
                'metode_rencana' => $p->metode,
                'metode_dipakai' => $metodeDipakai->all(),
                'metode_beda' => $p->metode !== null
                    && $metodeDipakai->isNotEmpty()
                    && $metodeDipakai->contains(fn ($m) => $m !== $p->metode),
                'voucher' => $kk->map(fn ($k) => [
                    'nomor' => $k->nomor_transaksi, 'tanggal' => $k->tanggal,
                    'rekening' => $k->kode_rekening, 'metode' => $k->metode, 'nominal' => Money::of($k->nominal),
                ])->values()->all(),
            ];
        })->filter(function ($r) use ($filter) {
            // "Ada selisih" = apa pun yang tidak persis seperti perintahnya.
            if (($filter['hanya_selisih'] ?? false)) {
                return $r['rekening_beda'] || $r['metode_beda']
                    || ($r['selisih_hari'] !== null && $r['selisih_hari'] !== 0)
                    || $r['terlambat_hari'] !== null
                    || Money::gtZero($r['sisa']);
            }

            return true;
        })->values()->all();
    }

    // ---- Pembantu ----

    private function ambil(int $id): PerintahPembayaran
    {
        $pp = PerintahPembayaran::find($id);
        if (! $pp) {
            throw new AppException(404, 'Perintah pembayaran tidak ditemukan.');
        }

        return $pp;
    }

    /**
     * Kewajiban yang sedang terkunci di PP hidup → [sumber:id => nomor PP].
     *
     * @return array<string,string>
     */
    private function kewajibanTerkunci(?int $kecualiPp = null): array
    {
        return PerintahPembayaranDetail::with('perintah:kode_transaksi,nomor,status')
            ->whereIn('status_baris', PerintahPembayaranDetail::STATUS_MENGUNCI)
            ->whereHas('perintah', fn ($q) => $q->whereIn('status', PerintahPembayaran::STATUS_HIDUP))
            ->when($kecualiPp, fn ($q) => $q->where('kode_transaksi', '!=', $kecualiPp))
            ->get()
            ->mapWithKeys(fn ($d) => ["{$d->sumber}:{$d->id_dokumen}" => $d->perintah?->nomor ?? '-'])
            ->all();
    }

    /**
     * Validasi & normalisasi baris yang diminta.
     *
     * @return list<array<string,mixed>>
     */
    private function siapkanBaris(array $input, ?int $kecualiPp = null): array
    {
        if (! $input) {
            return [];
        }

        $tersedia = collect($this->kewajibanTersedia($kecualiPp))
            ->keyBy(fn ($k) => "{$k['sumber']}:{$k['id_dokumen']}");

        $hasil = [];
        $dilihat = [];
        foreach ($input as $b) {
            $sumber = $b['sumber'] ?? '';
            $idDok = (int) ($b['id_dokumen'] ?? 0);
            $kunci = "{$sumber}:{$idDok}";

            if (! in_array($sumber, self::SUMBER, true)) {
                throw new AppException(422, "Sumber kewajiban \"{$sumber}\" tidak dikenal.");
            }
            if (isset($dilihat[$kunci])) {
                throw new AppException(422, 'Satu kewajiban tak boleh dimasukkan dua kali dalam perintah yang sama.');
            }
            $ref = $tersedia->get($kunci);
            if (! $ref) {
                throw new AppException(422, 'Kewajiban yang dipilih tidak tersedia — kemungkinan sudah lunas atau sudah dibatalkan.');
            }
            if ($ref['terkunci_di']) {
                throw new AppException(422, "Kewajiban {$ref['nomor_dokumen']} sedang berada di perintah {$ref['terkunci_di']}. Keluarkan dulu dari sana bila hendak dipindahkan.");
            }

            $nominal = Money::of($b['nominal'] ?? '0');
            if (! Money::gtZero($nominal)) {
                throw new AppException(422, "Nominal untuk {$ref['nomor_dokumen']} harus lebih dari nol.");
            }
            if (Money::gt($nominal, $ref['sisa'])) {
                throw new AppException(422, "Nominal untuk {$ref['nomor_dokumen']} melebihi sisa kewajibannya ({$ref['sisa']}).");
            }

            $dilihat[$kunci] = true;
            $hasil[] = [
                'sumber' => $sumber, 'id_dokumen' => $idDok,
                'nomor_dokumen' => $ref['nomor_dokumen'], 'pihak' => $ref['pihak'],
                'keterangan' => $b['keterangan'] ?? $ref['keterangan'],
                'kode_unit' => $ref['kode_unit'], 'jatuh_tempo' => $ref['jatuh_tempo'],
                'nominal_diajukan' => $nominal,
            ];
        }

        if (! $hasil) {
            throw new AppException(422, 'Tidak ada kewajiban yang dipilih.');
        }

        return $hasil;
    }

    /** @param list<array<string,mixed>> $baris */
    private function tulisBaris(PerintahPembayaran $pp, array $baris, bool $olehPengotorisasi = false): void
    {
        foreach ($baris as $b) {
            PerintahPembayaranDetail::create($b + [
                'kode_transaksi' => $pp->kode_transaksi,
                'nominal_diotorisasi' => '0',
                'terbayar' => '0',
                'sisa' => '0',
                'status_baris' => 'diajukan',
                'ditambahkan_pengotorisasi' => $olehPengotorisasi,
            ]);
        }
    }
}
