<x-mail::layout>
{{-- Kepala: nama wisma di pita cokelat --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
WISMA TRANS
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Kaki: alamat dan kontak resepsionis --}}
<x-slot:footer>
<x-mail::footer>
**Wisma Trans**<br>
Jln. Soekarno Hatta No. 17, Malili, Luwu Timur, Sulawesi Selatan<br>
Resepsionis 24 jam · [0822-9222-4111](https://wa.me/6282292224111)

<span class="tagline">Simple · Clean · Favour</span>

Email ini dikirim otomatis, mohon tidak dibalas. © {{ date('Y') }} Wisma Trans.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
