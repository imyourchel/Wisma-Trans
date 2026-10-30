{{--
    Halaman 500 mandiri: tanpa layout, tanpa basis data (view composer), tanpa Vite. Kalau penyebab errornya
    justru basis data atau aset, halaman ini tetap tampil. Warna mengikuti token desain (cream, brown, clay).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terjadi kesalahan · Wisma Trans</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; background: #F6F0E6; color: #2E2218; font-family: Inter, "Segoe UI", Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        header { background: #4E3423; min-height: 64px; display: flex; align-items: center; padding: 8px 24px; }
        header a { color: #F6F0E6; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; text-decoration: none; font-size: 15px; }
        main { position: relative; overflow: hidden; isolation: isolate; min-height: calc(100dvh - 64px); display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 80px 16px; text-align: center; }
        .latar { position: absolute; inset: 50% 0 auto; transform: translateY(-50%); z-index: -1; font-size: min(42vw, 380px); font-weight: 800; line-height: 1; letter-spacing: -.04em; color: rgba(181, 113, 74, .06); user-select: none; pointer-events: none; }
        .alis { display: flex; align-items: center; gap: 12px; font-size: 11px; font-weight: 600; letter-spacing: .32em; text-transform: uppercase; color: #B5714A; }
        .alis span { display: block; width: 32px; height: 1px; background: rgba(181, 113, 74, .5); }
        h1 { margin: 20px 0 0; max-width: 640px; font-size: clamp(32px, 6vw, 44px); font-weight: 800; line-height: 1.1; letter-spacing: .03em; text-transform: uppercase; }
        p { margin: 16px 0 0; max-width: 480px; font-size: 15px; line-height: 1.65; color: #7A6A58; }
        .tombol { margin-top: 32px; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; width: 100%; }
        .tombol a { display: inline-flex; align-items: center; justify-content: center; height: 48px; padding: 0 28px; border-radius: 2px; font-size: 12px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; text-decoration: none; min-width: 220px; }
        .utama { background: #B5714A; color: #F6F0E6; }
        .utama:hover { background: #9C5F3C; }
        .garis { border: 1px solid #D9CBAE; color: #4E3423; }
        .garis:hover { background: #EFE6D3; }
    </style>
</head>
<body>
    <header><a href="{{ url('/') }}">Wisma Trans</a></header>
    <main data-halaman-error="500">
        <div class="latar" aria-hidden="true">500</div>
        <div class="alis"><span></span>Error 500<span></span></div>
        <h1>Terjadi kesalahan</h1>
        <p>Maaf, ada masalah di server kami. Tim kami sudah mencatatnya. Silakan coba lagi beberapa saat lagi.</p>
        <div class="tombol">
            <a href="{{ url('/') }}" class="utama">Kembali ke beranda</a>
            <a href="javascript:location.reload()" class="garis">Coba lagi</a>
        </div>
    </main>
</body>
</html>
