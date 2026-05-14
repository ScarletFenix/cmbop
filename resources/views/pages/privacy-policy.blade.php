@extends('layouts.app')

@section('content')

<!-- ==================== PRIVACY HERO ==================== -->
<section style="position:relative; width:100%; padding:140px 0 60px; overflow:hidden; background:linear-gradient(180deg, #f0f5ff 0%, #f5faff 100%);">

    <!-- Background Shapes -->
    <div style="position:absolute; top:10%; left:-100px; width:250px; height:250px; border-radius:50%; background:#FF4757; opacity:0.08; z-index:1;"></div>
    <div style="position:absolute; bottom:-80px; right:-60px; width:220px; height:220px; border-radius:50%; background:#FFD93D; opacity:0.15; z-index:1;"></div>
    <div style="position:absolute; top:30%; right:10%; width:60px; height:60px; border-radius:50%; border:10px solid #4ECDCB; opacity:0.4; z-index:1;"></div>
    <div style="position:absolute; top:15%; right:25%; width:10px; height:10px; border-radius:50%; background:#4ECDCB; opacity:0.6; z-index:1;"></div>
    <div style="position:absolute; bottom:20%; left:15%; width:12px; height:12px; border-radius:50%; background:#FF4757; opacity:0.4; z-index:1;"></div>

    <div class="container" style="position:relative; z-index:5; max-width:900px;">
        <div class="text-center">
            <!-- Shield Icon -->
            <div style="display:inline-flex; align-items:center; justify-content:center; width:70px; height:70px; background:linear-gradient(135deg, #4ECDCB, #38b2ac); border-radius:18px; margin-bottom:1.25rem; box-shadow:0 12px 30px rgba(78,205,203,0.35);">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h1 style="font-size:3rem; font-weight:800; color:#1a1a2e; letter-spacing:-1px; margin-bottom:1rem;">
                Privacy Policy
            </h1>
            <p style="font-size:1.1rem; color:#666; max-width:680px; margin:0 auto; line-height:1.7;">
                This Privacy Policy explains how seolinkbuildings.com collects, uses, stores, and protects information when you visit or use our website and services. By accessing our website, you agree to the practices described in this policy.
            </p>
            <p style="color:#999; margin-top:1.25rem; font-size:0.9rem;">
                <strong style="color:#1a1a2e;">Last Updated:</strong> {{ date('F j, Y') }}
            </p>
        </div>
    </div>
</section>


