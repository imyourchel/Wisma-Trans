@php
    // Formulir dikirim setelah sesi habis (token CSRF tidak berlaku). Halaman sebelumnya dibuka ulang supaya mendapat token baru.
    $kembali = url()->previous() !== url()->current() ? url()->previous() : route('home');
@endphp
@include('errors.layout', [
    'kode' => '419',
    'polos' => true,
    'judul' => 'Sesi berakhir',
    'pesan' => 'Sesi Anda berakhir karena halaman terlalu lama dibiarkan terbuka. Buka kembali halaman sebelumnya, lalu kirim lagi.',
    'tombol' => [
        ['label' => 'Buka halaman sebelumnya', 'url' => $kembali, 'utama' => true],
        ['label' => 'Ke beranda', 'url' => route('home')],
    ],
])
