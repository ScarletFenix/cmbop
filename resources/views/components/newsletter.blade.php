<section class="slb-section slb-newsletter">
  <div class="container" style="max-width:1100px;">
    <div class="slb-newsletter-panel">

      <div class="row align-items-center">

        <div class="col-lg-5 mb-4 mb-lg-0">
          <div class="newsletter-proof">
            <div class="newsletter-proof-icon" aria-hidden="true">
              <i class="fa-solid fa-envelope-open-text"></i>
            </div>
            <h3 class="h5 mb-2 slb-newsletter-aside-title">{{ __('messages.newsletter_aside_title') }}</h3>
            <p class="text-muted small mb-0">
              {{ __('messages.newsletter_aside_body') }}
            </p>
          </div>
        </div>

        <div class="col-lg-7">
          <form id="newsletterForm" class="w-100">
            @csrf

            <h2 class="h4 mb-3">
              {{ __('messages.newsletter_title') }}
            </h2>

            <div id="newsletterAlert" class="alert d-none mb-3" role="alert"></div>

            <!-- Email + Button -->
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="email"
                     name="email"
                     placeholder="{{ __('messages.newsletter_email_placeholder') }}"
                     class="form-control me-sm-2"
                     required
                     aria-label="Email for newsletter">

              <button type="submit" id="newsletterSubmitBtn" class="btn btn-primary">
                {{ __('messages.newsletter_subscribe_btn') }}
              </button>
            </div>

            <!-- Consent -->
            <div class="slb-newsletter-consent mb-2">
              <input type="checkbox"
                     class="form-check-input"
                     id="agreement_newsletter"
                     name="newsletter_opt_in"
                     value="1"
                     required
                     aria-required="true">
              <label class="small" for="agreement_newsletter">
                {!! str_replace(
                    e(__('messages.privacy_policy')),
                    '<a href="'.e(route('privacy-policy')).'" target="_blank" rel="noopener">'.e(__('messages.privacy_policy')).'</a>',
                    e(__('messages.newsletter_consent_text'))
                ) !!}<span class="slb-newsletter-required" aria-hidden="true">*</span>
              </label>
            </div>

            <!-- GDPR / Info -->
            <div class="text-muted small">
              <p>{{ __('messages.newsletter_gdpr_text') }}</p>
              <p>{{ __('messages.newsletter_agreement_text') }}</p>
            </div>

            <input type="hidden" name="int_com_lang" value="{{ $currentLocale ?? app()->getLocale() }}">

          </form>
        </div>

      </div>

    </div>
  </div>
</section>

<style>
.newsletter-proof {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
  padding: 0.25rem 0;
}
.newsletter-proof-icon {
  width: 64px;
  height: 64px;
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, #e6f5f5 0%, #d4f1f0 100%);
  color: #1a585e;
  font-size: 1.5rem;
  border: 1px solid rgba(26, 88, 94, 0.12);
}
.slb-newsletter-aside-title {
  color: #1a585e;
  font-family: var(--slb-font-display, Sora, sans-serif);
}

.slb-newsletter-consent {
  display: grid;
  grid-template-columns: 1.15rem minmax(0, 1fr);
  column-gap: 0.55rem;
  align-items: start;
  margin-bottom: 0.5rem;
}

.slb-newsletter-consent .form-check-input {
  grid-column: 1;
  grid-row: 1;
  float: none !important;
  margin: 0.2rem 0 0 !important;
  width: 1.05em;
  height: 1.05em;
  position: static !important;
}

.slb-newsletter-consent > label {
  grid-column: 2;
  grid-row: 1;
  margin: 0;
  padding: 0;
  line-height: 1.45;
  color: inherit;
}

.slb-newsletter-required {
  color: #dc3545;
  font-weight: 700;
  margin-left: 0.12em;
}
</style>

<script>
document.getElementById('newsletterForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const form = this;
    const alertEl = document.getElementById('newsletterAlert');
    const submitBtn = document.getElementById('newsletterSubmitBtn');
    const consent = form.querySelector('input[name="newsletter_opt_in"]').checked;

    alertEl.classList.add('d-none');
    alertEl.classList.remove('alert-success', 'alert-danger', 'alert-warning');

    if (!consent) {
        alertEl.textContent = @json(__('messages.newsletter_consent_required'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add('alert-danger');
        return;
    }

    submitBtn.disabled = true;
    const originalLabel = submitBtn.textContent;
    submitBtn.textContent = '...';

    try {
        const response = await fetch(@json(localized_url('newsletter/subscribe')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
            credentials: 'same-origin',
        });

        const data = await response.json();
        alertEl.textContent = data.message || @json(__('messages.newsletter_error_message'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add(data.success ? 'alert-success' : 'alert-danger');

        if (data.success) {
            form.reset();
        }
    } catch (err) {
        alertEl.textContent = @json(__('messages.newsletter_error_message'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add('alert-danger');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
});
</script>
