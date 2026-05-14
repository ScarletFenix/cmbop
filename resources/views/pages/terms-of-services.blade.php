@extends('layouts.app')

@section('content')

<!-- ==================== TERMS HERO ==================== -->
<section style="position:relative; width:100%; padding:140px 0 60px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%);">

    <!-- Background Shapes -->
    <div style="position:absolute; top:10%; left:-100px; width:250px; height:250px; border-radius:50%; background:#FF4757; opacity:0.08; z-index:1;"></div>
    <div style="position:absolute; bottom:-80px; right:-60px; width:220px; height:220px; border-radius:50%; background:#FFD93D; opacity:0.15; z-index:1;"></div>
    <div style="position:absolute; top:30%; right:10%; width:60px; height:60px; border-radius:50%; border:10px solid #4ECDCB; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:15%; right:25%; width:10px; height:10px; border-radius:50%; background:#4ECDCB; opacity:0.6; z-index:1;"></div>
    <div style="position:absolute; bottom:20%; left:15%; width:12px; height:12px; border-radius:50%; background:#FF4757; opacity:0.4; z-index:1;"></div>

    <div class="container" style="position:relative; z-index:5; max-width:900px;">
        <div class="text-center">
            <!-- Document Icon -->
            <div style="display:inline-flex; align-items:center; justify-content:center; width:70px; height:70px; background:linear-gradient(135deg, #4ECDCB, #38b2ac); border-radius:18px; margin-bottom:1.25rem; box-shadow:0 12px 30px rgba(78,205,203,0.35);">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <h1 style="font-size:3rem; font-weight:800; color:#1a1a2e; letter-spacing:-1px; margin-bottom:1rem;">
                Terms of Service
            </h1>
            <p style="font-size:1.1rem; color:#666; max-width:680px; margin:0 auto; line-height:1.7;">
                By accessing or using seolinkbuildings.com, you agree to comply with and be bound by these Terms of Service. If you do not agree, please do not use our Website.
            </p>
            <div class="d-flex justify-content-center gap-4 flex-wrap mt-4">
                <p style="color:#999; margin:0; font-size:0.9rem;">
                    <strong style="color:#1a1a2e;">Effective Date:</strong> January 1, 2025
                </p>
                <p style="color:#999; margin:0; font-size:0.9rem;">
                    <strong style="color:#1a1a2e;">Last Updated:</strong> January 1, 2025
                </p>
            </div>
        </div>
    </div>
</section>


