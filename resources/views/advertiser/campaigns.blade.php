@extends('advertiser.layouts.app')

@section('content')

@php
    $projects = $projects ?? collect();
    $statusCounts = $statusCounts ?? [];
    $activeCampaignId = $activeCampaignId ?? session('active_campaign_id');
@endphp

<div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
    <div>
        <h3 class="mb-1">Campaigns</h3>
        <p class="text-muted mb-0">
            One campaign per client site. Order packages stay grouped so you never duplicate placements.
        </p>
    </div>

    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#projectModal">
        <i class="fa fa-plus"></i> Create Campaign
    </button>
</div>

<hr class="w-100">

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        {{ $errors->first() }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-3 mb-4">
    @forelse($projects as $project)
        @php
            $counts = $statusCounts[$project->id] ?? [
                'not_started' => 0,
                'in_progress' => 0,
                'waiting_approval' => 0,
                'needs_improvements' => 0,
                'completed' => 0,
                'rejected' => 0,
                'total' => 0,
            ];
            $isActive = (int) $activeCampaignId === (int) $project->id;
        @endphp

        <div class="col-md-4 col-sm-6">
            <div class="card h-100 border {{ $isActive ? 'border-primary' : 'border-secondary-subtle' }} shadow-sm rounded-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div class="min-w-0">
                            <a href="{{ route('advertiser.campaigns.show', $project) }}"
                               class="text-decoration-none text-dark">
                                <h6 class="mb-1">
                                    {{ $project->project_name }}
                                    @if($isActive)
                                        <span class="badge bg-primary-subtle text-primary ms-1">Active</span>
                                    @endif
                                </h6>
                            </a>
                            <a href="{{ $project->project_url }}"
                               target="_blank"
                               rel="noopener"
                               class="small text-muted text-decoration-none">
                                {{ \Illuminate\Support\Str::limit($project->project_url, 42) }}
                                <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i>
                            </a>
                        </div>

                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editProjectModal{{ $project->id }}"
                                    title="Edit campaign">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>

                            <form method="POST"
                                  action="{{ route('advertiser.campaigns.destroy', $project) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete this campaign? Orders stay in your history but lose this campaign link.')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete campaign">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <hr class="my-2">

                    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
                        <span class="fw-semibold">
                            <i class="fa-solid fa-pen-to-square me-1"></i>
                            Guest Posting
                            <span class="text-muted fw-normal small">({{ $counts['total'] }})</span>
                        </span>

                        <div class="d-flex flex-wrap gap-2 ms-auto">
                            <span class="badge bg-primary-subtle text-primary px-2 py-1"
                                  data-bs-toggle="tooltip" title="Not started">{{ $counts['not_started'] }}</span>
                            <span class="badge bg-info-subtle text-info px-2 py-1"
                                  data-bs-toggle="tooltip" title="In progress">{{ $counts['in_progress'] }}</span>
                            <span class="badge bg-warning-subtle text-warning px-2 py-1"
                                  data-bs-toggle="tooltip" title="Waiting approval">{{ $counts['waiting_approval'] }}</span>
                            <span class="badge bg-secondary-subtle text-secondary px-2 py-1"
                                  data-bs-toggle="tooltip" title="Needs improvements">{{ $counts['needs_improvements'] }}</span>
                            <span class="badge bg-success-subtle text-success px-2 py-1"
                                  data-bs-toggle="tooltip" title="Completed">{{ $counts['completed'] }}</span>
                            <span class="badge bg-danger-subtle text-danger px-2 py-1"
                                  data-bs-toggle="tooltip" title="Rejected">{{ $counts['rejected'] }}</span>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('advertiser.campaigns.show', $project) }}"
                           class="btn btn-sm btn-outline-primary">
                            Open
                        </a>
                        <form method="POST" action="{{ route('advertiser.campaigns.activate', $project) }}">
                            @csrf
                            <input type="hidden" name="redirect" value="{{ route('advertiser.catalog') }}">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="fa fa-shopping-bag me-1"></i>
                                {{ $isActive ? 'Shopping' : 'Shop catalog' }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editProjectModal{{ $project->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="{{ route('advertiser.campaigns.update', $project) }}">
                        @csrf
                        @method('PUT')
                        <div class="modal-header">
                            <h5 class="modal-title">Edit Campaign</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Campaign Name</label>
                                <input type="text" name="project_name" value="{{ $project->project_name }}"
                                       class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Target Website URL</label>
                                <input type="url" name="project_url" value="{{ $project->project_url }}"
                                       class="form-control" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="alert alert-light border">
                No campaigns yet. Create one for each client website, then shop the catalog inside that campaign.
            </div>
        </div>
    @endforelse
</div>

<div class="modal fade" id="projectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" action="{{ route('advertiser.campaigns.store') }}">
                @csrf
                <input type="hidden" name="activate" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Create Campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Campaign Name</label>
                        <input type="text" name="project_name" class="form-control"
                               placeholder="Client or brand name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Website URL</label>
                        <input type="url" name="project_url" class="form-control"
                               placeholder="https://example.com" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="shop" value="1" id="shopAfterCreate" checked>
                        <label class="form-check-label" for="shopAfterCreate">
                            Open catalog and shop for this campaign
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
