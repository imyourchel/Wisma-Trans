@props(['status'])
@php
    // Warna mengikuti token status: kuning = menunggu, biru = akan datang, hijau = menginap, abu = selesai, merah = berakhir tanpa menginap
    $warna = [
        'pending_payment' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'waiting_approval' => 'bg-[rgba(232,172,69,.18)] text-[#8A5E10]',
        'confirmed' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
        'checked_in' => 'bg-[rgba(92,158,103,.18)] text-status-available',
        'checked_out' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
    ][$status] ?? 'bg-[rgba(212,81,74,.12)] text-status-dirty';
@endphp
<span {{ $attributes->merge(['class' => "wt-lencana {$warna}"]) }}>{{ \App\Models\Reservation::STATUS[$status] ?? $status }}</span>
