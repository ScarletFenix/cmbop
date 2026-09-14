{{-- Visitor chat (public + advertiser/publisher). Order chat is separate. --}}
@php
    $tawkSrc = \App\Support\TawkChat::embedSrc();
    $tawkVisitor = null;
    if ($tawkSrc && auth()->check()) {
        $user = auth()->user();
        $name = trim((string) ($user->name ?? ''));
        $email = trim((string) ($user->email ?? ''));
        if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $tawkVisitor = ['name' => $name, 'email' => $email];
        }
    }
@endphp
@if ($tawkSrc)
<style>
.slb-tawk-launcher {
  position: fixed;
  right: 16px;
  bottom: 20px;
  z-index: 1080;
  width: 56px;
  height: 56px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: var(--brand-primary, #1a585e);
  color: #fff;
  box-shadow: 0 10px 24px rgba(26, 88, 94, 0.28);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.slb-tawk-launcher:hover { background: var(--brand-primary-deep, #123f42); }
.slb-tawk-launcher:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(58, 174, 178, 0.4), 0 10px 24px rgba(11, 98, 102, 0.28);
}
html.tawk-open .slb-tawk-launcher { display: none; }
html:not(.tawk-open) iframe[title="chat widget"],
html:not(.tawk-open) iframe[title="Chat widget"],
html:not(.tawk-open) iframe[src*="tawk.to"] {
  opacity: 0 !important;
  pointer-events: none !important;
}
</style>
<button type="button" class="slb-tawk-launcher" id="slbTawkLauncher" aria-label="Open customer support" title="Customer support">
  <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="currentColor">
    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/>
  </svg>
</button>
<script>
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
Tawk_API.customStyle = {
  zIndex: 1080,
  visibility: {
    desktop: { position: 'br', xOffset: 16, yOffset: 20 },
    mobile: { position: 'br', xOffset: 10, yOffset: 16 }
  }
};
@if ($tawkVisitor)
Tawk_API.visitor = {!! json_encode($tawkVisitor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
@endif
(function () {
  var pinning = false;
  var observer = null;
  var bootCollapse = true;
  var tawkIframes = 'iframe[title="chat widget"], iframe[title="Chat widget"], iframe[src*="tawk.to"]';

  function pinBox(el) {
    if (!el || !el.style) return;
    el.style.setProperty('position', 'fixed', 'important');
    el.style.setProperty('top', 'auto', 'important');
    el.style.setProperty('left', 'auto', 'important');
    el.style.setProperty('right', '16px', 'important');
    el.style.setProperty('bottom', '20px', 'important');
    el.style.setProperty('margin', '0', 'important');
    el.style.setProperty('transform', 'none', 'important');
    el.style.setProperty('z-index', '1080', 'important');
    el.style.setProperty('flex', '0 0 auto', 'important');
    el.style.setProperty('align-self', 'flex-end', 'important');
  }

  function setOpen(open) {
    document.documentElement.classList.toggle('tawk-open', !!open);
  }

  function watchTawk() {
    if (!observer || !document.body) return;
    observer.disconnect();
    observer.observe(document.body, { childList: true, subtree: true });
    document.querySelectorAll(tawkIframes).forEach(function (iframe) {
      observer.observe(iframe, { attributes: true, attributeFilter: ['style', 'src', 'title'] });
      var wrap = iframe.parentElement;
      if (wrap && wrap !== document.body && wrap !== document.documentElement) {
        observer.observe(wrap, { attributes: true, attributeFilter: ['style'] });
      }
    });
  }

  window.slbPinTawk = function () {
    if (pinning) return;
    pinning = true;
    if (observer) observer.disconnect();
    try {
      var open = document.documentElement.classList.contains('tawk-open');
      document.querySelectorAll(tawkIframes).forEach(function (iframe) {
        pinBox(iframe);
        if (open) {
          iframe.style.setProperty('max-width', 'min(400px, calc(100vw - 24px))', 'important');
          iframe.style.setProperty('max-height', 'min(640px, calc(100dvh - 24px))', 'important');
        } else {
          iframe.style.removeProperty('max-width');
          iframe.style.removeProperty('max-height');
        }
        var wrap = iframe.parentElement;
        if (wrap && wrap !== document.body && wrap !== document.documentElement) {
          pinBox(wrap);
          wrap.style.setProperty('width', 'auto', 'important');
          wrap.style.setProperty('height', 'auto', 'important');
        }
      });
    } finally {
      pinning = false;
      watchTawk();
    }
  };

  function collapseUnlessRequested() {
    if (window.slbTawkKeepOpen) return;
    setOpen(false);
    if (window.Tawk_API && typeof window.Tawk_API.minimize === 'function') {
      window.Tawk_API.minimize();
    }
    window.slbPinTawk();
  }

  if (document.body) {
    observer = new MutationObserver(function () { window.slbPinTawk(); });
    watchTawk();
  }
  window.addEventListener('resize', function () { window.slbPinTawk(); });

  Tawk_API.onLoad = function () {
    collapseUnlessRequested();
    setTimeout(collapseUnlessRequested, 400);
    setTimeout(function () {
      collapseUnlessRequested();
      bootCollapse = false;
    }, 1500);
  };
  Tawk_API.onChatMaximized = function () {
    if (bootCollapse && !window.slbTawkKeepOpen) {
      collapseUnlessRequested();
      return;
    }
    setOpen(true);
    window.slbPinTawk();
  };
  Tawk_API.onChatMinimized = function () {
    window.slbTawkKeepOpen = false;
    setOpen(false);
    window.slbPinTawk();
  };
})();
window.slbOpenSupport = function () {
  window.slbTawkKeepOpen = true;
  function openTawk() {
    if (window.Tawk_API && typeof window.Tawk_API.maximize === 'function') {
      document.documentElement.classList.add('tawk-open');
      window.Tawk_API.maximize();
      window.slbPinTawk && window.slbPinTawk();
      return true;
    }
    return false;
  }
  if (openTawk()) return;
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) {
    toggle.click();
    return;
  }
  var tries = 0;
  var timer = setInterval(function () {
    tries += 1;
    if (openTawk() || tries > 25) {
      clearInterval(timer);
      if (!document.documentElement.classList.contains('tawk-open')) {
        window.slbTawkKeepOpen = false;
      }
    }
  }, 200);
};
document.getElementById('slbTawkLauncher')?.addEventListener('click', function () {
  window.slbOpenSupport();
});
(function(){
var s1=document.createElement('script'),s0=document.getElementsByTagName('script')[0];
s1.async=true;
s1.src={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES) !!};
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
@else
<script>
window.slbOpenSupport = function () {
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) toggle.click();
};
</script>
@endif