<!-- ==================== TERMS CONTENT ==================== -->
<div class="container py-5" style="max-width:900px;">

    <!-- ===== 1. ACCEPTANCE OF TERMS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#4ECDCB,#38b2ac);">1</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Acceptance of Terms</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            By using our Website, you acknowledge that you have read, understood, and agreed to these Terms and our Privacy Policy. These Terms apply to all visitors, users, and others who access or use the Website.
        </p>
    </div>


    <!-- ===== 2. ELIGIBILITY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#667eea,#764ba2);">2</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Eligibility</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            You must be at least <strong>16 years old</strong> to use our services. By using the Website, you confirm that you meet this age requirement.
        </p>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            If you are under 16, you may not use or register for the Website, and we do not knowingly collect data from children.
        </p>
    </div>


    <!-- ===== 3. ACCOUNT REGISTRATION ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#f093fb,#f5576c);">3</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Account Registration</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            Some services may require you to register for an account. You agree to:
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Provide accurate, complete, and current information</li>
            <li>Maintain the security of your account credentials</li>
            <li>Notify us immediately of any unauthorized use of your account</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            You are responsible for all activity under your account. We may suspend or terminate accounts that violate these Terms.
        </p>
    </div>


    <!-- ===== 4. USE OF SERVICES ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#4CAF50,#66BB6A);">4</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Use of Services</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            You may use the Website only for lawful purposes and in compliance with these Terms. You agree <strong>not to</strong>:
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Violate any applicable laws or regulations</li>
            <li>Post, transmit, or share unlawful, harmful, or offensive content</li>
            <li>Attempt to access unauthorized areas of the Website</li>
            <li>Use automated tools to scrape or collect data without permission</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            We reserve the right to restrict, suspend, or terminate access to any user who violates these Terms.
        </p>
    </div>


    <!-- ===== 5. INTELLECTUAL PROPERTY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FFB74D,#FF9800);">5</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Intellectual Property</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            All content on the Website is the property of <strong>seolinkbuildings.com</strong> or its licensors and is protected by law.
        </p>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            You may not copy, distribute, modify, or use our content for commercial purposes without prior written consent.
        </p>
    </div>


    <!-- ===== 6. PAYMENT AND BILLING ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#1e40af,#3b82f6);">6</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Payment and Billing</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            If you purchase services through the Website:
        </p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>You agree to provide accurate payment information</li>
            <li>All payments are processed securely through third-party providers</li>
            <li>We reserve the right to modify prices with notice</li>
            <li>Refunds follow our Refund Policy if applicable</li>
        </ul>
    </div>


    <!-- ===== 7. THIRD-PARTY LINKS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#06b6d4,#0891b2);">7</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Third-Party Links</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            We are not responsible for third-party websites linked from our platform.
        </p>
    </div>


    <!-- ===== 8. DISCLAIMERS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4" style="background:#fffbeb; border-left:4px solid #f59e0b;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);">8</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Disclaimers</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            The Website is provided <strong>"as is"</strong> without warranties regarding:
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Accuracy or completeness of content</li>
            <li>Fitness for a particular purpose</li>
            <li>Uninterrupted service</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            Use of the Website is at your own risk.
        </p>
    </div>


    <!-- ===== 9. LIMITATION OF LIABILITY ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4" style="background:#fef2f2; border-left:4px solid #FF4757;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FF4757,#ee5a6f);">9</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Limitation of Liability</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            seolinkbuildings.com shall <strong>not be liable</strong> for:
        </p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Direct or indirect damages</li>
            <li>Loss of data or profits</li>
            <li>Unauthorized access to data</li>
        </ul>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            Your use of the Website is at your own risk.
        </p>
    </div>


    <!-- ===== 10. INDEMNIFICATION ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#8E44AD,#9b59b6);">10</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Indemnification</h2>
        </div>
        <p style="color:#555; line-height:1.7;">
            You agree to indemnify seolinkbuildings.com from claims arising from:
        </p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>Your use of the Website</li>
            <li>Violation of these Terms</li>
            <li>Violation of any law</li>
        </ul>
    </div>


    <!-- ===== 11. CHANGES TO TERMS ===== -->
    <div class="mb-4 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="terms-num" style="background:linear-gradient(135deg,#FF6F61,#ee5a6f);">11</div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.4rem;">Changes to Terms</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">
            We may update these Terms at any time. Continued use of the Website means acceptance of the updated Terms.
        </p>
    </div>


    <!-- ===== 12. CONTACT INFO ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4" style="background:linear-gradient(135deg, #4ECDCB, #38b2ac); box-shadow:0 15px 35px rgba(78,205,203,0.25);">
        <h2 class="fw-bold mb-3" style="color:white; font-size:1.5rem;">Contact Information</h2>
        <p style="color:rgba(255,255,255,0.9); line-height:1.7; margin-bottom:1.25rem;">
            If you have any questions about these Terms of Service, please reach out below.
        </p>

        <div class="d-flex flex-column gap-3">
            <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px);">
                <div style="width:38px; height:38px; background:white; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38b2ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div>
                    <p class="mb-0" style="color:rgba(255,255,255,0.8); font-size:0.85rem;">Email</p>
                    <a href="mailto:support@seolinkbuildings.com" style="color:white; font-weight:600; text-decoration:none;">support@seolinkbuildings.com</a>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:rgba(255,255,255,0.15); backdrop-filter:blur(10px);">
                <div style="width:38px; height:38px; background:white; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38b2ac" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <div>
                    <p class="mb-0" style="color:rgba(255,255,255,0.8); font-size:0.85rem;">Website</p>
                    <a href="https://seolinkbuildings.com" target="_blank" style="color:white; font-weight:600; text-decoration:none;">https://seolinkbuildings.com</a>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    /* Numbered circle badges */
    .terms-num {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 800;
        font-size: 1.1rem;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
</style>

@endsection