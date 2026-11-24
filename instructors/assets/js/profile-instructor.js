document.addEventListener('DOMContentLoaded', () => {
    // 1. Upload Avatar
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

    // 2. Edit Account Fields Inline (Email, Phone, Password)
    const accountItems = document.querySelectorAll('.account-item');

    accountItems.forEach((item) => {
        const viewEl   = item.querySelector('.account-view');
        const formWrap = item.querySelector('.account-form-wrap');
        const btnEdit  = item.querySelector('.js-edit-account');
        const btnCancel = item.querySelector('.js-cancel-account');
        const input    = item.querySelector('.inline-input');

        if (!viewEl || !formWrap || !btnEdit || !btnCancel) return;

        btnEdit.addEventListener('click', () => {
            // Tutup form account lain yang sedang terbuka
            accountItems.forEach((other) => {
                if (other !== item) {
                    other.querySelector('.account-view')?.removeAttribute('hidden');
                    other.querySelector('.account-form-wrap')?.setAttribute('hidden', '');
                }
            });

            viewEl.setAttribute('hidden', '');
            formWrap.removeAttribute('hidden');
            if (input) {
                input.focus();
            }
        });

        btnCancel.addEventListener('click', () => {
            formWrap.setAttribute('hidden', '');
            viewEl.removeAttribute('hidden');
        });
    });

    // 3. Edit Personal Information
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