<?php

namespace Tests;

use App\Services\Ledger\PostingService;
use App\Support\Akses;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * `PostingService` menyimpan konteks neraca (unit penampung + daftar
     * rekening kas) dalam properti STATIS, dan properti statis hidup melewati
     * batas test dalam satu proses PHPUnit. Tanpa pembersihan ini, test kedua
     * memposting jurnal memakai pengaturan milik test pertama — kegagalannya
     * pun bergantung pada urutan, yang paling melelahkan untuk ditelusuri.
     */
    protected function setUp(): void
    {
        parent::setUp();
        PostingService::lupakanKonteksNeraca();
        Akses::lupakan();
    }
}
