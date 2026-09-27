@props(['anak'])
{{-- Daftar submenu bergaris cabang: garis tegak turun dari ikon grup, tiap item disambung lengkung kecil.
     Garis tegak berhenti di tengah item terakhir. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    @foreach ($anak as $item)
        <div class="relative pb-0.5 pl-4">
            @unless ($loop->last)
                <span class="absolute inset-y-0 left-0 border-l border-[rgba(242,235,220,.22)]" aria-hidden="true"></span>
            @endunless
            <span class="absolute left-0 top-0 h-[calc(50%-1px)] w-2.5 rounded-bl-md border-b border-l border-[rgba(242,235,220,.22)]" aria-hidden="true"></span>
            <a href="{{ $item['url'] }}"
               @class(['block truncate rounded px-3 py-[7px] text-[13px] hover:no-underline',
                   'bg-[rgba(242,235,220,.16)] font-semibold text-cream-50' => $item['aktif'],
                   'text-[rgba(242,235,220,.66)] hover:bg-[rgba(242,235,220,.10)] hover:text-cream-50' => ! $item['aktif']])
               @if ($item['aktif']) aria-current="page" @endif>{{ $item['label'] }}</a>
        </div>
    @endforeach
</div>
