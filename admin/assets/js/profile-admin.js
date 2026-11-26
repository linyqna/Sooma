document.addEventListener('DOMContentLoaded', () => {
    const btnEditAvatar    = document.getElementById('btnEditAvatar');
    const avatarInput      = document.getElementById('avatarInput');
    const avatarUploadForm = document.getElementById('avatarUploadForm');
    const avatarWrap       = document.querySelector('.profile-card__avatar-wrap');

    if (btnEditAvatar && avatarInput && avatarUploadForm) {
        btnEditAvatar.addEventListener('click', () => {
            avatarInput.click();
        });

        avatarInput.addEventListener('change', () => {
            if (avatarInput.files && avatarInput.files.length > 0) {
                if (avatarWrap) {
                    avatarWrap.classList.add('is-uploading');
                }
                avatarUploadForm.submit();
            }
        });
    }

    const setPeekButton = (btn, shown) => {
        const icon = btn.querySelector('i');
        if (icon) {
            icon.className = shown ? 'ph ph-eye-slash' : 'ph ph-eye';
        }
        const label = shown ? 'Hide password' : 'Show password';
        btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    };
    const accountItems = document.querySelectorAll('.account-item');

    accountItems.forEach((item) => {
        const viewEl    = item.querySelector('.account-view');
        const formWrap  = item.querySelector('.account-form-wrap');
        const btnEdit   = item.querySelector('.js-edit-account');
        const btnCancel = item.querySelector('.js-cancel-account');
        const input     = item.querySelector('.inline-input');

        if (!viewEl || !formWrap || !btnEdit || !btnCancel) return;
        const peekText     = item.querySelector('[data-password-view]');
        const peekViewBtn  = item.querySelector('.js-peek-view');
        const peekInputBtn = formWrap.querySelector('.js-peek-input');
        const PEEK_MASK    = '\u2022'.repeat(8);
        let viewShown      = false;

        const renderViewPeek = () => {
            if (!peekText) return;
            const secret = peekText.dataset.secret || '';

            if (!viewShown) {
                peekText.textContent = peekText.dataset.mask || PEEK_MASK;
                peekText.classList.remove('is-fallback');
            } else if (secret !== '') {
                peekText.textContent = secret;
                peekText.classList.remove('is-fallback');
            } else {
                peekText.textContent = peekText.dataset.fallback || '';
                peekText.classList.add('is-fallback');
            }

            if (peekViewBtn) {
                setPeekButton(peekViewBtn, viewShown);
            }
        };

        const hideViewSecret = () => {
            viewShown = false;
            renderViewPeek();
        };

        const resetInputPeek = () => {
            if (peekInputBtn && input) {
                input.type = 'password';
                setPeekButton(peekInputBtn, false);
            }
        };

        if (peekText && peekViewBtn) {
            peekViewBtn.addEventListener('click', () => {
                viewShown = !viewShown;
                renderViewPeek();
            });
        }

        if (peekInputBtn && input) {
            peekInputBtn.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                setPeekButton(peekInputBtn, show);
                input.focus();
            });
        }

        btnEdit.addEventListener('click', () => {
            accountItems.forEach((other) => {
                if (other !== item) {
                    other.querySelector('.account-view')?.removeAttribute('hidden');
                    other.querySelector('.account-form-wrap')?.setAttribute('hidden', '');
                }
            });

            hideViewSecret();
            viewEl.setAttribute('hidden', '');
            formWrap.removeAttribute('hidden');
            if (input) {
                input.focus();
            }
        });

        btnCancel.addEventListener('click', () => {
            resetInputPeek();
            formWrap.setAttribute('hidden', '');
            viewEl.removeAttribute('hidden');
        });
    });

    const btnEditPersonal   = document.getElementById('btnEditPersonal');
    const btnCancelPersonal = document.getElementById('btnCancelPersonal');
    const personalViewMode  = document.getElementById('personalViewMode');
    const personalEditMode  = document.getElementById('personalEditMode');

    if (btnEditPersonal && btnCancelPersonal && personalViewMode && personalEditMode) {
        btnEditPersonal.addEventListener('click', () => {
            personalViewMode.setAttribute('hidden', '');
            personalEditMode.removeAttribute('hidden');
            btnEditPersonal.setAttribute('hidden', '');
            document.getElementById('inputName')?.focus();
        });

        btnCancelPersonal.addEventListener('click', () => {
            personalEditMode.setAttribute('hidden', '');
            personalViewMode.removeAttribute('hidden');
            btnEditPersonal.removeAttribute('hidden');
        });
    }
});