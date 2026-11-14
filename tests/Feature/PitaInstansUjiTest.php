<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pita INSTANS UJI (14 Nov 2026): hanya tampil bila PITA_UJI diisi (instans uji ber-salinan data produksi). */
class PitaInstansUjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pita_tampil_hanya_bila_diisi(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-pita-uji', false);

        config(['instans.pita_uji' => 'INSTANS UJI — data salinan']);
        $this->get(route('login'))->assertOk()->assertSee('data-pita-uji', false)->assertSee('INSTANS UJI — data salinan');
    }
}
