/* ==========================================================================
   AppMenu · panel de administración
   SPA sin dependencias: sesión, navegación por hash y vistas del panel.
   ========================================================================== */

import { ApiClient, ApiError } from '../core/api.js';
import { icon } from '../core/icons.js';
import {
    qs,
    qsa,
    escapeHtml,
    debounce,
    initials,
    formatMoney,
    formatDate,
    formatDateLong,
    timeLabel,
    todayISO,
    toast,
    openModal,
    confirmDialog,
    setButtonBusy,
    clearFieldErrors,
    applyFieldErrors,
    whatsappLink,
} from '../core/ui.js';

const api = new ApiClient('../api/v1');

const state = {
    user: null,
    branding: {},
    publicSettings: null,
    current: '',
    categories: [],
    categoryQuery: '',
    products: [],
    productsMeta: null,
    productFilters: { page: 1, per_page: 20, q: '', category_id: '', status: '' },
    reservations: [],
    reservationsMeta: null,
    reservationFilters: { page: 1, per_page: 20, q: '', status: '', date_from: '', date_to: '' },
    auditPage: 1,
};

let renderToken = 0;

/* --------------------------------------------------------------- navegación */

const NAV = [
    {
        group: 'Operación',
        items: [
            { key: 'dashboard', label: 'Resumen', icon: 'grid', subtitle: 'Estado general del restaurante' },
            { key: 'reservations', label: 'Reservaciones', icon: 'calendar', subtitle: 'Solicitudes, confirmaciones y notas' },
        ],
    },
    {
        group: 'Carta',
        items: [
            { key: 'categories', label: 'Categorías', icon: 'tag', subtitle: 'Agrupa y ordena la carta' },
            { key: 'products', label: 'Productos', icon: 'list', subtitle: 'Platos, precios y disponibilidad' },
        ],
    },
    {
        group: 'Difusión',
        items: [
            { key: 'qr', label: 'Código QR', icon: 'qr', subtitle: 'Comparte el menú público' },
        ],
    },
    {
        group: 'Sistema',
        adminOnly: true,
        items: [
            { key: 'settings', label: 'Configuración', icon: 'settings', subtitle: 'Negocio, moneda, horarios y reservas' },
            { key: 'users', label: 'Usuarios', icon: 'users', subtitle: 'Equipo, roles y acceso' },
            { key: 'audit', label: 'Auditoría', icon: 'shield', subtitle: 'Registro de actividad del panel' },
        ],
    },
];

const NAV_ITEMS = NAV.flatMap((group) => group.items);
const ADMIN_ONLY = new Set(['settings', 'users', 'audit']);

const VIEWS = {
    dashboard: renderDashboardView,
    categories: renderCategoriesView,
    products: renderProductsView,
    reservations: renderReservationsView,
    qr: renderQrView,
    settings: renderSettingsView,
    users: renderUsersView,
    audit: renderAuditView,
};

const ROLE_LABELS = {
    admin: 'Administrador',
    editor: 'Editor',
};

const RESERVATION_STATUS = {
    pending: { label: 'Pendiente', badge: 'badge-gold' },
    confirmed: { label: 'Confirmada', badge: 'badge-success' },
    cancelled: { label: 'Cancelada', badge: 'badge-danger' },
    completed: { label: 'Completada', badge: 'badge-muted' },
};

const AUDIT_ACTIONS = {
    'auth.login': ['Inició sesión', 'success'],
    'auth.logout': ['Cerró sesión', ''],
    'auth.login_failed': ['Intento de acceso fallido', 'danger'],
    'auth.password_changed': ['Cambió su contraseña', 'success'],
    'category.created': ['Creó una categoría', 'success'],
    'category.updated': ['Actualizó una categoría', ''],
    'category.deleted': ['Eliminó una categoría', 'danger'],
    'category.reordered': ['Reordenó las categorías', ''],
    'product.created': ['Creó un producto', 'success'],
    'product.updated': ['Actualizó un producto', ''],
    'product.deleted': ['Eliminó un producto', 'danger'],
    'product.reordered': ['Reordenó los productos', ''],
    'product.availability_changed': ['Cambió la disponibilidad', ''],
    'product.featured_changed': ['Cambió el producto destacado', ''],
    'product.image_uploaded': ['Subió una imagen de producto', ''],
    'product.image_deleted': ['Eliminó una imagen de producto', 'danger'],
    'reservation.updated': ['Actualizó una reservación', ''],
    'reservation.deleted': ['Eliminó una reservación', 'danger'],
    'settings.updated': ['Actualizó la configuración', ''],
    'settings.image_uploaded': ['Subió una imagen de marca', ''],
    'user.created': ['Creó un usuario', 'success'],
    'user.updated': ['Actualizó un usuario', ''],
    'user.deactivated': ['Desactivó un usuario', 'danger'],
};

const TIMEZONES = [
    'America/Caracas',
    'America/Bogota',
    'America/Mexico_City',
    'America/New_York',
    'America/Los_Angeles',
    'America/Argentina/Buenos_Aires',
    'America/Santiago',
    'America/Lima',
    'America/Panama',
    'America/Santo_Domingo',
    'America/Puerto_Rico',
    'America/Sao_Paulo',
    'Europe/Madrid',
    'Europe/London',
    'Europe/Paris',
    'Europe/Rome',
    'Africa/Casablanca',
    'UTC',
];

const CURRENCIES = ['USD', 'EUR', 'MXN', 'COP', 'ARS', 'CLP', 'PEN', 'VES', 'BRL', 'DOP', 'GTQ', 'CRC', 'PAB', 'UYU'];

const DAYS = [
    { iso: 1, key: 'monday', label: 'Lunes' },
    { iso: 2, key: 'tuesday', label: 'Martes' },
    { iso: 3, key: 'wednesday', label: 'Miércoles' },
    { iso: 4, key: 'thursday', label: 'Jueves' },
    { iso: 5, key: 'friday', label: 'Viernes' },
    { iso: 6, key: 'saturday', label: 'Sábado' },
    { iso: 7, key: 'sunday', label: 'Domingo' },
];

/* ------------------------------------------------------------------ helpers */

