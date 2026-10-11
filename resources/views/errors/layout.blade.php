{{--
    Kerangka bersama halaman error (403, 404, 419, 429). Variabel: $kode, $judul, $pesan, $tombol (daftar
    ['label', 'url', 'utama' => bool, 'wa' => bool]), dan $polos.
    $polos = true: dokumen ringan tanpa navbar dan tanpa skrip (hanya CSS), supaya tetap rapi saat Livewire
    menampilkannya di dalam modal (iframe) dan tidak memuat ulang komponen di dalamnya. 404 dan 429 memakai layout tamu.
--}}
@if ($polos ?? false)
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $judul }} · Wisma Trans</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-cream-100 font-sans text-ink-900 antialiased">
    <header class="bg-brown-800">
        <div class="flex min-h-[64px] items-center px-4 py-2 sm:px-6">
            <a href="{{ route('home') }}" target="_top" class="flex-none"><img src="{{ asset('ds/logo/DC-wismatrans-landscape.png') }}" alt="Wisma Trans" class="h-10 w-auto"></a>
        </div>
    </header>
    <main>@include('errors.partials.isi')</main>
</body>
</html>
@else
<x-layouts.guest :title="$judul">
    @include('errors.partials.isi')
</x-layouts.guest>
@endif
