{{--
  Satu SEL tarif: isian nominal + centang "Bebas".
    <x-tarif-sel name="umum[spp]" :sel="$sel" />
    <x-tarif-sel :name="'umum[daftar_ulang]['.$t.']'" :sel="$sel" lebar="w-36" />

  `sel` = ['nominal' => ?string, 'bebas' => bool] dari TarifService::grid().
  Tiga keadaan yang harus tetap berbeda: nominal terisi · Bebas dicentang ·
  dikosongkan (= belum diisi, penagihan berhenti). Nol adalah angka yang SAH.

  WAJIB dipasang di dalam elemen ber-`x-data="{ bebas: … }"` — centangnya yang
  mematikan isian nominalnya, dan Alpine perlu keadaan itu di lingkup pemanggil.
  Hidden 0 mendahului checkbox karena checkbox yang tak dicentang tidak dikirim
  peramban sama sekali.
--}}
@props(['name', 'sel', 'lebar' => 'w-44'])
{{-- `nonaktif` mematikan isian TERLIHAT dan hidden-nya sekaligus: hidden yang
     masih hidup akan tetap mengirim angka yang sengaja ditandai Bebas. --}}
<x-input-rupiah :name="$name.'[nominal]'" :value="$sel['nominal']" nonaktif="bebas"
                placeholder="belum diisi" :lebar="$lebar"
                class="rounded-lg border border-gray-400 px-2 py-1.5 text-right focus:border-brand focus:ring-1 focus:ring-brand disabled:bg-gray-100 disabled:text-gray-400" />
<label class="mt-1 flex items-center gap-1 text-[11px] text-gray-500">
    <input type="hidden" name="{{ $name }}[bebas]" value="0">
    <input type="checkbox" value="1" x-model="bebas" name="{{ $name }}[bebas]"
           class="rounded border-gray-300 text-brand focus:ring-brand">
    Bebas
</label>