function normalize(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

function firstName(name) {
    return String(name || '').trim().split(/\s+/)[0] || '';
}

function greeting() {
    const hour = new Date().getHours();
    if (hour < 12) return 'Buenos días';
    if (hour < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

function roleLabel(role) {
    return ROLE_LABELS[role] || role || 'Usuario';
}

function formatDateTime(value, dateOnly = false) {
    if (!value) return '—';
    const iso = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat('es-ES', dateOnly
        ? { day: '2-digit', month: 'short', year: 'numeric' }
        : { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date);
}

function timeAgo(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return formatDateTime(value);

    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return 'hace un momento';
    if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
    if (diff < 604800) return `hace ${Math.floor(diff / 86400)} d`;

    return new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' }).format(date);
}

function auditLabel(action) {
    return AUDIT_ACTIONS[action] || [String(action || 'Actividad'), ''];
}

function reservationStatus(status) {
    return RESERVATION_STATUS[status] || { label: status || '—', badge: 'badge-muted' };
}

function loadingPanel(message = 'Cargando…') {
    return `<div class="loading-panel"><span class="spinner" aria-hidden="true"></span><p>${escapeHtml(message)}</p></div>`;
}

function failurePanel(message = 'No pudimos cargar la información.') {
    return `<div class="admin-empty">
        <h3>Algo salió mal</h3>
        <p>${escapeHtml(message)}</p>
        <button class="btn btn-outline" type="button" data-retry>Reintentar</button>
    </div>`;
}

function bindRetry() {
    qs('[data-retry]')?.addEventListener('click', () => renderRoute(state.current));
}

function isCurrent(token) {
    return token === renderToken;
}

function fieldValue(form, name) {
    const element = form.querySelector(`[name="${CSS.escape(name)}"]`);
    return element ? String(element.value ?? '').trim() : '';
}

function fieldChecked(form, name) {
    const element = form.querySelector(`[name="${CSS.escape(name)}"]`);
    return Boolean(element?.checked);
}

function field(name, {
    label,
    value = '',
    type = 'text',
    required = false,
    placeholder = '',
    hint = '',
    errorKey = name,
    attrs = '',
    rows = 3,
} = {}) {
    const id = `f-${String(name).replace(/[^a-z0-9]+/gi, '-')}`;
    const common = `id="${id}" name="${escapeHtml(name)}"${required ? ' required' : ''}${placeholder ? ` placeholder="${escapeHtml(placeholder)}"` : ''}`;
    const control = type === 'textarea'
        ? `<textarea class="textarea" ${common} rows="${rows}" ${attrs}>${escapeHtml(value)}</textarea>`
        : `<input class="input" ${common} type="${type}" value="${escapeHtml(value)}" ${attrs}>`;

    return `<div class="field">
        <label for="${id}">${escapeHtml(label)}${required ? ' *' : ''}</label>
        ${control}
        ${hint ? `<span class="form-hint">${escapeHtml(hint)}</span>` : ''}
        <span class="field-error" data-error-for="${escapeHtml(errorKey)}"></span>
    </div>`;
}

function imageThumb(url, emoji, alt = '') {
    if (url) {
        return `<img src="${escapeHtml(url)}" alt="${escapeHtml(alt)}" loading="lazy">`;
    }
    return emoji ? escapeHtml(emoji) : icon('image', 18);
}

function initialsAvatar(name) {
    return `<span class="user-avatar">${escapeHtml(initials(name))}</span>`;
}

/* ------------------------------------------------------------------- sesión */

function hideScreens() {
    ['#boot', '#login-root', '#password-root', '#app-shell'].forEach((selector) => {
        const node = qs(selector);
        if (node) node.hidden = true;
    });
}

function showLogin() {
    hideScreens();
    const screen = qs('#login-root');
    if (screen) screen.hidden = false;

    const loginForm = qs('#login-form');
    if (loginForm) {
        clearFieldErrors(loginForm);
    }

    const password = qs('#login-password');
    if (password) password.value = '';

    document.title = 'Acceso · Panel · AppMenu';
    window.setTimeout(() => qs('#login-email')?.focus({ preventScroll: true }), 60);
}

function showPassword(forced = true) {
    hideScreens();
    const screen = qs('#password-root');
    if (screen) screen.hidden = false;

    const form = qs('#password-form');
    if (form) {
        form.reset();
        clearFieldErrors(form);
    }

    const intro = qs('#password-intro');
    if (intro) {
        intro.textContent = forced
            ? 'Por seguridad, debes reemplazar la contraseña temporal antes de continuar.'
            : 'Actualiza la contraseña de tu cuenta.';
    }

    const submit = qs('#password-submit');
    if (submit) submit.textContent = 'Guardar y continuar';

    document.title = 'Cambiar contraseña · Panel · AppMenu';
    window.setTimeout(() => qs('#current-password')?.focus({ preventScroll: true }), 60);
}

function applyUser() {
    if (!state.user) return;
    const nameNode = qs('#user-name');
    const roleNode = qs('#user-role');
    const avatarNode = qs('#user-avatar');

    if (nameNode) nameNode.textContent = state.user.name;
    if (roleNode) roleNode.textContent = roleLabel(state.user.role);
    if (avatarNode) avatarNode.textContent = initials(state.user.name);
}

function applyBranding(business = {}) {
    state.branding = business;
    const name = String(business.name || 'AppMenu').trim() || 'AppMenu';
    const tagline = String(business.tagline || 'Menú digital');
    const logo = String(business.logo_url || '');

    const loginBrand = qs('#login-brand');
    const loginTagline = qs('#login-tagline');
    const shellBrand = qs('#shell-brand-name');

    if (loginBrand) loginBrand.textContent = name;
    if (loginTagline) loginTagline.textContent = tagline;
    if (shellBrand) shellBrand.textContent = name;

    ['#login-mark', '#shell-brand-mark'].forEach((selector) => {
        const node = qs(selector);
        if (!node) return;
        node.innerHTML = logo
            ? `<img src="${escapeHtml(logo)}" alt="">`
            : escapeHtml(initials(name));
    });

    const publicUrl = state.publicSettings?.public_menu_url || new URL('../', window.location.href).href;
    ['#sidebar-public', '#view-public'].forEach((selector) => {
        const link = qs(selector);
        if (!link) return;
        link.href = publicUrl;
        link.target = '_blank';
        link.rel = 'noopener';
    });

    const currentItem = NAV_ITEMS.find((item) => item.key === state.current);
    document.title = `${currentItem?.label || 'Panel'} · Panel · ${name}`;
}

function renderNav() {
    const nav = qs('#admin-nav');
    if (!nav || !state.user) return;

    nav.innerHTML = NAV.map((group) => {
        if (group.adminOnly && state.user.role !== 'admin') return '';

        const links = group.items.map((item) => `
            <a class="nav-link" href="#/${item.key}" data-nav="${item.key}">
                ${icon(item.icon, 17)}
                <span>${escapeHtml(item.label)}</span>
            </a>
        `).join('');

        return `<div class="nav-group">
            <p class="nav-group-label">${escapeHtml(group.group)}</p>
            <div class="nav-list">${links}</div>
        </div>`;
    }).join('');
}

function updateChrome(name) {
    const item = NAV_ITEMS.find((entry) => entry.key === name) || NAV_ITEMS[0];
    const title = qs('#page-title');
    const subtitle = qs('#page-subtitle');

    if (title) title.textContent = item.label;
    if (subtitle) subtitle.textContent = item.subtitle;

    qsa('.nav-link').forEach((link) => {
        const active = link.dataset.nav === name;
        link.classList.toggle('is-active', active);
        if (active) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    document.title = `${item.label} · Panel · ${state.branding?.name || 'AppMenu'}`;
}

function showShell() {
    hideScreens();
    const shell = qs('#app-shell');
    if (shell) shell.hidden = false;

    renderNav();
    applyUser();
    applyBranding(state.publicSettings?.business || state.branding || {});
    closeSidebar();

    const route = routeFromHash();
    if (!window.location.hash || !NAV_ITEMS.some((item) => item.key === route)) {
        history.replaceState(null, '', '#/dashboard');
    }

    const safeRoute = ADMIN_ONLY.has(route) && state.user.role !== 'admin' ? 'dashboard' : route;
    renderRoute(safeRoute);
}

function openSidebar() {
    qs('#app-shell')?.classList.add('is-sidebar-open');
    const backdrop = qs('#sidebar-backdrop');
    if (backdrop) backdrop.hidden = false;
    qs('#sidebar-toggle')?.setAttribute('aria-expanded', 'true');
}

function closeSidebar() {
    qs('#app-shell')?.classList.remove('is-sidebar-open');
    const backdrop = qs('#sidebar-backdrop');
    if (backdrop) backdrop.hidden = true;
    qs('#sidebar-toggle')?.setAttribute('aria-expanded', 'false');
}

function routeFromHash() {
    const raw = String(window.location.hash || '').replace(/^#\/?/, '').split('?')[0].trim();
    return NAV_ITEMS.some((item) => item.key === raw) ? raw : 'dashboard';
}

function navigate(name) {
    const target = `#/${name}`;
    if (window.location.hash === target) {
        renderRoute(name);
        return;
    }
    window.location.hash = target;
}

async function handleRoute() {
    if (!state.user) return;

    let name = routeFromHash();
    if (ADMIN_ONLY.has(name) && state.user.role !== 'admin') {
        toast('No tienes permisos para esa sección.', 'error');
        name = 'dashboard';
        history.replaceState(null, '', '#/dashboard');
    }

    renderRoute(name);
}

let expiring = false;

function expireSession() {
    if (expiring) return;
    expiring = true;
    api.setCsrf(null);
    state.user = null;
    showLogin();
    toast('Tu sesión expiró. Vuelve a iniciar sesión.', 'error');
    window.setTimeout(() => {
        expiring = false;
    }, 600);
}

function handleApiError(error, form = null) {
    if (!(error instanceof ApiError)) {
        toast('Ocurrió un error inesperado. Intenta de nuevo.', 'error');
        return;
    }

    if (error.status === 401) {
        expireSession();
        return;
    }

    if (error.code === 'PASSWORD_CHANGE_REQUIRED') {
        showPassword(true);
        return;
    }

    if (error.code === 'FORBIDDEN') {
        toast(error.message, 'error');
        if (state.user && state.user.role !== 'admin') {
            navigate('dashboard');
        }
        return;
    }

    if (form) {
        clearFieldErrors(form);
        applyFieldErrors(form, error.fields);
    }

    toast(error.message, 'error');
}

async function guardRequest(promise, token = null, form = null) {
    try {
        return await promise;
    } catch (error) {
        if (token !== null && token !== renderToken) return null;
        handleApiError(error, form);
        return null;
    }
}

async function perform(action, { form = null, success = '', onSuccess = null } = {}) {
    try {
        const result = await action();
        if (success) toast(success, 'success');
        if (onSuccess) await onSuccess(result);
        return { ok: true, result };
    } catch (error) {
        handleApiError(error, form);
        return { ok: false, error };
    }
}

async function logout() {
    try {
        await api.post('auth/logout');
    } catch {
        /* La sesión pudo expirar: continuamos igualmente. */
    }

    api.setCsrf(null);
    state.user = null;
    history.replaceState(null, '', window.location.pathname);
    showLogin();
    toast('Sesión cerrada.', 'info', 2500);
}

/* ------------------------------------------------------------------ arranque */

async function loadBranding() {
    try {
        const settings = await api.get('settings/public');
        if (settings) {
            state.publicSettings = settings;
            applyBranding(settings.business || {});
        }
    } catch {
        /* El panel funciona sin datos públicos de marca. */
    }
}

async function boot() {
    bindStaticEvents();
    await loadBranding();

    try {
        const session = await api.get('auth/me');
        api.setCsrf(session?.csrf_token);
        state.user = session?.user || null;

        if (session?.must_change_password) {
            applyUser();
            showPassword(true);
        } else if (state.user) {
            applyUser();
            showShell();
        } else {
            showLogin();
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            showLogin();
        } else {
            showLogin();
            if (error instanceof ApiError) {
                toast(error.message, 'error');
            }
        }
    }

    const bootNode = qs('#boot');
    if (bootNode) bootNode.hidden = true;
}

function bindStaticEvents() {
    qs('#login-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;

        clearFieldErrors(form);
        const submit = qs('#login-submit');
        setButtonBusy(submit, true, 'Entrando…');

        try {
            const data = await api.post('auth/login', {
                email: fieldValue(form, 'email'),
                password: fieldValue(form, 'password'),
            });

            api.setCsrf(data.csrf_token);
            state.user = data.user;
            applyUser();

            if (data.must_change_password) {
                showPassword(true);
            } else {
                showShell();
                toast(`Bienvenido, ${firstName(state.user.name)}.`, 'success', 3000);
            }
        } catch (error) {
            if (error instanceof ApiError) {
                clearFieldErrors(form);
                applyFieldErrors(form, error.fields);
                toast(error.message, 'error');
            } else {
                toast('No pudimos conectar con el servidor. Revisa tu conexión.', 'error');
            }
        } finally {
            setButtonBusy(submit, false);
        }
    });

    qs('#password-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        if (!form.reportValidity()) return;

        clearFieldErrors(form);
        const password = fieldValue(form, 'password');
        const confirmation = fieldValue(form, 'password_confirmation');

        if (password.length < 10) {
            applyFieldErrors(form, { password: 'La contraseña debe tener al menos 10 caracteres.' });
            return;
        }

        if (password !== confirmation) {
            applyFieldErrors(form, { password_confirmation: 'Las contraseñas no coinciden.' });
            return;
        }

        const submit = qs('#password-submit');
        setButtonBusy(submit, true, 'Guardando…');

        try {
            const data = await api.post('auth/change-password', {
                current_password: fieldValue(form, 'current_password'),
                password,
                password_confirmation: confirmation,
            });

            api.setCsrf(data.csrf_token);
            state.user = data.user;
            applyUser();
            toast('Contraseña actualizada.', 'success');
            showShell();
        } catch (error) {
            handleApiError(error, form);
        } finally {
            setButtonBusy(submit, false);
        }
    });

    qs('#password-logout')?.addEventListener('click', logout);
    qs('#logout-btn')?.addEventListener('click', logout);

    qs('#sidebar-toggle')?.addEventListener('click', () => {
        if (qs('#app-shell')?.classList.contains('is-sidebar-open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    qs('#sidebar-close')?.addEventListener('click', closeSidebar);
    qs('#sidebar-backdrop')?.addEventListener('click', closeSidebar);

    qs('#admin-nav')?.addEventListener('click', (event) => {
        if (event.target.closest('.nav-link')) closeSidebar();
    });

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-toggle-password]');
        if (!toggle) return;

        const input = qs(`#${CSS.escape(toggle.dataset.togglePassword)}`);
        if (!(input instanceof HTMLInputElement)) return;

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        toggle.innerHTML = icon(show ? 'eyeOff' : 'eye', 18);
    });

    document.addEventListener('input', (event) => {
        const input = event.target.closest('.is-invalid');
        if (!input) return;
        input.classList.remove('is-invalid');
        const error = input.closest('.field')?.querySelector('.field-error');
        if (error) error.textContent = '';
    });

    window.addEventListener('hashchange', handleRoute);

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSidebar();
    });
}

/* ------------------------------------------------------------------ router */

async function renderRoute(name) {
    if (!state.user) return;

    const safeName = VIEWS[name] ? name : 'dashboard';
    renderToken += 1;
    const token = renderToken;

    state.current = safeName;
    updateChrome(safeName);
    closeSidebar();
    view.innerHTML = loadingPanel();

    try {
        await VIEWS[safeName](token);
    } catch (error) {
        if (!isCurrent(token)) return;
        handleApiError(error);
        if (!isCurrent(token)) return;
        view.innerHTML = failurePanel();
        bindRetry();
    }
}

const view = qs('#view');

/* --------------------------------------------------------------- dashboard */

function statCard(label, value, iconName, tone = '') {
    return `<article class="stat-card${tone ? ` is-${tone}` : ''}">
        <span class="stat-icon">${icon(iconName, 17)}</span>
        <p class="stat-value">${escapeHtml(String(value ?? 0))}</p>
        <p class="stat-label">${escapeHtml(label)}</p>
    </article>`;
}

function activityItem(row) {
    const [label, tone] = auditLabel(row.action);
    return `<div class="activity-item">
        <span class="activity-dot${tone ? ` is-${tone}` : ''}"></span>
        <div>
            <strong>${escapeHtml(label)}</strong>
            <small>${escapeHtml(row.user_name || 'Sistema')} · ${escapeHtml(timeAgo(row.created_at))}</small>
        </div>
    </div>`;
}

function upcomingItem(row, index) {
    const date = new Date(`${String(row.reservation_date).slice(0, 10)}T00:00:00`);
    const valid = !Number.isNaN(date.getTime());
    const day = valid ? String(date.getDate()).padStart(2, '0') : '—';
    const month = valid
        ? new Intl.DateTimeFormat('es-ES', { month: 'short' }).format(date).replace('.', '')
        : '';
    const status = reservationStatus(row.status);

    return `<button class="reservation-item" type="button" data-upcoming-index="${index}">
        <span class="reservation-date"><strong>${escapeHtml(day)}</strong><small>${escapeHtml(month)}</small></span>
        <span class="reservation-info">
            <strong>${escapeHtml(row.name)}</strong>
            <small>${escapeHtml(timeLabel(row.reservation_time))} · ${escapeHtml(String(row.party_size))} personas</small>
        </span>
        <span class="badge ${status.badge}">${escapeHtml(status.label)}</span>
    </button>`;
}

async function renderDashboardView(token) {
    const data = await guardRequest(api.get('admin/dashboard'), token);
    if (!isCurrent(token)) return;

    if (!data) {
        view.innerHTML = failurePanel('No pudimos cargar el resumen del restaurante.');
        bindRetry();
        return;
    }

    const stats = data.stats || {};
    const upcoming = Array.isArray(data.upcoming_reservations) ? data.upcoming_reservations : [];
    const activity = Array.isArray(data.recent_activity) ? data.recent_activity : [];

    view.innerHTML = `
        <div class="page-actions">
            <p>${escapeHtml(greeting())}, ${escapeHtml(firstName(state.user.name))}. Este es el estado de tu restaurante hoy.</p>
            <div class="row">
                <button class="btn btn-outline btn-sm" type="button" data-refresh>${icon('refresh', 15)} Actualizar</button>
                <button class="btn btn-primary btn-sm" type="button" data-new-product>${icon('plus', 15)} Nuevo producto</button>
            </div>
        </div>

        <div class="stat-grid">
            ${statCard('Productos activos', stats.products_active ?? 0, 'list')}
            ${statCard('Agotados', stats.products_sold_out ?? 0, 'alert', 'warning')}
            ${statCard('Destacados', stats.products_featured ?? 0, 'star')}
            ${statCard('Categorías activas', stats.categories_active ?? 0, 'tag')}
            ${statCard('Reservas hoy', stats.reservations_today ?? 0, 'calendar', 'success')}
            ${statCard('Reservas pendientes', stats.reservations_pending ?? 0, 'clock', 'warning')}
        </div>

        <div class="dashboard-grid">
            <div class="stack-18">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Próximas reservas</h2>
                            <span class="small">Solicitudes pendientes y confirmadas</span>
                        </div>
                        <a class="btn btn-ghost btn-sm" href="#/reservations">Ver todas ${icon('arrowRight', 14)}</a>
                    </div>
                    ${upcoming.length
                        ? `<div class="reservation-list">${upcoming.map(upcomingItem).join('')}</div>`
                        : `<div class="admin-empty"><h3>Sin reservas próximas</h3><p>Cuando recibas una solicitud aparecerá aquí.</p></div>`}
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Actividad reciente</h2>
                            <span class="small">Últimos movimientos del equipo</span>
                        </div>
                        ${state.user.role === 'admin' ? `<a class="btn btn-ghost btn-sm" href="#/audit">Ver registro ${icon('arrowRight', 14)}</a>` : ''}
                    </div>
                    ${activity.length
                        ? `<div class="activity-list">${activity.map(activityItem).join('')}</div>`
                        : `<div class="admin-empty"><h3>Sin actividad todavía</h3><p>Los cambios del panel se registrarán aquí.</p></div>`}
                </section>
            </div>

            <div class="stack-18">
                <section class="panel">
                    <div class="panel-head"><h2>Accesos rápidos</h2></div>
                    <div class="panel-body quick-links">
                        <a class="quick-link" href="#/categories">${icon('tag', 17)} Gestionar categorías ${icon('chevronRight', 15)}</a>
                        <a class="quick-link" href="#/products">${icon('list', 17)} Editar la carta ${icon('chevronRight', 15)}</a>
                        <a class="quick-link" href="#/reservations">${icon('calendar', 17)} Revisar reservaciones ${icon('chevronRight', 15)}</a>
                        <a class="quick-link" href="#/qr">${icon('qr', 17)} Descargar el código QR ${icon('chevronRight', 15)}</a>
                        <a class="quick-link" href="${escapeHtml(state.publicSettings?.public_menu_url || '../')}" target="_blank" rel="noopener">${icon('external', 17)} Ver el menú público ${icon('chevronRight', 15)}</a>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-head"><h2>Estado de la carta</h2></div>
                    <div class="panel-body">
                        <div class="detail-grid">
                            <div class="detail-item"><span>Productos totales</span><strong>${escapeHtml(String(stats.products_total ?? 0))}</strong></div>
                            <div class="detail-item"><span>Activos</span><strong>${escapeHtml(String(stats.products_active ?? 0))}</strong></div>
                            <div class="detail-item"><span>Destacados</span><strong>${escapeHtml(String(stats.products_featured ?? 0))}</strong></div>
                            <div class="detail-item"><span>Agotados</span><strong>${escapeHtml(String(stats.products_sold_out ?? 0))}</strong></div>
                        </div>
                        <p class="form-hint" style="margin-top:12px">Los productos agotados pueden ocultarse del menú público desde la configuración.</p>
                    </div>
                </section>
            </div>
        </div>
    `;

    qs('[data-refresh]')?.addEventListener('click', () => renderRoute('dashboard'));
    qs('[data-new-product]')?.addEventListener('click', () => navigate('products'));

    qsa('[data-upcoming-index]').forEach((button) => {
        button.addEventListener('click', () => {
            const reservation = upcoming[Number(button.dataset.upcomingIndex)];
            if (reservation) {
                openReservationModal(reservation, () => renderRoute('dashboard'));
            }
        });
    });
}

/* -------------------------------------------------------------- categorías */

function categoryTranslations(category) {
    const translations = category?.translations || {};
    return {
        es: {
            name: translations.es?.name || category?.name || '',
            description: translations.es?.description || category?.description || '',
        },
        en: {
            name: translations.en?.name || '',
            description: translations.en?.description || '',
        },
    };
}

function categoryRow(category) {
    const translations = categoryTranslations(category);
    const english = translations.en.name;
    const active = Boolean(category.is_active);
    const orderLocked = Boolean(normalize(state.categoryQuery));
    const index = state.categories.findIndex((item) => item.id === category.id);

    return `<tr>
        <td class="nowrap">
            <div class="order-cell">
                <button class="mini-btn" type="button" data-action="up" data-id="${category.id}"
                    ${orderLocked || index <= 0 ? 'disabled' : ''} aria-label="Subir ${escapeHtml(category.name)}">
                    ${icon('arrowUp', 14)}
                </button>
                <span>${escapeHtml(String(category.sort_order))}</span>
                <button class="mini-btn" type="button" data-action="down" data-id="${category.id}"
                    ${orderLocked || index >= state.categories.length - 1 ? 'disabled' : ''} aria-label="Bajar ${escapeHtml(category.name)}">
                    ${icon('arrowDown', 14)}
                </button>
            </div>
        </td>
        <td>
            <div class="cell-title">
                <strong>${escapeHtml(category.name)}</strong>
                <small>${escapeHtml(category.description || category.slug)}</small>
                ${english ? `<span class="badge badge-muted" style="margin-top:5px">EN · ${escapeHtml(english)}</span>` : ''}
            </div>
        </td>
        <td><span class="badge badge-muted">${escapeHtml(String(category.product_count ?? 0))} productos</span></td>
        <td>
            <button class="toggle${active ? ' is-on' : ''}" type="button" role="switch" aria-checked="${active}"
                data-action="toggle" data-id="${category.id}" title="${active ? 'Ocultar categoría' : 'Mostrar categoría'}">
                <span class="toggle-track"></span>
                <span>${active ? 'Activa' : 'Oculta'}</span>
            </button>
        </td>
        <td class="actions-col">
            <div class="row-actions">
                <button class="mini-btn" type="button" data-action="edit" data-id="${category.id}" aria-label="Editar ${escapeHtml(category.name)}" title="Editar">
                    ${icon('edit', 15)}
                </button>
                <button class="mini-btn is-danger" type="button" data-action="delete" data-id="${category.id}" aria-label="Eliminar ${escapeHtml(category.name)}" title="Eliminar">
                    ${icon('trash', 15)}
                </button>
            </div>
        </td>
    </tr>`;
}

function refreshCategoryRows() {
    if (state.current !== 'categories') return;

    const tbody = qs('#category-rows');
    if (!tbody) return;

    const query = normalize(state.categoryQuery);
    const visible = state.categories.filter((category) => {
        if (!query) return true;
        const translations = categoryTranslations(category);
        return normalize([
            category.name,
            category.slug,
            category.description,
            translations.es.name,
            translations.es.description,
            translations.en.name,
            translations.en.description,
        ].join(' ')).includes(query);
    });

    const table = qs('#categories-table');
    const empty = qs('#categories-empty');
    const count = qs('#category-count');

    if (count) {
        count.textContent = query
            ? `${visible.length} de ${state.categories.length} categorías`
            : `${state.categories.length} ${state.categories.length === 1 ? 'categoría' : 'categorías'}`;
    }

    if (visible.length === 0) {
        if (table) table.hidden = true;
        if (empty) {
            empty.hidden = false;
            empty.innerHTML = state.categories.length === 0
                ? `<h3>Aún no hay categorías</h3><p>Crea la primera categoría para empezar a organizar la carta.</p><button class="btn btn-primary" type="button" data-empty-new>${icon('plus', 15)} Nueva categoría</button>`
                : `<h3>Sin coincidencias</h3><p>Prueba con otro nombre o limpia la búsqueda.</p>`;
            empty.querySelector('[data-empty-new]')?.addEventListener('click', () => openCategoryModal(null));
        }
        return;
    }

    if (table) table.hidden = false;
    if (empty) empty.hidden = true;
    tbody.innerHTML = visible.map(categoryRow).join('');
}

async function reloadCategories() {
    const categories = await guardRequest(api.get('admin/categories'));
    if (!categories) return;
    state.categories = categories;
    refreshCategoryRows();
}

function openCategoryModal(category = null) {
    const translations = categoryTranslations(category);
    const active = category ? Boolean(category.is_active) : true;

    const modal = openModal({
        title: category ? 'Editar categoría' : 'Nueva categoría',
        size: 'lg',
        body: `
            <form id="category-form" class="modal-form" novalidate>
                <div>
                    <p class="modal-section-title">Español</p>
                    <div class="form-grid">
                        ${field('translations.es.name', { label: 'Nombre', value: translations.es.name, required: true, placeholder: 'Ej. Entradas' })}
                        ${field('translations.es.description', { label: 'Descripción (opcional)', value: translations.es.description, type: 'textarea', rows: 3, hint: 'Se muestra debajo del nombre de la categoría.' })}
                    </div>
                </div>
                <hr class="divider">
                <div>
                    <p class="modal-section-title">English</p>
                    <div class="form-grid">
                        ${field('translations.en.name', { label: 'Name', value: translations.en.name, placeholder: 'e.g. Starters' })}
                        ${field('translations.en.description', { label: 'Description (optional)', value: translations.en.description, type: 'textarea', rows: 3 })}
                    </div>
                    <p class="form-hint" style="margin-top:8px">Si dejas el inglés vacío, el menú público usará el español.</p>
                </div>
                <hr class="divider">
                <div class="form-grid">
                    ${field('slug', { label: 'Slug', value: category?.slug || '', placeholder: 'se-genera-automaticamente', hint: 'Identificador para la URL. Puedes dejarlo vacío.' })}
                    <div class="field">
                        <label>Visibilidad</label>
                        <label class="check">
                            <input type="checkbox" name="is_active" ${active ? 'checked' : ''}>
                            <span>Visible en el menú público</span>
                        </label>
                    </div>
                </div>
            </form>
        `,
        footer: `
            <button class="btn btn-ghost" type="button" data-cancel>Cancelar</button>
            <button class="btn btn-primary" type="submit" form="category-form" data-save>${icon('check', 15)} ${category ? 'Guardar cambios' : 'Crear categoría'}</button>
        `,
    });

    modal.layer.querySelector('[data-cancel]')?.addEventListener('click', modal.close);

    const form = modal.layer.querySelector('#category-form');
    const saveButton = modal.layer.querySelector('[data-save]');

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors(form);

        const esName = fieldValue(form, 'translations.es.name');
        if (!esName) {
            applyFieldErrors(form, { 'translations.es.name': 'El nombre en español es obligatorio.' });
            return;
        }

        const payload = {
            slug: fieldValue(form, 'slug') || undefined,
            is_active: fieldChecked(form, 'is_active'),
            translations: {
                es: {
                    name: esName,
                    description: fieldValue(form, 'translations.es.description'),
                },
                en: {
                    name: fieldValue(form, 'translations.en.name'),
                    description: fieldValue(form, 'translations.en.description'),
                },
            },
        };

        setButtonBusy(saveButton, true, 'Guardando…');
        const outcome = await perform(
            () => (category
                ? api.put(`admin/categories/${category.id}`, payload)
                : api.post('admin/categories', payload)),
            { form },
        );
        setButtonBusy(saveButton, false);

        if (!outcome.ok) return;

        modal.close();
        toast(category ? 'Categoría actualizada.' : 'Categoría creada.', 'success');
        await reloadCategories();
    });
}

async function toggleCategory(category, button) {
    button.disabled = true;
    const outcome = await perform(() => api.put(`admin/categories/${category.id}`, {
        is_active: !category.is_active,
        translations: categoryTranslations(category),
    }), { form: null });

    if (outcome.ok) {
        const index = state.categories.findIndex((item) => item.id === category.id);
        if (index >= 0) state.categories[index] = outcome.result;
        refreshCategoryRows();
        toast(category.is_active ? 'Categoría oculta.' : 'Categoría visible.', 'success', 2500);
    } else {
        button.disabled = false;
    }
}

async function moveCategory(category, delta) {
    const index = state.categories.findIndex((item) => item.id === category.id);
    const target = index + delta;
    if (index < 0 || target < 0 || target >= state.categories.length) return;

    const ids = state.categories.map((item) => item.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];

    const outcome = await perform(() => api.put('admin/categories/reorder', { ids }));
    if (!outcome.ok) return;

    state.categories = outcome.result;
    refreshCategoryRows();
    toast('Orden actualizado.', 'success', 2000);
}

async function deleteCategory(category) {
    const confirmed = await confirmDialog({
        title: 'Eliminar categoría',
        message: `¿Eliminar “${category.name}”? Solo es posible si no tiene productos.`,
        confirmLabel: 'Eliminar',
        danger: true,
    });
    if (!confirmed) return;

    const outcome = await perform(() => api.delete(`admin/categories/${category.id}`), {
        success: 'Categoría eliminada.',
        onSuccess: async () => {
            state.categories = state.categories.filter((item) => item.id !== category.id);
            refreshCategoryRows();
        },
    });

    if (!outcome.ok) {
        /* El mensaje del servidor ya se muestra en un toast. */
    }
}

async function renderCategoriesView(token) {
    const categories = await guardRequest(api.get('admin/categories'), token);
    if (!isCurrent(token)) return;

    if (!categories) {
        view.innerHTML = failurePanel('No pudimos cargar las categorías.');
        bindRetry();
        return;
    }

    state.categories = categories;

    view.innerHTML = `
        <div class="toolbar">
            <div class="search input-group">
                ${icon('search', 16)}
                <input class="input" id="category-search" type="search" placeholder="Buscar categoría…" value="${escapeHtml(state.categoryQuery)}" autocomplete="off">
            </div>
            <span class="toolbar-count" id="category-count"></span>
            <span class="spacer"></span>
            <button class="btn btn-primary" type="button" data-new-category>${icon('plus', 15)} Nueva categoría</button>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Orden de la carta</h2>
                    <span class="small">Usa las flechas para ordenar. La búsqueda desactiva el reordenamiento.</span>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table" id="categories-table">
                    <thead>
                        <tr>
                            <th>Orden</th>
                            <th>Categoría</th>
                            <th>Productos</th>
                            <th>Estado</th>
                            <th class="actions-col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="category-rows"></tbody>
                </table>
            </div>
            <div class="admin-empty" id="categories-empty" hidden></div>
        </section>
    `;

    qs('[data-new-category]')?.addEventListener('click', () => openCategoryModal(null));

    const search = qs('#category-search');
    search?.addEventListener('input', debounce(() => {
        state.categoryQuery = search.value;
        refreshCategoryRows();
    }, 180));

    qs('#category-rows')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const category = state.categories.find((item) => item.id === Number(button.dataset.id));
        if (!category) return;

        const action = button.dataset.action;
        if (action === 'edit') openCategoryModal(category);
        if (action === 'delete') await deleteCategory(category);
        if (action === 'toggle') await toggleCategory(category, button);
        if (action === 'up') await moveCategory(category, -1);
        if (action === 'down') await moveCategory(category, 1);
    });

    refreshCategoryRows();
}

