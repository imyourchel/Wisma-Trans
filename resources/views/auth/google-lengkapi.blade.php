{{-- Langkah terakhir daftar dengan Google, tampil sebagai modal di atas beranda seperti modal Masuk/Daftar.
     Menutup modal = membatalkan pendaftaran. --}}
<div x-data x-init="document.body.style.overflow = 'hidden'" @keydown.escape.window="$refs.batal.requestSubmit()">
    <div class="fixed inset-0 z-[370] bg-[rgba(62,42,28,.44)] backdrop-blur-[2px]"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="judul-lengkapi"
         class="fixed inset-0 z-[375] m-auto grid h-fit max-h-[calc(100dvh-24px)] w-[900px] max-w-[calc(100vw-24px)] animate-wt-fade overflow-y-auto overflow-x-hidden rounded-2xl bg-cream-50 shadow-wt-lg min-[760px]:min-h-[560px] min-[760px]:grid-cols-2">
        <div class="relative hidden overflow-hidden bg-brown-800 min-[760px]:block">
            <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-[.22]">
            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(62,42,28,.55),rgba(62,42,28,.92))]"></div>
            <div class="absolute inset-4 rounded-[10px] border border-[rgba(242,235,220,.22)]"></div>
            <img src="{{ asset('ds/logo/wismatrans-landscape-cream-clear.png') }}" alt="Wisma Trans"
                 class="absolute inset-x-0 top-11 mx-auto block h-[38px] w-auto max-w-[calc(100%-80px)] object-contain">
            <div class="absolute inset-0 flex flex-col items-center justify-center gap-3.5 px-14 text-center">
                <div class="h-px w-7 bg-sand-300"></div>
                <div class="font-display text-[22px] font-semibold uppercase leading-[1.4] tracking-[.08em] text-cream-150">Satu langkah lagi</div>
                <div class="max-w-[260px] text-sm leading-[1.7] text-[rgba(242,235,220,.78)]">Nomor telepon digunakan untuk mengirim konfirmasi pesanan melalui WhatsApp.</div>
            </div>
            <div class="absolute inset-x-0 bottom-10 text-center font-display text-[10px] font-semibold uppercase tracking-[.24em] text-sand-300">Simple · Clean · Favour</div>
        </div>

        <div class="relative flex flex-col justify-center gap-4 px-6 py-8 sm:px-10">
            <button type="button" @click="$refs.batal.requestSubmit()" title="Batal"
                    class="absolute right-4 top-4 flex h-[34px] w-[34px] items-center justify-center rounded-lg border-0 bg-transparent hover:bg-cream-200">
                <img src="{{ asset('ds/icons/x-olive.svg') }}" alt="Batal" width="16" height="16">
            </button>

            <div class="font-display text-[10px] font-bold uppercase tracking-[.24em] text-clay-500">Daftar dengan Google</div>
            <div class="flex flex-col gap-0.5">
                <h1 id="judul-lengkapi" class="m-0 font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Lengkapi pendaftaran</h1>
                <div class="text-[13px] text-ink-500">Akun Google Anda belum terdaftar. Silakan lengkapi data berikut untuk membuat akun tamu.</div>
            </div>

            <form method="POST" action="{{ route('auth.google.store') }}" class="flex flex-col gap-3" novalidate>
                @csrf
                <div>
                    <span class="wt-field-label">Email</span>
                    <span class="wt-line-field h-10 text-ink-500">
                        <img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                        <input type="email" value="{{ $googlePending['email'] }}" readonly class="!text-ink-500">
                    </span>
                </div>
                @foreach ([
                    ['name', 'Nama lengkap', 'text', 'user-muted', 'Nama lengkap Anda', 'name', old('name', $googlePending['name'])],
                    ['phone', 'Nomor telepon', 'tel', 'phone-muted', '08xxxxxxxxxx', 'tel', old('phone')],
                ] as [$field, $label, $type, $icon, $placeholder, $autocomplete, $nilai])
                    <label class="block">
                        {{-- Galat di samping label, sama seperti formulir daftar --}}
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="wt-field-label flex-none">{{ $label }}</span>
                            @error($field)<span class="min-w-0 truncate text-[11px] font-medium text-status-dirty" title="{{ $message }}">{{ $message }}</span>@enderror
                        </span>
                        <span @class(['wt-line-field h-10', 'is-invalid' => $errors->has($field)])>
                            <img src="{{ asset("ds/icons/{$icon}.svg") }}" alt="" width="16" height="16" class="flex-none">
                            <input type="{{ $type }}" name="{{ $field }}" value="{{ $nilai }}" placeholder="{{ $placeholder }}" autocomplete="{{ $autocomplete }}" required
                                   @if ($field === ($errors->has('name') ? 'name' : 'phone')) autofocus @endif>
                        </span>
                    </label>
                @endforeach
                <div class="-mt-1 text-xs leading-[1.6] text-ink-500">Jika Anda pernah memesan dengan nomor ini, pesanan sebelumnya akan otomatis terhubung ke akun Anda.</div>
                <button type="submit" class="mt-1 h-11 w-full rounded-[10px] border-0 bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600">Buat akun</button>
            </form>

            <form x-ref="batal" method="POST" action="{{ route('auth.google.batal') }}" class="text-center">
                @csrf
                <button type="submit" class="border-0 bg-transparent p-0 text-[13px] text-olive-600 hover:text-brown-800">Batal</button>
            </form>
        </div>
    </div>
</div>
