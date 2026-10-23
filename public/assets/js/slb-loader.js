(function (global) {
    'use strict';

    var anim = null;
    var count = 0;

    function root() {
        return document.getElementById('slbPageLoader');
    }

    function host() {
        var el = root();
        return el ? el.querySelector('.slb-page-loader__anim') : null;
    }

    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function ensure() {
        var box = host();
        if (!box || anim || reduced() || !global.lottie || typeof global.lottie.loadAnimation !== 'function') {
            return anim;
        }
        var src = box.getAttribute('data-lottie');
        if (!src) return null;
        anim = global.lottie.loadAnimation({
            container: box,
            renderer: 'svg',
            loop: true,
            autoplay: false,
            path: src
        });
        return anim;
    }

    function show() {
        var el = root();
        if (!el) return;
        count += 1;
        el.hidden = false;
        el.classList.add('is-on');
        el.setAttribute('aria-hidden', 'false');
        var player = ensure();
        if (player && typeof player.goToAndPlay === 'function') {
            player.goToAndPlay(0, true);
        }
    }

    function hide() {
        count = Math.max(0, count - 1);
        if (count > 0) return;
        var el = root();
        if (!el) return;
        el.classList.remove('is-on');
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        if (anim && typeof anim.stop === 'function') {
            anim.stop();
        }
    }

    function hideAll() {
        count = 1;
        hide();
    }

    global.SlbLoader = { show: show, hide: hide, hideAll: hideAll };
})(window);
