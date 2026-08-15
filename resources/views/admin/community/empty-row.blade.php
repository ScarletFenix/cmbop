@php
    $colspan = $colspan ?? 6;
    $empty = $empty ?? 'Nothing here yet.';
@endphp
<tr>
    <td colspan="{{ $colspan }}" class="text-center text-muted py-4">
        @if($filtered)
            No matches for this filter.
            <a href="{{ route('admin.community.index', ['tab' => $tab]) }}" class="ms-1">Reset</a>
        @else
            {{ $empty }}
        @endif
    </td>
</tr>