/* ---------------------------------------------------------------- productos */

function productQuery() {
    const filters = state.productFilters;
    const params = new URLSearchParams();
    params.set('page', String(filters.page));
    params.set('per_page', String(filters.per_page));
    if (filters.q) params.set('q', filters.q);
    if (filters.category_id) params.set('category_id', filters.category_id);
    if (filters.status) params.set('status', filters.status);
    return params.toString();
}

function productOrderLocked() {
    return Boolean(state.productFilters.q || state.productFilters.status);
}

function productRow(product) {
    const category = state.categories.find((item) => item.id === product.category_id);
    const categoryName = product.category_name || category?.name || '—';
    const english = product.translations?.en?.name || '';
    const locked = productOrderLocked();
    const price = formatMoney(product.price, state.publicSettings?.currency);

    return `<tr>
        <td class="nowrap">
            <div class="order-cell">
                <button class="mini-btn" type="button" data-action="move-up" data-id="${product.id}"
                    ${locked ? 'disabled' : ''} title="${locked ? 'Quita los filtros para ordenar' : 'Subir'}" aria-label="Subir ${escapeHtml(product.name)}">
                    ${icon('arrowUp', 14)}
                </button>
                <span>${escapeHtml(String(product.sort_order))}</span>
                <button class="mini-btn" type="button" data-action="move-down" data-id="${product.id}"
                    ${locked ? 'disabled' : ''} title="${locked ? 'Quita los filtros para ordenar' : 'Bajar'}" aria-label="Bajar ${escapeHtml(product.name)}">
                    ${icon('arrowDown', 14)}
                </button>
            </div>
        </td>
        <td>
            <div class="cell-product">
                <span class="thumb">${imageThumb(product.image_thumb_url || product.image_url, product.emoji, product.name)}</span>
                <span class="cell-title">
                    <strong>${escapeHtml(product.name)}</strong>
                    <small>${escapeHtml(english || product.slug)}</small>
                    <span class="badge-stack" style="margin-top:5px">
                        ${!product.is_active ? '<span class="badge badge-muted">Oculto</span>' : ''}
                        ${!product.is_available ? '<span class="badge badge-danger">Agotado</span>' : ''}
                        ${product.is_featured ? '<span class="badge badge-gold">Destacado</span>' : ''}
                    </span>
                </span>
            </div>
        </td>
        <td><span class="cell-muted">${escapeHtml(categoryName)}</span></td>
        <td class="num">
            <span class="price-cell">${escapeHtml(price)}</span>
            ${product.unit_label ? `<br><small class="cell-muted">${escapeHtml(product.unit_label)}</small>` : ''}
        </td>
        <td>
            <button class="toggle${product.is_available ? ' is-on' : ''}" type="button" role="switch" aria-checked="${product.is_available}"
                data-action="availability" data-id="${product.id}" title="${product.is_available ? 'Marcar como agotado' : 'Marcar como disponible'}">
                <span class="toggle-track"></span>
                <span>${product.is_available ? 'Disponible' : 'Agotado'}</span>
            </button>
        </td>
        <td>
            <button class="mini-btn${product.is_featured ? ' is-on' : ''}" type="button" data-action="featured" data-id="${product.id}"
                aria-pressed="${product.is_featured}" title="${product.is_featured ? 'Quitar de destacados' : 'Destacar producto'}" aria-label="Destacar ${escapeHtml(product.name)}">
                ${icon('star', 16)}
            </button>
        </td>
        <td class="actions-col">
            <div class="row-actions">
                <button class="mini-btn" type="button" data-action="edit" data-id="${product.id}" title="Editar" aria-label="Editar ${escapeHtml(product.name)}">
                    ${icon('edit', 15)}
                </button>
                <button class="mini-btn is-danger" type="button" data-action="delete" data-id="${product.id}" title="Eliminar" aria-label="Eliminar ${escapeHtml(product.name)}">
                    ${icon('trash', 15)}
                </button>
            </div>
        </td>
    </tr>`;
}

