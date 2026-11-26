(() => {
    'use strict';

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const root = $('.instructor-page');
    if (!root) return;

    let DATA = {};
    try {
        DATA = JSON.parse(($('#instructor-data') || {}).textContent || '{}');
    } catch (err) {
        console.warn('[instructor-list.js] Data halaman tidak valid:', err);
    }

    const STATUS_LABELS = DATA.statusLabels || {};
    const SAVE_URL      = root.getAttribute('data-save-endpoint') || '';
    const DELETE_URL    = root.getAttribute('data-delete-endpoint') || '';

    const PW_MASK = '••••••••';
    const PW_UNAVAILABLE = 'Not available (edit password once to enable)';
    const DEFAULT_COLOR_HEX = '#f4d66d';
    const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

    const list          = $('#instructorList');
    const emptyState    = $('#emptyState');
    const resultCount   = $('#resultCount');
    const searchInput   = $('#filterSearch');
    const titleFilter   = $('#filterTitle');
    const titleWrap     = titleFilter.closest('.filter-control');
    const openAddBtn    = $('#openAdd');
    const cardTemplate  = $('#instructorCardTemplate');
    const toastEl       = $('#dashToast');

    const detailModal      = $('#detailModal');
    const detailForm       = $('#detailForm');
    const detailPhoto      = $('#detailPhoto');
    const detailPhotoInput = $('#detailPhotoInput');
    const detailPhotoLabel = $('#detailPhotoLabel');
    const detailSwatch     = $('#detailSwatch');
    const editToggle       = $('#editToggle');
    const editLabel        = $('#editToggleLabel');
    const pwToggle         = $('#togglePassword');
    const pwIcon           = $('#togglePasswordIcon');
    const detailSave       = $('#detailSave');
    const detailDelete     = $('#detailDelete');
    const scheduleLink     = $('#scheduleLink');

    const addModal      = $('#addModal');
    const addForm       = $('#addForm');
    const addSave       = $('#addSave');
    const addDelete     = $('#addDelete');
    const photoInput    = $('#addPhoto');
    const uploadBox     = $('#uploadBox');
    const uploadPreview = $('#uploadPreview');

    const pad = (n) => String(n).padStart(2, '0');
    const todayISO = () => {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };
    const formatDate = (iso) => {
        const d = new Date(`${iso}T00:00:00`);
        return Number.isNaN(d.getTime())
            ? iso
            : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    };
    const cleanName = (s) => s.trim().replace(/\s+/g, ' ').replace(/^coach\s+/i, '');
    const field = (form, name) => form.elements.namedItem(name);
    const statusLabel = (value) => STATUS_LABELS[value] || value;

    function setBusy(button, busy) {
        button.disabled = busy;
        button.classList.toggle('is-loading', busy);
    }

    let toastTimer = null;
    function toast(message, isError = false) {
        if (!toastEl) return;
        toastEl.textContent = message;
        toastEl.classList.toggle('is-error', isError);
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3200);
    }

    async function sendForm(url, formData) {
        if (!url) return { ok: true, message: '', data: {} };

        formData.set('csrf_token', DATA.csrf || '');
        try {
            const res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
            const json = await res.json().catch(() => null);
            if (!json || typeof json !== 'object') {
                return { ok: false, message: `Unexpected server response (${res.status}).`, data: {} };
            }
            if (!res.ok || json.success !== true) {
                return { ok: false, message: json.message || `Request failed (${res.status}).`, data: {} };
            }
            return { ok: true, message: json.message || '', data: json.data || {} };
        } catch (err) {
            console.warn('[instructor-list.js] Request gagal:', err);
            return { ok: false, message: 'Network error. Please try again.', data: {} };
        }
    }

    function watchPhoto(frame) {
        const img = $('img', frame);
        if (!img) return;
        const missing = () => { frame.classList.add('is-missing'); img.hidden = true; };
        const ok = () => { frame.classList.remove('is-missing'); img.hidden = false; };
        img.addEventListener('error', missing);
        img.addEventListener('load', ok);
        if (!img.getAttribute('src')) missing();
        else if (img.complete && img.naturalWidth === 0) missing();
    }

    function setPhoto(frame, src, name) {
        const img = $('img', frame);
        frame.dataset.initial = (name || '?').trim().charAt(0).toUpperCase() || '?';
        if (src) {
            img.alt = `Photo of Coach ${name}`;
            if (img.getAttribute('src') !== src) img.src = src;
        } else {
            img.removeAttribute('src');
            img.alt = '';
            img.hidden = true;
            frame.classList.add('is-missing');
        }
    }

    $$('[data-photo-frame]').forEach(watchPhoto);

    function renderCard(card) {
        const d = card.dataset;
        setPhoto($('.instructor-photo', card), d.photo, d.name);
        $('.instructor-card__name', card).textContent = `Coach ${d.name}`;
        $('.instructor-card__title', card).textContent = d.title;
        card.setAttribute('aria-label', `Coach ${d.name}, ${d.title}. Open profile`);
    }

    function buildCard(data) {
        const li = cardTemplate.content.firstElementChild.cloneNode(true);
        const card = $('.instructor-card', li);
        Object.assign(card.dataset, data);
        renderCard(card);
        watchPhoto($('.instructor-photo', li));
        return li;
    }

    const existingIds = () => new Set($$('.instructor-card', list).map((c) => c.dataset.id));

    function generateCoachId() {
        const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const pick = () => letters[Math.floor(Math.random() * letters.length)];
        const taken = existingIds();
        let id;
        do {
            id = `CO-${pick()}${pad(Math.floor(Math.random() * 100))}${pick()}`;
        } while (taken.has(id));
        return id;
    }

    function applyFilters() {
        const q = searchInput.value.toLowerCase().trim().replace(/^#/, '').replace(/^coach\s+/, '');
        const title = titleFilter.value;
        let visible = 0;

        $$('.instructor-card', list).forEach((card) => {
            const d = card.dataset;
            const haystack = `${d.id} coach ${d.name} ${d.title} ${d.speciality}`.toLowerCase();
            const show = (!q || haystack.includes(q)) && (!title || d.title === title);
            card.closest('li').hidden = !show;
            if (show) visible += 1;
        });

        emptyState.hidden = visible !== 0;
        titleWrap.classList.toggle('is-active', Boolean(title));
        resultCount.textContent = `${visible} instructor${visible === 1 ? '' : 's'} shown`;
    }

    searchInput.addEventListener('input', applyFilters);
    titleFilter.addEventListener('change', applyFilters);

    let lastTrigger = null;
    let overlayPressed = false;

    function openModal(overlay, trigger) {
        lastTrigger = trigger || document.activeElement;
        document.body.classList.add('dash-modal-open');
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        const dialog = $('.instructor-modal', overlay);
        requestAnimationFrame(() => dialog.focus({ preventScroll: true }));
    }

    function closeModal(overlay) {
        if (!overlay.classList.contains('is-open')) return;
        if (overlay === detailModal) {
            clearDetailPreview();
            setEditing(false, { silent: true });
            disarmDelete();
            setPasswordVisible(false);
        }
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('dash-modal-open');
        if (lastTrigger && document.contains(lastTrigger) && typeof lastTrigger.focus === 'function') {
            lastTrigger.focus({ preventScroll: true });
        }
    }

    [detailModal, addModal].forEach((overlay) => {
        overlay.addEventListener('mousedown', (e) => { overlayPressed = e.target === overlay; });
        overlay.addEventListener('click', (e) => {
            if (overlayPressed && e.target === overlay) closeModal(overlay);
            overlayPressed = false;
        });
    });

    document.addEventListener('keydown', (e) => {
        const open = $$('.dash-modal-overlay.is-open').pop();
        if (!open) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal(open);
            return;
        }
        if (e.key === 'Tab') {
            const dialog = $('.instructor-modal', open);
            const focusables = $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', dialog)
                .filter((el) => el.offsetParent !== null);
            if (!focusables.length) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    let activeCard = null;
    let editing = false;
    let pwVisible = false;
    let detailPreviewURL = null;

    const view = (key) => $(`[data-view="${key}"]`, detailModal);

    function clearDetailPreview() {
        if (detailPreviewURL) URL.revokeObjectURL(detailPreviewURL);
        detailPreviewURL = null;
        if (detailPhotoInput) detailPhotoInput.value = '';
    }

    function populateDetail(d) {
        view('name').textContent = d.name;
        view('title').textContent = d.speciality;
        view('id').textContent = `#${d.id}`;
        view('joined').textContent = formatDate(d.joined);
        view('whatsapp').textContent = d.whatsapp;
        view('email').textContent = d.email;
        view('sessions').textContent = `${d.sessions} Session`;
        view('status').textContent = statusLabel(d.status);
        view('colorName').textContent = d.colorName;
        detailSwatch.style.background = d.colorHex;
        scheduleLink.href = `schedule-class.php?instructor=${encodeURIComponent(d.id)}`;
        setPhoto(detailPhoto, d.photo, d.name);
        if (detailPhotoLabel) {
            detailPhotoLabel.textContent = d.photo ? 'Change Photo' : 'Upload Photo';
        }

        field(detailForm, 'coach_id').value = d.id;
        field(detailForm, 'joined').value = d.joined;
        field(detailForm, 'name').value = d.name;
        field(detailForm, 'title').value = d.title;
        field(detailForm, 'speciality').value = d.speciality;
        field(detailForm, 'whatsapp').value = d.whatsapp;
        field(detailForm, 'email').value = d.email;
        field(detailForm, 'password').value = d.password || '';
        field(detailForm, 'password').placeholder = SAVE_URL ? 'Leave blank to keep current' : '';
        field(detailForm, 'status').value = d.status;
        field(detailForm, 'color_name').value = d.colorName;
        field(detailForm, 'color_hex').value = d.colorHex || DEFAULT_COLOR_HEX;
        setPasswordVisible(pwVisible);
    }

    function setPasswordVisible(show) {
        pwVisible = show;
        field(detailForm, 'password').type = show ? 'text' : 'password';
        const storedPw = (activeCard && activeCard.dataset.password) || '';
        view('password').textContent = show ? (storedPw || PW_UNAVAILABLE) : PW_MASK;
        pwIcon.className = show ? 'ph ph-eye-slash' : 'ph ph-eye';
        pwToggle.setAttribute('aria-pressed', String(show));
        pwToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    }

    function setEditing(on, { silent = false } = {}) {
        editing = on;
        $('.instructor-modal', detailModal).classList.toggle('is-editing', on);
        editToggle.setAttribute('aria-pressed', String(on));
        editLabel.textContent = on ? 'Cancel edit' : 'Edit Profile';
        detailSave.disabled = !on;
        detailSave.title = on ? '' : 'Click Edit Profile to make changes';
        disarmDelete();
        if (!on && activeCard && !silent) {
            clearDetailPreview();
            populateDetail(activeCard.dataset);
        }
        if (on) field(detailForm, 'name').focus();
    }

    function openDetail(card) {
        activeCard = card;
        pwVisible = false;
        clearDetailPreview();
        populateDetail(card.dataset);
        setEditing(false, { silent: true });
        openModal(detailModal, card);
    }

    list.addEventListener('click', (e) => {
        const card = e.target.closest('.instructor-card');
        if (card) openDetail(card);
    });

    editToggle.addEventListener('click', () => setEditing(!editing));
    pwToggle.addEventListener('click', () => setPasswordVisible(!pwVisible));

    if (detailPhotoInput) {
        detailPhotoInput.addEventListener('change', () => {
            const file = detailPhotoInput.files[0];
            if (!file) return;

            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                detailPhotoInput.value = '';
                toast('Choose an image file (JPG, PNG, or WebP).', true);
                return;
            }
            if (file.size > MAX_PHOTO_BYTES) {
                detailPhotoInput.value = '';
                toast('Photo is too large. Maximum size is 2 MB.', true);
                return;
            }

            if (detailPreviewURL) URL.revokeObjectURL(detailPreviewURL);
            detailPreviewURL = URL.createObjectURL(file);

            const name = (activeCard && activeCard.dataset.name) || field(detailForm, 'name').value || '?';
            setPhoto(detailPhoto, detailPreviewURL, name);
            if (detailPhotoLabel) detailPhotoLabel.textContent = 'Change Photo';

            if (!editing) {
                setEditing(true, { silent: true });
            }
        });
    }

    detailForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!editing || !activeCard || detailSave.classList.contains('is-loading')) return;

        const name = cleanName(field(detailForm, 'name').value);
        if (!name) {
            toast('Enter the instructor name.', true);
            field(detailForm, 'name').focus();
            return;
        }
        if (!detailForm.reportValidity()) return;

        const fd = new FormData(detailForm);
        fd.set('action', 'update');
        fd.set('name', name);
        if (detailPhotoInput && detailPhotoInput.files[0]) {
            fd.set('photo', detailPhotoInput.files[0]);
        }

        setBusy(detailSave, true);
        const result = await sendForm(SAVE_URL, fd);
        setBusy(detailSave, false);
        if (!result.ok) {
            toast(result.message || 'Unable to save the profile.', true);
            return;
        }

        const srv = result.data || {};
        const newPassword = field(detailForm, 'password').value;
        const updates = {
            name,
            title: field(detailForm, 'title').value,
            speciality: field(detailForm, 'speciality').value.trim(),
            whatsapp: field(detailForm, 'whatsapp').value.trim(),
            email: field(detailForm, 'email').value.trim(),
            status: field(detailForm, 'status').value,
            colorName: field(detailForm, 'color_name').value.trim(),
            colorHex: field(detailForm, 'color_hex').value,
        };
        if (srv.photo) {
            updates.photo = srv.photo;
        } else if (detailPreviewURL && !SAVE_URL) {
            updates.photo = detailPreviewURL;
        }
        if (typeof srv.password === 'string') {
            updates.password = srv.password;
        } else if (newPassword && !SAVE_URL) {
            updates.password = newPassword;
        }

        if (srv.photo || SAVE_URL) {
            clearDetailPreview();
        } else {
            detailPreviewURL = null;
            if (detailPhotoInput) detailPhotoInput.value = '';
        }

        Object.assign(activeCard.dataset, updates);
        renderCard(activeCard);
        populateDetail(activeCard.dataset);
        setEditing(false, { silent: true });
        applyFilters();
        toast(result.message || 'Profile saved');
    });

    let deleteArmed = false;
    let deleteTimer = null;

    function disarmDelete() {
        deleteArmed = false;
        clearTimeout(deleteTimer);
        detailDelete.classList.remove('is-armed');
        detailDelete.textContent = 'Delete';
    }

    detailDelete.addEventListener('click', async () => {
        if (!deleteArmed) {
            deleteArmed = true;
            detailDelete.classList.add('is-armed');
            detailDelete.textContent = 'Confirm?';
            deleteTimer = setTimeout(disarmDelete, 4000);
            return;
        }

        const card = activeCard;
        if (!card) return;
        const name = card.dataset.name;

        const fd = new FormData();
        fd.set('action', 'delete');
        fd.set('coach_id', card.dataset.id);

        setBusy(detailDelete, true);
        const result = await sendForm(DELETE_URL || SAVE_URL, fd);
        setBusy(detailDelete, false);
        if (!result.ok) {
            disarmDelete();
            toast(result.message || 'Unable to delete the instructor.', true);
            return;
        }

        lastTrigger = openAddBtn;
        closeModal(detailModal);
        card.closest('li').remove();
        activeCard = null;
        applyFilters();
        toast(result.message || `Coach ${name} deleted`);
    });

    let previewURL = null;

    function clearPreview() {
        if (previewURL) URL.revokeObjectURL(previewURL);
        previewURL = null;
        uploadPreview.removeAttribute('src');
        uploadPreview.hidden = true;
        uploadBox.classList.remove('has-photo');
        photoInput.value = '';
    }

    function setAddMeta({ keepId = false } = {}) {
        const idInput = $('#addCoachIdInput');
        if (!keepId || !idInput.value) {
            const id = generateCoachId();
            idInput.value = id;
            $('#addCoachId').textContent = `#${id}`;
        }
        const iso = todayISO();
        $('#addJoined').textContent = formatDate(iso);
        $('#addJoinedInput').value = iso;
    }

    function resetAddForm() {
        addForm.reset();
        clearPreview();
        setAddMeta();
    }

    openAddBtn.addEventListener('click', () => {
        setAddMeta({ keepId: true });
        openModal(addModal, openAddBtn);
    });

    photoInput.addEventListener('change', () => {
        const file = photoInput.files[0];
        if (!file) { clearPreview(); return; }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            clearPreview();
            toast('Choose an image file (JPG, PNG, or WebP).', true);
            return;
        }
        if (file.size > MAX_PHOTO_BYTES) {
            clearPreview();
            toast('Photo is too large. Maximum size is 2 MB.', true);
            return;
        }
        if (previewURL) URL.revokeObjectURL(previewURL);
        previewURL = URL.createObjectURL(file);
        uploadPreview.src = previewURL;
        uploadPreview.hidden = false;
        uploadBox.classList.add('has-photo');
    });

    addDelete.addEventListener('click', () => {
        resetAddForm();
        closeModal(addModal);
        toast('Draft discarded');
    });

    addForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (addSave.classList.contains('is-loading')) return;

        const name = cleanName(field(addForm, 'name').value);
        if (!name) {
            toast('Enter the instructor name.', true);
            field(addForm, 'name').focus();
            return;
        }
        if (!addForm.reportValidity()) return;

        const fd = new FormData(addForm);
        fd.set('action', 'create');
        fd.set('name', name);

        setBusy(addSave, true);
        const result = await sendForm(SAVE_URL, fd);
        setBusy(addSave, false);
        if (!result.ok) {
            toast(result.message || 'Unable to add the instructor.', true);
            return;
        }

        const srv = result.data || {};
        const data = {
            id: srv.id || field(addForm, 'coach_id').value,
            name,
            title: field(addForm, 'title').value,
            speciality: field(addForm, 'speciality').value.trim(),
            joined: srv.joined || field(addForm, 'joined').value,
            whatsapp: field(addForm, 'whatsapp').value.trim(),
            email: field(addForm, 'email').value.trim(),
            password: typeof srv.password === 'string'
                ? srv.password
                : (SAVE_URL ? '' : field(addForm, 'password').value),
            sessions: '0',
            colorName: field(addForm, 'color_name').value.trim(),
            colorHex: field(addForm, 'color_hex').value || DEFAULT_COLOR_HEX,
            status: field(addForm, 'status').value,
            photo: srv.photo || previewURL || '',
        };

        const li = buildCard(data);
        list.appendChild(li);

        if (!srv.photo) previewURL = null;
        searchInput.value = '';
        titleFilter.value = '';
        applyFilters();
        resetAddForm();
        lastTrigger = openAddBtn;
        closeModal(addModal);
        li.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        toast(result.message || `Coach ${name} added`);
    });

    applyFilters();
})();