(function () {
    'use strict';

    var form       = document.getElementById('purchaseForm');
    var agree      = document.getElementById('agreeTerms');
    var agreeError = document.getElementById('agreeError');

    var overlay    = document.getElementById('termsModal');
    var openLink   = document.getElementById('openTerms');

    function showAgreeError(show) {
        if (!agreeError || !agree) return;
        agreeError.hidden = !show;
        if (show) {
            agree.setAttribute('aria-invalid', 'true');
            agree.setAttribute('aria-describedby', 'agreeError');
        } else {
            agree.removeAttribute('aria-invalid');
            agree.removeAttribute('aria-describedby');
        }
    }

    if (form && agree) {
        agree.addEventListener('change', function () {
            showAgreeError(!agree.checked);
        });

        form.addEventListener('submit', function (e) {
            if (!agree.checked) {
                e.preventDefault();
                showAgreeError(true);
                agree.focus();
                return;
            }

            if (!form.checkValidity()) {
                e.preventDefault();
                form.reportValidity();
                return;
            }

            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
        });

        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                var btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = false;
            }
        });
    }

    if (!overlay) return;

    var modal     = overlay.querySelector('.terms-modal');
    var closeBtns = overlay.querySelectorAll('.terms-modal__close, .terms-modal__done');
    var body      = overlay.querySelector('.terms-modal__body');
    var lastFocus = null;
    var pressedOnBackdrop = false;

    function openModal(trigger) {
        lastFocus = trigger || document.activeElement;

        var scrollbar = window.innerWidth - document.documentElement.clientWidth;
        if (scrollbar > 0) document.body.style.paddingRight = scrollbar + 'px';
        document.body.classList.add('terms-open');

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        if (body) body.scrollTop = 0;
        modal.focus({ preventScroll: true });
    }

    function closeModal() {
        if (!overlay.classList.contains('is-open')) return;

        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('terms-open');
        document.body.style.paddingRight = '';

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    if (openLink) {
        openLink.addEventListener('click', function (e) {
            e.preventDefault();              
            openModal(openLink);
        });
    }

    closeBtns.forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    overlay.addEventListener('mousedown', function (e) {
        pressedOnBackdrop = (e.target === overlay);
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay && pressedOnBackdrop) closeModal();
        pressedOnBackdrop = false;
    });

    document.addEventListener('keydown', function (e) {
        if (!overlay.classList.contains('is-open')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
            return;
        }

        if (e.key === 'Tab') {
            var focusable = modal.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusable.length) return;

            var first = focusable[0];
            var last  = focusable[focusable.length - 1];

            if (e.shiftKey && (document.activeElement === first || document.activeElement === modal)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });
})();