@extends('advertiser.layouts.app')

@section('content')

<!-- ================= MARQUEE ================= -->
<style>
.marquee-wrapper {
    width: 100%;
    overflow: hidden;
    border-radius: 6px;
    position: relative;
    background: rgba(255,255,255,0.65);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(0,0,0,0.08);
    margin-bottom: 20px;
}

.fade-left, .fade-right {
    position: absolute;
    top: 0; bottom: 0;
    width: 60px;
    z-index: 2;
}
.fade-left { left: 0; background: linear-gradient(to right, rgba(255,255,255,0.9), transparent); }
.fade-right { right: 0; background: linear-gradient(to left, rgba(255,255,255,0.9), transparent); }

.marquee-container {
    padding: 12px 0;
    overflow: hidden;
}

.marquee-track {
    display: flex;
    width: max-content;
    animation: marquee 20s linear infinite;
}

.marquee-text {
    white-space: nowrap;
    padding-right: 120px;
    font-weight: 600;
}

@keyframes marquee {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
}

.marquee-container:hover .marquee-track {
    animation-play-state: paused;
}
</style>

<!-- ================= DASHBOARD ================= -->
@php
    $stats = $stats ?? ['total' => 0, 'completed' => 0, 'in_progress' => 0, 'cancelled' => 0];
    $isNewAdvertiser = ($stats['total'] ?? 0) === 0;
@endphp

