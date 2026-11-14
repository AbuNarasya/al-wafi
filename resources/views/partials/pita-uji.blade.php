{{-- Pita INSTANS UJI (config instans.pita_uji, .env PITA_UJI). Melayang di bawah tengah, tak menghalangi klik.
     Warna dipaku lewat style supaya mode gelap portal tak mengubahnya. --}}
@if (filled(config('instans.pita_uji')))
    <div data-pita-uji aria-hidden="true"
         style="position:fixed;left:50%;bottom:calc(10px + env(safe-area-inset-bottom, 0px));transform:translateX(-50%);z-index:2147483000;pointer-events:none;background:#b91c1c;color:#fff;font:700 12px/1.2 system-ui,sans-serif;letter-spacing:.06em;padding:6px 14px;border-radius:9999px;box-shadow:0 2px 8px rgba(0,0,0,.35);white-space:nowrap;opacity:.92">
        {{ config('instans.pita_uji') }}
    </div>
@endif
