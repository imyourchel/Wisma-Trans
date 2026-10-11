@props(['status' => null, 'prioritas' => null, 'jalurCepat' => false])
{{-- Lencana status laporan maintenance, prioritasnya, atau penanda jalur cepat (laporan ditutup atas persetujuan Manager) --}}
@php
    use App\Models\MaintenanceRequest;

    if ($jalurCepat) {
        $gaya = 'bg-[rgba(181,113,74,.14)] text-clay-600';
        $teks = MaintenanceRequest::CATATAN_JALUR_CEPAT;
    } else {
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
        $teks = $status !== null ? MaintenanceRequest::labelUntuk($status) : (MaintenanceRequest::PRIORITAS[$prioritas] ?? $prioritas);
    }
@endphp
<span {{ $attributes->merge(['class' => 'wt-lencana whitespace-nowrap '.$gaya]) }}>{{ $teks }}</span>
