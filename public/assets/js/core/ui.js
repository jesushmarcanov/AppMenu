/* Utilidades de interfaz compartidas por el menú y el panel. */

import { icon } from './icons.js';

export const qs = (selector, root = document) => root.querySelector(selector);
export const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

export function debounce(fn, delay = 220) {
    let timer = 0;
    return (...args) => {
        clearTimeout(timer);
        timer = window.setTimeout(() => fn(...args), delay);
    };
}

export function initials(name) {
    return String(name || '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || 'AM';
}

export function formatMoney(value, currency = {}) {
    const decimals = Number.isInteger(Number(currency?.decimals)) ? Number(currency.decimals) : 2;
    const code = String(currency?.code || 'USD').toUpperCase();
    const locale = String(currency?.locale || 'en-US');
    const amount = Number(value || 0);

    try {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: code,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(amount);
    } catch {
        const symbol = currency?.symbol || '$';
        return `${symbol}${amount.toFixed(decimals)}`;
    }
}

export function formatDate(dateString, locale = 'es') {
    if (!dateString) return '';
    const date = new Date(`${String(dateString).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(dateString);

    return new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'es-ES', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(date);
}

export function formatDateLong(dateString, locale = 'es') {
    if (!dateString) return '';
    const date = new Date(`${String(dateString).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(dateString);

    return new Intl.DateTimeFormat(locale === 'en' ? 'en-US' : 'es-ES', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(date);
}

export function timeLabel(value) {
    return String(value || '').slice(0, 5);
}

export function todayISO(offsetDays = 0) {
    const date = new Date();
    date.setDate(date.getDate() + offsetDays);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/* ------------------------------------------------------------------- toasts */

export function toast(message, type = 'info', timeout = 4500) {
    let stack = qs('#toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'toast-stack';
        stack.className = 'toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.append(stack);
    }

    const node = document.createElement('div');
    node.className = `toast${type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : ''}`;
    const iconName = type === 'error' ? 'alert' : type === 'success' ? 'check' : 'info';
    node.innerHTML = `${icon(iconName, 18)}<div>${escapeHtml(message)}</div>`;
    stack.append(node);

    while (stack.children.length > 4) {
        stack.firstElementChild?.remove();
    }

    const remove = () => {
        node.classList.add('is-leaving');
        window.setTimeout(() => node.remove(), 220);
    };

    window.setTimeout(remove, timeout);
    node.addEventListener('click', remove);
}

/* --------------------------------------------------------------- body lock */

let lockCount = 0;

export function lockBody() {
    lockCount += 1;
    document.body.classList.add('is-locked');
}

export function unlockBody() {
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount === 0) {
        document.body.classList.remove('is-locked');
    }
}

/* -------------------------------------------------------------- modal base */

let modalCounter = 0;

export function openModal({
    title = '',
    body = '',
    footer = '',
    size = '',
    className = '',
    ariaLabel = '',
    initialFocus = null,
    onClose = null,
} = {}) {
    const id = `modal-title-${++modalCounter}`;
    const previousFocus = document.activeElement;
    const layer = document.createElement('div');
    layer.className = 'modal-layer';

    const labelAttr = title ? `aria-labelledby="${id}"` : `aria-label="${escapeHtml(ariaLabel || 'Diálogo')}"`;
    layer.innerHTML = `
        <div class="modal-backdrop" data-modal-close></div>
        <section class="modal ${size === 'lg' ? 'modal-lg' : ''} ${className}" role="dialog" aria-modal="true" ${labelAttr}>
            ${title ? `<header class="modal-head"><h3 id="${id}">${escapeHtml(title)}</h3><button class="btn-icon" type="button" data-modal-close aria-label="Cerrar">${icon('close', 18)}</button></header>` : ''}
            <div class="modal-body" data-modal-body></div>
            ${footer ? `<footer class="modal-foot" data-modal-foot>${footer}</footer>` : ''}
        </section>
    `;

    const modal = layer.querySelector('.modal');
    const bodyNode = layer.querySelector('[data-modal-body]');

    if (body instanceof Node) {
        bodyNode.append(body);
    } else {
        bodyNode.innerHTML = body;
    }

    document.body.append(layer);
    lockBody();

    let closed = false;

    const close = () => {
        if (closed) return;
        closed = true;
        layer.remove();
        unlockBody();
        document.removeEventListener('keydown', onKeydown, true);
        if (previousFocus instanceof HTMLElement) {
            previousFocus.focus({ preventScroll: true });
        }
        if (typeof onClose === 'function') {
            onClose();
        }
    };

    function onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }

        if (event.key !== 'Tab') return;

        const focusable = qsa(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            modal,
        ).filter((node) => node.offsetParent !== null || node === document.activeElement);

        if (focusable.length === 0) {
            event.preventDefault();
            modal.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    layer.addEventListener('click', (event) => {
        if (event.target.closest('[data-modal-close]')) {
            event.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', onKeydown, true);

    window.setTimeout(() => {
        const target = (typeof initialFocus === 'string' ? qs(initialFocus, layer) : null)
            || qs('[autofocus]', layer)
            || qs('input:not([type="hidden"]), select, textarea, button', layer)
            || modal;
        target?.focus({ preventScroll: true });
    }, 30);

    return { layer, modal, body: bodyNode, close };
}

export function confirmDialog({
    title = 'Confirmar',
    message = '¿Deseas continuar?',
    confirmLabel = 'Confirmar',
    cancelLabel = 'Cancelar',
    danger = false,
} = {}) {
    return new Promise((resolve) => {
        let settled = false;
        const modal = openModal({
            title,
            body: `<p class="muted" style="font-size:14.5px">${escapeHtml(message)}</p>`,
            footer: `
                <button class="btn btn-ghost" type="button" data-cancel>${escapeHtml(cancelLabel)}</button>
                <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" type="button" data-confirm>${escapeHtml(confirmLabel)}</button>
            `,
            onClose: () => {
                if (!settled) {
                    settled = true;
                    resolve(false);
                }
            },
        });

        modal.layer.querySelector('[data-cancel]')?.addEventListener('click', () => {
            settled = true;
            modal.close();
            resolve(false);
        });

        modal.layer.querySelector('[data-confirm]')?.addEventListener('click', () => {
            settled = true;
            modal.close();
            resolve(true);
        });
    });
}

/* ------------------------------------------------------------- utilidades */

export function setButtonBusy(button, busy, labelWhenBusy = 'Guardando…') {
    if (!(button instanceof HTMLButtonElement)) return;
    if (busy) {
        button.dataset.label = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<span class="spinner" aria-hidden="true"></span> ${escapeHtml(labelWhenBusy)}`;
    } else {
        button.disabled = false;
        if (button.dataset.label) {
            button.innerHTML = button.dataset.label;
            delete button.dataset.label;
        }
    }
}

export function clearFieldErrors(form) {
    qsa('.field-error', form).forEach((node) => {
        node.textContent = '';
    });
    qsa('.is-invalid', form).forEach((node) => node.classList.remove('is-invalid'));
}

export function applyFieldErrors(form, fields = {}) {
    Object.entries(fields || {}).forEach(([name, message]) => {
        const errorNode = qs(`[data-error-for="${CSS.escape(name)}"]`, form)
            || qs(`[data-error-for="${CSS.escape(name.split('.').pop())}"]`, form);
        if (errorNode) {
            errorNode.textContent = message;
        }

        const input = form.elements?.[name] || qs(`[name="${CSS.escape(name)}"]`, form);
        if (input instanceof HTMLElement) {
            input.classList.add('is-invalid');
        }
    });
}

export function serializeForm(form) {
    const data = {};
    const formData = new FormData(form);

    for (const [key, value] of formData.entries()) {
        if (key.endsWith('[]')) {
            const clean = key.slice(0, -2);
            data[clean] = data[clean] || [];
            data[clean].push(value);
        } else if (key in data) {
            data[key] = Array.isArray(data[key]) ? [...data[key], value] : [data[key], value];
        } else {
            data[key] = value;
        }
    }

    return data;
}

export function externalLink(url) {
    return String(url || '').startsWith('http') ? url : '';
}

export function whatsappLink(number, message) {
    const digits = String(number || '').replace(/\D/g, '');
    return digits ? `https://wa.me/${digits}?text=${encodeURIComponent(message)}` : '';
}

export function observeReveals(root = document) {
    const nodes = qsa('.reveal:not(.is-visible)', root);
    if (nodes.length === 0) return;

    if (!('IntersectionObserver' in window)) {
        nodes.forEach((node) => node.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    nodes.forEach((node) => observer.observe(node));
}
