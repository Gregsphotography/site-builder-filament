<svg viewBox="0 0 160 96" aria-hidden="true" focusable="false">
    @switch($type)
        @case('standard.content-listing')
            <rect x="17" y="30" width="38" height="48" rx="3"/><rect x="61" y="30" width="38" height="48" rx="3"/><rect x="105" y="30" width="38" height="48" rx="3"/><path d="M18 18h80m-70 24h17m27 0h17m27 0h17M28 58h17m27 0h17m27 0h17"/>
            @break
        @case('standard.search-box')
            <rect x="15" y="30" width="130" height="35" rx="5"/><circle cx="39" cy="45" r="8"/><path d="m45 51 7 7m11-12h47m12-7 9 7-9 7"/>
            @break
        @case('standard.search-results')
            <circle cx="29" cy="20" r="7"/><path d="m34 25 7 7m9-12h70M23 45h24m12 0h73M23 56h109M23 70h24m12 0h73M23 81h109"/>
            @break
        @case('standard.image-comparison')
            <rect x="20" y="16" width="120" height="64" rx="3"/><path d="M80 16v64M27 64l20-27 23 27m19-15 14-16 29 31M71 46l-8 5 8 5m18-10 8 5-8 5"/>
            @break
        @case('standard.image-hotspots')
            <rect x="20" y="16" width="120" height="64" rx="3"/><circle cx="52" cy="43" r="10"/><path d="M52 38v10m-5-5h10"/><circle cx="109" cy="57" r="10"/><path d="M109 52v10m-5-5h10M22 75l29-17 22 9"/>
            @break
        @case('standard.carousel')
            <rect x="43" y="20" width="74" height="52" rx="3"/><path d="M31 25H18v42h13m98-42h13v42h-13M61 82h7m8 0h7m8 0h7m-44-25 15-17 14 10 12-14 12 21"/>
            @break
        @case('standard.timeline')
            <path d="M44 16v64m20-58h64m-64 9h44m-44 21h64m-64 9h44m-44 17h64"/><circle cx="44" cy="25" r="5"/><circle cx="44" cy="55" r="5"/><circle cx="44" cy="78" r="5"/>
            @break
        @case('standard.form')
            <rect x="22" y="12" width="116" height="72" rx="4" />
            <path d="M33 24h49" /><rect x="33" y="32" width="94" height="10" rx="2" /><rect x="33" y="47" width="94" height="10" rx="2" />
            <rect x="33" y="63" width="37" height="11" rx="3" />
            @break
        @case('standard.video')
            <rect x="18" y="15" width="124" height="66" rx="4" /><path d="m71 30 22 14-22 14z" />
            <path d="M28 70h104" class="sb-illustration-muted" /><circle cx="47" cy="70" r="3" />
            @break
        @case('standard.location')
            <rect x="19" y="16" width="122" height="64" rx="4" />
            <path d="m22 64 31-17 20 8 35-23 30 9M50 18l8 27M96 80l-9-27" class="sb-illustration-muted" />
            <path d="M91 34c0 10-11 18-11 18s-11-8-11-18a11 11 0 0 1 22 0z" /><circle cx="80" cy="34" r="4" />
            @break
        @case('standard.tabs')
            <rect x="18" y="28" width="124" height="50" rx="3" /><path d="M18 40h124M26 33h24m9 0h24m9 0h24" />
            <path d="M29 51h60m-60 9h90m-90 8h72" class="sb-illustration-muted" />
            @break
        @case('standard.tab-panel')
            <rect x="18" y="22" width="124" height="55" rx="3" class="sb-illustration-muted" /><path d="M18 35h124M26 28h24m9 0h24m9 0h24" class="sb-illustration-muted" />
            <rect x="25" y="41" width="110" height="29" rx="2" stroke-dasharray="4 3" /><path d="M34 50h55m-55 9h81" />
            @break
        @case('standard.accordion')
            <rect x="20" y="19" width="120" height="18" rx="2" /><rect x="20" y="39" width="120" height="18" rx="2" /><rect x="20" y="59" width="120" height="18" rx="2" />
            <path d="M29 28h63m-63 20h63m-63 20h63m33-45v10m-5-5h10m-5 15v10m-5-5h10m-5 15v10m-5-5h10" />
            @break
        @case('standard.accordion-item')
            <rect x="20" y="19" width="120" height="18" rx="2" class="sb-illustration-muted" /><rect x="20" y="59" width="120" height="18" rx="2" class="sb-illustration-muted" />
            <rect x="20" y="39" width="120" height="18" rx="2" /><path d="M29 48h63m38-5v10m-5-5h10" />
            @break
        @case('standard.table')
            <rect x="20" y="17" width="120" height="62" rx="3" />
            <path d="M20 34h120M20 49h120M20 64h120M60 17v62M100 17v62" />
            @break
        @case('standard.table-of-contents')
            <rect x="23" y="15" width="114" height="66" rx="3" /><path d="M35 28h54" />
            <path d="M35 41h7m10 0h61M35 51h7m10 0h52M35 61h7m10 0h61M35 71h7m10 0h45" />
            @break
        @case('standard.breadcrumbs')
            <path d="M23 48h29m12-6 6 6-6 6m18-6h27m11-6 6 6-6 6m12-6h9" />
            <path d="M23 62h114" class="sb-illustration-muted" />
            @break
        @case('standard.heading')
            <path d="M28 29v38M52 29v38M28 48h24M70 34h62M70 44h48" />
            <path d="M70 57h54M70 66h42" class="sb-illustration-muted" />
            @break
        @case('standard.buttons')
            <rect x="20" y="35" width="56" height="24" rx="4" /><rect x="84" y="35" width="56" height="24" rx="4" />
            <path d="M32 47h32m32 0h32" />
            @break
        @case('standard.icon')
            <circle cx="80" cy="48" r="29" /><path d="m80 28 5 12 13 1-10 8 3 13-11-7-11 7 3-13-10-8 13-1z" />
            @break
        @case('standard.icon-list')
            <circle cx="32" cy="27" r="7" /><circle cx="32" cy="48" r="7" /><circle cx="32" cy="69" r="7" />
            <path d="m29 27 2 2 4-4m-6 23 2 2 4-4m-6 23 2 2 4-4M49 27h79M49 48h79M49 69h79" />
            @break
        @case('standard.social-links')
            <circle cx="30" cy="48" r="12" /><circle cx="63" cy="48" r="12" /><circle cx="96" cy="48" r="12" /><circle cx="129" cy="48" r="12" />
            <path d="M25 48h10m-5-5v10m28-10 10 10m0-10-10 10m34-10h8v10h-8zm32 0h10v10h-10z" />
            @break
        @case('standard.divider')
            <path d="M24 25h68M24 32h43M24 64h96M24 71h72" class="sb-illustration-muted" />
            <path d="M20 48h120" />
            @break
        @case('standard.anchor')
            <path d="M70 50 61 59a13 13 0 0 1-18-18l14-14a13 13 0 0 1 18 0M90 46l9-9a13 13 0 1 1 18 18l-14 14a13 13 0 0 1-18 0M63 56l34-34" />
            @break
        @case('standard.gallery')
            <path d="M18 18h39m-39 6h23" class="sb-illustration-muted" />
            <rect x="18" y="34" width="37" height="36" rx="3" />
            <rect x="61" y="34" width="38" height="36" rx="3" />
            <rect x="105" y="34" width="37" height="36" rx="3" />
            <circle cx="44" cy="43" r="3" /><path d="m20 64 10-10 8 7 6-5 9 8" />
            <circle cx="88" cy="43" r="3" /><path d="m63 64 10-10 8 7 6-5 10 8" />
            <circle cx="132" cy="43" r="3" /><path d="m107 64 10-10 8 7 6-5 9 8" />
            @break
        @case('standard.testimonials')
            <rect x="16" y="18" width="60" height="60" rx="4" /><rect x="84" y="18" width="60" height="60" rx="4" />
            <path d="M26 32h3v5h-3zm7 0h3v5h-3zm61 0h3v5h-3zm7 0h3v5h-3z" />
            <path d="M26 45h38m-38 6h30m38-6h38m-38 6h30" class="sb-illustration-muted" />
            <circle cx="30" cy="65" r="4" /><circle cx="98" cy="65" r="4" />
            <path d="M39 63h19m-19 5h13m55-5h19m-19 5h13" />
            @break
        @case('standard.brand')
            <circle cx="42" cy="48" r="19" /><path d="m42 35 4 9 9 4-9 4-4 9-4-9-9-4 9-4z" />
            <path d="M72 42h57M72 50h46M72 58h29" />
            @break
        @case('standard.navigation')
            <rect x="12" y="27" width="136" height="42" rx="4" />
            <rect x="22" y="38" width="19" height="19" rx="3" /><path d="M51 47h16m9 0h16m9 0h16" />
            <rect x="125" y="40" width="13" height="14" rx="3" />
            @break
        @case('standard.hero')
        @case('demo.hero')
            <rect x="12" y="12" width="136" height="72" rx="4" />
            <path d="M69 26h22m-49 12h76m-68 9h60m-53 9h46" />
            <rect x="62" y="64" width="36" height="10" rx="3" />
            @break
        @case('standard.text')
        @case('demo.text')
            <path d="M25 23h64" /><path d="M25 38h110M25 47h110M25 56h94M25 69h110M25 78h71" class="sb-illustration-muted" />
            @break
        @case('standard.image')
            <rect x="22" y="14" width="116" height="67" rx="4" />
            <circle cx="112" cy="31" r="6" /><path d="m25 72 30-30 23 22 15-15 42 23" />
            @break
        @case('standard.image-text')
        @case('demo.image-text')
            <rect x="15" y="20" width="56" height="55" rx="3" />
            <circle cx="56" cy="33" r="4" /><path d="m18 69 15-16 11 10 8-7 16 13" />
            <path d="M84 28h55M84 40h53M84 48h45M84 56h49" /><rect x="84" y="64" width="31" height="10" rx="3" />
            @break
        @case('standard.cards')
            <path d="M18 16h54" class="sb-illustration-muted" />
            <rect x="18" y="26" width="37" height="51" rx="3" /><rect x="62" y="26" width="36" height="51" rx="3" /><rect x="105" y="26" width="37" height="51" rx="3" />
            <circle cx="36" cy="39" r="6" /><circle cx="80" cy="39" r="6" /><circle cx="124" cy="39" r="6" />
            <path d="M25 54h23m-23 7h18m26-7h22m-22 7h17m26-7h23m-23 7h18" />
            @break
        @case('standard.cta')
        @case('demo.cta')
            <rect x="13" y="22" width="134" height="52" rx="4" />
            <path d="M25 37h63M25 46h51M25 54h43" /><rect x="101" y="39" width="33" height="16" rx="4" />
            @break
        @case('standard.faq')
            <path d="M20 19h54M20 32h120M20 51h120M20 70h120M20 79h120" class="sb-illustration-muted" />
            <path d="M25 42h73m-73 19h63m-63 19h54M129 37v10m-5-5h10m-5 14v10m-5-5h10m-5 14v10m-5-5h10" />
            @break
        @case('standard.contact-details')
            <path d="M24 21h55" class="sb-illustration-muted" />
            <circle cx="30" cy="40" r="6" /><path d="M30 34v12m-6-6h12M47 37h34m-34 7h70" />
            <rect x="24" y="53" width="12" height="9" rx="1" /><path d="m24 54 6 4 6-4m11 1h35m-35 7h64" />
            <path d="M25 72c2 5 5 8 10 9l3-3-4-3-2 2c-2-1-3-2-4-4l2-2-3-3zM47 72h32m-32 7h53" />
            @break
        @case('standard.section')
            <rect x="12" y="17" width="136" height="62" rx="4" stroke-dasharray="5 4" />
            <path d="M31 31h60m-60 10h98m-98 8h76" /><rect x="31" y="58" width="98" height="9" rx="2" class="sb-illustration-muted" />
            @break
        @case('standard.container')
            <path d="M12 18h136M12 78h136" class="sb-illustration-muted" />
            <rect x="27" y="24" width="106" height="48" rx="4" stroke-dasharray="5 4" />
            <rect x="39" y="35" width="82" height="26" rx="2" /><path d="M48 44h43m-43 8h62" />
            @break
        @case('standard.columns')
            <rect x="15" y="19" width="130" height="58" rx="4" class="sb-illustration-muted" />
            <rect x="22" y="26" width="34" height="44" rx="3" /><rect x="63" y="26" width="34" height="44" rx="3" /><rect x="104" y="26" width="34" height="44" rx="3" />
            <path d="M29 39h20m-20 8h20m-20 8h14m27-16h20m-20 8h20m-20 8h14m27-16h20m-20 8h20m-20 8h14" />
            @break
        @case('standard.column')
            <rect x="15" y="19" width="130" height="58" rx="4" class="sb-illustration-muted" />
            <g class="sb-illustration-muted"><rect x="22" y="26" width="34" height="44" rx="3" /><rect x="104" y="26" width="34" height="44" rx="3" /></g>
            <rect x="63" y="26" width="34" height="44" rx="3" /><path d="M70 39h20m-20 8h20m-20 8h14" />
            @break
        @default
            <rect x="25" y="19" width="110" height="58" rx="4" />
            <path d="M38 31h54M38 42h83M38 51h67M38 60h38" />
    @endswitch
</svg>
