@props([
    'varian',            // nama => ['manfaat' => [[judul, keterangan], ...], 'lanjut' => label tombol tanpa akun, 'ingat' => bool, 'catatan' => teks kecil di bawah]
    'kunci',             // kunci sessionStorage: pilihan "tanpa akun" diingat selama sesi peramban (hanya varian yang 'ingat')
    'kembali' => null,   // halaman setelah masuk/daftar; kosong = halaman ini
])
{{--
    Pop-up tawaran masuk/daftar untuk tamu yang belum masuk, berisi manfaat memakai akun.
    Dibuka dengan $dispatch('tawar-akun', { varian }). Pilihan tanpa akun mengirim 'tanpa-akun' { varian } ke window;
    saat buka/tutup dikirim 'tawar-akun-buka' / 'tawar-akun-tutup' supaya halaman bisa menahan tombol Esc-nya sendiri.
--}}
@guest
    @php $pertama = array_key_first($varian); @endphp
    <div x-data="{
            buka: false,
            v: @js($pertama),
            cfg: @js(collect($varian)->map(fn ($x) => ['ingat' => (bool) ($x['ingat'] ?? false)])),
            sudah() { try { return sessionStorage.getItem(@js($kunci)) === '1'; } catch { return false; } },
            tanya(v) {
                this.v = v && this.cfg[v] ? v : @js($pertama);
                if (this.cfg[this.v].ingat && this.sudah()) return this.$dispatch('tanpa-akun', { varian: this.v });
                this.buka = true;
                this.$dispatch('tawar-akun-buka');
                this.$nextTick(() => this.$refs.masuk?.focus());
            },
            tutup() {
                if (!this.buka) return;
                this.buka = false;
                // Ditunda supaya pendengar Esc lain di halaman masih melihat pop-up terbuka pada tombol yang sama
                setTimeout(() => this.$dispatch('tawar-akun-tutup'));
            },
            akun(mode) { this.tutup(); this.$dispatch('buka-akun', { mode, kembali: @js($kembali) }); },
            tanpaAkun() {
                if (this.cfg[this.v].ingat) { try { sessionStorage.setItem(@js($kunci), '1'); } catch { /* mode privat */ } }
                const v = this.v;
                this.tutup();
                this.$dispatch('tanpa-akun', { varian: v });
            },
         }"
         @tawar-akun.window="tanya($event.detail?.varian)"
         @keydown.escape.window="buka && tutup()"
         x-effect="document.documentElement.style.overflow = buka ? 'hidden' : ''">
        <div x-show="buka" x-cloak x-transition.opacity @click="tutup()" class="fixed inset-0 z-[360] bg-[rgba(40,27,18,.55)] backdrop-blur-[3px]"></div>
        <div x-show="buka" x-cloak
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 scale-[.97]" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             role="dialog" aria-modal="true" aria-labelledby="judul-tawaran-akun" aria-describedby="isi-tawaran-akun" data-tawar-akun
             class="fixed inset-x-4 top-1/2 z-[365] mx-auto max-h-[calc(100dvh-32px)] w-auto max-w-[440px] -translate-y-1/2 overflow-y-auto rounded-xl bg-cream-50 shadow-wt-lg">
            {{-- Kepala: cokelat tua dengan bingkai tipis, seperti kepala halaman tamu --}}
            <div class="relative isolate overflow-hidden bg-brown-800 px-7 pb-7 pt-8 text-cream-50">
                <div class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(circle_at_85%_0%,rgba(228,185,143,.22),transparent_55%)]" aria-hidden="true"></div>
                <div class="pointer-events-none absolute inset-2.5 border border-[rgba(255,253,246,.16)]" aria-hidden="true"></div>
                <button type="button" @click="tutup()" aria-label="Tutup"
                        class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full border-0 bg-transparent text-[rgba(242,235,220,.7)] hover:bg-[rgba(242,235,220,.12)] hover:text-cream-50">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
                <div class="font-display text-[10px] font-semibold uppercase tracking-[.3em] text-sand-300">Akun Wisma Trans</div>
                <h2 id="judul-tawaran-akun" class="mb-0 mt-2.5 pr-8 font-display text-[21px] font-extrabold leading-tight tracking-[.01em]">Pesan lebih praktis dengan akun Anda</h2>
                <p id="isi-tawaran-akun" class="mb-0 mt-2 text-[13px] leading-relaxed text-[rgba(242,235,220,.78)]">Masuk atau buat akun tanpa biaya untuk menikmati kemudahan berikut.</p>
            </div>

            <div class="px-7 pb-7 pt-5">
                @foreach ($varian as $nama => $isi)
                    <ul x-show="v === @js($nama)" @if (! $loop->first) style="display: none" @endif data-varian-akun="{{ $nama }}"
                        class="m-0 flex list-none flex-col divide-y divide-cream-300 p-0">
                        @foreach ($isi['manfaat'] as [$judul, $keterangan])
                            <li class="flex items-start gap-3.5 py-3.5">
                                <span class="mt-0.5 flex h-7 w-7 flex-none items-center justify-center rounded-full border border-[rgba(181,113,74,.35)] text-clay-600" aria-hidden="true">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[14px] font-semibold leading-snug text-ink-900">{{ $judul }}</span>
                                    <span class="mt-0.5 block text-[12.5px] leading-relaxed text-ink-500">{{ $keterangan }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                <button type="button" x-ref="masuk" @click="akun('masuk')"
                        class="mt-5 h-12 w-full rounded border-0 bg-brown-800 font-display text-[12px] font-bold uppercase tracking-[.14em] text-cream-50 hover:bg-brown-900">Masuk</button>
                <p class="mb-0 mt-3 text-center text-[13px] text-ink-500">
                    Belum punya akun?
                    <button type="button" @click="akun('daftar')" class="border-0 bg-transparent p-0 font-semibold text-clay-600 underline decoration-[rgba(181,113,74,.4)] underline-offset-4 hover:text-clay-500">Buat akun gratis</button>
                </p>

                <div class="my-5 flex items-center gap-3 text-[11px] uppercase tracking-[.2em] text-ink-500" aria-hidden="true">
                    <span class="h-px flex-1 bg-cream-300"></span>atau<span class="h-px flex-1 bg-cream-300"></span>
                </div>

                @foreach ($varian as $nama => $isi)
                    <div x-show="v === @js($nama)" @if (! $loop->first) style="display: none" @endif>
                        <button type="button" @click="tanpaAkun()" data-tanpa-akun
                                class="h-11 w-full rounded border border-cream-400 bg-transparent text-[13px] font-semibold text-brown-800 hover:bg-cream-200">{{ $isi['lanjut'] }}</button>
                        @if (! empty($isi['catatan']))
                            <p class="mb-0 mt-3 text-center text-[12px] leading-relaxed text-ink-500">{{ $isi['catatan'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endguest