function refreshProductRows() {
    if (state.current !== 'products') return;

    const tbody = qs('#product-rows');
    if (!tbody) return;

    const meta = state.productsMeta || {};
    const total = Number(meta.total ?? state.products.length);
    const page = Number(meta.page ?? state.productFilters.page);
    const pages = Number(meta.pages ?? (total > 0 ? 1 : 0));
    const perPage = Number(meta.per_page ?? state.productFilters.per_page);
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(total, page * perPage);

    const summary = qs('#products-summary');
    if (summary) summary.textContent = `${total} ${total === 1 ? 'producto' : 'productos'}`;

    const table = qs('#products-table');
    const empty = qs('#products-empty');

    if (state.products.length === 0) {
        if (table) table.hidden = true;
        if (empty) {
            empty.hidden = false;
            const hasFilters = Boolean(state.productFilters.q || state.productFilters.category_id || state.productFilters.status);
            empty.innerHTML = hasFilters
                ? `<h3>Sin resultados</h3><p>No encontramos productos con esos filtros.</p><button class="btn btn-outline" type="button" data-clear-filters>Limpiar filtros</button>`
                : `<h3>La carta está vacía</h3><p>Crea tu primer producto para mostrarlo en el menú público.</p><button class="btn btn-primary" type="button" data-empty-new>${icon('plus', 15)} Nuevo producto</button>`;
            empty.querySelector('[data-clear-filters]')?.addEventListener('click', resetProductFilters);
            empty.querySelector('[data-empty-new]')?.addEventListener('click', () => openProductModal(null));
        }
    } else {
        if (table) table.hidden = false;
        if (empty) empty.hidden = true;
        tbody.innerHTML = state.products.map(productRow).join('');
    }

    const pagination = qs('#products-pagination');
    if (pagination) {
        pagination.innerHTML = total === 0 ? '' : `
            <span class="pagination-info">Mostrando ${from}–${to} de ${total}</span>
            <div class="pagination-actions">
                <button class="btn btn-outline btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button>
                <span class="pagination-info">Página ${page} de ${Math.max(pages, 1)}</span>
                <button class="btn btn-outline btn-sm" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;

        qsa('[data-page]', pagination).forEach((button) => {
            button.addEventListener('click', () => {
                state.productFilters.page = Number(button.dataset.page);
                loadProducts();
            });
        });
    }

    const orderNote = qs('#products-order-note');
    if (orderNote) {
        orderNote.textContent = productOrderLocked()
            ? 'Quita la búsqueda y el filtro de estado para reordenar.'
            : 'El orden se aplica dentro de cada categoría.';
    }
}

async function loadProducts() {
    if (state.current !== 'products') return;
    const token = renderToken;
    const result = await guardRequest(api.getFull(`admin/products?${productQuery()}`), token);
    if (!isCurrent(token) || !result) return;

    state.products = Array.isArray(result.data) ? result.data : [];
    state.productsMeta = result.meta || {};

    if (state.products.length === 0 && state.productFilters.page > 1) {
        state.productFilters.page = Math.max(1, state.productFilters.page - 1);
        await loadProducts();
        return;
    }

    refreshProductRows();
}

function resetProductFilters() {
    state.productFilters = { page: 1, per_page: 20, q: '', category_id: '', status: '' };
    const search = qs('#product-search');
    const category = qs('#product-category');
    const status = qs('#product-status');
    if (search) search.value = '';
    if (category) category.value = '';
    if (status) status.value = '';
    loadProducts();
}

async function toggleProductFlag(product, fieldName, successMessage) {
    const next = !product[fieldName];
    const outcome = await perform(() => api.patch(`admin/products/${product.id}/${fieldName === 'is_available' ? 'availability' : 'featured'}`, {
        [fieldName]: next,
    }));

    if (!outcome.ok) return;

    const index = state.products.findIndex((item) => item.id === product.id);
    if (index >= 0) state.products[index] = outcome.result;
    refreshProductRows();
    toast(successMessage, 'success', 2500);
}

async function moveProduct(product, delta) {
    if (productOrderLocked()) {
        toast('Quita la búsqueda y el filtro de estado para ordenar.', 'info');
        return;
    }

    const categoryProducts = await guardRequest(api.get(`admin/products?category_id=${product.category_id}&per_page=100&page=1`));
    if (!Array.isArray(categoryProducts)) return;

    const index = categoryProducts.findIndex((item) => item.id === product.id);
    const target = index + delta;
    if (index < 0) return;

    if (target < 0 || target >= categoryProducts.length) {
        toast(delta < 0 ? 'Ya está primero en su categoría.' : 'Ya está último en su categoría.', 'info', 2500);
        return;
    }

    const ids = categoryProducts.map((item) => item.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];

    const outcome = await perform(() => api.put('admin/products/reorder', { ids }));
    if (!outcome.ok) return;

    await loadProducts();
    toast('Orden actualizado.', 'success', 2000);
}

function productImagePreview(product) {
    const url = product?.image_thumb_url || product?.image_url;
    if (url) return `<img src="${escapeHtml(url)}" alt="">`;
    return product?.emoji ? escapeHtml(product.emoji) : '<span>Sin imagen</span>';
}

function openProductModal(product = null) {
    const categories = state.categories;
    const translations = product?.translations || {};
    const isEdit = Boolean(product);

    if (categories.length === 0) {
        toast('Crea una categoría antes de añadir productos.', 'info');
        openCategoryModal(null);
        return;
    }

    const categoryOptions = categories.map((category) => `
        <option value="${category.id}" ${product?.category_id === category.id ? 'selected' : ''}>${escapeHtml(category.name)}</option>
    `).join('');

    const modal = openModal({
        title: isEdit ? 'Editar producto' : 'Nuevo producto',
        size: 'lg',
        className: 'modal-xl',
        body: `
            <form id="product-form" class="modal-form" novalidate>
                <div>
                    <p class="modal-section-title">Datos del producto</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="product-category-field">Categoría *</label>
                            <select class="select" id="product-category-field" name="category_id" required>
                                ${categoryOptions}
                            </select>
                            <span class="field-error" data-error-for="category_id"></span>
                        </div>
                        ${field('price', { label: 'Precio', value: product?.price || '', type: 'number', required: true, placeholder: '0.00', attrs: 'min="0" max="999999" step="0.01" inputmode="decimal"' })}
                        ${field('emoji', { label: 'Emoji', value: product?.emoji || '', placeholder: '🍽️', hint: 'Se muestra si el producto no tiene imagen.', attrs: 'maxlength="16"' })}
                        ${field('slug', { label: 'Slug', value: product?.slug || '', placeholder: 'se-genera-automaticamente', hint: 'Identificador para la URL. Puedes dejarlo vacío.' })}
                    </div>
                    <div class="row" style="margin-top:14px; gap:18px">
                        <label class="check"><input type="checkbox" name="is_available" ${product ? (product.is_available ? 'checked' : '') : 'checked'}> <span>Disponible</span></label>
                        <label class="check"><input type="checkbox" name="is_featured" ${product?.is_featured ? 'checked' : ''}> <span>Destacado</span></label>
                        <label class="check"><input type="checkbox" name="is_active" ${product ? (product.is_active ? 'checked' : '') : 'checked'}> <span>Visible en el menú</span></label>
                    </div>
                </div>

                <hr class="divider">

                <div>
                    <p class="modal-section-title">Español</p>
                    <div class="form-grid">
                        ${field('translations.es.name', { label: 'Nombre', value: translations.es?.name || '', required: true, placeholder: 'Ej. Burrata con tomates asados' })}
                        ${field('translations.es.unit_label', { label: 'Unidad', value: translations.es?.unit_label || '', placeholder: 'plato, copa, porción…' })}
                        ${field('translations.es.description', { label: 'Descripción', value: translations.es?.description || '', type: 'textarea', rows: 3, attrs: 'maxlength="1000"' })}
                    </div>
                </div>

                <hr class="divider">

                <div>
                    <p class="modal-section-title">English</p>
                    <div class="form-grid">
                        ${field('translations.en.name', { label: 'Name', value: translations.en?.name || '', placeholder: 'e.g. Burrata salad' })}
                        ${field('translations.en.unit_label', { label: 'Unit', value: translations.en?.unit_label || '', placeholder: 'plate, glass, portion…' })}
                        ${field('translations.en.description', { label: 'Description', value: translations.en?.description || '', type: 'textarea', rows: 3, attrs: 'maxlength="1000"' })}
                    </div>
                    <p class="form-hint" style="margin-top:8px">Si dejas el inglés vacío, el menú público usará el español.</p>
                </div>

                <hr class="divider">

                <div>
                    <p class="modal-section-title">Imagen</p>
                    <div class="image-field">
                        <div class="image-preview" id="product-image-preview">${productImagePreview(product)}</div>
                        <div>
                            <div class="image-actions">
                                ${isEdit
                                    ? `<label class="file-label">${icon('upload', 15)} Subir imagen<input type="file" id="product-image-input" accept="image/jpeg,image/png,image/webp"></label>
                                       <button class="btn btn-danger btn-sm" type="button" id="product-image-delete" ${product?.image_url ? '' : 'disabled'}>${icon('trash', 14)} Eliminar</button>`
                                    : `<span class="form-hint">Guarda el producto y vuelve a editarlo para subir una imagen.</span>`}
                            </div>
                            <p class="form-hint" style="margin-top:9px">JPG, PNG o WebP · máximo 5 MB. La imagen se optimiza a WebP con miniatura.</p>
                            <span class="field-error" data-error-for="image"></span>
                        </div>
                    </div>
                </div>
            </form>
        `,
        footer: `
            <button class="btn btn-ghost" type="button" data-cancel>Cancelar</button>
            <button class="btn btn-primary" type="submit" form="product-form" data-save>${icon('check', 15)} ${isEdit ? 'Guardar cambios' : 'Crear producto'}</button>
        `,
    });

    modal.layer.querySelector('[data-cancel]')?.addEventListener('click', modal.close);

    const form = modal.layer.querySelector('#product-form');
    const saveButton = modal.layer.querySelector('[data-save]');
    let currentProduct = product;

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors(form);

        const esName = fieldValue(form, 'translations.es.name');
        const price = fieldValue(form, 'price');

        if (!esName) {
            applyFieldErrors(form, { 'translations.es.name': 'El nombre en español es obligatorio.' });
            return;
        }

        if (price === '' || Number.isNaN(Number(price)) || Number(price) < 0) {
            applyFieldErrors(form, { price: 'Indica un precio válido.' });
            return;
        }

        const payload = {
            category_id: Number(fieldValue(form, 'category_id')),
            price: Number(price).toFixed(2),
            emoji: fieldValue(form, 'emoji'),
            is_available: fieldChecked(form, 'is_available'),
            is_featured: fieldChecked(form, 'is_featured'),
            is_active: fieldChecked(form, 'is_active'),
            translations: {
                es: {
                    name: esName,
                    description: fieldValue(form, 'translations.es.description'),
                    unit_label: fieldValue(form, 'translations.es.unit_label'),
                },
                en: {
                    name: fieldValue(form, 'translations.en.name'),
                    description: fieldValue(form, 'translations.en.description'),
                    unit_label: fieldValue(form, 'translations.en.unit_label'),
                },
            },
        };

        const slug = fieldValue(form, 'slug');
        if (slug) payload.slug = slug;

        setButtonBusy(saveButton, true, 'Guardando…');
        const outcome = await perform(
            () => (currentProduct
                ? api.put(`admin/products/${currentProduct.id}`, payload)
                : api.post('admin/products', payload)),
            { form },
        );
        setButtonBusy(saveButton, false);

        if (!outcome.ok) return;

        modal.close();
        toast(currentProduct ? 'Producto actualizado.' : 'Producto creado.', 'success');
        await loadProducts();
    });

    const imageInput = modal.layer.querySelector('#product-image-input');
    const imagePreview = modal.layer.querySelector('#product-image-preview');

    imageInput?.addEventListener('change', async () => {
        const file = imageInput.files?.[0];
        if (!file || !currentProduct) return;

        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            toast('Usa una imagen JPG, PNG o WebP.', 'error');
            imageInput.value = '';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            toast('La imagen supera el límite de 5 MB.', 'error');
            imageInput.value = '';
            return;
        }

        const formData = new FormData();
        formData.append('image', file);
        imagePreview.innerHTML = '<span class="spinner" aria-hidden="true"></span>';

        const outcome = await perform(() => api.upload(`admin/products/${currentProduct.id}/image`, formData), { form });

        if (!outcome.ok) {
            imagePreview.innerHTML = productImagePreview(currentProduct);
            imageInput.value = '';
            return;
        }

        currentProduct = outcome.result;
        imagePreview.innerHTML = productImagePreview(currentProduct);
        imageInput.value = '';
        const deleteButton = modal.layer.querySelector('#product-image-delete');
        if (deleteButton) deleteButton.disabled = !currentProduct.image_url;
        toast('Imagen actualizada.', 'success');
        await loadProducts();
    });

    modal.layer.querySelector('#product-image-delete')?.addEventListener('click', async (event) => {
        if (!currentProduct) return;

        const confirmed = await confirmDialog({
            title: 'Eliminar imagen',
            message: '¿Quitar la imagen de este producto?',
            confirmLabel: 'Eliminar',
            danger: true,
        });
        if (!confirmed) return;

        const button = event.currentTarget;
        setButtonBusy(button, true, 'Eliminando…');

        const outcome = await perform(() => api.delete(`admin/products/${currentProduct.id}/image`), { form });

        if (outcome.ok) {
            currentProduct = { ...currentProduct, image_url: null, image_thumb_url: null };
            imagePreview.innerHTML = productImagePreview(currentProduct);
            button.disabled = true;
            button.innerHTML = `${icon('trash', 14)} Eliminar`;
            toast('Imagen eliminada.', 'success');
            await loadProducts();
        } else {
            setButtonBusy(button, false);
        }
    });
}

async function deleteProduct(product) {
    const confirmed = await confirmDialog({
        title: 'Eliminar producto',
        message: `¿Eliminar “${product.name}”? Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
        danger: true,
    });
    if (!confirmed) return;

    await perform(() => api.delete(`admin/products/${product.id}`), {
        success: 'Producto eliminado.',
        onSuccess: loadProducts,
    });
}

