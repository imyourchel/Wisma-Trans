@props(['nama', 'label', 'bantuan' => null, 'tag' => 'label'])
{{-- Versi Livewire dari x-staf.field: pesan galat pendek tampil di samping label, pesan panjang utuh di bawah isian --}}
@php $panjang = $errors->has($nama) && mb_strlen($errors->first($nama)) > 60; @endphp
<{{ $tag }} {{ $attributes->merge(['class' => 'block min-w-0']) }}>
    <span class="mb-1.5 flex items-baseline justify-between gap-3">
        <span class="wt-field-label">{{ $label }}</span>
        @if ($errors->has($nama) && ! $panjang)
            <span class="min-w-0 truncate text-[11px] font-medium text-status-dirty" title="{{ $errors->first($nama) }}" data-galat>{{ $errors->first($nama) }}</span>
        @endif
    </span>
    {{ $slot }}
    @if ($panjang)
        <span class="mt-1.5 block text-xs font-medium leading-normal text-status-dirty" data-galat>{{ $errors->first($nama) }}</span>
    @endif
    @if ($bantuan)
        <span class="mt-1.5 block text-xs leading-normal text-ink-500">{{ $bantuan }}</span>
    @endif
</{{ $tag }}>
