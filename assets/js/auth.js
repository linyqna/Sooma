(function () {
    'use strict';

    const layout = document.querySelector('.auth-layout');
    if (!layout) return;

    layout.addEventListener('click', function (event) {
        const btn = event.target.closest('.password-toggle-btn');
        if (!btn || !layout.contains(btn)) return;

        const wrap  = btn.closest('.password-input-wrap');
        const input = wrap ? wrap.querySelector('input') : null;
        if (!input) return;

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';

        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.remove(show ? 'ph-eye' : 'ph-eye-slash');
            icon.classList.add(show ? 'ph-eye-slash' : 'ph-eye');
        }

        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        input.focus();
    });

    const pollBox = document.getElementById('reset-poll-box');
    if (pollBox) {
        const pollUrl    = pollBox.getAttribute('data-poll-url') || 'auth/check-reset-status.php';
        const statusText = document.getElementById('poll-status-text');
        let isRedirecting = false;

        const checkVerificationStatus = async function () {
            if (isRedirecting) return;

            try {
                const response = await fetch(pollUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                if (!response.ok) return;

                const data = await response.json();
                if (data && data.verified && data.redirect_url) {
                    isRedirecting = true;
                    if (statusText) {
                        statusText.textContent = 'Email verified! Redirecting to Set New Password...';
                    }
                    window.location.href = data.redirect_url;
                }
            } catch (err) {
            }
        };

        const pollInterval = window.setInterval(checkVerificationStatus, 2000);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                checkVerificationStatus();
            }
        });

        window.addEventListener('beforeunload', function () {
            window.clearInterval(pollInterval);
        });
    }

    const resetForm = document.getElementById('reset-password-form');
    if (resetForm) {
        const newPassInput     = document.getElementById('new-password');
        const confirmPassInput = document.getElementById('confirm-password');
        const clientErrorBox   = document.getElementById('client-reset-error');

        const showResetError = function (message) {
            if (!clientErrorBox) return;
            clientErrorBox.textContent = message;
            clientErrorBox.classList.remove('is-hidden');
        };

        const hideResetError = function () {
            if (!clientErrorBox) return;
            clientErrorBox.textContent = '';
            clientErrorBox.classList.add('is-hidden');
        };

        confirmPassInput.addEventListener('input', hideResetError);
        newPassInput.addEventListener('input', hideResetError);

        resetForm.addEventListener('submit', function (event) {
            if (newPassInput.value.length < 8) {
                event.preventDefault();
                showResetError('Password must be at least 8 characters long.');
                newPassInput.focus();
                return;
            }

            if (newPassInput.value !== confirmPassInput.value) {
                event.preventDefault();
                showResetError('New password and confirmation password do not match.');
                confirmPassInput.focus();
            }
        });
    }

    const loadingForms = layout.querySelectorAll('form[data-submit-loading]');
    loadingForms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = form.getAttribute('data-submit-loading') || 'Processing...';
            }
        });
    });
})();