<style>
.get-started-steps {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.get-started-step {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f8fafb;
    transition: border-color .2s ease, background .2s ease, transform .2s ease;
    text-decoration: none;
    color: inherit;
}
.get-started-step:hover {
    border-color: #4ECDCB;
    background: #f0fbfb;
    transform: translateY(-1px);
    color: inherit;
}
.get-started-step .step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #0b6266;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.get-started-step .step-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
}
.get-started-step .step-desc {
    font-size: 12px;
    color: #6b7280;
    margin: 0;
}
.get-started-cta {
    background: linear-gradient(135deg, #3aaeb2, #0b6266);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 12px 18px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: opacity .2s ease, transform .2s ease;
}
.get-started-cta:hover {
    color: #fff;
    opacity: .95;
    transform: translateY(-1px);
}
</style>

<!-- Welcome Message -->
<div class="d-flex align-items-center mb-4">
    <div>
        <h4 class="mb-1">Welcome back, {{ auth()->user()->name }}!</h4>
        <small class="text-muted">
            @if($isNewAdvertiser)
                Ready to place your first order? Follow the path below to get started.
            @else
                Here's a quick overview of your account and some helpful resources.
            @endif
        </small>
    </div>
</div>

<div class="marquee-wrapper">
    <div class="fade-left"></div>
    <div class="fade-right"></div>

    <div class="marquee-container">
        <div class="marquee-track">

            @for($i=0; $i<5; $i++)
            <span class="marquee-text">
                <i class="fa-solid fa-bullhorn text-danger me-1"></i>
                Exclusive Access:
                <i class="fa-solid fa-dice text-primary mx-1"></i> iGaming,
                <i class="fa-solid fa-coins text-warning mx-1"></i> Casino &
                <i class="fa-brands fa-bitcoin text-warning mx-1"></i> Crypto Backlink Opportunities —
                <i class="fa-solid fa-shield-halved text-success mx-1"></i> Manual Placements Only |
                <i class="fa-solid fa-envelope text-dark mx-1"></i> Message Us
            </span>
            @endfor

        </div>
    </div>
</div>

<div class="row g-4">

    <!-- Stats / Get started -->
    <div class="col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                @if($isNewAdvertiser)
                    <h5 class="mb-1">Get started</h5>
                    <p class="text-muted small mb-3">Three steps to your first placement.</p>

                    <div class="get-started-steps mb-3">
                        <a href="{{ route('advertiser.catalog') }}" class="get-started-step">
                            <span class="step-num">1</span>
                            <div>
                                <div class="step-title">Browse the catalog</div>
                                <p class="step-desc">Find sites that match your niche and market.</p>
                            </div>
                        </a>
                        <a href="{{ route('advertiser.add-funds') }}" class="get-started-step">
                            <span class="step-num">2</span>
                            <div>
                                <div class="step-title">Add funds</div>
                                <p class="step-desc">Top up your wallet so checkout is one click.</p>
                            </div>
                        </a>
                        <a href="{{ route('advertiser.catalog') }}" class="get-started-step">
                            <span class="step-num">3</span>
                            <div>
                                <div class="step-title">Place your first order</div>
                                <p class="step-desc">Add a site to cart, attach content, and pay.</p>
                            </div>
                        </a>
                    </div>

                    <a href="{{ route('advertiser.catalog') }}" class="get-started-cta w-100 justify-content-center">
                        <i class="fa fa-list"></i> Browse catalog
                    </a>
                @else
                    <h5 class="mb-4">Your Overview</h5>

                    <div class="row g-3">

                        <div class="col-6">
                            <div class="d-flex align-items-center p-3 border rounded bg-primary bg-opacity-10">
                                <div class="me-3 p-2 text-white rounded" style="background-color: #3aaeb2; color: white;">
                                    <i class="fa-solid fa-box-open"></i>
                                </div>
                                <div>
                                    <small class="text-muted">Total Orders</small>
                                    <h5 class="mb-0">{{ $stats['total'] }}</h5>
                                </div>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="d-flex align-items-center p-3 border rounded bg-success bg-opacity-10">
                                <div class="me-3 p-2 bg-success text-white rounded">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                                <div>
                                    <small class="text-muted">Completed</small>
                                    <h5 class="mb-0">{{ $stats['completed'] }}</h5>
                                </div>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="d-flex align-items-center p-3 border rounded bg-warning bg-opacity-10">
                                <div class="me-3 p-2 bg-warning text-white rounded">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                                <div>
                                    <small class="text-muted">In Progress</small>
                                    <h5 class="mb-0">{{ $stats['in_progress'] }}</h5>
                                </div>
                            </div>
                        </div>

                        <div class="col-6">
                            <div class="d-flex align-items-center p-3 border rounded bg-danger bg-opacity-10">
                                <div class="me-3 p-2 bg-danger text-white rounded">
                                    <i class="fa-solid fa-xmark-circle"></i>
                                </div>
                                <div>
                                    <small class="text-muted">Cancelled</small>
                                    <h5 class="mb-0">{{ $stats['cancelled'] }}</h5>
                                </div>
                            </div>
                        </div>

                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Promo -->
    <div class="col-xl-4">
        <div class="card text-white shadow-sm h-100 position-relative overflow-hidden"
             style="background: linear-gradient(90deg, #3aaeb2, #0b6266);">

            <!-- Right-side background image -->
            <div class="position-absolute top-0 end-0 h-100 w-50 d-none d-md-block">
                <img src="{{ asset('assets/img/backlink-strategy.png') }}" 
                     alt="Backlink Strategy" 
                     class="w-100 h-100 object-fit-cover">
            </div>

            <!-- Content -->
            <div class="position-relative p-4" style="max-width: 60%;">
                <h5 class="fw-semibold mb-3">Backlink strategy for {{ date('Y') }}</h5>

                <ul class="small mb-0" style="line-height: 1.5;">
                    <li>Personalized link strategy</li>
                    <li>Built by SEO professionals</li>
                    <li>Tailored for your niche</li>
                    <li>Drive organic traffic</li>
                    <li>Increase domain authority</li>
                    <li>Boost rankings</li>
                </ul>
            </div>

        </div>
    </div>

    <!-- Support -->
    <div class="col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex flex-column justify-content-between">

                <div>
                    <h5 class="mb-3">
                        <i class="fa fa-circle-question me-1"></i>
                        Need Assistance?
                    </h5>

                    <div class="d-flex align-items-center mb-3">
                        <img src="{{ asset('assets/img/support-avatar.jpg') }}"
                             onerror="this.src='https://ui-avatars.com/api/?name=Arslan&background=0d6efd&color=fff'"
                             class="rounded-circle me-3"
                             width="60" height="60">

                        <div>
                            <strong>M. Arslan</strong><br>
                            <small class="text-muted">Client Manager</small><br>
                            <small class="text-primary">support@seolinkbuildings.com</small>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="https://t.me/arslan_seolinkbuildings" target="_blank"
                       class="btn w-100" style="background-color: #3aaeb2; color: white;">
                        <i class="fa fa-message me-1"></i> Start Chat
                    </a>

                    <small class="text-muted d-block text-center mt-2">
                        Mon–Fri, 9AM – 6PM UTC
                    </small>
                </div>

            </div>
        </div>
    </div>

</div>



<!-- ================= VIDEO TUTORIALS ================= -->
<h3 class="mt-5 mb-4">How Our Platform Works</h3>

<div class="row g-4">
    <!-- Video Card 1 -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 position-relative overflow-hidden">
            <img src="{{ asset('assets/img/video-thumb-1.webp') }}" alt="Video 1" class="w-100 h-100 object-fit-cover lazyload" loading="lazy">
            <div class="position-absolute top-50 start-50 translate-middle">
                <i class="fa-solid fa-circle-play fa-3x text-white"></i>
            </div>
            <div class="card-body bg-dark bg-opacity-50 text-white position-absolute bottom-0 w-100 p-2">
                <h6 class="mb-1">How to Place Orders</h6>
                <small>Step-by-step guide for advertisers</small>
            </div>
        </div>
    </div>

    <!-- Video Card 2 -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 position-relative overflow-hidden">
            <img src="{{ asset('assets/img/video-thumb-2.webp') }}" alt="Video 2" class="w-100 h-100 object-fit-cover lazyload" loading="lazy">
            <div class="position-absolute top-50 start-50 translate-middle">
                <i class="fa-solid fa-circle-play fa-3x text-white"></i>
            </div>
            <div class="card-body bg-dark bg-opacity-50 text-white position-absolute bottom-0 w-100 p-2">
                <h6 class="mb-1">Tracking Progress</h6>
                <small>Monitor and manage your campaigns</small>
            </div>
        </div>
    </div>

    <!-- Video Card 3 -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 position-relative overflow-hidden">
            <img src="{{ asset('assets/img/video-thumb-3.webp') }}" alt="Video 3" class="w-100 h-100 object-fit-cover lazyload" loading="lazy">
            <div class="position-absolute top-50 start-50 translate-middle">
                <i class="fa-solid fa-circle-play fa-3x text-white"></i>
            </div>
            <div class="card-body bg-dark bg-opacity-50 text-white position-absolute bottom-0 w-100 p-2">
                <h6 class="mb-1">Boosting Results</h6>
                <small>Increase traffic and domain authority</small>
            </div>
        </div>
    </div>

    <!-- Video Card 4 -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 position-relative overflow-hidden">
            <img src="{{ asset('assets/img/video-thumb-4.webp') }}" alt="Video 4" class="w-100 h-100 object-fit-cover lazyload" loading="lazy">
            <div class="position-absolute top-50 start-50 translate-middle">
                <i class="fa-solid fa-circle-play fa-3x text-white"></i>
            </div>
            <div class="card-body bg-dark bg-opacity-50 text-white position-absolute bottom-0 w-100 p-2">
                <h6 class="mb-1">Maximizing ROI</h6>
                <small>Get the most out of your advertising budget</small>
            </div>
        </div>
    </div>

    <!-- Video Card 5 -->
    <div class="col-md-4">
        <div class="card shadow-sm h-100 position-relative overflow-hidden">
            <img src="{{ asset('assets/img/video-thumb-5.webp') }}" alt="Video 5" class="w-100 h-100 object-fit-cover lazyload" loading="lazy">
            <div class="position-absolute top-50 start-50 translate-middle">
                <i class="fa-solid fa-circle-play fa-3x text-white"></i>
            </div>
            <div class="card-body bg-dark bg-opacity-50 text-white position-absolute bottom-0 w-100 p-2">
                <h6 class="mb-1">Creating Effective Campaigns</h6>
                <small>Learn how to design high-performing ads</small>
            </div>
        </div>
    </div>
</div>

@endsection