async function renderProductsView(token) {
    const [categories, result] = await guardRequest(Promise.all([
        api.get('admin/categories'),
        api.getFull(`admin/products?${productQuery()}`),
    ]), token);
    if (!isCurrent(token)) return;

    if (!Array.isArray(categories) || !result) {
        view.innerHTML = failurePanel('No pudimos cargar los productos.');
        bindRetry();
        return;
    }

    state.categories = categories;
    state.products = Array.isArray(result.data) ? result.data : [];
    state.productsMeta = result.meta || {};

    if (state.productFilters.category_id
        && !categories.some((category) => String(category.id) === String(state.productFilters.category_id))) {
        state.productFilters.category_id = '';
    }

    const categoryOptions = categories.map((category) => `
        <option value="${category.id}" ${String(state.productFilters.category_id) === String(category.id) ? 'selected' : ''}>${escapeHtml(category.name)}</option>
    `).join('');

    view.innerHTML = `
        <div class="toolbar">
            <div class="search input-group">
                ${icon('search', 16)}
                <input class="input" id="product-search" type="search" placeholder="Buscar producto…" value="${escapeHtml(state.productFilters.q)}" autocomplete="off">
            </div>
            <select class="select" id="product-category" aria-label="Filtrar por categoría">
                <option value="">Todas las categorías</option>
                ${categoryOptions}
            </select>
            <select class="select" id="product-status" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <option value="available" ${state.productFilters.status === 'available' ? 'selected' : ''}>Disponibles</option>
                <option value="featured" ${state.productFilters.status === 'featured' ? 'selected' : ''}>Destacados</option>
                <option value="sold_out" ${state.productFilters.status === 'sold_out' ? 'selected' : ''}>Agotados</option>
            </select>
            <span class="spacer"></span>
            <button class="btn btn-primary" type="button" data-new-product>${icon('plus', 15)} Nuevo producto</button>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Productos de la carta</h2>
                    <span class="small" id="products-summary">${escapeHtml(String(state.productsMeta?.total ?? state.products.length))} productos</span>
                </div>
                <p class="toolbar-count" id="products-order-note"></p>
            </div>
            <div class="table-wrap">
                <table class="table" id="products-table">
                    <thead>
                        <tr>
                            <th>Orden</th>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th class="num">Precio</th>
                            <th>Disponibilidad</th>
                            <th>Destacado</th>
                            <th class="actions-col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="product-rows"></tbody>
                </table>
            </div>
            <div class="admin-empty" id="products-empty" hidden></div>
            <div class="pagination" id="products-pagination"></div>
        </section>
    `;

    qs('[data-new-product]')?.addEventListener('click', () => openProductModal(null));

    const search = qs('#product-search');
    search?.addEventListener('input', debounce(() => {
        state.productFilters.q = search.value.trim();
        state.productFilters.page = 1;
        loadProducts();
    }, 280));

    qs('#product-category')?.addEventListener('change', (event) => {
        state.productFilters.category_id = event.currentTarget.value;
        state.productFilters.page = 1;
        loadProducts();
    });

    qs('#product-status')?.addEventListener('change', (event) => {
        state.productFilters.status = event.currentTarget.value;
        state.productFilters.page = 1;
        loadProducts();
    });

    qs('#product-rows')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const product = state.products.find((item) => item.id === Number(button.dataset.id));
        if (!product) return;

        const action = button.dataset.action;
        if (action === 'edit') openProductModal(product);
        if (action === 'delete') await deleteProduct(product);
        if (action === 'availability') {
            await toggleProductFlag(product, 'is_available', product.is_available ? 'Producto marcado como agotado.' : 'Producto disponible de nuevo.');
        }
        if (action === 'featured') {
            await toggleProductFlag(product, 'is_featured', product.is_featured ? 'Producto quitado de destacados.' : 'Producto destacado.');
        }
        if (action === 'move-up') await moveProduct(product, -1);
        if (action === 'move-down') await moveProduct(product, 1);
    });

    refreshProductRows();
}

/* ------------------------------------------------------------ reservaciones */

function reservationQuery() {
    const filters = state.reservationFilters;
    const params = new URLSearchParams();
    params.set('page', String(filters.page));
    params.set('per_page', String(filters.per_page));
    if (filters.q) params.set('q', filters.q);
    if (filters.status) params.set('status', filters.status);
    if (filters.date_from) params.set('date_from', filters.date_from);
    if (filters.date_to) params.set('date_to', filters.date_to);
    return params.toString();
}

function reservationRow(reservation) {
    const status = reservationStatus(reservation.status);
    return `<tr>
        <td class="nowrap">
            <strong>${escapeHtml(formatDate(reservation.reservation_date))}</strong>
            <br><small class="cell-muted">${escapeHtml(timeLabel(reservation.reservation_time))}</small>
        </td>
        <td>
            <div class="cell-title">
                <strong>${escapeHtml(reservation.name)}</strong>
                <small>${escapeHtml(reservation.phone)}</small>
            </div>
        </td>
        <td class="nowrap"><span class="badge badge-muted">${escapeHtml(String(reservation.party_size))} pers.</span></td>
        <td><span class="badge ${status.badge}">${escapeHtml(status.label)}</span></td>
        <td>
            <span class="cell-note" title="${escapeHtml(reservation.notes || '')}">${escapeHtml(reservation.notes || reservation.admin_notes || '—')}</span>
        </td>
        <td class="actions-col">
            <div class="row-actions">
                <button class="btn btn-outline btn-sm" type="button" data-action="view" data-id="${reservation.id}">${icon('eye', 14)} Ver</button>
            </div>
        </td>
    </tr>`;
}

