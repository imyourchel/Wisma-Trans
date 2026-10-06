{{-- Email notifikasi Wisma Trans: sapaan, isi, tombol, lalu keterangan dalam kotak dan salam penutup --}}
<x-mail::message>
<p class="eyebrow">{{ $subject ?? 'Wisma Trans' }}</p>

# {{ $greeting ?: 'Halo!' }}

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="match ($level) { 'success', 'error' => $level, default => 'primary' }">
{{ $actionText }}
</x-mail::button>
@endisset

@if (count($outroLines))
<x-mail::panel>
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
</x-mail::panel>
@endif

@php [$salam, $pengirim] = array_pad(explode(', ', $salutation ?: 'Salam hangat, Wisma Trans', 2), 2, null); @endphp
<p class="salam">{{ $salam }}{{ $pengirim ? ',' : '' }}<br><strong>{{ $pengirim ?? '' }}</strong></p>

@isset($actionText)
<x-slot:subcopy>
Tombol **{{ $actionText }}** tidak bisa diklik? Salin tautan berikut lalu buka di browser Anda:
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
