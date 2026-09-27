@props(['terang' => false])
{{-- Hiasan pemisah: garis tipis dengan belah ketupat di tengah --}}
<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-3']) }} aria-hidden="true">
    <span @class(['h-px w-10', 'bg-sand-300/50' => $terang, 'bg-clay-500/40' => ! $terang])></span>
    <span @class(['h-1.5 w-1.5 rotate-45', 'bg-sand-300' => $terang, 'bg-clay-500' => ! $terang])></span>
    <span @class(['h-px w-10', 'bg-sand-300/50' => $terang, 'bg-clay-500/40' => ! $terang])></span>
</div>