function refreshReservationRows() {
    if (state.current !== 'reservations') return;

    const tbody = qs('#reservation-rows');
    if (!tbody) return;

    const meta = state.reservationsMeta || {};
    const total = Number(meta.total ?? state.reservations.length);
    const page = Number(meta.page ?? state.reservationFilters.page);
    const pages = Number(meta.pages ?? (total > 0 ? 1 : 0));
    const perPage = Number(meta.per_page ?? state.reservationFilters.per_page);
    const from = total === 0 ? 0 : (page - 1) * perPage + 1;
    const to = Math.min(total, page * perPage);

    const table = qs('#reservations-table');
    const empty = qs('#reservations-empty');

    if (state.reservations.length === 0) {
        if (table) table.hidden = true;
        if (empty) {
            empty.hidden = false;
            const hasFilters = Boolean(state.reservationFilters.q || state.reservationFilters.status
                || state.reservationFilters.date_from || state.reservationFilters.date_to);
            empty.innerHTML = hasFilters
                ? `<h3>Sin resultados</h3><p>No hay reservaciones con esos filtros.</p><button class="btn btn-outline" type="button" data-clear-reservation-filters>Limpiar filtros</button>`
                : `<h3>Sin reservaciones</h3><p>Las solicitudes del menú público aparecerán aquí.</p>`;
            empty.querySelector('[data-clear-reservation-filters]')?.addEventListener('click', resetReservationFilters);
        }
    } else {
        if (table) table.hidden = false;
        if (empty) empty.hidden = true;
        tbody.innerHTML = state.reservations.map(reservationRow).join('');
    }

    const summary = qs('#reservations-summary');
    if (summary) summary.textContent = `${total} ${total === 1 ? 'reservación' : 'reservaciones'}`;

    const pagination = qs('#reservations-pagination');
    if (pagination) {
        pagination.innerHTML = total === 0 ? '' : `
            <span class="pagination-info">Mostrando ${from}–${to} de ${total}</span>
            <div class="pagination-actions">
                <button class="btn btn-outline btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button>
                <span class="pagination-info">Página ${page} de ${Math.max(pages, 1)}</span>
                <button class="btn btn-outline btn-sm" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;

        qsa('[data-page]', pagination).forEach((button) => {
            button.addEventListener('click', () => {
                state.reservationFilters.page = Number(button.dataset.page);
                loadReservations();
            });
        });
    }
}

async function loadReservations() {
    if (state.current !== 'reservations') return;
    const token = renderToken;
    const result = await guardRequest(api.getFull(`admin/reservations?${reservationQuery()}`), token);
    if (!isCurrent(token) || !result) return;

    state.reservations = Array.isArray(result.data) ? result.data : [];
    state.reservationsMeta = result.meta || {};

    if (state.reservations.length === 0 && state.reservationFilters.page > 1) {
        state.reservationFilters.page = Math.max(1, state.reservationFilters.page - 1);
        await loadReservations();
        return;
    }

    refreshReservationRows();
}

function resetReservationFilters() {
    state.reservationFilters = { page: 1, per_page: 20, q: '', status: '', date_from: '', date_to: '' };
    const search = qs('#reservation-search');
    const status = qs('#reservation-status');
    const from = qs('#reservation-from');
    const to = qs('#reservation-to');
    if (search) search.value = '';
    if (status) status.value = '';
    if (from) from.value = '';
    if (to) to.value = '';
    loadReservations();
}

function openReservationModal(reservation, onSaved = null) {
    const status = reservationStatus(reservation.status);
    const email = reservation.email || '';
    const phone = String(reservation.phone || '');
    const phoneHref = phone.replace(/[^\d+]/g, '');
    const waLink = whatsappLink(phone, `Hola ${reservation.name}, te escribimos sobre tu reservación en ${state.branding.name || 'el restaurante'}.`);

    const modal = openModal({
        title: `Reservación de ${reservation.name}`,
        size: 'lg',
        body: `
            <form id="reservation-form" class="modal-form" novalidate>
                <div class="detail-grid">
                    <div class="detail-item"><span>Fecha</span><strong>${escapeHtml(formatDateLong(reservation.reservation_date))}</strong></div>
                    <div class="detail-item"><span>Hora</span><strong>${escapeHtml(timeLabel(reservation.reservation_time))}</strong></div>
                    <div class="detail-item"><span>Personas</span><strong>${escapeHtml(String(reservation.party_size))}</strong></div>
                    <div class="detail-item"><span>Estado actual</span><strong><span class="badge ${status.badge}">${escapeHtml(status.label)}</span></strong></div>
                    <div class="detail-item"><span>Teléfono</span><strong>${phoneHref ? `<a href="tel:${escapeHtml(phoneHref)}">${escapeHtml(phone)}</a>` : '—'}</strong></div>
                    <div class="detail-item"><span>Correo</span><strong>${email ? `<a href="mailto:${escapeHtml(email)}">${escapeHtml(email)}</a>` : '—'}</strong></div>
                    <div class="detail-item"><span>Idioma</span><strong>${escapeHtml(String(reservation.locale || 'es').toUpperCase())}</strong></div>
                    <div class="detail-item"><span>Recibida</span><strong>${escapeHtml(formatDateTime(reservation.created_at))}</strong></div>
                </div>

                ${reservation.notes ? `<div class="detail-notes"><strong>Notas del cliente</strong><br>${escapeHtml(reservation.notes)}</div>` : ''}

                <hr class="divider">

                <div class="form-grid">
                    <div class="field">
                        <label for="reservation-status-field">Estado</label>
                        <select class="select" id="reservation-status-field" name="status">
                            ${Object.entries(RESERVATION_STATUS).map(([value, info]) => `
                                <option value="${value}" ${reservation.status === value ? 'selected' : ''}>${escapeHtml(info.label)}</option>
                            `).join('')}
                        </select>
                        <span class="field-error" data-error-for="status"></span>
                    </div>
                    <div class="field">
                        <label>Contacto rápido</label>
                        <div class="row">
                            ${phoneHref ? `<a class="btn btn-outline btn-sm" href="tel:${escapeHtml(phoneHref)}">${icon('phone', 14)} Llamar</a>` : ''}
                            ${waLink ? `<a class="btn btn-outline btn-sm" href="${escapeHtml(waLink)}" target="_blank" rel="noopener">${icon('whatsapp', 14)} WhatsApp</a>` : ''}
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label for="reservation-notes-field">Notas internas</label>
                    <textarea class="textarea" id="reservation-notes-field" name="admin_notes" rows="3" maxlength="2000" placeholder="Mesa asignada, alergias, seguimiento…">${escapeHtml(reservation.admin_notes || '')}</textarea>
                    <span class="field-error" data-error-for="admin_notes"></span>
                </div>
            </form>
        `,
        footer: `
            <button class="btn btn-danger" type="button" data-delete>${icon('trash', 15)} Eliminar</button>
            <span class="spacer"></span>
            <button class="btn btn-ghost" type="button" data-cancel>Cancelar</button>
            <button class="btn btn-primary" type="submit" form="reservation-form" data-save>${icon('check', 15)} Guardar</button>
        `,
    });

    modal.layer.querySelector('[data-cancel]')?.addEventListener('click', modal.close);

    const form = modal.layer.querySelector('#reservation-form');
    const saveButton = modal.layer.querySelector('[data-save]');

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors(form);
        setButtonBusy(saveButton, true, 'Guardando…');

        const payload = {
            status: fieldValue(form, 'status'),
            admin_notes: fieldValue(form, 'admin_notes'),
        };

        const outcome = await perform(() => api.patch(`admin/reservations/${reservation.id}`, payload), { form });
        setButtonBusy(saveButton, false);

        if (!outcome.ok) return;

        modal.close();
        toast('Reservación actualizada.', 'success');
        if (typeof onSaved === 'function') {
            await onSaved(outcome.result);
        } else {
            await loadReservations();
        }
    });

    modal.layer.querySelector('[data-delete]')?.addEventListener('click', async () => {
        const confirmed = await confirmDialog({
            title: 'Eliminar reservación',
            message: `¿Eliminar la reservación de “${reservation.name}”?`,
            confirmLabel: 'Eliminar',
            danger: true,
        });
        if (!confirmed) return;

        const outcome = await perform(() => api.delete(`admin/reservations/${reservation.id}`), {
            success: 'Reservación eliminada.',
        });

        if (!outcome.ok) return;

        modal.close();
        if (typeof onSaved === 'function') {
            await onSaved(null);
        } else {
            await loadReservations();
        }
    });
}

async function renderReservationsView(token) {
    const result = await guardRequest(api.getFull(`admin/reservations?${reservationQuery()}`), token);
    if (!isCurrent(token)) return;

    if (!result) {
        view.innerHTML = failurePanel('No pudimos cargar las reservaciones.');
        bindRetry();
        return;
    }

    state.reservations = Array.isArray(result.data) ? result.data : [];
    state.reservationsMeta = result.meta || {};

    const filters = state.reservationFilters;

    view.innerHTML = `
        <div class="toolbar">
            <div class="search input-group">
                ${icon('search', 16)}
                <input class="input" id="reservation-search" type="search" placeholder="Buscar por nombre, teléfono o correo…" value="${escapeHtml(filters.q)}" autocomplete="off">
            </div>
            <select class="select" id="reservation-status" aria-label="Filtrar por estado">
                <option value="">Todos los estados</option>
                <option value="pending" ${filters.status === 'pending' ? 'selected' : ''}>Pendientes</option>
                <option value="confirmed" ${filters.status === 'confirmed' ? 'selected' : ''}>Confirmadas</option>
                <option value="cancelled" ${filters.status === 'cancelled' ? 'selected' : ''}>Canceladas</option>
                <option value="completed" ${filters.status === 'completed' ? 'selected' : ''}>Completadas</option>
            </select>
            <input class="input input-date" id="reservation-from" type="date" value="${escapeHtml(filters.date_from)}" aria-label="Desde">
            <input class="input input-date" id="reservation-to" type="date" value="${escapeHtml(filters.date_to)}" aria-label="Hasta">
            <button class="btn btn-outline btn-sm" type="button" data-today>Hoy</button>
            <span class="spacer"></span>
            <button class="btn btn-ghost btn-sm" type="button" data-clear>Limpiar</button>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <h2>Reservaciones</h2>
                    <span class="small" id="reservations-summary">${escapeHtml(String(state.reservationsMeta?.total ?? state.reservations.length))} reservaciones</span>
                </div>
                <button class="btn btn-outline btn-sm" type="button" data-refresh>${icon('refresh', 14)} Actualizar</button>
            </div>
            <div class="table-wrap">
                <table class="table" id="reservations-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Personas</th>
                            <th>Estado</th>
                            <th>Notas</th>
                            <th class="actions-col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="reservation-rows"></tbody>
                </table>
            </div>
            <div class="admin-empty" id="reservations-empty" hidden></div>
            <div class="pagination" id="reservations-pagination"></div>
        </section>
    `;

    const search = qs('#reservation-search');
    search?.addEventListener('input', debounce(() => {
        state.reservationFilters.q = search.value.trim();
        state.reservationFilters.page = 1;
        loadReservations();
    }, 280));

    qs('#reservation-status')?.addEventListener('change', (event) => {
        state.reservationFilters.status = event.currentTarget.value;
        state.reservationFilters.page = 1;
        loadReservations();
    });

    qs('#reservation-from')?.addEventListener('change', (event) => {
        state.reservationFilters.date_from = event.currentTarget.value;
        state.reservationFilters.page = 1;
        loadReservations();
    });

    qs('#reservation-to')?.addEventListener('change', (event) => {
        state.reservationFilters.date_to = event.currentTarget.value;
        state.reservationFilters.page = 1;
        loadReservations();
    });

    qs('[data-today]')?.addEventListener('click', () => {
        state.reservationFilters.date_from = todayISO();
        state.reservationFilters.date_to = todayISO();
        state.reservationFilters.page = 1;
        const from = qs('#reservation-from');
        const to = qs('#reservation-to');
        if (from) from.value = state.reservationFilters.date_from;
        if (to) to.value = state.reservationFilters.date_to;
        loadReservations();
    });

    qs('[data-clear]')?.addEventListener('click', resetReservationFilters);
    qs('[data-refresh]')?.addEventListener('click', () => renderRoute('reservations'));

    qs('#reservation-rows')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action="view"]');
        if (!button) return;
        const reservation = state.reservations.find((item) => item.id === Number(button.dataset.id));
        if (reservation) openReservationModal(reservation);
    });

    refreshReservationRows();
}

/* ---------------------------------------------------------------------- QR */

async function renderQrView(token) {
    const settings = await guardRequest(api.get('settings/public'), token);
    if (!isCurrent(token)) return;

    if (!settings) {
        view.innerHTML = failurePanel('No pudimos cargar los datos del menú público.');
        bindRetry();
        return;
    }

    state.publicSettings = settings;
    applyBranding(settings.business || {});

    const business = settings.business || {};
    const url = settings.public_menu_url || new URL('../', window.location.href).href;
    const urlIsConfigured = settings.public_menu_url_is_configured !== false;
    const urlIsLocal = /^https?:\/\/(?:localhost|127\.|0\.0\.0\.0|\[::1\])/i.test(url);
    const pngUrl = api.url('admin/qr?format=png');
    const svgUrl = api.url('admin/qr?format=svg');
    const printUrl = api.url('admin/qr/print');

    view.innerHTML = `
        <div class="page-actions">
            <p>Imprime el QR para las mesas o compártelo en tus redes. Apunta siempre al menú público actualizado.</p>
            <button class="btn btn-outline btn-sm" type="button" data-refresh>${icon('refresh', 14)} Regenerar vista</button>
        </div>

        <div class="qr-layout">
            <div class="qr-card">
                <img id="qr-image" src="${escapeHtml(pngUrl)}&v=${Date.now()}" alt="Código QR del menú público">
                <p class="qr-caption">Escanea para ver nuestro menú</p>
            </div>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Tu código QR</h2>
                        <span class="small">${escapeHtml(business.name || 'Menú público')}</span>
                    </div>
                    <span class="badge badge-success">Activo</span>
                </div>
                <div class="panel-body qr-meta">
                    <div>
                        <p class="field-label">Enlace público</p>
                        <a class="qr-url" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="Abrir menú público">
                            <span>${escapeHtml(url)}</span>
                            ${icon('external', 14)}
                        </a>
                    </div>

                    <div class="qr-actions">
                        <a class="btn btn-primary" href="${escapeHtml(pngUrl)}&download=1">${icon('image', 15)} Descargar PNG</a>
                        <a class="btn btn-outline" href="${escapeHtml(svgUrl)}&download=1">${icon('qr', 15)} Descargar SVG</a>
                        <button class="btn btn-outline" type="button" data-print>${icon('print', 15)} Imprimir</button>
                        <button class="btn btn-outline" type="button" data-copy>${icon('copy', 15)} Copiar enlace</button>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-item"><span>Negocio</span><strong>${escapeHtml(business.name || '—')}</strong></div>
                        <div class="detail-item"><span>Teléfono</span><strong>${escapeHtml(business.phone || '—')}</strong></div>
                        <div class="detail-item"><span>Dirección</span><strong>${escapeHtml(business.address || '—')}</strong></div>
                        <div class="detail-item"><span>Formato</span><strong>PNG · SVG</strong></div>
                    </div>

                    ${!urlIsConfigured && !urlIsLocal ? `
                        <div class="qr-note">
                            ${icon('info', 15)}
                            <span>Dirección detectada automáticamente en tu red local. Para usar tu dominio definitivo, edita “Enlace público del menú” en Configuración.</span>
                        </div>
                    ` : ''}

                    ${urlIsLocal ? `
                        <div class="qr-note qr-note-warning">
                            ${icon('alert', 15)}
                            <span>Este enlace apunta a <strong>${escapeHtml(url)}</strong>, que solo funciona en este equipo. Un teléfono no podrá abrirlo: configura el enlace público en Configuración.</span>
                        </div>
                    ` : ''}

                    <div class="qr-note">
                        El código se genera en el servidor a partir del enlace público configurado.
                        Si cambias el dominio o la ruta del menú, actualiza “Enlace público” en Configuración y descarga un QR nuevo.
                    </div>
                </div>
            </section>
        </div>
    `;

    const image = qs('#qr-image');

    qs('[data-refresh]')?.addEventListener('click', () => {
        if (image) image.src = `${pngUrl}&v=${Date.now()}`;
    });

    qs('[data-print]')?.addEventListener('click', () => {
        window.open(printUrl, '_blank', 'noopener');
    });

    qs('[data-copy]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(url);
            toast('Enlace copiado.', 'success');
        } catch {
            const input = document.createElement('textarea');
            input.value = url;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.append(input);
            input.select();
            const copied = document.execCommand('copy');
            input.remove();
            toast(copied ? 'Enlace copiado.' : 'No pudimos copiar el enlace.', copied ? 'success' : 'error');
        }
    });
}

/* -------------------------------------------------------------- configuración */

function settingValue(settings, key, fallback = '') {
    const entry = settings[key];
    return entry ? String(entry.value ?? fallback) : fallback;
}

function settingTranslation(settings, key, locale = 'es') {
    const entry = settings[key];
    return String(entry?.translations?.[locale] ?? entry?.value ?? '');
}

function settingBool(settings, key, fallback = false) {
    return ['1', 'true', 'on'].includes(settingValue(settings, key, fallback ? '1' : '0'));
}

function settingJson(settings, key, fallback) {
    try {
        const parsed = JSON.parse(settingValue(settings, key, JSON.stringify(fallback)));
        return parsed && typeof parsed === 'object' ? parsed : fallback;
    } catch {
        return fallback;
    }
}

function settingsImageField(key, label, url, hint) {
    return `<div class="field">
        <label>${escapeHtml(label)}</label>
        <div class="image-preview is-wide" id="settings-${key}-preview">
            ${url ? `<img src="${escapeHtml(url)}" alt="">` : '<span>Sin imagen</span>'}
        </div>
        <div class="image-actions" style="margin-top:8px">
            <label class="file-label">${icon('upload', 15)} Subir imagen
                <input type="file" accept="image/jpeg,image/png,image/webp" data-settings-image="${key}">
            </label>
        </div>
        <span class="form-hint">${escapeHtml(hint)}</span>
        <span class="field-error" data-error-for="image"></span>
    </div>`;
}

function settingsToggle(name, label, checked, hint = '') {
    return `<label class="check">
        <input type="checkbox" name="${escapeHtml(name)}" ${checked ? 'checked' : ''}>
        <span>${escapeHtml(label)}${hint ? `<br><small class="form-hint">${escapeHtml(hint)}</small>` : ''}</span>
    </label>`;
}

async function uploadSettingImage(key, file, form) {
    if (!file) return;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        toast('Usa una imagen JPG, PNG o WebP.', 'error');
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        toast('La imagen supera el límite de 5 MB.', 'error');
        return;
    }

    const preview = qs(`#settings-${key}-preview`);
    if (preview) preview.innerHTML = '<span class="spinner" aria-hidden="true"></span>';

    const formData = new FormData();
    formData.append('key', key);
    formData.append('image', file);

    const outcome = await perform(() => api.upload('admin/settings/image', formData), { form });

    if (!outcome.ok) {
        if (preview) {
            const publicData = state.publicSettings?.business || {};
            const url = key === 'logo' ? publicData.logo_url : publicData.hero_image_url;
            preview.innerHTML = url ? `<img src="${escapeHtml(url)}" alt="">` : '<span>Sin imagen</span>';
        }
        return;
    }

    state.publicSettings = outcome.result.public || state.publicSettings;
    applyBranding(state.publicSettings?.business || {});

    if (preview) {
        preview.innerHTML = outcome.result.url
            ? `<img src="${escapeHtml(outcome.result.url)}" alt="">`
            : '<span>Sin imagen</span>';
    }

    toast(key === 'logo' ? 'Logo actualizado.' : 'Imagen principal actualizada.', 'success');
}

async function renderSettingsView(token) {
    const data = await guardRequest(api.get('admin/settings'), token);
    if (!isCurrent(token)) return;

    if (!data) {
        view.innerHTML = failurePanel('No pudimos cargar la configuración.');
        bindRetry();
        return;
    }

    const settings = data.settings || {};
    state.publicSettings = data.public || state.publicSettings;
    applyBranding(state.publicSettings?.business || {});

    const openingHours = settingJson(settings, 'opening_hours', {});
    const reservationDays = settingJson(settings, 'reservation_days', [1, 2, 3, 4, 5, 6]);
    const reservationDaySet = new Set(Array.isArray(reservationDays) ? reservationDays.map(Number) : []);
    const currentTimezone = settingValue(settings, 'timezone', 'America/Caracas');
    const timezoneOptions = TIMEZONES.includes(currentTimezone) ? TIMEZONES : [currentTimezone, ...TIMEZONES];
    const currentCurrency = settingValue(settings, 'currency_code', 'USD');
    const currencyOptions = CURRENCIES.includes(currentCurrency) ? CURRENCIES : [currentCurrency, ...CURRENCIES];
    const defaultLang = settingValue(settings, 'default_lang', 'es');

    const hoursRows = DAYS.map((day) => {
        const entry = openingHours[day.key] || {};
        const closed = Boolean(entry.closed);
        return `<div class="hours-row${closed ? ' is-closed' : ''}" data-hours-day="${day.key}">
            <span class="hours-day">${day.label}</span>
            <input class="input" type="time" data-open value="${escapeHtml(String(entry.open || '12:00').slice(0, 5))}" aria-label="${day.label} apertura" ${closed ? 'disabled' : ''}>
            <input class="input" type="time" data-close value="${escapeHtml(String(entry.close || '22:00').slice(0, 5))}" aria-label="${day.label} cierre" ${closed ? 'disabled' : ''}>
            <label class="check"><input type="checkbox" data-closed ${closed ? 'checked' : ''}> <span>Cerrado</span></label>
        </div>`;
    }).join('');

    const dayChips = DAYS.map((day) => `
        <label class="day-chip">
            <input type="checkbox" name="reservation_days" value="${day.iso}" ${reservationDaySet.has(day.iso) ? 'checked' : ''}>
            <span>${day.label}</span>
        </label>
    `).join('');

    view.innerHTML = `
        <form id="settings-form" novalidate>
            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('home', 17)}</span>
                    <div><h2>Identidad del restaurante</h2><p>Nombre, descripción y dirección que verán tus clientes.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        ${field('business_name.es', { label: 'Nombre · Español', value: settingTranslation(settings, 'business_name', 'es'), required: true, placeholder: 'Casa Oliva' })}
                        ${field('business_name.en', { label: 'Nombre · English', value: settingTranslation(settings, 'business_name', 'en'), placeholder: 'Casa Oliva' })}
                        ${field('tagline.es', { label: 'Frase corta · Español', value: settingTranslation(settings, 'tagline', 'es'), placeholder: 'Cocina mediterránea de autor' })}
                        ${field('tagline.en', { label: 'Frase corta · English', value: settingTranslation(settings, 'tagline', 'en'), placeholder: 'Signature Mediterranean cuisine' })}
                        ${field('description.es', { label: 'Descripción · Español', value: settingTranslation(settings, 'description', 'es'), type: 'textarea', rows: 4 })}
                        ${field('description.en', { label: 'Descripción · English', value: settingTranslation(settings, 'description', 'en'), type: 'textarea', rows: 4 })}
                        ${field('address.es', { label: 'Dirección · Español', value: settingTranslation(settings, 'address', 'es'), placeholder: 'Calle 45 #12-34, Ciudad' })}
                        ${field('address.en', { label: 'Dirección · English', value: settingTranslation(settings, 'address', 'en'), placeholder: '45th Street #12-34, City' })}
                    </div>
                    <p class="form-hint">Si dejas un campo en inglés vacío, el menú público usará el español.</p>
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('phone', 17)}</span>
                    <div><h2>Contacto y enlaces</h2><p>Canales de contacto y redes sociales.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid-3">
                        ${field('phone', { label: 'Teléfono', value: settingValue(settings, 'phone'), placeholder: '+58 412 555 0101', attrs: 'maxlength="30"' })}
                        ${field('whatsapp', { label: 'WhatsApp', value: settingValue(settings, 'whatsapp'), placeholder: '584125550101', hint: 'Solo dígitos con código de país.', attrs: 'maxlength="30"' })}
                        ${field('email', { label: 'Correo', value: settingValue(settings, 'email'), type: 'email', placeholder: 'hola@restaurante.com' })}
                        ${field('instagram', { label: 'Instagram', value: settingValue(settings, 'instagram'), placeholder: '@restaurante' })}
                        ${field('facebook', { label: 'Facebook', value: settingValue(settings, 'facebook'), placeholder: 'restaurante' })}
                        ${field('maps_url', { label: 'Google Maps', value: settingValue(settings, 'maps_url'), type: 'url', placeholder: 'https://maps.google.com/…' })}
                    </div>
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('tag', 17)}</span>
                    <div><h2>Moneda e idioma</h2><p>Cómo se muestran los precios y el idioma por defecto.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid-3">
                        <div class="field">
                            <label for="currency-code">Moneda</label>
                            <select class="select" id="currency-code" name="currency_code">
                                ${currencyOptions.map((code) => `<option value="${escapeHtml(code)}" ${code === currentCurrency ? 'selected' : ''}>${escapeHtml(code)}</option>`).join('')}
                            </select>
                            <span class="field-error" data-error-for="currency_code"></span>
                        </div>
                        ${field('currency_symbol', { label: 'Símbolo', value: settingValue(settings, 'currency_symbol', '$'), attrs: 'maxlength="5"' })}
                        ${field('currency_locale', { label: 'Locale de formato', value: settingValue(settings, 'currency_locale', 'en-US'), placeholder: 'en-US', hint: 'Ej. en-US, es-VE, es-ES.' })}
                        ${field('currency_decimals', { label: 'Decimales', value: settingValue(settings, 'currency_decimals', '2'), type: 'number', attrs: 'min="0" max="4" step="1"' })}
                        <div class="field">
                            <label for="default-lang">Idioma por defecto</label>
                            <select class="select" id="default-lang" name="default_lang">
                                <option value="es" ${defaultLang === 'es' ? 'selected' : ''}>Español</option>
                                <option value="en" ${defaultLang === 'en' ? 'selected' : ''}>English</option>
                            </select>
                            <span class="field-error" data-error-for="default_lang"></span>
                        </div>
                        <div class="field">
                            <label for="timezone">Zona horaria</label>
                            <select class="select" id="timezone" name="timezone">
                                ${timezoneOptions.map((zone) => `<option value="${escapeHtml(zone)}" ${zone === currentTimezone ? 'selected' : ''}>${escapeHtml(zone)}</option>`).join('')}
                            </select>
                            <span class="field-error" data-error-for="timezone"></span>
                        </div>
                    </div>
                    <p class="form-hint">La moneda y el formato se aplican a todos los precios del menú público y del panel.</p>
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('clock', 17)}</span>
                    <div><h2>Horario de atención</h2><p>Horas de apertura y cierre por día.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="hours-grid">${hoursRows}</div>
                    <span class="field-error" data-error-for="opening_hours"></span>
                    ${field('opening_hours_text.es', { label: 'Texto de horario · Español', value: settingTranslation(settings, 'opening_hours_text', 'es'), placeholder: 'Martes a domingo · desde las 12:00' })}
                    ${field('opening_hours_text.en', { label: 'Horario · English', value: settingTranslation(settings, 'opening_hours_text', 'en'), placeholder: 'Tuesday to Sunday · from 12:00' })}
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('calendar', 17)}</span>
                    <div><h2>Reservas</h2><p>Disponibilidad, franjas horarias y mensaje de confirmación.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid-3">
                        <div class="field">
                            <label>Reservas en línea</label>
                            ${settingsToggle('reservation_enabled', 'Aceptar solicitudes desde el menú', settingBool(settings, 'reservation_enabled', true))}
                        </div>
                        ${field('reservation_start', { label: 'Primera reserva', value: settingValue(settings, 'reservation_start', '12:00').slice(0, 5), type: 'time' })}
                        ${field('reservation_end', { label: 'Última reserva', value: settingValue(settings, 'reservation_end', '22:00').slice(0, 5), type: 'time' })}
                        ${field('reservation_slot_minutes', { label: 'Intervalo (minutos)', value: settingValue(settings, 'reservation_slot_minutes', '30'), type: 'number', attrs: 'min="5" max="120" step="5"' })}
                        ${field('reservation_max_party', { label: 'Máximo de personas', value: settingValue(settings, 'reservation_max_party', '12'), type: 'number', attrs: 'min="1" max="100" step="1"' })}
                        ${field('reservation_capacity', { label: 'Aforo por franja (personas)', value: settingValue(settings, 'reservation_capacity', '20'), type: 'number', attrs: 'min="0" max="500" step="1"', hint: '0 = sin límite.' })}
                        ${field('reservation_turn_minutes', { label: 'Duración de la reserva (min)', value: settingValue(settings, 'reservation_turn_minutes', '90'), type: 'number', attrs: 'min="30" max="240" step="15"', hint: 'Tiempo que ocupa la mesa.' })}
                    </div>
                    <p class="form-hint">El aforo suma las personas de reservas pendientes y confirmadas que se solapen con la franja. Si se alcanza el límite, la hora deja de estar disponible en el menú.</p>
                    <div class="field">
                        <label>Días disponibles</label>
                        <div class="days-grid">${dayChips}</div>
                        <span class="field-error" data-error-for="reservation_days"></span>
                    </div>
                    ${field('reservation_note.es', { label: 'Nota de reserva · Español', value: settingTranslation(settings, 'reservation_note', 'es'), type: 'textarea', rows: 3 })}
                    ${field('reservation_note.en', { label: 'Nota de reserva · English', value: settingTranslation(settings, 'reservation_note', 'en'), type: 'textarea', rows: 3 })}
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('bag', 17)}</span>
                    <div><h2>Pedidos y visibilidad</h2><p>Pedidos por WhatsApp y productos agotados.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        ${settingsToggle('whatsapp_orders_enabled', 'Pedidos por WhatsApp', settingBool(settings, 'whatsapp_orders_enabled', true), 'El menú público arma el mensaje y abre WhatsApp.')}
                        ${settingsToggle('order_hours_enabled', 'Respetar horario de atención', settingBool(settings, 'order_hours_enabled', true), 'Bloquea el envío de pedidos cuando el restaurante está cerrado.')}
                        ${settingsToggle('show_sold_out', 'Mostrar productos agotados', settingBool(settings, 'show_sold_out', true), 'Si se desactiva, los agotados se ocultan del menú.')}
                    </div>
                    ${field('public_menu_url', { label: 'Enlace público del menú', value: settingValue(settings, 'public_menu_url'), type: 'url', placeholder: 'https://turestaurante.com/', hint: 'Se usa en el QR y en los enlaces “Ver menú”. Déjalo vacío para usar automáticamente la IP de tu red local.' })}
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('bell', 17)}</span>
                    <div><h2>Notificaciones</h2><p>Avisos de nuevas reservas por Telegram.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        ${settingsToggle('telegram_enabled', 'Avisar por Telegram', settingBool(settings, 'telegram_enabled', false), 'Cada reserva nueva se envía al chat configurado.')}
                        ${field('telegram_chat_id', { label: 'Chat ID', value: settingValue(settings, 'telegram_chat_id'), placeholder: '-1001234567890', hint: 'ID numérico del chat o grupo, o @usuario.' })}
                    </div>
                    ${field('telegram_bot_token', { label: 'Token del bot', value: settingValue(settings, 'telegram_bot_token'), placeholder: '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ', hint: 'Lo entrega @BotFather. Se guarda solo en la base de datos y no se publica.' })}
                    <div class="form-actions-inline">
                        <button class="btn btn-ghost" type="button" data-test-telegram>${icon('bell', 15)} Enviar prueba</button>
                        <span class="form-hint" data-telegram-status role="status" aria-live="polite"></span>
                    </div>
                    <p class="form-hint">Para recibir avisos: crea un bot con @BotFather, inicia una conversación con él y pega aquí el token y tu chat ID. Guarda los cambios antes de enviar la prueba.</p>
                </div>
            </section>

            <section class="form-section">
                <div class="form-section-head">
                    <span class="section-icon">${icon('image', 17)}</span>
                    <div><h2>Imágenes de marca</h2><p>Logo y portada del menú público.</p></div>
                </div>
                <div class="form-section-body">
                    <div class="form-grid">
                        ${settingsImageField('logo', 'Logo', data.public?.business?.logo_url || '', 'Se muestra en el encabezado del menú y del panel.')}
                        ${settingsImageField('hero', 'Imagen principal', data.public?.business?.hero_image_url || '', 'Portada del menú público. Recomendado: horizontal.')}
                    </div>
                </div>
            </section>

            <div class="save-bar">
                <p>Los cambios se publican al guardar.</p>
                <button class="btn btn-ghost" type="button" data-discard>Descartar</button>
                <button class="btn btn-primary" type="submit" data-save>${icon('check', 15)} Guardar cambios</button>
            </div>
        </form>
    `;

    const form = qs('#settings-form');
    const saveButton = form?.querySelector('[data-save]');

    qsa('[data-closed]', form).forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const row = checkbox.closest('[data-hours-day]');
            const closed = checkbox.checked;
            row?.classList.toggle('is-closed', closed);
            qsa('input[type="time"]', row).forEach((input) => {
                input.disabled = closed;
            });
        });
    });

    qsa('[data-settings-image]', form).forEach((input) => {
        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            input.value = '';
            await uploadSettingImage(input.dataset.settingsImage, file, form);
        });
    });

    const testButton = qs('[data-test-telegram]', form);
    const testStatus = qs('[data-telegram-status]', form);
    testButton?.addEventListener('click', async () => {
        if (testStatus) {
            testStatus.textContent = 'Enviando…';
            testStatus.classList.remove('is-error', 'is-success');
        }

        setButtonBusy(testButton, true, 'Enviando…');
        const outcome = await perform(() => api.post('admin/settings/test-notification'));
        setButtonBusy(testButton, false);

        if (!outcome.ok) {
            if (testStatus) {
                testStatus.textContent = outcome.error?.message || 'No se pudo enviar la prueba.';
                testStatus.classList.add('is-error');
            }
            return;
        }

        const sent = Boolean(outcome.result?.sent);
        if (testStatus) {
            testStatus.textContent = sent
                ? 'Prueba enviada. Revisa tu chat de Telegram.'
                : (outcome.result?.error || 'No se pudo enviar la prueba.');
            testStatus.classList.toggle('is-success', sent);
            testStatus.classList.toggle('is-error', !sent);
        }

        toast(sent ? 'Notificación de prueba enviada.' : 'Telegram rechazó la prueba.', sent ? 'success' : 'error');
    });

    qs('[data-discard]')?.addEventListener('click', async () => {
        const confirmed = await confirmDialog({
            title: 'Descartar cambios',
            message: 'Se perderán los cambios que no hayas guardado.',
            confirmLabel: 'Descartar',
            danger: true,
        });
        if (confirmed) renderRoute('settings');
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors(form);

        const esName = fieldValue(form, 'business_name.es');
        if (!esName) {
            applyFieldErrors(form, { 'business_name.es': 'El nombre del restaurante es obligatorio.' });
            return;
        }

        const translations = {};
        ['business_name', 'tagline', 'description', 'address', 'opening_hours_text', 'reservation_note'].forEach((key) => {
            const es = fieldValue(form, `${key}.es`);
            const en = fieldValue(form, `${key}.en`) || es;
            translations[key] = { es, en };
        });

        const openingHoursPayload = {};
        qsa('[data-hours-day]', form).forEach((row) => {
            openingHoursPayload[row.dataset.hoursDay] = {
                open: row.querySelector('[data-open]')?.value || '12:00',
                close: row.querySelector('[data-close]')?.value || '22:00',
                closed: Boolean(row.querySelector('[data-closed]')?.checked),
            };
        });

        const days = qsa('input[name="reservation_days"]:checked', form).map((input) => Number(input.value));

        const payload = {
            ...translations,
            phone: fieldValue(form, 'phone'),
            whatsapp: fieldValue(form, 'whatsapp'),
            email: fieldValue(form, 'email'),
            instagram: fieldValue(form, 'instagram'),
            facebook: fieldValue(form, 'facebook'),
            maps_url: fieldValue(form, 'maps_url'),
            currency_code: fieldValue(form, 'currency_code'),
            currency_symbol: fieldValue(form, 'currency_symbol'),
            currency_locale: fieldValue(form, 'currency_locale'),
            currency_decimals: Number(fieldValue(form, 'currency_decimals') || 2),
            default_lang: fieldValue(form, 'default_lang'),
            timezone: fieldValue(form, 'timezone'),
            opening_hours: openingHoursPayload,
            reservation_enabled: fieldChecked(form, 'reservation_enabled'),
            reservation_start: fieldValue(form, 'reservation_start'),
            reservation_end: fieldValue(form, 'reservation_end'),
            reservation_days: days,
            reservation_slot_minutes: Number(fieldValue(form, 'reservation_slot_minutes') || 30),
            reservation_max_party: Number(fieldValue(form, 'reservation_max_party') || 20),
            reservation_capacity: Number(fieldValue(form, 'reservation_capacity') || 0),
            reservation_turn_minutes: Number(fieldValue(form, 'reservation_turn_minutes') || 90),
            whatsapp_orders_enabled: fieldChecked(form, 'whatsapp_orders_enabled'),
            order_hours_enabled: fieldChecked(form, 'order_hours_enabled'),
            show_sold_out: fieldChecked(form, 'show_sold_out'),
            public_menu_url: fieldValue(form, 'public_menu_url'),
            telegram_enabled: fieldChecked(form, 'telegram_enabled'),
            telegram_bot_token: fieldValue(form, 'telegram_bot_token'),
            telegram_chat_id: fieldValue(form, 'telegram_chat_id'),
        };

        setButtonBusy(saveButton, true, 'Guardando…');
        const outcome = await perform(() => api.put('admin/settings', payload), { form });
        setButtonBusy(saveButton, false);

        if (!outcome.ok) return;

        state.publicSettings = outcome.result.public || state.publicSettings;
        applyBranding(state.publicSettings?.business || {});
        updateChrome('settings');
        toast('Configuración guardada.', 'success');
    });
}

