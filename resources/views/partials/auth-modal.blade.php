@php
    // Buka modal otomatis bila ada galat validasi, atau saat diakses lewat /masuk dan /daftar
    $authMode = $errors->register->any() ? 'daftar'
        : ($errors->login->any() ? 'masuk' : request()->route()?->parameter('authModal'));

    // Bekal formulir daftar: isian lama (bila galat datang dari server) dan aturan yang sama dengan RegisterController
    $daftarAwal = [
        'isi' => collect(['name', 'phone', 'email'])
            ->mapWithKeys(fn ($f) => [$f => $errors->register->any() ? (string) old($f) : ''])
            ->all(),
        'galat' => (object) collect($errors->register->messages())->map(fn ($pesan) => $pesan[0])->all(),
        'polaNama' => \App\Support\PersonName::PATTERN,
        'pesanNama' => \App\Support\PersonName::MESSAGE,
        'polaHp' => \App\Support\Phone::MOBILE_PATTERN,
        'polaEmail' => \App\Rules\EmailWajar::PATTERN,
        'salahKetik' => \App\Rules\EmailWajar::TYPO_DOMAINS,
    ];
@endphp

<div x-data="{
        open: @js((bool) $authMode),
        mode: @js($authMode ?? 'masuk'),
        sempit: window.innerWidth < 760,
        lihat: false,
        lupa: false,
        get up() { return this.mode === 'daftar' },
        buka(mode) {
            this.mode = mode;
            this.lupa = false;
            this.open = true;
            this.$nextTick(() => this.fokus());
        },
        tutup() {
            this.open = false;
            if (['/masuk', '/daftar'].includes(location.pathname)) history.replaceState(null, '', @js(route('home')));
        },
        fokus() {
            const el = document.getElementById(this.up ? 'daftar-name' : 'masuk-login');
            if (el) setTimeout(() => el.focus({ preventScroll: true }), 50);
        },
        bukaLupa(email) {
            this.mode = 'masuk';
            this.lupa = true;
            this.open = true;
            this.$dispatch('lupa-sandi', { email });
        },
        toast(teks, warna) { Alpine.store('toasts').tampil(teks, warna) },
     }"
     x-init="if (new URLSearchParams(location.search).has('lupa')) { $nextTick(() => bukaLupa('')) } else if (open) { fokus() }"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''"
     @buka-akun.window="buka($event.detail.mode)"
     @keydown.escape.window="open && tutup()"
     @resize.window="sempit = window.innerWidth < 760">
    <div x-show="open" x-cloak>
            <div @click="tutup()" class="fixed inset-0 z-[370] bg-[rgba(62,42,28,.44)] backdrop-blur-[2px]"></div>

            <div role="dialog" aria-modal="true" :aria-label="up ? 'Buat akun' : (lupa ? 'Lupa kata sandi' : 'Masuk')"
                 :class="up ? 'min-[760px]:h-[650px]' : 'min-[760px]:h-[560px]'"
                 style="transition:height 650ms var(--ease-slide)"
                 class="fixed inset-0 z-[375] m-auto h-[min(740px,calc(100vh-24px))] min-[760px]:max-h-[calc(100vh-24px)] w-[900px] max-w-[calc(100vw-24px)] animate-wt-fade overflow-hidden rounded-2xl bg-cream-50 shadow-wt-lg">
                <div class="absolute inset-0 overflow-y-auto overflow-x-hidden">
                    <div class="relative h-full min-h-[500px]">

                        {{-- Panel yang sedang tersembunyi diberi overflow-hidden agar isinya tidak ikut membuat modal bisa digulir --}}
                        {{-- Panel masuk --}}
                        <div class="absolute inset-y-0 flex flex-col gap-2.5 px-6 py-5 [justify-content:safe_center] sm:px-10"
                             style="transition:opacity 450ms var(--ease-wt),transform 650ms var(--ease-slide)"
                             :style="{ left: sempit ? '0' : '50%', width: sempit ? '100%' : '50%', opacity: up ? 0 : 1, pointerEvents: up ? 'none' : 'auto', transform: `translateX(${sempit ? '0' : (up ? '-40px' : '0')})` }"
                             :class="up && 'overflow-hidden'"
                             :inert="up">
                            <div x-show="!lupa" class="contents">
                            <div class="flex-none font-display text-[10px] font-bold uppercase tracking-[.24em] text-clay-500">Wisma Trans · Malili</div>
                            <div class="flex flex-none flex-col gap-0.5">
                                <div class="font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Selamat datang kembali</div>
                                <div class="text-[13px] text-ink-500">Silakan masuk untuk melihat pesanan kamar, restoran, dan aula Anda.</div>
                            </div>

                            {{-- Dikirim lewat fetch: kata sandi salah tidak memuat ulang halaman --}}
                            <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-2.5" novalidate
                                  x-data="formMasuk(@js(['login' => $errors->login->any() ? (string) old('login') : '', 'galat' => (string) $errors->login->first()]))"
                                  @submit.prevent="kirim($el)" :class="goyang && 'wt-goyang'">
                                @csrf
                                <label class="block flex-none">
                                    <span class="wt-field-label">Email atau nomor telepon</span>
                                    <span class="wt-line-field h-[42px]" :class="salah.login && 'is-invalid'">
                                        <img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                                        <input id="masuk-login" x-ref="login" type="text" name="login" x-model="isi.login" @input="bersihkan()" placeholder="Contoh: nama@email.com" autocomplete="username" required>
                                    </span>
                                </label>
                                <label class="block flex-none">
                                    <span class="wt-field-label">Kata sandi</span>
                                    <span class="wt-line-field h-[42px]" :class="salah.password && 'is-invalid'">
                                        <img src="{{ asset('ds/icons/lock-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                                        <input x-ref="sandi" :type="lihat ? 'text' : 'password'" type="password" name="password" x-model="isi.password" @input="bersihkan()" placeholder="Masukkan kata sandi" autocomplete="current-password" required>
                                        <button type="button" @click="lihat = !lihat" :title="lihat ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'" class="flex border-0 bg-transparent p-0">
                                            <img x-show="!lihat" src="{{ asset('ds/icons/eye-muted.svg') }}" alt="" width="16" height="16">
                                            <img x-show="lihat" x-cloak src="{{ asset('ds/icons/eye-off-muted.svg') }}" alt="" width="16" height="16">
                                        </button>
                                    </span>
                                </label>
                                <div class="flex flex-none items-center justify-between gap-3">
                                    <label class="flex cursor-pointer items-center gap-2 text-[13px] text-ink-900">
                                        <input type="checkbox" name="remember" value="1" checked class="m-0 h-4 w-4 accent-clay-500">Ingat saya
                                    </label>
                                    <button type="button" @click="bukaLupa(isi.login.includes('@') ? isi.login.trim() : '')"
                                            class="border-0 bg-transparent p-0 text-[13px] font-medium text-olive-600 hover:text-brown-800">Lupa kata sandi?</button>
                                </div>
                                <div x-show="galat" x-text="galat" role="alert" @unless ($errors->login->any()) x-cloak @endunless
                                     class="flex-none text-[13px] text-status-dirty">{{ $errors->login->first() }}</div>
                                <button type="submit" :disabled="proses" x-text="proses ? 'Memproses…' : 'Masuk'"
                                        class="h-11 w-full flex-none rounded-[10px] border-0 bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Masuk</button>
                            </form>

                            <div class="flex flex-none items-center gap-3 text-xs text-ink-500"><span class="h-px flex-1 bg-cream-300"></span>atau<span class="h-px flex-1 bg-cream-300"></span></div>
                            <a href="{{ route('auth.google') }}"
                               class="flex h-11 w-full flex-none items-center justify-center gap-2.5 rounded-[10px] border border-cream-300 bg-cream-50 text-sm font-medium text-ink-900 hover:border-[#D8CBB0] hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                                @include('partials.google-icon')
                                Masuk dengan Google
                            </a>
                            <div class="flex-none text-center text-[13px] text-ink-500">Belum punya akun?
                                <button type="button" @click="buka('daftar')" class="border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-500">Daftar</button>
                            </div>
                            </div>

                            {{-- Lupa kata sandi: tautan dikirim ke email, jawaban sama untuk email terdaftar maupun tidak --}}
                            <div x-show="lupa" x-cloak class="contents" x-data="formLupa" @lupa-sandi.window="mulai($event.detail.email)">
                                <div class="flex-none font-display text-[10px] font-bold uppercase tracking-[.24em] text-clay-500">Lupa kata sandi</div>
                                <div x-show="!terkirim" class="contents">
                                    <div class="flex flex-none flex-col gap-0.5">
                                        <div class="font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Atur ulang kata sandi</div>
                                        <div class="text-[13px] text-ink-500">Masukkan email akun Anda. Kami akan mengirim tautan untuk membuat kata sandi baru.</div>
                                    </div>
                                    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-2.5" novalidate @submit.prevent="kirim($el)" :class="goyang && 'wt-goyang'">
                                        @csrf
                                        <label class="block flex-none">
                                            <span class="wt-field-label">Email</span>
                                            <span class="wt-line-field h-[42px]" :class="galat && 'is-invalid'">
                                                <img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                                                <input id="lupa-email" x-ref="email" type="email" name="email" x-model="email" @input="galat = ''" placeholder="Contoh: nama@email.com" autocomplete="email" required>
                                            </span>
                                        </label>
                                        <div x-show="galat" x-text="galat" x-cloak role="alert" class="flex-none text-[13px] text-status-dirty"></div>
                                        <button type="submit" :disabled="proses" x-text="proses ? 'Mengirim…' : 'Kirim tautan'"
                                                class="h-11 w-full flex-none rounded-[10px] border-0 bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Kirim tautan</button>
                                    </form>
                                </div>
                                <div x-show="terkirim" x-cloak class="contents">
                                    <div class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-[rgba(92,158,103,.16)]">
                                        <img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="22" height="22">
                                    </div>
                                    <div class="flex flex-none flex-col gap-1">
                                        <div class="font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Periksa email Anda</div>
                                        <div class="text-[13px] leading-relaxed text-ink-500">
                                            Jika <strong class="font-semibold text-ink-900" x-text="email"></strong> terdaftar, tautan untuk membuat kata sandi baru sudah dikirim.
                                            Tautan berlaku 60 menit. Bila tidak ada di kotak masuk, periksa folder Spam.
                                        </div>
                                    </div>
                                    <button type="button" @click="terkirim = false; $nextTick(() => $refs.email.focus())" class="self-start border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-500">Kirim ke email lain</button>
                                </div>
                                <div class="flex-none rounded-lg bg-cream-200 px-3.5 py-2.5 text-xs leading-relaxed text-ink-500">
                                    Akun Anda tidak memakai email, atau email tidak diterima? Hubungi resepsionis di {{ $wisma['telepon'] }}.
                                </div>
                                <button type="button" @click="lupa = false; fokus()" class="flex-none self-center border-0 bg-transparent p-0 text-[13px] font-semibold text-clay-500">Kembali ke halaman masuk</button>
                            </div>
                        </div>

                        {{-- Panel daftar --}}
                        <div class="absolute inset-y-0 flex flex-col gap-2.5 px-6 py-5 [justify-content:safe_center] sm:px-10"
                             style="transition:opacity 450ms var(--ease-wt),transform 650ms var(--ease-slide)"
                             :style="{ left: '0', width: sempit ? '100%' : '50%', opacity: up ? 1 : 0, pointerEvents: up ? 'auto' : 'none', transform: `translateX(${sempit ? '0' : (up ? '0' : '40px')})` }"
                             :class="!up && 'overflow-hidden'"
                             :inert="!up">
                            <div class="flex flex-none flex-col gap-0.5">
                                <div class="font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Buat akun</div>
                                <div class="text-[13px] text-ink-500">Lengkapi data berikut untuk membuat akun tamu.</div>
                            </div>

                            {{-- Dikirim lewat fetch: bila ada yang salah, halaman tidak dimuat ulang dan isian tetap ada.
                                 Pesan galat tampil di samping label agar tinggi kartu tidak berubah. --}}
                            <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-3" novalidate
                                  x-data="formDaftar(@js($daftarAwal))" @submit.prevent="kirim($el)">
                                @csrf
                                <div class="flex flex-none flex-col gap-2.5">
                                    @foreach ([
                                        ['name', 'Nama lengkap', 'text', 'user-muted', 'Nama lengkap Anda', 'name', null],
                                        ['phone', 'Nomor telepon', 'tel', 'phone-muted', 'Contoh: 081234567890', 'tel', null],
                                        ['email', 'Email', 'email', 'mail-muted', 'Contoh: nama@email.com', 'email', null],
                                        ['password', 'Kata sandi', 'password', 'lock-muted', 'Minimal 8 karakter', 'new-password', 'lihat1'],
                                        ['password_confirmation', 'Konfirmasi kata sandi', 'password', 'lock-muted', 'Ulangi kata sandi', 'new-password', 'lihat2'],
                                    ] as [$field, $label, $type, $icon, $placeholder, $autocomplete, $lihat])
                                        @php($galatServer = $errors->register->first($field))
                                        <label class="block flex-none">
                                            <span class="flex items-baseline justify-between gap-3">
                                                <span class="wt-field-label flex-none">{{ $label }}</span>
                                                <span x-show="pesan('{{ $field }}')" x-text="pesan('{{ $field }}')" :title="pesan('{{ $field }}')" @unless ($galatServer) x-cloak @endunless
                                                      class="min-w-0 truncate text-[11px] font-medium text-status-dirty">{{ $galatServer }}</span>
                                            </span>
                                            <span @class(['wt-line-field h-9', 'is-invalid' => $galatServer]) :class="pesan('{{ $field }}') && 'is-invalid'">
                                                <img src="{{ asset("ds/icons/{$icon}.svg") }}" alt="" width="16" height="16" class="flex-none">
                                                <input id="daftar-{{ $field }}" x-model="isi.{{ $field }}" @input="hapus('{{ $field }}')"
                                                       @if ($lihat) :type="{{ $lihat }} ? 'text' : 'password'" @endif
                                                       type="{{ $type }}" name="{{ $field }}" value="{{ $daftarAwal['isi'][$field] ?? '' }}" placeholder="{{ $placeholder }}" autocomplete="{{ $autocomplete }}" required>
                                                @if ($lihat)
                                                    <button type="button" @click="{{ $lihat }} = !{{ $lihat }}" :title="{{ $lihat }} ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'" class="flex border-0 bg-transparent p-0">
                                                        <img x-show="!{{ $lihat }}" src="{{ asset('ds/icons/eye-muted.svg') }}" alt="" width="16" height="16">
                                                        <img x-show="{{ $lihat }}" x-cloak src="{{ asset('ds/icons/eye-off-muted.svg') }}" alt="" width="16" height="16">
                                                    </button>
                                                @endif
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                <button type="submit" :disabled="proses" x-text="proses ? 'Memproses…' : 'Buat akun'"
                                        class="h-11 w-full flex-none rounded-[10px] border-0 bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Buat akun</button>
                            </form>

                            {{-- Akun Google baru diarahkan melengkapi nomor telepon, lalu dibuat sebagai customer --}}
                            <div class="flex flex-none items-center gap-3 text-xs text-ink-500"><span class="h-px flex-1 bg-cream-300"></span>atau<span class="h-px flex-1 bg-cream-300"></span></div>
                            <a href="{{ route('auth.google') }}"
                               class="flex h-10 w-full flex-none items-center justify-center gap-2.5 rounded-[10px] border border-cream-300 bg-cream-50 text-sm font-medium text-ink-900 hover:border-[#D8CBB0] hover:bg-row-hover hover:text-ink-900 hover:no-underline">
                                @include('partials.google-icon')
                                Daftar dengan Google
                            </a>

                            <div class="flex-none text-center text-xs leading-[1.6] text-ink-500">
                                Dengan mendaftar, Anda menyetujui <a href="#" class="font-semibold text-olive-600">Syarat dan Ketentuan</a> serta <a href="#" class="font-semibold text-olive-600">Kebijakan Privasi</a>.
                                {{-- Di layar lebar tombol Masuk sudah ada di panel foto --}}
                                <div x-show="sempit" x-cloak class="mt-1">Sudah punya akun?
                                    <button type="button" @click="buka('masuk')" class="border-0 bg-transparent p-0 text-xs font-semibold text-clay-500">Masuk di sini</button>
                                </div>
                            </div>
                        </div>

                        {{-- Panel foto yang bergeser --}}
                        <div x-show="!sempit" class="absolute inset-y-0 z-[2] w-1/2 overflow-hidden bg-brown-800"
                             style="transition:left 650ms var(--ease-slide)" :style="{ left: up ? '50%' : '0' }">
                            <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-[.22]">
                            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(62,42,28,.55),rgba(62,42,28,.92))]"></div>
                            <div class="absolute inset-4 rounded-[10px] border border-[rgba(242,235,220,.22)]"></div>
                            <img src="{{ asset('ds/logo/wismatrans-landscape-cream-clear.png') }}" alt="Wisma Trans"
                                 class="absolute inset-x-0 top-11 mx-auto block h-[38px] w-auto max-w-[calc(100%-80px)] object-contain">
                            <div class="absolute inset-0 flex flex-col items-center justify-center px-14 text-center">
                                <div class="grid w-full">
                                    <div class="flex flex-col items-center gap-3.5 [grid-area:1/1]" style="transition:opacity 400ms ease" :style="{ opacity: up ? 0 : 1 }">
                                        <div class="h-px w-7 bg-sand-300"></div>
                                        <div class="font-display text-[22px] font-semibold uppercase leading-[1.4] tracking-[.08em] text-cream-150">Tamu baru?</div>
                                        <div class="max-w-[260px] text-sm leading-[1.7] text-[rgba(242,235,220,.78)]">Buat akun agar semua pesanan kamar, restoran, dan aula tersimpan di satu tempat.</div>
                                    </div>
                                    <div class="flex flex-col items-center gap-3.5 [grid-area:1/1]" style="transition:opacity 400ms ease" :style="{ opacity: up ? 1 : 0 }">
                                        <div class="h-px w-7 bg-sand-300"></div>
                                        <div class="font-display text-[22px] font-semibold uppercase leading-[1.4] tracking-[.08em] text-cream-150">Sudah punya akun?</div>
                                        <div class="max-w-[260px] text-sm leading-[1.7] text-[rgba(242,235,220,.78)]">Silakan masuk untuk melihat dan memantau pesanan Anda.</div>
                                    </div>
                                </div>
                                <button type="button" @click="buka(up ? 'masuk' : 'daftar')" x-text="up ? 'Masuk' : 'Daftar'"
                                        class="mt-[26px] h-11 min-w-40 rounded-[10px] border border-[rgba(242,235,220,.55)] bg-transparent px-[26px] font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-150 hover:bg-[rgba(242,235,220,.12)]"></button>
                            </div>
                            <div class="absolute inset-x-0 bottom-10 whitespace-nowrap text-center font-display text-[10px] font-semibold uppercase tracking-[.24em] text-sand-300">Simple · Clean · Favour</div>
                        </div>

                        <button type="button" @click="tutup()" title="Tutup"
                                class="absolute right-4 top-7 z-[3] flex h-[34px] w-[34px] items-center justify-center rounded-lg border-0 bg-transparent"
                                :class="up && !sempit ? 'hover:bg-[rgba(242,235,220,.12)]' : 'hover:bg-cream-200'">
                            <img x-show="up && !sempit" src="{{ asset('ds/icons/x-cream.svg') }}" alt="Tutup" width="16" height="16">
                            <img x-show="!(up && !sempit)" src="{{ asset('ds/icons/x-olive.svg') }}" alt="Tutup" width="16" height="16">
                        </button>
                    </div>
                </div>
            </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        // Aturan sama dengan RegisterController, supaya kesalahan umum langsung terlihat tanpa menunggu server
        // Lupa kata sandi: minta tautan atur ulang lewat email
        Alpine.data('formLupa', () => ({
            email: '',
            galat: '',
            proses: false,
            terkirim: false,
            goyang: false,

            mulai(email) {
                this.email = email;
                this.galat = '';
                this.terkirim = false;
                setTimeout(() => this.$refs.email.focus(), 80);
            },
            salah(pesan) {
                this.galat = pesan;
                this.goyang = false;
                this.$nextTick(() => { this.goyang = true; setTimeout(() => { this.goyang = false; }, 450); this.$refs.email.focus(); });
            },

            async kirim(form) {
                if (this.proses) return;
                const email = this.email.trim();
                if (!email) return this.salah('Email wajib diisi.');
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return this.salah('Format email tidak valid.');

                this.proses = true;
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });
                    if (res.ok) {
                        this.terkirim = true;
                    } else if (res.status === 422) {
                        this.salah((await res.json()).errors.email[0]);
                    } else if (res.status === 419) {
                        Alpine.store('toasts').tampil('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.', '#D4514A');
                    } else {
                        Alpine.store('toasts').tampil('Terjadi kesalahan pada server. Silakan coba lagi.', '#D4514A');
                    }
                } catch {
                    Alpine.store('toasts').tampil('Tidak dapat terhubung ke server. Periksa koneksi internet Anda, lalu coba lagi.', '#D4514A');
                }
                this.proses = false;
            },
        }));

        // Formulir masuk: galat tampil di tempat, email tetap terisi, kata sandi dipilih agar mudah diketik ulang
        Alpine.data('formMasuk', (awal) => ({
            isi: { login: awal.login, password: '' },
            galat: awal.galat,
            salah: { login: !!awal.galat, password: false },
            proses: false,
            goyang: false,

            bersihkan() {
                this.galat = '';
                this.salah = { login: false, password: false };
            },
            tampilkan(pesan, salah, fokus) {
                this.galat = pesan;
                this.salah = salah;
                this.goyang = false;
                this.$nextTick(() => {
                    this.goyang = true;
                    setTimeout(() => { this.goyang = false; }, 450);
                    fokus.focus();
                    fokus.select();
                });
            },

            async kirim(form) {
                if (this.proses) return;
                if (!this.isi.login.trim()) return this.tampilkan('Email atau nomor telepon wajib diisi.', { login: true }, this.$refs.login);
                if (!this.isi.password) return this.tampilkan('Kata sandi wajib diisi.', { password: true }, this.$refs.sandi);

                const gagal = (teks) => Alpine.store('toasts').tampil(teks, '#D4514A');
                this.proses = true;
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });
                    if (res.ok) {
                        window.location.href = (await res.json()).redirect;
                        return; // tombol tetap "Memproses…" sampai halaman berpindah
                    }
                    if (res.status === 422) {
                        const { errors } = await res.json();
                        if (errors.password && !errors.login) {
                            this.tampilkan(errors.password[0], { password: true }, this.$refs.sandi);
                        } else {
                            // Email/sandi salah atau terlalu banyak percobaan: tandai keduanya, ketik ulang sandi
                            this.tampilkan(errors.login[0], { login: true, password: true }, this.$refs.sandi);
                        }
                    } else if (res.status === 419) {
                        gagal('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.');
                    } else {
                        gagal('Terjadi kesalahan pada server. Silakan coba lagi.');
                    }
                } catch {
                    gagal('Tidak dapat terhubung ke server. Periksa koneksi internet Anda, lalu coba lagi.');
                }
                this.proses = false;
            },
        }));

        Alpine.data('formDaftar', (awal) => ({
            isi: { name: '', phone: '', email: '', password: '', password_confirmation: '', ...awal.isi },
            galat: awal.galat,
            proses: false,
            lihat1: false,
            lihat2: false,

            get beda() {
                return this.isi.password_confirmation !== '' && this.isi.password_confirmation !== this.isi.password;
            },

            pesan(field) {
                return this.galat[field] || (field === 'password_confirmation' && this.beda ? 'Tidak sesuai.' : '');
            },

            hapus(field) {
                delete this.galat[field];
            },

            periksa() {
                const g = {};
                const isi = this.isi;
                // Sama seperti PersonName::normalize(): apostrof ponsel jadi ' dan spasi dirapikan
                const nama = isi.name.replace(/[’‘ʼ`]/g, "'").replace(/\s+/g, ' ').trim();
                const email = isi.email.trim().toLowerCase();
                let hp = isi.phone.replace(/\D/g, '');
                if (hp.startsWith('62')) hp = '0' + hp.slice(2);
                else if (hp.startsWith('8')) hp = '0' + hp;

                if (!nama) g.name = 'Wajib diisi.';
                else if (nama.length < 3) g.name = 'Minimal 3 huruf.';
                else if (!new RegExp(awal.polaNama, 'u').test(nama)) g.name = awal.pesanNama;

                if (!isi.phone.trim()) g.phone = 'Wajib diisi.';
                else if (/[^\d\s+\-().]/.test(isi.phone) || !new RegExp(awal.polaHp).test(hp) || /(\d)\1{6,}/.test(hp.slice(4))) {
                    g.phone = 'Nomor tidak valid, contoh 0812xxxxxxxx.';
                }

                if (!email) g.email = 'Wajib diisi.';
                else if (!new RegExp(awal.polaEmail).test(email)) g.email = 'Format email tidak valid.';
                else {
                    const benar = awal.salahKetik[email.split('@')[1]];
                    if (benar) g.email = `Mungkin maksud Anda @${benar}?`;
                }

                if (!isi.password) g.password = 'Wajib diisi.';
                else if (isi.password.length < 8) g.password = 'Minimal 8 karakter.';
                else if (isi.password.length > 72) g.password = 'Maksimal 72 karakter.';

                if (!isi.password_confirmation) g.password_confirmation = 'Wajib diisi.';

                return g;
            },

            fokusGalat() {
                const pertama = ['name', 'phone', 'email', 'password', 'password_confirmation'].find((f) => this.pesan(f));
                if (pertama) document.getElementById('daftar-' + pertama)?.focus();
            },

            async kirim(form) {
                if (this.proses) return;
                this.galat = this.periksa();
                if (this.beda || Object.keys(this.galat).length) return this.fokusGalat();

                const gagal = (teks) => Alpine.store('toasts').tampil(teks, '#D4514A');
                this.proses = true;
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });

                    if (res.ok) {
                        window.location.href = (await res.json()).redirect;
                        return; // tombol tetap "Memproses…" sampai halaman berpindah
                    }

                    if (res.status === 422) {
                        const { errors } = await res.json();
                        this.galat = Object.fromEntries(Object.entries(errors).map(([f, pesan]) => [f, pesan[0]]));
                        this.fokusGalat();
                    } else if (res.status === 419) {
                        gagal('Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.');
                    } else {
                        gagal('Terjadi kesalahan pada server. Silakan coba lagi.');
                    }
                } catch {
                    gagal('Tidak dapat terhubung ke server. Periksa koneksi internet Anda, lalu coba lagi.');
                }
                this.proses = false;
            },
        }));
    });
</script>
@endpush
