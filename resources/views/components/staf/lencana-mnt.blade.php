@props(['status' => null, 'prioritas' => null])
{{-- Lencana status laporan maintenance atau prioritasnya --}}
@php
    use App\Models\MaintenanceRequest;

    $gaya = $status !== null
        ? ([
            'reported' => 'bg-[rgba(232,172,69,.18)] text-[#7A520C]',
            'in_progress' => 'bg-[rgba(108,149,196,.16)] text-[#3F6D9E]',
            'completed' => 'bg-[rgba(92,158,103,.16)] text-status-available',
            'cancelled' => 'bg-[rgba(155,139,120,.20)] text-[#6E604F]',
        ][$status] ?? 'wt-lencana-netral')
        : ([
            'high' => 'bg-[rgba(212,81,74,.12)] text-status-dirty',
            'medium' => 'bg-[rgba(232,172,69,.16)] text-[#7A520C]',
            'low' => 'bg-cream-200 text-ink-500',
        ][$prioritas] ?? 'wt-lencana-netral');
    $teks = $status !== null ? (MaintenanceRequest::STATUS[$status] ?? $status) : (MaintenanceRequest::PRIORITAS[$prioritas] ?? $prioritas);
@endphp
<span {{ $attributes->merge(['class' => 'wt-lencana whitespace-nowrap '.$gaya]) }}>{{ $teks }}</span>