/* ------------------------------------------------------------------ usuarios */

function userRow(user) {
    const active = Boolean(user.is_active);
    const self = user.id === state.user.id;
    const role = roleLabel(user.role);

    return `<tr>
        <td>
            <div class="cell-product">
                <span class="thumb">${escapeHtml(initials(user.name))}</span>
                <span class="cell-title">
                    <strong>${escapeHtml(user.name)}${self ? ' <span class="badge badge-gold">Tú</span>' : ''}</strong>
                    <small>${escapeHtml(user.email)}</small>
                </span>
            </div>
        </td>
        <td><span class="badge ${user.role === 'admin' ? 'badge-gold' : 'badge-muted'}">${escapeHtml(role)}</span></td>
        <td><span class="badge ${active ? 'badge-success' : 'badge-danger'}">${active ? 'Activo' : 'Inactivo'}</span></td>
        <td><span class="cell-muted">${escapeHtml(user.last_login_at ? formatDateTime(user.last_login_at) : 'Nunca')}</span></td>
        <td><span class="cell-muted">${escapeHtml(user.must_change_password ? 'Pendiente' : 'Al día')}</span></td>
        <td class="actions-col">
            <div class="row-actions">
                <button class="mini-btn" type="button" data-action="edit" data-id="${user.id}" title="Editar" aria-label="Editar ${escapeHtml(user.name)}">
                    ${icon('edit', 15)}
                </button>
                <button class="mini-btn is-danger" type="button" data-action="deactivate" data-id="${user.id}"
                    title="${self ? 'No puedes desactivar tu propio usuario' : 'Desactivar'}"
                    aria-label="Desactivar ${escapeHtml(user.name)}" ${self || !active ? 'disabled' : ''}>
                    ${icon('trash', 15)}
                </button>
            </div>
        </td>
    </tr>`;
}

