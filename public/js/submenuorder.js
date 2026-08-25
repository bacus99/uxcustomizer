/* global Sortable */
/**
 * UX Customizer — Sub-Menu Order drag-to-reorder (config page).
 * Reorders items WITHIN a top-level category, GLOBALLY (no profile scoping).
 * Guards on the presence of #uxc-submenu-list so it's a no-op on other tabs.
 */
(function () {
    'use strict';

    const list = document.getElementById('uxc-submenu-list');
    if (!list || !window.UxcConfig) {
        return;
    }

    const status   = document.getElementById('uxc-submenu-status');
    const category = list.dataset.category;
    const ajaxUrl  = window.UxcConfig.subMenuAjax;
    const i18n     = window.UxcConfig.i18n;

    function csrfToken() {
        const m = document.querySelector("meta[property='glpi:csrf_token']");
        return m ? m.getAttribute('content') : '';
    }

    function setStatus(text, kind) {
        if (!status) return;
        status.textContent = text;
        status.className = 'uxc-status uxc-status--' + (kind || 'info');
        if (kind === 'success') {
            setTimeout(() => {
                if (status.textContent === text) { status.textContent = ''; status.className = 'uxc-status'; }
            }, 2000);
        }
    }

    function post(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        return fetch(ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrfToken() },
        })
            .then(r => r.json().catch(() => ({ ok: false, error: 'HTTP ' + r.status })))
            .catch(err => ({ ok: false, error: String(err) }));
    }

    function persist() {
        const order = Array.from(list.querySelectorAll('.uxc-submenu-item')).map(li => li.dataset.key);
        setStatus(i18n.saving, 'info');
        post({ action: 'save', category: category, order: JSON.stringify(order) })
            .then(resp => setStatus(resp.ok ? i18n.saved : i18n.failed + ' ' + (resp.error || ''), resp.ok ? 'success' : 'error'));
    }

    function initSortable() {
        if (typeof Sortable === 'undefined') { return setTimeout(initSortable, 200); }
        Sortable.create(list, {
            handle: '.uxc-handle',
            animation: 150,
            ghostClass: 'uxc-item--ghost',
            chosenClass: 'uxc-item--chosen',
            onEnd: persist,
        });
    }

    initSortable();

    // Sort alphabetically — a one-click convenience scoped to THIS list only.
    const sortBtn = document.getElementById('uxc-submenu-sort-az');
    if (sortBtn) {
        sortBtn.addEventListener('click', function () {
            const items = Array.from(list.querySelectorAll('.uxc-submenu-item'));
            items.sort((a, b) => {
                const la = a.querySelector('.fw-semibold')?.textContent.trim() ?? '';
                const lb = b.querySelector('.fw-semibold')?.textContent.trim() ?? '';
                return la.localeCompare(lb, undefined, { sensitivity: 'base' });
            });
            items.forEach(li => list.appendChild(li));
            persist();
        });
    }
})();
