(function () {
    'use strict';

    var page      = document.querySelector('.instructor-page');
    var tbody     = document.getElementById('scheduleBody');
    if (!page || !tbody) return;

    var PER_PAGE  = parseInt(page.getAttribute('data-per-page'), 10) || 2;

    var daySel    = document.getElementById('filterDay');
    var classSel  = document.getElementById('filterClass');
    var emptyRow  = document.getElementById('scheduleEmpty');
    var pager     = document.getElementById('pagination');
    var pagePrev  = document.getElementById('pagePrev');
    var pageNext  = document.getElementById('pageNext');
    var pageNums  = document.getElementById('pageNumbers');

    var state = { day: '', cls: '', page: 1 };

    function allRows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-day]'));
    }

    function matches(row) {
        if (state.day && row.getAttribute('data-day') !== state.day) return false;
        if (state.cls && row.getAttribute('data-class') !== state.cls) return false;
        return true;
    }

    function renderPagination(totalPages) {
        pageNums.textContent = '';

        for (var n = 1; n <= totalPages; n++) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'page-btn' + (n === state.page ? ' is-current' : '');
            btn.textContent = String(n);
            btn.setAttribute('data-page', String(n));
            btn.setAttribute('aria-label', 'Page ' + n);
            if (n === state.page) btn.setAttribute('aria-current', 'page');
            pageNums.appendChild(btn);
        }

        pagePrev.disabled = state.page <= 1;
        pageNext.disabled = state.page >= totalPages;
    }

    function render() {
        var rows       = allRows();
        var filtered   = rows.filter(matches);
        var totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
        state.page     = Math.min(Math.max(state.page, 1), totalPages);

        var start   = (state.page - 1) * PER_PAGE;
        var visible = filtered.slice(start, start + PER_PAGE);

        rows.forEach(function (row) {
            row.hidden = visible.indexOf(row) === -1;
            row.classList.remove('is-last-visible');
        });
        if (visible.length) visible[visible.length - 1].classList.add('is-last-visible');

        emptyRow.hidden = filtered.length > 0;
        pager.hidden    = filtered.length === 0;

        renderPagination(totalPages);
    }

    daySel.addEventListener('change', function () {
        state.day = daySel.value;
        state.page = 1;
        render();
    });
    classSel.addEventListener('change', function () {
        state.cls = classSel.value;
        state.page = 1;
        render();
    });

    pagePrev.addEventListener('click', function () { state.page--; render(); });
    pageNext.addEventListener('click', function () { state.page++; render(); });
    pageNums.addEventListener('click', function (e) {
        var btn = e.target.closest('.page-btn');
        if (!btn) return;
        state.page = parseInt(btn.getAttribute('data-page'), 10);
        render();
    });

    var overlay   = document.getElementById('sessionDetailModal');
    if (!overlay) { render(); return; }

    var modal     = overlay.querySelector('.session-modal');
    var body      = overlay.querySelector('.session-modal__body');
    var listEl    = document.getElementById('participantList');
    var emptyEl   = document.getElementById('participantEmpty');
    var closeBtns = overlay.querySelectorAll('[data-close]');

    var lastFocus = null;
    var pressedOnBackdrop = false;

    function fill(data) {
        overlay.querySelectorAll('[data-field]').forEach(function (el) {
            var key = el.getAttribute('data-field');
            var value = '';
            if (key === 'quota') {
                value = data.count + ' / ' + data.capacity + ' Participants';
            } else if (data[key] !== undefined && data[key] !== null) {
                value = data[key];
            }
            el.textContent = value;
        });

        listEl.textContent = '';
        var people = Array.isArray(data.participants) ? data.participants : [];

        people.forEach(function (person, i) {
            var note = (person.medical_note || '').trim();
            var hasNote = note !== '' && note !== '-';

            var li = document.createElement('li');

            var num = document.createElement('span');
            num.className = 'participant__num';
            num.textContent = (i + 1) + '.';

            var content = document.createElement('span');
            content.className = 'participant__body';

            var name = document.createElement('span');
            name.className = 'participant__name';
            name.textContent = person.name || '-';

            var noteEl = document.createElement('span');
            noteEl.className = 'participant__note' + (hasNote ? ' participant__note--alert' : '');
            noteEl.textContent = 'Note: ' + (note || '-');

            content.appendChild(name);
            content.appendChild(document.createTextNode(' — '));
            content.appendChild(noteEl);

            li.appendChild(num);
            li.appendChild(content);
            listEl.appendChild(li);
        });

        emptyEl.hidden = people.length > 0;
    }

    function openModal(data, trigger) {
        fill(data);
        lastFocus = trigger || document.activeElement;

        var scrollbar = window.innerWidth - document.documentElement.clientWidth;
        if (scrollbar > 0) document.body.style.paddingRight = scrollbar + 'px';
        document.body.classList.add('session-modal-open');

        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
        body.scrollTop = 0;
        modal.focus({ preventScroll: true });
    }

    function closeModal() {
        if (!overlay.classList.contains('is-open')) return;

        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('session-modal-open');
        document.body.style.paddingRight = '';

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-view-session');
        if (!btn) return;

        var data;
        try {
            data = JSON.parse(btn.getAttribute('data-session') || '{}');
        } catch (err) {
            console.warn('[instructor-dashboard.js] data-session tidak valid:', err);
            return;
        }
        openModal(data, btn);
    });

    closeBtns.forEach(function (btn) { btn.addEventListener('click', closeModal); });

    overlay.addEventListener('mousedown', function (e) { pressedOnBackdrop = (e.target === overlay); });
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
            var focusable = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
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
    
    render();
})();