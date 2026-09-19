@php
    $layout = $layout ?? 'row';
    $name = $claim->display_name ?? $claim->displayNameFor(auth()->user());
    $host = $claim->display_host ?? $claim->displayHostFor(auth()->user());
    $status = (string) $claim->status;
    $statusLabel = ucfirst($status);
    $note = ($status !== 'pending' && filled($claim->admin_notes)) ? (string) $claim->admin_notes : '';
    $submitted = optional($claim->created_at)->diffForHumans() ?: '—';
    $reviewed = optional($claim->reviewed_at)->diffForHumans() ?: '—';
@endphp

@if($layout === 'card')
    <div class="claims-mobile-card">
        <div class="claims-site-name">{{ $name }}</div>
        <div class="claims-site-host">{{ $host }}</div>
        <div class="claims-mobile-meta">
            @if($claim->name_matches)
                <span class="claims-pill claims-pill--ok">Matches</span>
            @else
                <span class="claims-pill claims-pill--warn">Mismatch</span>
            @endif
            <span class="claims-pill claims-pill--{{ $status }}">{{ $statusLabel }}</span>
        </div>
        <div class="claims-mobile-dates">
            <span>Submitted {{ $submitted }}</span>
            <span>Reviewed {{ $reviewed }}</span>
        </div>
        @if($note !== '')
            <div class="claims-note">Note: {{ $note }}</div>
        @endif
    </div>
@else
    <tr>
        <td>
            <div class="claims-site-name">{{ $name }}</div>
            <div class="claims-site-host">{{ $host }}</div>
            @if($note !== '')
                <div class="claims-note">Note: {{ $note }}</div>
            @endif
        </td>
        <td>
            @if($claim->name_matches)
                <span class="claims-pill claims-pill--ok">Matches</span>
            @else
                <span class="claims-pill claims-pill--warn">Mismatch</span>
            @endif
        </td>
        <td><span class="claims-pill claims-pill--{{ $status }}">{{ $statusLabel }}</span></td>
        <td class="claims-date">{{ $submitted }}</td>
        <td class="claims-date">{{ $reviewed }}</td>
    </tr>
@endif
