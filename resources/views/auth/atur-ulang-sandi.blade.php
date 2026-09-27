{{-- Halaman dari tautan email lupa kata sandi, tampil sebagai modal di atas beranda --}}
<div x-data="formAturUlang(@js(['email' => $aturUlang['email'], 'token' => $aturUlang['token']]))" x-init="document.body.style.overflow = 'hidden'">
    <div class="fixed inset-0 z-[370] bg-[rgba(62,42,28,.44)] backdrop-blur-[2px]"></div>

    <div role="dialog" aria-modal="true" aria-labelledby="judul-atur-ulang"
         class="fixed inset-0 z-[375] m-auto grid h-fit max-h-[calc(100vh-24px)] w-[900px] max-w-[calc(100vw-24px)] animate-wt-fade overflow-y-auto overflow-x-hidden rounded-2xl bg-cream-50 shadow-wt-lg min-[760px]:min-h-[520px] min-[760px]:grid-cols-2">
        <div class="relative hidden overflow-hidden bg-brown-800 min-[760px]:block">
            <img src="{{ asset('ds/img/bangunan_depan.png') }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-[.22]">
            <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(62,42,28,.55),rgba(62,42,28,.92))]"></div>
            <div class="absolute inset-4 rounded-[10px] border border-[rgba(242,235,220,.22)]"></div>
            <img src="{{ asset('ds/logo/wismatrans-landscape-cream-clear.png') }}" alt="Wisma Trans"
                 class="absolute inset-x-0 top-11 mx-auto block h-[38px] w-auto max-w-[calc(100%-80px)] object-contain">
            <div class="absolute inset-0 flex flex-col items-center justify-center gap-3.5 px-14 text-center">
                <div class="h-px w-7 bg-sand-300"></div>
                <div class="font-display text-[22px] font-semibold uppercase leading-[1.4] tracking-[.08em] text-cream-150">Kata sandi baru</div>
                <div class="max-w-[260px] text-sm leading-[1.7] text-[rgba(242,235,220,.78)]">Setelah disimpan, Anda langsung masuk dengan kata sandi yang baru.</div>
            </div>
            <div class="absolute inset-x-0 bottom-10 text-center font-display text-[10px] font-semibold uppercase tracking-[.24em] text-sand-300">Simple · Clean · Favour</div>
        </div>

        <div class="relative flex flex-col justify-center gap-4 px-6 py-8 sm:px-10">
            <a href="{{ route('home') }}" title="Tutup"
               class="absolute right-4 top-4 flex h-[34px] w-[34px] items-center justify-center rounded-lg hover:bg-cream-200">
                <img src="{{ asset('ds/icons/x-olive.svg') }}" alt="Tutup" width="16" height="16">
            </a>
            <div class="font-display text-[10px] font-bold uppercase tracking-[.24em] text-clay-500">Lupa kata sandi</div>

            @if ($aturUlang['berlaku'])
                <div class="flex flex-col gap-0.5">
                    <h1 id="judul-atur-ulang" class="m-0 font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Buat kata sandi baru</h1>
                    <div class="text-[13px] text-ink-500">Gunakan minimal 8 karakter yang mudah Anda ingat, tetapi sulit ditebak orang lain.</div>
                </div>

                <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-3" novalidate @submit.prevent="kirim($el)">
                    @csrf
                    <input type="hidden" name="token" :value="token">
                    <input type="hidden" name="email" :value="email">
                    <div>
                        <span class="wt-field-label">Email</span>
                        <span class="wt-line-field h-10 text-ink-500">
                            <img src="{{ asset('ds/icons/mail-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                            <input type="email" :value="email" readonly autocomplete="username" class="!text-ink-500">
                        </span>
                    </div>
                    @foreach ([['password', 'Kata sandi baru', 'Minimal 8 karakter', 'lihat1'], ['password_confirmation', 'Konfirmasi kata sandi', 'Ulangi kata sandi baru', 'lihat2']] as [$field, $label, $placeholder, $lihat])
                        <label class="block">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="wt-field-label flex-none">{{ $label }}</span>
                                <span x-show="pesan('{{ $field }}')" x-text="pesan('{{ $field }}')" x-cloak class="min-w-0 truncate text-[11px] font-medium text-status-dirty"></span>
                            </span>
                            <span class="wt-line-field h-10" :class="pesan('{{ $field }}') && 'is-invalid'">
                                <img src="{{ asset('ds/icons/lock-muted.svg') }}" alt="" width="16" height="16" class="flex-none">
                                <input :type="{{ $lihat }} ? 'text' : 'password'" type="password" name="{{ $field }}" x-model="isi.{{ $field }}" @input="hapus('{{ $field }}')"
                                       placeholder="{{ $placeholder }}" autocomplete="new-password" required @if ($loop->first) autofocus @endif>
                                <button type="button" @click="{{ $lihat }} = !{{ $lihat }}" :title="{{ $lihat }} ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'" class="flex border-0 bg-transparent p-0">
                                    <img x-show="!{{ $lihat }}" src="{{ asset('ds/icons/eye-muted.svg') }}" alt="" width="16" height="16">
                                    <img x-show="{{ $lihat }}" x-cloak src="{{ asset('ds/icons/eye-off-muted.svg') }}" alt="" width="16" height="16">
                                </button>
                            </span>
                        </label>
                    @endforeach
                    <div x-show="galatTautan" x-cloak role="alert" class="rounded-lg bg-[rgba(212,81,74,.08)] px-3.5 py-2.5 text-[13px] text-status-dirty">
                        <span x-text="galatTautan"></span>
                        <a href="{{ route('login') }}?lupa=1" class="font-semibold text-clay-600">Minta tautan baru</a>
                    </div>
                    <button type="submit" :disabled="proses" x-text="proses ? 'Menyimpan…' : 'Simpan kata sandi'"
                            class="mt-1 h-11 w-full rounded-[10px] border-0 bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 disabled:cursor-wait disabled:opacity-70">Simpan kata sandi</button>
                </form>
            @else
                <div class="flex flex-col gap-1">
                    <h1 id="judul-atur-ulang" class="m-0 font-display text-[22px] font-bold uppercase tracking-[.05em] text-ink-900">Tautan tidak berlaku</h1>
                    <div class="text-[13px] leading-relaxed text-ink-500">
                        Tautan ini sudah kedaluwarsa, sudah pernah dipakai, atau tidak lengkap. Tautan atur ulang kata sandi hanya berlaku 60 menit dan satu kali pakai.
                    </div>
                </div>
                <a href="{{ route('login') }}?lupa=1"
                   class="flex h-11 w-full items-center justify-center rounded-[10px] bg-clay-500 font-display text-xs font-semibold uppercase tracking-[.16em] text-cream-50 hover:bg-clay-600 hover:text-cream-50 hover:no-underline">Minta tautan baru</a>
                <a href="{{ route('home') }}" class="self-center text-[13px] text-olive-600 hover:text-brown-800">Kembali ke beranda</a>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('formAturUlang', (awal) => ({
            email: awal.email,
            token: awal.token,
            isi: { password: '', password_confirmation: '' },
            galat: {},
            galatTautan: '',
            proses: false,
            lihat1: false,
            lihat2: false,

            pesan(f) {
                if (f === 'password_confirmation' && this.isi.password_confirmation && this.isi.password_confirmation !== this.isi.password) {
                    return 'Tidak sesuai.';
                }
                return this.galat[f] || '';
            },
            hapus(f) { delete this.galat[f]; },

            async kirim(form) {
                if (this.proses) return;
                const g = {};
                if (!this.isi.password) g.password = 'Wajib diisi.';
                else if (this.isi.password.length < 8) g.password = 'Minimal 8 karakter.';
                else if (this.isi.password.length > 72) g.password = 'Maksimal 72 karakter.';
                if (!this.isi.password_confirmation) g.password_confirmation = 'Wajib diisi.';
                this.galat = g;
                if (Object.keys(g).length || this.pesan('password_confirmation')) return;

                this.proses = true;
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(form),
                    });
                    if (res.ok) {
                        window.location.href = (await res.json()).redirect;
                        return;
                    }
                    if (res.status === 422) {
                        const { errors } = await res.json();
                        this.galatTautan = errors.token?.[0] || errors.email?.[0] || '';
                        this.galat = Object.fromEntries(Object.entries(errors).filter(([k]) => k.startsWith('password'))
                            .map(([k, v]) => [k, v[0] === 'Konfirmasi kata sandi tidak sesuai.' ? 'Tidak sesuai.' : v[0]]));
                        if (errors.password?.[0] === 'Konfirmasi kata sandi tidak sesuai.') {
                            this.galat = { password_confirmation: 'Tidak sesuai.' };
                        }
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
    });
</script>
@endpush