<!-- ==================== PRIVACY CONTENT ==================== -->
<div class="container py-5" style="max-width:900px;">

    <!-- ===== TABLE OF CONTENTS ===== -->
    <div class="mb-5 p-4 rounded-4" style="background:linear-gradient(135deg, #f0f5ff, #f5faff); border:1px solid #e0e8f5;">
        <h3 class="fw-bold mb-3" style="color:#1a1a2e; font-size:1.1rem;">Quick Navigation</h3>
        <div class="row g-2">
            <div class="col-md-6">
                <a href="#info-collect" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Information We Collect</a>
                <a href="#info-use" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ How We Use Your Information</a>
                <a href="#info-share" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Sharing Your Information</a>
                <a href="#data-security" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Data Security</a>
            </div>
            <div class="col-md-6">
                <a href="#data-retention" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Data Retention</a>
                <a href="#your-rights" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Your Rights Under GDPR</a>
                <a href="#children-privacy" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Children's Privacy</a>
                <a href="#contact-info" class="d-block py-1" style="color:#4ECDCB; text-decoration:none; font-size:0.9rem;">→ Contact Information</a>
            </div>
        </div>
    </div>


    <!-- ===== 1. INFORMATION WE COLLECT ===== -->
    <div id="info-collect" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#4ECDCB,#38b2ac); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">1. Information We Collect</h2>
        </div>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Information You Provide</h3>
        <p style="color:#555; line-height:1.7;">We collect information you voluntarily provide when you:</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Register on our website</li>
            <li>Place an order</li>
            <li>Contact us or request support</li>
            <li>Fill out forms</li>
        </ul>
        <p style="color:#555; line-height:1.7;">This may include your name, email address, phone number, billing details, payment information, and business-related information.</p>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Automatically Collected Information</h3>
        <p style="color:#555; line-height:1.7;">When you visit our website, we may automatically collect:</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>IP address and device information</li>
            <li>Browser type and version</li>
            <li>Operating system details</li>
            <li>Usage data and session duration</li>
            <li>Referral source</li>
        </ul>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Cookies &amp; Tracking Technologies</h3>
        <p style="color:#555; line-height:1.7;">We use cookies and similar technologies to improve website performance, analyze usage and traffic, enhance user experience, and ensure website security.</p>
        <p class="mb-0" style="color:#555; line-height:1.7;">You can manage or disable cookies through your browser settings. Please note that disabling cookies may affect certain features of the website.</p>
    </div>


    <!-- ===== 2. HOW WE USE INFORMATION ===== -->
    <div id="info-use" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#667eea,#764ba2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">2. How We Use Your Information</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Primary Uses</h3>
        <p style="color:#555; line-height:1.7;">We use your information to:</p>
        <ul class="mb-4" style="color:#555; line-height:2;">
            <li>Provide and manage our services</li>
            <li>Process transactions and payments</li>
            <li>Respond to inquiries and provide support</li>
            <li>Improve website functionality, performance, and security</li>
        </ul>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Legal Basis for Processing (GDPR)</h3>
        <p style="color:#555; line-height:1.7;">In accordance with Regulation (EU) 2016/679 (General Data Protection Regulation – GDPR), personal data is processed only where at least one lawful basis applies, as defined in Article 6(1) GDPR.</p>

        <!-- Info Callout -->
        <div class="mt-4 p-4 rounded-3" style="background:#eff6ff; border-left:4px solid #3b82f6;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">Communication Preferences</h4>
            </div>
            <p class="mb-0" style="color:#555; line-height:1.7;">You may opt out of promotional communications at any time by clicking the unsubscribe link in our emails or by contacting us directly.</p>
        </div>
    </div>


    <!-- ===== 3. SHARING YOUR INFORMATION ===== -->
    <div id="info-share" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#f093fb,#f5576c); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">3. Sharing Your Information</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Trusted Third-Party Providers</h3>
        <p style="color:#555; line-height:1.7;">We may share your information with trusted third-party service providers, including:</p>
        <ul class="mb-3" style="color:#555; line-height:2;">
            <li>Payment processors</li>
            <li>Hosting providers</li>
            <li>Email and communication services</li>
            <li>Analytics providers</li>
        </ul>
        <p style="color:#555; line-height:1.7;">These providers process data only on our behalf and in accordance with applicable data protection laws.</p>

        <!-- Warning Callout -->
        <div class="mt-4 p-4 rounded-3" style="background:#fffbeb; border-left:4px solid #f59e0b;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">We Do Not Sell Your Data</h4>
            </div>
            <p class="mb-0" style="color:#555; line-height:1.7;">We do not sell, rent, or trade your personal information to third parties.</p>
        </div>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Legal Disclosure</h3>
        <p class="mb-0" style="color:#555; line-height:1.7;">We may disclose information if required by law or to protect our rights, safety, or property.</p>
    </div>


    <!-- ===== 4. DATA SECURITY ===== -->
    <div id="data-security" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#4CAF50,#66BB6A); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">4. Data Security</h2>
        </div>

        <h3 class="fw-bold mt-3 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Security Measures</h3>
        <p style="color:#555; line-height:1.7;">We implement reasonable technical and organizational measures, including SSL encryption and secure servers, to protect your personal data.</p>

        <h3 class="fw-bold mt-4 mb-3" style="color:#1a1a2e; font-size:1.15rem;">Security Disclaimer</h3>
        <p class="mb-0" style="color:#555; line-height:1.7;">While we take data security seriously, no method of transmission or storage can guarantee absolute security.</p>
    </div>


    <!-- ===== 5. DATA RETENTION ===== -->
    <div id="data-retention" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#FFB74D,#FF9800); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">5. Data Retention</h2>
        </div>

        <p style="color:#555; line-height:1.7;">We retain personal information only for as long as necessary to:</p>
        <ul class="mb-0" style="color:#555; line-height:2;">
            <li>Provide services</li>
            <li>Meet legal and regulatory obligations</li>
            <li>Resolve disputes and enforce agreements</li>
        </ul>
    </div>


    <!-- ===== 6. YOUR RIGHTS UNDER GDPR ===== -->
    <div id="your-rights" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-4">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#1e40af,#3b82f6); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">6. Your Rights Under GDPR</h2>
        </div>

        <p style="color:#555; line-height:1.7;">In accordance with Articles 12–23 GDPR, you have the right to:</p>
        <ul class="mb-4" style="color:#555; line-height:2;">
            <li>Access your personal data (Article 15)</li>
            <li>Correct inaccurate or incomplete data (Article 16)</li>
            <li>Request deletion of your data ("right to be forgotten") (Article 17)</li>
            <li>Restrict processing (Article 18)</li>
            <li>Request data portability (Article 20)</li>
            <li>Object to processing (Article 21)</li>
            <li>Withdraw consent at any time (Article 7(3))</li>
        </ul>

        <!-- Success Callout -->
        <div class="p-4 rounded-3" style="background:#f0fdf4; border-left:4px solid #4CAF50;">
            <div class="d-flex align-items-start gap-2 mb-2">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; margin-top:2px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <h4 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.05rem;">Exercise Your Rights</h4>
            </div>
            <p style="color:#555; line-height:1.7;">To exercise these rights, please contact us at:</p>
            <p style="color:#1a1a2e; font-weight:700; font-size:1.05rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4ECDCB" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <a href="mailto:support@seolinkbuildings.com" style="color:#4ECDCB; text-decoration:none;">support@seolinkbuildings.com</a>
            </p>
            <p class="mb-0" style="color:#555; line-height:1.7;">You also have the right to lodge a complaint with a data protection supervisory authority in accordance with Article 77 GDPR.</p>
        </div>
    </div>


    <!-- ===== 7. CHILDREN'S PRIVACY ===== -->
    <div id="children-privacy" class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#FF6F61,#ee5a6f); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">7. Children's Privacy</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">Our services are not intended for individuals under the age of 16. We do not knowingly collect personal data from children. If such data is discovered, it will be deleted promptly.</p>
    </div>


    <!-- ===== 8. EXTERNAL LINKS ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#06b6d4,#0891b2); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">8. External Links</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">Our website may contain links to third-party websites. We are not responsible for the privacy practices or content of those websites.</p>
    </div>


    <!-- ===== 9. CHANGES TO POLICY ===== -->
    <div class="mb-5 p-4 p-md-5 rounded-4 shadow-sm" style="background:white; border:1px solid #eef0f3;">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:42px; height:42px; background:linear-gradient(135deg,#8E44AD,#9b59b6); border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </div>
            <h2 class="fw-bold mb-0" style="color:#1a1a2e; font-size:1.5rem;">9. Changes to This Privacy Policy</h2>
        </div>
        <p class="mb-0" style="color:#555; line-height:1.7;">We may update this Privacy Policy from time to time. Any changes will be posted on this page, and continued use of the website indicates acceptance of the updated policy.</p>
    </div>


    <!-- ===== 10. CONTACT INFO ===== -->
    <div id="contact-info" class="mb-5 p-4 p-md-5 rounded-4" style="background:linear-gradient(135deg, #4ECDCB, #38b2ac); box-shadow:0 15px 35px rgba(78,205,203,0.25);">
        <h2 class="fw-bold mb-4" style="color:white; font-size:1.5rem;">Contact Information</h2>
        <p style="color:rgba(255,255,255,0.9); line-height:1.7; margin-bottom:1.25rem;">
            If you have any questions about this Privacy Policy, please reach out using the details below.
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
    /* Smooth scroll for anchor links */
    html {
        scroll-behavior: smooth;
    }

    /* Quick navigation hover */
    a[href^="#"]:hover {
        color: #38b2ac !important;
        padding-left: 4px;
    }
    a[href^="#"] {
        transition: all 0.2s ease;
    }
</style>

@endsection