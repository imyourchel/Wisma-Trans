@props(['aktif', 'ya' => 'Aktif', 'tidak' => 'Nonaktif'])
<span @class(['wt-lencana', 'wt-lencana-aktif' => $aktif, 'wt-lencana-nonaktif' => ! $aktif])>{{ $aktif ? $ya : $tidak }}</span>