async function reloadUsers() {
    const users = await guardRequest(api.get('admin/users'));
    if (!users) return;
    refreshUserRows(users);
}

let usersCache = [];

function refreshUserRows(users = usersCache) {
    usersCache = users;
    if (state.current !== 'users') return;

    const tbody = qs('#user-rows');
    if (!tbody) return;

    tbody.innerHTML = users.map(userRow).join('');

    const count = qs('#users-count');
    if (count) count.textContent = `${users.length} ${users.length === 1 ? 'usuario' : 'usuarios'}`;

    const table = qs('#users-table');
    const empty = qs('#users-empty');
    if (users.length === 0) {
        if (table) table.hidden = true;
        if (empty) {
            empty.hidden = false;
            empty.innerHTML = '<h3>Sin usuarios</h3><p>Crea una cuenta para tu equipo.</p>';
        }
    } else {
        if (table) table.hidden = false;
        if (empty) empty.hidden = true;
    }
}

function openUserModal(user = null) {
    const isEdit = Boolean(user);
    const active = user ? Boolean(user.is_active) : true;

    const modal = openModal({
        title: isEdit ? 'Editar usuario' : 'Nuevo usuario',
        body: `
            <form id="user-form" class="modal-form" novalidate>
                ${field('name', { label: 'Nombre', value: user?.name || '', required: true, placeholder: 'Nombre y apellido', autocomplete: 'off' })}
                ${field('email', { label: 'Correo', value: user?.email || '', type: 'email', required: true, placeholder: 'persona@restaurante.com' })}
                <div class="field">
                    <label for="user-role-field">Rol</label>
                    <select class="select" id="user-role-field" name="role">
                        <option value="editor" ${user?.role === 'editor' || !user ? 'selected' : ''}>Editor · carta, reservas y QR</option>
                        <option value="admin" ${user?.role === 'admin' ? 'selected' : ''}>Administrador · acceso total</option>
                    </select>
                    <span class="field-error" data-error-for="role"></span>
                </div>
                ${field('password', {
                    label: isEdit ? 'Nueva contraseña (opcional)' : 'Contraseña temporal',
                    value: '',
                    type: 'password',
                    required: !isEdit,
                    placeholder: isEdit ? 'Dejar vacío para no cambiarla' : 'Mínimo 10 caracteres',
                    hint: isEdit ? 'Si escribes una contraseña, el usuario deberá cambiarla al iniciar sesión.' : 'El usuario deberá cambiarla al iniciar sesión.',
                    attrs: 'minlength="10" maxlength="120" autocomplete="new-password"',
                })}
                ${isEdit
                    ? `<div class="field"><label>Estado</label><label class="check"><input type="checkbox" name="is_active" ${active ? 'checked' : ''}> <span>Usuario activo</span></label></div>`
                    : ''}
            </form>
        `,
        footer: `
            <button class="btn btn-ghost" type="button" data-cancel>Cancelar</button>
            <button class="btn btn-primary" type="submit" form="user-form" data-save>${icon('check', 15)} ${isEdit ? 'Guardar cambios' : 'Crear usuario'}</button>
        `,
    });

    modal.layer.querySelector('[data-cancel]')?.addEventListener('click', modal.close);

    const form = modal.layer.querySelector('#user-form');
    const saveButton = modal.layer.querySelector('[data-save]');

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors(form);

        const password = fieldValue(form, 'password');
        if (!isEdit && password.length < 10) {
            applyFieldErrors(form, { password: 'La contraseña debe tener al menos 10 caracteres.' });
            return;
        }
        if (isEdit && password !== '' && password.length < 10) {
            applyFieldErrors(form, { password: 'La contraseña debe tener al menos 10 caracteres.' });
            return;
        }

        const payload = {
            name: fieldValue(form, 'name'),
            email: fieldValue(form, 'email'),
            role: fieldValue(form, 'role'),
        };

        if (password) payload.password = password;
        if (isEdit) payload.is_active = fieldChecked(form, 'is_active');

        setButtonBusy(saveButton, true, 'Guardando…');
        const outcome = await perform(
            () => (user ? api.put(`admin/users/${user.id}`, payload) : api.post('admin/users', payload)),
            { form },
        );
        setButtonBusy(saveButton, false);

        if (!outcome.ok) return;

        modal.close();
        toast(user ? 'Usuario actualizado.' : 'Usuario creado.', 'success');
        await reloadUsers();
    });
}

async function deactivateUser(user) {
    const confirmed = await confirmDialog({
        title: 'Desactivar usuario',
        message: `¿Desactivar la cuenta de “${user.name}”? No podrá iniciar sesión hasta que la reactives.`,
        confirmLabel: 'Desactivar',
        danger: true,
    });
    if (!confirmed) return;

    await perform(() => api.delete(`admin/users/${user.id}`), {
        success: 'Usuario desactivado.',
        onSuccess: reloadUsers,
    });
}

async function renderUsersView(token) {
    const users = await guardRequest(api.get('admin/users'), token);
    if (!isCurrent(token)) return;

    if (!users) {
        view.innerHTML = failurePanel('No pudimos cargar los usuarios.');
        bindRetry();
        return;
    }

    usersCache = Array.isArray(users) ? users : [];

    view.innerHTML = `
        <div class="toolbar">
            <span class="toolbar-count" id="users-count"></span>
            <span class="spacer"></span>
            <button class="btn btn-primary" type="button" data-new-user>${icon('plus', 15)} Nuevo usuario</button>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div><h2>Equipo del restaurante</h2><span class="small">Los editores no pueden acceder a configuración, usuarios ni auditoría.</span></div>
            </div>
            <div class="table-wrap">
                <table class="table" id="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Último acceso</th>
                            <th>Contraseña</th>
                            <th class="actions-col">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="user-rows"></tbody>
                </table>
            </div>
            <div class="admin-empty" id="users-empty" hidden></div>
        </section>
    `;

    qs('[data-new-user]')?.addEventListener('click', () => openUserModal(null));

    qs('#user-rows')?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;

        const user = usersCache.find((item) => item.id === Number(button.dataset.id));
        if (!user) return;

        if (button.dataset.action === 'edit') openUserModal(user);
        if (button.dataset.action === 'deactivate') await deactivateUser(user);
    });

    refreshUserRows(usersCache);
}

/* ---------------------------------------------------------------- auditoría */

function auditRow(row) {
    const [label, tone] = auditLabel(row.action);
    const meta = row.meta && typeof row.meta === 'object' ? JSON.stringify(row.meta, null, 2) : '';
    const entity = row.entity ? `${row.entity}${row.entity_id ? ` #${row.entity_id}` : ''}` : '—';

    return `<tr>
        <td class="nowrap"><span class="cell-muted">${escapeHtml(formatDateTime(row.created_at))}</span></td>
        <td>
            <div class="cell-title">
                <strong>${escapeHtml(row.user_name || 'Sistema')}</strong>
                <small>${escapeHtml(row.user_email || '—')}</small>
            </div>
        </td>
        <td>
            <div class="cell-title">
                <strong>${escapeHtml(label)}</strong>
                <small>${escapeHtml(row.action)}</small>
            </div>
        </td>
        <td><span class="cell-muted">${escapeHtml(entity)}</span></td>
        <td><span class="cell-muted">${escapeHtml(row.ip || '—')}</span></td>
        <td>
            ${meta
                ? `<details class="audit-meta"><summary>Ver detalles</summary><pre>${escapeHtml(meta)}</pre></details>`
                : '<span class="cell-muted">—</span>'}
        </td>
    </tr>`;
}

async function renderAuditView(token) {
    const result = await guardRequest(api.getFull(`admin/audit-log?page=${state.auditPage}&per_page=30`), token);
    if (!isCurrent(token)) return;

    if (!result) {
        view.innerHTML = failurePanel('No pudimos cargar la auditoría.');
        bindRetry();
        return;
    }

    const items = Array.isArray(result.data) ? result.data : [];
    const meta = result.meta || {};
    const page = Number(meta.page || state.auditPage);
    const pages = Number(meta.pages || 0);
    const total = Number(meta.total || items.length);

    view.innerHTML = `
        <div class="toolbar">
            <span class="toolbar-count">${escapeHtml(String(total))} movimientos registrados</span>
            <span class="spacer"></span>
            <button class="btn btn-outline btn-sm" type="button" data-refresh>${icon('refresh', 14)} Actualizar</button>
        </div>

        <section class="panel">
            <div class="panel-head">
                <div><h2>Registro de actividad</h2><span class="small">Acciones del equipo, cambios de estado y accesos.</span></div>
            </div>
            <div class="table-wrap">
                <table class="table" id="audit-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Entidad</th>
                            <th>IP</th>
                            <th>Detalles</th>
                        </tr>
                    </thead>
                    <tbody id="audit-rows"></tbody>
                </table>
            </div>
            <div class="admin-empty" id="audit-empty" hidden>
                <h3>Sin actividad</h3><p>Las acciones del panel se registrarán aquí.</p>
            </div>
            <div class="pagination" id="audit-pagination"></div>
        </section>
    `;

    qs('[data-refresh]')?.addEventListener('click', () => renderRoute('audit'));

    const tbody = qs('#audit-rows');
    const table = qs('#audit-table');
    const empty = qs('#audit-empty');

    if (items.length === 0) {
        if (table) table.hidden = true;
        if (empty) empty.hidden = false;
    } else {
        if (table) table.hidden = false;
        if (empty) empty.hidden = true;
        if (tbody) tbody.innerHTML = items.map(auditRow).join('');
    }

    const pagination = qs('#audit-pagination');
    if (pagination) {
        pagination.innerHTML = total === 0 ? '' : `
            <span class="pagination-info">Página ${page} de ${Math.max(pages, 1)}</span>
            <div class="pagination-actions">
                <button class="btn btn-outline btn-sm" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Anterior</button>
                <button class="btn btn-outline btn-sm" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Siguiente</button>
            </div>
        `;

        qsa('[data-page]', pagination).forEach((button) => {
            button.addEventListener('click', () => {
                state.auditPage = Number(button.dataset.page);
                renderRoute('audit');
            });
        });
    }
}

/* ------------------------------------------------------------------ inicio */

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
