@php
    $detik = (int) (($exception ?? null)?->getHeaders()['Retry-After'] ?? 60);
    $tunggu = $detik <= 60 ? 'satu menit' : (int) ceil($detik / 60).' menit';
@endphp
@include('errors.layout', [
    'kode' => '429',
    'judul' => 'Terlalu banyak percobaan',
    'pesan' => "Permintaan dari perangkat Anda terlalu sering. Tunggu {$tunggu}, lalu coba lagi.",
    'tombol' => [
        ['label' => 'Kembali ke beranda', 'url' => route('home'), 'utama' => true],
        ['label' => 'Hubungi kami', 'url' => 'https://wa.me/'.$wisma['whatsapp'].'?text='.rawurlencode('Halo Wisma Trans, saya butuh bantuan.'), 'wa' => true],
    ],
])
