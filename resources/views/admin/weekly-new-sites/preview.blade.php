@extends('admin.layouts.app')

@section('title', 'Weekly new sites preview')

@section('content')
<div class="container-fluid py-3">
    <p><a href="{{ route('admin.weekly-new-sites.index') }}">Back</a></p>
    <h2 class="h4">Match preview</h2>
    <p class="text-muted">No mail is sent from this page.</p>

    @if(!$user)
        <p>No advertiser found for {{ $email }}.</p>
    @else
        <p>User #{{ $user->id }} — permission {{ $permission['allowed'] ? 'yes ('.$permission['basis'].')' : 'no ('.$permission['reason'].')' }}</p>
        <p>{{ $cards->count() }} matching site(s). Pairs:</p>
        <ul>
            @forelse($pairs as $pair)
                <li>{{ $pair['niche'] }} / {{ $pair['country_code'] }}</li>
            @empty
                <li class="text-muted">None</li>
            @endforelse
        </ul>
        <table class="table table-sm">
            <thead><tr><th>Domain</th><th>Niche</th><th>Country</th><th>DA</th><th>DR</th><th>Traffic</th><th>Price</th></tr></thead>
            <tbody>
            @foreach($cards as $card)
                <tr>
                    <td>{{ $card['domain'] }}</td>
                    <td>{{ $card['niche'] }}</td>
                    <td>{{ $card['country_code'] }}</td>
                    <td>{{ $card['da'] ?? '—' }}</td>
                    <td>{{ $card['dr'] ?? '—' }}</td>
                    <td>{{ $card['traffic'] ?? '—' }}</td>
                    <td>{{ $card['price'] !== null ? '€'.number_format((float) $card['price'], 2) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
