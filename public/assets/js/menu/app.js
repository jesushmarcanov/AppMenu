/* ==========================================================================
   AppMenu · menú público
   Carga la carta desde la API, filtros, pedido por WhatsApp y reservas.
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
    formatDateLong,
    todayISO,
    toast,
    lockBody,
    unlockBody,
    openModal,
    setButtonBusy,
    clearFieldErrors,
    applyFieldErrors,
    observeReveals,
    whatsappLink,
} from '../core/ui.js';

const CART_KEY = 'appmenu.cart.v1';
const LANG_KEY = 'appmenu.lang';
const WEEK_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const I18N = {
    es: {
        common: {
            skip: 'Saltar al menú',
            closed: 'Cerrado',
            close: 'Cerrar',
            retry: 'Reintentar',
            sending: 'Enviando…',
            error: 'No pudimos completar la acción.',
            errorTitle: 'Algo salió mal',
            loading: 'Cargando…',
        },
        nav: {
            menu: 'Menú',
            featured: 'Especiales',
            reservations: 'Reservas',
            contact: 'Contacto',
            toggle: 'Abrir navegación',
            language: 'Idioma',
        },
        hero: {
            viewMenu: 'Ver el menú',
            reserve: 'Reservar mesa',
            today: 'Horario de hoy',
            location: 'Dónde estamos',
            call: 'Llámanos',
            confirmation: 'Confirmación personal',
            select: 'Selección de la casa',
        },
        featured: {
            eyebrow: 'Selección de la casa',
            title: 'Los imprescindibles',
            note: 'Platos que nuestros comensales vuelven a pedir.',
        },
        menu: {
            explore: 'Explora',
            eyebrow: 'La carta',
            title: 'Nuestro menú',
            subtitle: 'Cocina de mercado, preparada al momento.',
            searchLabel: 'Buscar en el menú',
            searchPlaceholder: 'Buscar un plato…',
            all: 'Todos',
            details: 'Ver',
            order: 'Pedir',
            empty: 'No encontramos platos',
            emptyHint: 'Prueba con otra búsqueda o categoría.',
            resultOne: '1 plato',
            resultMany: '{count} platos',
        },
        product: {
            special: 'Especial',
            soldOut: 'Agotado',
            soldOutNote: 'No disponible por ahora',
            addName: 'Añadir {name} al pedido',
            addToOrder: 'Añadir al pedido',
            added: '{name} se añadió al pedido',
        },
        reservation: {
            eyebrow: 'Reservas',
            title: 'Una mesa para compartir',
            intro: 'Cuéntanos cuándo quieres venir y prepararemos todo para recibirte. Confirmaremos tu reserva personalmente.',
            hours: 'Horario',
            where: 'Dónde estamos',
            noteTitle: 'Buena mesa, mejor compañía',
            note: 'Para grupos grandes, escríbenos directamente.',
            formTitle: 'Reserva tu mesa',
            formSubtitle: 'Completa los datos y te confirmamos.',
            submit: 'Solicitar reserva',
            legal: 'Te contactaremos para confirmar. Sin cargos anticipados.',
            successTitle: 'Solicitud recibida',
            successText: 'Gracias. Te contactaremos para confirmar tu mesa.',
            successDetail: '{date} a las {time} · {party} personas',
            again: 'Hacer otra reserva',
            closedTitle: 'Reservas en pausa',
            closed: 'Escríbenos por WhatsApp y te ayudamos a reservar.',
            chooseTime: 'Selecciona una hora',
            guest: '{count} persona',
            guests: '{count} personas',
            weekdayClosed: 'No abrimos este día. Elige otra fecha.',
            noSlots: 'No quedan horarios para esta fecha.',
            full: 'Sin espacio',
            required: 'Completa este campo.',
            nameMin: 'Escribe tu nombre completo.',
            phoneMin: 'Escribe un teléfono válido.',
            emailInvalid: 'Revisa el correo electrónico.',
            submitError: 'No pudimos enviar la reserva. Inténtalo de nuevo.',
        },
        form: {
            name: 'Nombre completo',
            phone: 'Teléfono',
            email: 'Correo (opcional)',
            date: 'Fecha',
            time: 'Hora',
            party: 'Personas',
            notes: 'Notas (opcional)',
        },
        contact: {
            eyebrow: 'Visítanos',
            title: 'Nos encantará verte',
            note: 'Estamos a un mensaje de distancia.',
            contactTitle: 'Contacto',
            hoursTitle: 'Horario',
            book: 'Reservar una mesa',
            mapTitle: 'Encuéntranos',
            openMap: 'Abrir en mapas',
        },
        footer: {
            menu: 'Menú',
            contact: 'Contacto',
            follow: 'Síguenos',
            menuLink: 'La carta',
            specials: 'Especiales',
            reservations: 'Reservas',
            phone: 'Teléfono',
            email: 'Correo',
            digital: 'Menú digital',
            admin: 'Panel de administración',
        },
        cart: {
            fab: 'Pedido',
            open: 'Abrir pedido',
            title: 'Tu pedido',
            items: '{count} artículos',
            empty: 'Tu pedido está vacío',
            emptyHint: 'Añade platos del menú para pedir por WhatsApp.',
            browse: 'Ver el menú',
            total: 'Total',
            whatsapp: 'Pedir por WhatsApp',
            disabled: 'Los pedidos por WhatsApp no están disponibles ahora.',
            legal: 'El pedido se abre en WhatsApp. No se realiza ningún cargo en esta página.',
            orderType: 'Tipo de pedido',
            table: 'En mesa',
            pickup: 'Para llevar',
            delivery: 'Delivery',
            noteLabel: 'Nota para el pedido',
            noteTable: 'Número de mesa (opcional)',
            notePickup: 'Hora de retiro (opcional)',
            noteDelivery: 'Dirección de entrega (opcional)',
            name: 'Nombre',
            namePlaceholder: 'Tu nombre',
            phone: 'Teléfono',
            phonePlaceholder: '+58 412 000 0000',
            tableNumber: 'Mesa',
            tablePlaceholder: 'Número de mesa',
            address: 'Dirección de entrega',
            addressPlaceholder: 'Calle, edificio, punto de referencia',
            statusOpen: 'Abierto ahora · cierra a las {time}',
            statusOpensAt: 'Cerrado ahora · abre {when}',
            statusAlways: 'Pedidos por WhatsApp disponibles',
            statusChecking: 'Comprobando horario…',
            statusClosedHint: 'Puedes armar tu pedido y enviarlo cuando abramos.',
            nameMin: 'Escribe tu nombre.',
            phoneMin: 'Escribe un teléfono válido.',
            tableMin: 'Indica el número de mesa.',
            addressMin: 'Indica la dirección de entrega.',
            opensToday: 'hoy a las {time}',
            opensOn: '{day} a las {time}',
            messageName: 'Cliente',
            messagePhone: 'Teléfono',
            messageTable: 'Mesa',
            messageAddress: 'Dirección',
            remove: 'Quitar del pedido',
            addOne: 'Añadir uno',
            removeOne: 'Quitar uno',
            emptyError: 'Añade al menos un plato para pedir.',
            messageIntro: 'Hola {business}, quiero hacer un pedido:',
            messageTotal: 'Total',
            messageType: 'Tipo de pedido',
            messageNote: 'Nota',
            messageThanks: '¡Gracias!',
        },
        days: {
            monday: 'Lunes',
            tuesday: 'Martes',
            wednesday: 'Miércoles',
            thursday: 'Jueves',
            friday: 'Viernes',
            saturday: 'Sábado',
            sunday: 'Domingo',
        },
    },
    en: {
        common: {
            skip: 'Skip to menu',
            closed: 'Closed',
            close: 'Close',
            retry: 'Try again',
            sending: 'Sending…',
            error: 'We could not complete the action.',
            errorTitle: 'Something went wrong',
            loading: 'Loading…',
        },
        nav: {
            menu: 'Menu',
            featured: 'Specials',
            reservations: 'Reservations',
            contact: 'Contact',
            toggle: 'Open navigation',
            language: 'Language',
        },
        hero: {
            viewMenu: 'View the menu',
            reserve: 'Book a table',
            today: "Today's hours",
            location: 'Find us',
            call: 'Call us',
            confirmation: 'Personal confirmation',
            select: "Chef's selection",
        },
        featured: {
            eyebrow: "Chef's selection",
            title: 'The essentials',
            note: 'Dishes our guests keep coming back for.',
        },
        menu: {
            explore: 'Explore',
            eyebrow: 'The menu',
            title: 'Our menu',
            subtitle: 'Market cooking, prepared to order.',
            searchLabel: 'Search the menu',
            searchPlaceholder: 'Search a dish…',
            all: 'All',
            details: 'View',
            order: 'Order',
            empty: 'No dishes found',
            emptyHint: 'Try another search or category.',
            resultOne: '1 dish',
            resultMany: '{count} dishes',
        },
        product: {
            special: 'Special',
            soldOut: 'Sold out',
            soldOutNote: 'Not available right now',
            addName: 'Add {name} to the order',
            addToOrder: 'Add to order',
            added: '{name} was added to your order',
        },
        reservation: {
            eyebrow: 'Reservations',
            title: 'A table to share',
            intro: 'Tell us when you would like to come and we will have everything ready. We confirm every reservation personally.',
            hours: 'Hours',
            where: 'Find us',
            noteTitle: 'Good table, better company',
            note: 'For larger parties, please contact us directly.',
            formTitle: 'Book your table',
            formSubtitle: 'Fill in your details and we will confirm.',
            submit: 'Request reservation',
            legal: 'We will contact you to confirm. No advance charges.',
            successTitle: 'Request received',
            successText: 'Thank you. We will contact you to confirm your table.',
            successDetail: '{date} at {time} · {party} guests',
            again: 'Make another reservation',
            closedTitle: 'Reservations paused',
            closed: 'Message us on WhatsApp and we will help you book.',
            chooseTime: 'Choose a time',
            guest: '{count} guest',
            guests: '{count} guests',
            weekdayClosed: 'We are closed that day. Please choose another date.',
            noSlots: 'No times left for this date.',
            full: 'Fully booked',
            required: 'Please complete this field.',
            nameMin: 'Please enter your full name.',
            phoneMin: 'Please enter a valid phone number.',
            emailInvalid: 'Please check the email address.',
            submitError: 'We could not send the reservation. Please try again.',
        },
        form: {
            name: 'Full name',
            phone: 'Phone',
            email: 'Email (optional)',
            date: 'Date',
            time: 'Time',
            party: 'Guests',
            notes: 'Notes (optional)',
        },
        contact: {
            eyebrow: 'Visit us',
            title: 'We would love to see you',
            note: 'We are one message away.',
            contactTitle: 'Contact',
            hoursTitle: 'Hours',
            book: 'Book a table',
            mapTitle: 'Find us',
            openMap: 'Open in maps',
        },
        footer: {
            menu: 'Menu',
            contact: 'Contact',
            follow: 'Follow us',
            menuLink: 'The menu',
            specials: 'Specials',
            reservations: 'Reservations',
            phone: 'Phone',
            email: 'Email',
            digital: 'Digital menu',
            admin: 'Admin panel',
        },
        cart: {
            fab: 'Order',
            open: 'Open order',
            title: 'Your order',
            items: '{count} items',
            empty: 'Your order is empty',
            emptyHint: 'Add dishes from the menu to order on WhatsApp.',
            browse: 'Browse the menu',
            total: 'Total',
            whatsapp: 'Order on WhatsApp',
            disabled: 'WhatsApp orders are not available right now.',
            legal: 'The order opens in WhatsApp. No charge is made on this page.',
            orderType: 'Order type',
            table: 'Dine in',
            pickup: 'Pickup',
            delivery: 'Delivery',
            noteLabel: 'Order note',
            noteTable: 'Table number (optional)',
            notePickup: 'Pickup time (optional)',
            noteDelivery: 'Delivery address (optional)',
            name: 'Name',
            namePlaceholder: 'Your name',
            phone: 'Phone',
            phonePlaceholder: '+1 555 000 0000',
            tableNumber: 'Table',
            tablePlaceholder: 'Table number',
            address: 'Delivery address',
            addressPlaceholder: 'Street, building, landmark',
            statusOpen: 'Open now · closes at {time}',
            statusOpensAt: 'Closed now · opens {when}',
            statusAlways: 'WhatsApp orders available',
            statusChecking: 'Checking opening hours…',
            statusClosedHint: 'You can build your order and send it when we open.',
            nameMin: 'Enter your name.',
            phoneMin: 'Enter a valid phone number.',
            tableMin: 'Enter the table number.',
            addressMin: 'Enter the delivery address.',
            opensToday: 'today at {time}',
            opensOn: '{day} at {time}',
            messageName: 'Customer',
            messagePhone: 'Phone',
            messageTable: 'Table',
            messageAddress: 'Address',
            remove: 'Remove from order',
            addOne: 'Add one',
            removeOne: 'Remove one',
            emptyError: 'Add at least one dish to order.',
            messageIntro: 'Hello {business}, I would like to place an order:',
            messageTotal: 'Total',
            messageType: 'Order type',
            messageNote: 'Note',
            messageThanks: 'Thank you!',
        },
        days: {
            monday: 'Monday',
            tuesday: 'Tuesday',
            wednesday: 'Wednesday',
            thursday: 'Thursday',
            friday: 'Friday',
            saturday: 'Saturday',
            sunday: 'Sunday',
        },
    },
};

/* --------------------------------------------------------------- estado */

function initialLang() {
    const fromUrl = new URLSearchParams(window.location.search).get('lang');
    if (fromUrl === 'es' || fromUrl === 'en') {
        return fromUrl;
    }

    const saved = localStorage.getItem(LANG_KEY);
    if (saved === 'es' || saved === 'en') {
        return saved;
    }

    return String(navigator.language || 'es').toLowerCase().startsWith('en') ? 'en' : 'es';
}

function sanitizeItems(list) {
    const merged = new Map();

    if (!Array.isArray(list)) {
        return [];
    }

    for (const item of list) {
        const id = Number(item?.id);
        const qty = Math.trunc(Number(item?.qty));

        if (!Number.isInteger(id) || id <= 0 || !Number.isFinite(qty) || qty <= 0) {
            continue;
        }

        merged.set(id, Math.min(99, (merged.get(id) || 0) + qty));
    }

    return Array.from(merged, ([id, qty]) => ({ id, qty }));
}

function loadOrderState() {
    const fallback = {
        items: [],
        orderType: 'table',
        notes: { table: '', pickup: '', delivery: '' },
        customer: { name: '', phone: '', address: '', table: '' },
    };

    try {
        const raw = JSON.parse(localStorage.getItem(CART_KEY) || 'null');
        if (!raw) {
            return fallback;
        }

        if (Array.isArray(raw)) {
            return { ...fallback, items: sanitizeItems(raw) };
        }

        return {
            items: sanitizeItems(raw.items),
            orderType: ['table', 'pickup', 'delivery'].includes(raw.orderType) ? raw.orderType : 'table',
            notes: {
                table: String(raw.notes?.table || ''),
                pickup: String(raw.notes?.pickup || ''),
                delivery: String(raw.notes?.delivery || ''),
            },
            customer: {
                name: String(raw.customer?.name || ''),
                phone: String(raw.customer?.phone || ''),
                address: String(raw.customer?.address || ''),
                table: String(raw.customer?.table || ''),
            },
        };
    } catch {
        return fallback;
    }
}

const state = {
    lang: initialLang(),
    menu: null,
    settings: null,
    productById: new Map(),
    filter: 'all',
    search: '',
    loading: false,
    cartLayer: null,
    cartFocus: null,
    orderStatus: null,
    orderStatusAt: 0,
    reservationSlots: null,
    ...loadOrderState(),
};

const api = new ApiClient('api/v1');
let loadToken = 0;

/* --------------------------------------------------------------- helpers */

function t(key, vars = {}) {
    const dot = key.indexOf('.');
    const group = dot === -1 ? 'common' : key.slice(0, dot);
    const name = dot === -1 ? key : key.slice(dot + 1);
    const value = I18N[state.lang]?.[group]?.[name] ?? I18N.es?.[group]?.[name] ?? key;

    return String(value).replace(/\{(\w+)\}/g, (match, token) => (
        Object.prototype.hasOwnProperty.call(vars, token) ? String(vars[token]) : match
    ));
}

function normalize(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

function money(value) {
    return formatMoney(value, state.settings?.currency || {});
}

function persistOrder() {
    try {
        localStorage.setItem(CART_KEY, JSON.stringify({
            items: state.items,
            orderType: state.orderType,
            notes: state.notes,
            customer: state.customer,
        }));
    } catch {
        /* almacenamiento no disponible */
    }
}

function setText(id, value) {
    const node = qs(`#${id}`);
    if (node) {
        node.textContent = value;
    }
}

function setBrandMark(node, logoUrl, name) {
    if (!node) return;

    if (logoUrl) {
        node.innerHTML = `<img src="${escapeHtml(logoUrl)}" alt="">`;
    } else {
        node.textContent = initials(name);
    }
}

/* --------------------------------------------------------------- idioma */

function applyStaticI18n() {
    document.documentElement.lang = state.lang;
    window.AppMenuTheme?.refreshLabels();

    qsa('[data-i18n]').forEach((node) => {
        node.textContent = t(node.dataset.i18n);
    });

    qsa('[data-i18n-placeholder]').forEach((node) => {
        node.setAttribute('placeholder', t(node.dataset.i18nPlaceholder));
    });

    const search = qs('#menu-search');
    if (search) {
        search.placeholder = t('menu.searchPlaceholder');
    }

    qs('#cart-button')?.setAttribute('aria-label', t('cart.open'));
    qs('#cart-fab')?.setAttribute('aria-label', t('cart.open'));
    qs('#nav-toggle')?.setAttribute('aria-label', t('nav.toggle'));
    qs('.lang-switch')?.setAttribute('aria-label', t('nav.language'));

    qsa('[data-lang]').forEach((button) => {
        const active = button.dataset.lang === state.lang;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

function setLang(lang) {
    if (lang !== 'es' && lang !== 'en') return;
    if (lang === state.lang && state.menu) return;

    state.lang = lang;
    localStorage.setItem(LANG_KEY, lang);
    applyStaticI18n();
    loadMenu();
}

/* --------------------------------------------------------------- render */

function productMedia(product) {
    const src = product.image_thumb_url || product.image_url;

    if (src) {
        return `<img src="${escapeHtml(src)}" alt="${escapeHtml(product.name)}" loading="lazy" decoding="async">`;
    }

    return `<span class="product-emoji" aria-hidden="true">${escapeHtml(product.emoji || '🍽️')}</span>`;
}

function badges(product) {
    const items = [];

    if (product.is_featured) {
        items.push(`<span class="badge badge-gold">${t('product.special')}</span>`);
    }
    if (!product.is_available) {
        items.push(`<span class="badge badge-danger">${t('product.soldOut')}</span>`);
    }

    return items.length ? `<div class="product-badges">${items.join('')}</div>` : '';
}

function productCard(product) {
    const unit = product.unit ? `<small>/${escapeHtml(product.unit)}</small>` : '';

    return `
        <article class="product-card${product.is_available ? '' : ' is-sold-out'}">
            <div class="product-media">
                ${badges(product)}
                ${productMedia(product)}
                <button class="quick-add" type="button" data-add="${product.id}" aria-label="${escapeHtml(t('product.addName', { name: product.name }))}"${product.is_available ? '' : ' disabled'}>
                    ${icon('plus', 16)}
                </button>
            </div>
            <div class="product-body">
                <span class="product-cat">${escapeHtml(product.category_name || '')}</span>
                <h3 class="product-title">${escapeHtml(product.name)}</h3>
                <p class="product-desc">${escapeHtml(product.description || '')}</p>
                <div class="product-foot">
                    <span class="price">${money(product.price)}${unit}</span>
                    <button class="btn btn-sm btn-outline" type="button" data-open="${product.id}">${t('menu.details')}</button>
                </div>
            </div>
        </article>
    `;
}

function featuredCard(product) {
    return `
        <article class="featured-card">
            <div class="featured-media">
                ${badges(product)}
                ${productMedia(product)}
            </div>
            <div class="featured-body">
                <span class="product-cat">${escapeHtml(product.category_name || '')}</span>
                <h3>${escapeHtml(product.name)}</h3>
                <p>${escapeHtml(product.description || '')}</p>
                <div class="featured-foot">
                    <span class="price">${money(product.price)}</span>
                    <button class="btn btn-sm btn-primary" type="button" data-open="${product.id}">${t('menu.order')}</button>
                </div>
            </div>
        </article>
    `;
}

function renderFeatured() {
    const featured = state.menu?.featured || [];
    const section = qs('#featured');
    const navLink = qs('[data-nav="featured"]');
    const rail = qs('#featured-rail');

    if (section) section.hidden = featured.length === 0;
    if (navLink) navLink.hidden = featured.length === 0;
    if (rail) rail.innerHTML = featured.map(featuredCard).join('');
}

function renderPills() {
    const container = qs('#category-pills');
    if (!container) return;

    const categories = state.menu?.categories || [];
    const allProducts = categories.flatMap((category) => category.products);
    const entries = [
        { slug: 'all', name: t('menu.all'), products: allProducts, emoji: '✦' },
        ...categories.map((category) => ({
            ...category,
            emoji: category.products[0]?.emoji || '🍴',
        })),
    ];

    container.innerHTML = entries.map((entry) => {
        const active = state.filter === entry.slug;
        return `
            <button class="pill${active ? ' is-active' : ''}" type="button" data-category="${escapeHtml(entry.slug)}" aria-pressed="${active ? 'true' : 'false'}">
                <span class="pill-emoji" aria-hidden="true">${escapeHtml(entry.emoji)}</span>
                ${escapeHtml(entry.name)}
                <span class="pill-count">${entry.products.length}</span>
            </button>
        `;
    }).join('');
}

function renderMenu() {
    const content = qs('#menu-content');
    if (!content) return;

    if (!state.menu) {
        return;
    }

    const query = normalize(state.search);
    const categories = (state.menu.categories || [])
        .filter((category) => state.filter === 'all' || category.slug === state.filter)
        .map((category) => ({
            ...category,
            products: category.products.filter((product) => {
                if (!query) return true;
                return normalize(`${product.name} ${product.description} ${category.name}`).includes(query);
            }),
        }))
        .filter((category) => category.products.length > 0);

    if (categories.length === 0) {
        content.innerHTML = `
            <div class="empty-state menu-empty">
                ${icon('search', 30)}
                <h3>${t('menu.empty')}</h3>
                <p>${t('menu.emptyHint')}</p>
                <button class="btn btn-outline btn-sm" type="button" data-reset-filters>${t('menu.all')}</button>
            </div>
        `;
        return;
    }

    content.innerHTML = categories.map((category, index) => {
        const count = category.products.length;
        const countLabel = count === 1 ? t('menu.resultOne') : t('menu.resultMany', { count });
        const groupId = `menu-group-${index}`;

        return `
            <section class="menu-group" aria-labelledby="${groupId}">
                <div class="menu-group-head">
                    <h3 id="${groupId}">${escapeHtml(category.name)}</h3>
                    <span class="group-count">${countLabel}</span>
                    ${category.description ? `<p>${escapeHtml(category.description)}</p>` : '<span class="spacer"></span>'}
                </div>
                <div class="product-grid">
                    ${category.products.map(productCard).join('')}
                </div>
            </section>
        `;
    }).join('');

    observeReveals(content);
}

function todayHours() {
    const hours = state.settings?.opening_hours || {};
    const key = WEEK_KEYS[(new Date().getDay() + 6) % 7];
    const schedule = hours[key];

    if (!schedule || schedule.closed || !schedule.open || !schedule.close) {
        return t('common.closed');
    }

    return `${schedule.open} – ${schedule.close}`;
}

function renderHoursList(node) {
    if (!node) return;

    const hours = state.settings?.opening_hours || {};
    const rows = WEEK_KEYS.map((key) => {
        const schedule = hours[key] || {};
        const value = !schedule.closed && schedule.open && schedule.close
            ? `${schedule.open} – ${schedule.close}`
            : t('common.closed');

        return `<div><span>${t(`days.${key}`)}</span><span>${value}</span></div>`;
    });

    node.innerHTML = rows.join('');
}

/* ------------------------------------------------------------ SEO / metadatos */

function setMeta(selector, value) {
    if (!value) return;
    const node = document.head.querySelector(selector);
    if (node) {
        node.setAttribute('content', value);
    }
}

function openingHoursSpecification() {
    const hours = state.settings?.opening_hours || {};
    const names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const result = [];

    WEEK_KEYS.forEach((key, index) => {
        const entry = hours[key] || {};
        if (entry.closed || !entry.open || !entry.close) return;

        result.push({
            '@type': 'OpeningHoursSpecification',
            dayOfWeek: `https://schema.org/${names[index]}`,
            opens: String(entry.open).slice(0, 5),
            closes: String(entry.close).slice(0, 5),
        });
    });

    return result;
}

function setFavicon(name) {
    const mark = initials(name || 'M') || 'M';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="#0b0a09" stroke="#c9a227"/><text x="32" y="43" font-family="Georgia" font-size="${mark.length > 1 ? 22 : 28}" fill="#e4c766" text-anchor="middle">${mark}</text></svg>`;
    document.querySelector('link[rel="icon"]')?.setAttribute('href', `data:image/svg+xml,${encodeURIComponent(svg)}`);
}

function applySeo() {
    const business = state.settings?.business || {};
    const name = String(business.name || '').trim();
    if (name === '') return;

    const description = String(business.description || business.tagline || '').trim()
        || `Carta, horarios y reservas de ${name}.`;
    const url = String(state.settings?.public_menu_url || window.location.href);
    const image = String(business.hero_image_url || business.logo_url || '');

    document.title = `${name} · ${t('menu.title')}`;
    document.documentElement.lang = state.lang;

    setMeta('meta[name="description"]', description);
    setMeta('meta[property="og:title"]', document.title);
    setMeta('meta[property="og:description"]', description);
    setMeta('meta[property="og:site_name"]', name);
    setMeta('meta[property="og:url"]', url);
    setMeta('meta[name="twitter:title"]', document.title);
    setMeta('meta[name="twitter:description"]', description);

    if (image !== '') {
        setMeta('meta[property="og:image"]', image);
        setMeta('meta[name="twitter:image"]', image);
    }

    let canonical = document.head.querySelector('link[rel="canonical"]');
    if (!canonical) {
        canonical = document.createElement('link');
        canonical.rel = 'canonical';
        document.head.append(canonical);
    }
    canonical.href = url;

    let structured = document.getElementById('structured-data');
    if (!structured) {
        structured = document.createElement('script');
        structured.type = 'application/ld+json';
        structured.id = 'structured-data';
        document.head.append(structured);
    }

    const data = {
        '@context': 'https://schema.org',
        '@type': 'Restaurant',
        name,
        description,
        url,
        hasMenu: state.settings?.public_menu_url || url,
    };

    if (business.tagline) data.servesCuisine = String(business.tagline);
    if (business.phone) data.telephone = String(business.phone);
    if (business.address) data.address = { '@type': 'PostalAddress', streetAddress: String(business.address) };
    if (image !== '') data.image = image;

    const hours = openingHoursSpecification();
    if (hours.length > 0) data.openingHoursSpecification = hours;

    structured.textContent = JSON.stringify(data);
    setFavicon(name);
}

function renderHero() {
    const settings = state.settings;
    const business = settings?.business || {};
    const name = business.name || 'Menú digital';
    const featured = state.menu?.featured || [];
    const product = featured[0] || state.menu?.categories?.[0]?.products?.[0];

    setText('brand-name', name);
    setText('footer-brand-name', name);
    setText('brand-tagline', business.tagline || '');
    setText('footer-brand-tagline', business.tagline || '');
    setText('footer-note', business.description || '');
    setText('hero-eyebrow', business.opening_hours_text || t('hero.select'));
    setText('hero-tagline', business.tagline || '');
    setText('hero-description', business.description || '');
    setText('map-address', business.address || '');
    setText('reservation-address', business.address || '');
    setText('float-hours', todayHours());

    setBrandMark(qs('#brand-mark'), business.logo_url, name);
    setBrandMark(qs('#footer-brand-mark'), business.logo_url, name);

    const heroTitle = qs('#hero-title');
    if (heroTitle) {
        const words = name.trim().split(/\s+/);
        const last = words.pop() || name;
        heroTitle.innerHTML = `${words.length ? `${escapeHtml(words.join(' '))} ` : ''}<em>${escapeHtml(last)}</em>`;
    }

    const heroBg = qs('#hero-bg');
    if (heroBg) {
        if (business.hero_image_url) {
            heroBg.style.backgroundImage = `url("${business.hero_image_url}")`;
        } else {
            heroBg.style.backgroundImage = '';
        }
    }

    const plate = qs('#hero-plate');
    if (plate && product) {
        const src = product.image_thumb_url || product.image_url;
        const media = src
            ? `<img class="plate-media" src="${escapeHtml(src)}" alt="${escapeHtml(product.name)}" loading="lazy">`
            : `<span class="plate-emoji" aria-hidden="true">${escapeHtml(product.emoji || '🍽️')}</span>`;

        plate.innerHTML = `
            ${media}
            <span class="plate-caption">
                <strong>${escapeHtml(product.name)}</strong>
                ${escapeHtml(product.category_name || t('hero.select'))}
            </span>
        `;
    }

    const facts = [
        { icon: 'clock', title: todayHours(), label: t('hero.today') },
        business.address ? { icon: 'pin', title: t('hero.location'), label: business.address } : null,
        business.phone ? { icon: 'phone', title: t('hero.call'), label: business.phone } : null,
    ].filter(Boolean);

    const factsNode = qs('#hero-facts');
    if (factsNode) {
        factsNode.innerHTML = facts.map((fact) => `
            <div class="hero-fact">
                ${icon(fact.icon, 16)}
                <div>
                    <strong>${escapeHtml(fact.title)}</strong>
                    <span>${escapeHtml(fact.label)}</span>
                </div>
            </div>
        `).join('');
    }

    document.title = `${name} · ${t('menu.title')}`;
    setText('footer-copy', `© ${new Date().getFullYear()} ${name}`);
}

function mapsUrl(business) {
    if (business.maps_url) {
        return business.maps_url;
    }

    return business.address
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(business.address)}`
        : '';
}

function socialUrl(value, base) {
    if (!value) return '';
    return /^https?:\/\//i.test(value) ? value : `${base}${String(value).replace(/^@/, '')}`;
}

function renderContact() {
    const business = state.settings?.business || {};
    const items = [];

    if (business.phone) {
        items.push(`<a href="tel:${escapeHtml(business.phone.replace(/[^\d+]/g, ''))}">${icon('phone', 16)}<span>${escapeHtml(business.phone)}</span></a>`);
    }
    if (business.email) {
        items.push(`<a href="mailto:${escapeHtml(business.email)}">${icon('mail', 16)}<span>${escapeHtml(business.email)}</span></a>`);
    }
    if (business.address) {
        items.push(`<div>${icon('pin', 16)}<span>${escapeHtml(business.address)}</span></div>`);
    }

    const list = qs('#contact-list');
    if (list) list.innerHTML = items.join('');

    const socialLinks = [
        business.instagram ? { label: 'Instagram', href: socialUrl(business.instagram, 'https://instagram.com/'), icon: 'instagram' } : null,
        business.facebook ? { label: 'Facebook', href: socialUrl(business.facebook, 'https://facebook.com/'), icon: 'facebook' } : null,
        mapsUrl(business) ? { label: 'Mapa', href: mapsUrl(business), icon: 'map' } : null,
    ].filter(Boolean);

    const social = qs('#social-row');
    if (social) {
        social.innerHTML = socialLinks.map((link) => `
            <a href="${escapeHtml(link.href)}" target="_blank" rel="noopener noreferrer" aria-label="${escapeHtml(link.label)}">
                ${icon(link.icon, 17)}
            </a>
        `).join('');
        social.hidden = socialLinks.length === 0;
    }

    const mapLink = qs('#maps-link');
    if (mapLink) {
        const url = mapsUrl(business);
        mapLink.hidden = url === '';
        if (url) mapLink.href = url;
    }

    const phoneLink = qs('#footer-phone');
    if (phoneLink) {
        phoneLink.hidden = !business.phone;
        if (business.phone) {
            phoneLink.textContent = business.phone;
            phoneLink.href = `tel:${business.phone.replace(/[^\d+]/g, '')}`;
        }
    }

    const whatsappFooter = qs('#footer-whatsapp');
    if (whatsappFooter) {
        const link = whatsappLink(business.whatsapp, '');
        whatsappFooter.hidden = link === '';
        if (link) whatsappFooter.href = link;
    }

    const emailLink = qs('#footer-email');
    if (emailLink) {
        emailLink.hidden = !business.email;
        if (business.email) {
            emailLink.textContent = business.email;
            emailLink.href = `mailto:${business.email}`;
        }
    }

    const instagram = qs('#footer-instagram');
    if (instagram) {
        const url = socialUrl(business.instagram, 'https://instagram.com/');
        instagram.hidden = url === '';
        if (url) instagram.href = url;
    }

    const facebook = qs('#footer-facebook');
    if (facebook) {
        const url = socialUrl(business.facebook, 'https://facebook.com/');
        facebook.hidden = url === '';
        if (url) facebook.href = url;
    }

    renderHoursList(qs('#reservation-hours'));
    renderHoursList(qs('#contact-hours'));

    setText('reservation-note', state.settings?.reservation?.note || t('reservation.note'));
}

function renderSettings() {
    renderHero();
    renderContact();
}

/* ------------------------------------------------------------- reservas */

function minutesToTime(total) {
    const hours = Math.floor(total / 60) % 24;
    const minutes = total % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function timeToMinutes(value) {
    const [hours, minutes = '0'] = String(value || '').split(':');
    return Number(hours) * 60 + Number(minutes);
}

function dateParts(iso) {
    const date = new Date(`${iso}T12:00:00`);
    const weekIndex = (date.getDay() + 6) % 7;
    return {
        date,
        isoDay: weekIndex + 1,
        dayKey: WEEK_KEYS[weekIndex],
    };
}

function reservationDayAllowed(iso, reservation) {
    if (!iso) return false;

    const { isoDay, dayKey } = dateParts(iso);
    const days = (reservation?.days || []).map(Number);
    if (days.length > 0 && !days.includes(isoDay)) {
        return false;
    }

    const schedule = state.settings?.opening_hours?.[dayKey];
    if (schedule && schedule.closed) {
        return false;
    }

    return true;
}

function nextReservationDate(reservation) {
    for (let offset = 0; offset < 90; offset += 1) {
        const iso = todayISO(offset);
        if (reservationDayAllowed(iso, reservation)) {
            return iso;
        }
    }

    return '';
}

function buildTimeSlots(iso, reservation) {
    if (!iso || !reservationDayAllowed(iso, reservation)) {
        return [];
    }

    const schedule = state.settings?.opening_hours?.[dateParts(iso).dayKey] || {};
    const step = Math.max(15, Number(reservation.slot_minutes) || 30);
    let start = timeToMinutes(reservation.start || '12:00');
    let end = timeToMinutes(reservation.end || '22:00');

    if (schedule.open && schedule.close) {
        const open = timeToMinutes(schedule.open);
        let close = timeToMinutes(schedule.close);
        if (close <= open) {
            close += 1440;
        }
        start = Math.max(start, open);
        end = Math.min(end, close);
    }

    if (end <= start) {
        return [];
    }

    const slots = [];
    for (let cursor = start; cursor < end; cursor += step) {
        slots.push(minutesToTime(cursor));
    }

    if (slots.length === 0) {
        slots.push(minutesToTime(start));
    }

    if (iso === todayISO()) {
        const now = new Date();
        const limit = now.getHours() * 60 + now.getMinutes() + 15;
        return slots.filter((slot) => timeToMinutes(slot) > limit);
    }

    return slots;
}

let reservationSlotsToken = 0;

async function fillTimeOptions(select, iso, reservation) {
    if (!select) return;

    state.reservationSlots = null;
    renderTimeOptions(iso, reservation);

    const slots = buildTimeSlots(iso, reservation);
    if (slots.length === 0) return;

    const token = ++reservationSlotsToken;
    try {
        const data = await api.get(`reservations/availability?date=${encodeURIComponent(iso)}&lang=${state.lang}`);
        if (token !== reservationSlotsToken) return;

        const map = new Map();
        (data?.slots || []).forEach((slot) => {
            if (slot && typeof slot.time === 'string') {
                map.set(slot.time, slot);
            }
        });

        state.reservationSlots = { date: iso, slots: map };
        renderTimeOptions(iso, reservation);
    } catch {
        /* sin conexión: se mantienen los horarios calculados localmente */
    }
}

function renderTimeOptions(iso, reservation) {
    const select = qs('#res-time');
    if (!select) return;

    const slots = buildTimeSlots(iso, reservation);
    const availability = state.reservationSlots?.date === iso ? state.reservationSlots.slots : null;
    const party = Number(qs('#res-party')?.value || 0);
    const previous = select.value;

    if (slots.length === 0) {
        select.innerHTML = `<option value="">${t('reservation.noSlots')}</option>`;
        return;
    }

    const options = slots.map((slot) => {
        const entry = availability?.get(slot);
        const available = !entry
            || (entry.available !== false && (entry.remaining === null || entry.remaining === undefined || entry.remaining >= party));

        return `<option value="${slot}"${available ? '' : ' disabled'}>${slot}${available ? '' : ` · ${t('reservation.full')}`}</option>`;
    });

    select.innerHTML = `<option value="">${t('reservation.chooseTime')}</option>${options.join('')}`;

    const keep = previous ? select.querySelector(`option[value="${previous}"]`) : null;
    if (keep && !keep.disabled) {
        select.value = previous;
    }
}

function setupReservation() {
    const reservation = state.settings?.reservation;
    const form = qs('#reservation-form');
    const wrapper = qs('#reservation-form-wrap');
    const success = qs('#reservation-success');
    const unavailable = qs('#reservation-unavailable');
    const dateInput = qs('#res-date');
    const timeInput = qs('#res-time');
    const partyInput = qs('#res-party');

    if (!form || !wrapper || !dateInput || !timeInput || !partyInput) return;

    const enabled = Boolean(reservation?.enabled);

    wrapper.hidden = !enabled;
    if (success) success.hidden = true;
    if (unavailable) unavailable.hidden = enabled;

    if (!enabled) return;

    const maxParty = Math.max(1, Number(reservation.max_party) || 12);
    partyInput.innerHTML = Array.from({ length: maxParty }, (_, index) => {
        const count = index + 1;
        const label = count === 1 ? t('reservation.guest', { count }) : t('reservation.guests', { count });
        return `<option value="${count}"${count === 2 ? ' selected' : ''}>${label}</option>`;
    }).join('');

    dateInput.min = todayISO(0);
    dateInput.max = todayISO(120);

    if (!dateInput.value || !reservationDayAllowed(dateInput.value, reservation)) {
        dateInput.value = nextReservationDate(reservation);
    }

    fillTimeOptions(timeInput, dateInput.value, reservation);

    partyInput.addEventListener('change', () => {
        renderTimeOptions(dateInput.value, reservation);
    });
}

async function submitReservation(event) {
    event.preventDefault();

    const form = event.currentTarget;
    const reservation = state.settings?.reservation;
    if (!reservation) return;

    clearFieldErrors(form);

    const data = new FormData(form);
    if (String(data.get('website') || '').trim() !== '') {
        return;
    }

    const name = String(data.get('name') || '').trim();
    const phone = String(data.get('phone') || '').trim();
    const email = String(data.get('email') || '').trim();
    const date = String(data.get('reservation_date') || '');
    const time = String(data.get('reservation_time') || '');
    const partySize = Number(data.get('party_size') || 0);
    const shortNotes = String(data.get('notes') || '').trim();
    const longNotes = String(data.get('details') || '').trim();
    const notes = [shortNotes, longNotes].filter(Boolean).join(' · ').slice(0, 1000);
    const errors = {};

    if (name.length < 2) {
        errors.name = t('reservation.nameMin');
    }
    if (phone.replace(/\D/g, '').length < 7) {
        errors.phone = t('reservation.phoneMin');
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = t('reservation.emailInvalid');
    }
    if (!date) {
        errors.reservation_date = t('reservation.required');
    } else if (!reservationDayAllowed(date, reservation)) {
        errors.reservation_date = t('reservation.weekdayClosed');
    }
    if (!time) {
        errors.reservation_time = t('reservation.required');
    }
    if (!partySize) {
        errors.party_size = t('reservation.required');
    }

    if (Object.keys(errors).length > 0) {
        applyFieldErrors(form, errors);
        qs('.is-invalid', form)?.focus();
        return;
    }

    const button = qs('#reservation-submit');
    setButtonBusy(button, true, t('common.sending'));

    try {
        await api.post('reservations', {
            name,
            phone,
            email: email || undefined,
            reservation_date: date,
            reservation_time: time,
            party_size: partySize,
            notes: notes || undefined,
            locale: state.lang,
        });

        form.reset();
        dateInputReset(date);
        const success = qs('#reservation-success');
        const wrapper = qs('#reservation-form-wrap');
        const message = qs('#reservation-success-text');

        if (message) {
            message.textContent = `${t('reservation.successText')} ${t('reservation.successDetail', {
                date: formatDateLong(date, state.lang),
                time,
                party: partySize,
            })}`;
        }
        if (wrapper) wrapper.hidden = true;
        if (success) {
            success.hidden = false;
            success.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    } catch (error) {
        if (error instanceof ApiError && Object.keys(error.fields || {}).length > 0) {
            applyFieldErrors(form, error.fields);
            qs('.is-invalid', form)?.focus();
        }
        toast(error?.message || t('reservation.submitError'), 'error');
    } finally {
        setButtonBusy(button, false);
    }
}

function dateInputReset() {
    const reservation = state.settings?.reservation;
    const dateInput = qs('#res-date');
    const timeInput = qs('#res-time');
    if (!dateInput || !reservation) return;

    dateInput.value = nextReservationDate(reservation);
    fillTimeOptions(timeInput, dateInput.value, reservation);
}

/* --------------------------------------------------------------- carrito */

function productFor(item) {
    return state.productById.get(Number(item.id)) || null;
}

function cartCount() {
    return state.items.reduce((total, item) => total + item.qty, 0);
}

function cartTotal() {
    return state.items.reduce((total, item) => {
        const product = productFor(item);
        return total + (product ? Number(product.price) * item.qty : 0);
    }, 0);
}

function renderCartCount() {
    const count = cartCount();

    for (const node of [qs('#cart-count'), qs('#cart-count-mobile')]) {
        if (!node) continue;
        node.textContent = count > 0 ? String(count) : '';
        node.dataset.count = String(count);
    }
}

function pruneCart() {
    const before = state.items.length;
    state.items = state.items.filter((item) => state.productById.has(Number(item.id)));

    if (state.items.length !== before) {
        persistOrder();
    }
}

function addToCart(id, qty = 1) {
    const product = state.productById.get(Number(id));
    if (!product) return;

    if (!product.is_available) {
        toast(t('product.soldOutNote'), 'error');
        return;
    }

    const amount = Math.min(99, Math.max(1, Math.trunc(qty)));
    const existing = state.items.find((item) => Number(item.id) === Number(id));

    if (existing) {
        existing.qty = Math.min(99, existing.qty + amount);
    } else {
        state.items.push({ id: Number(id), qty: amount });
    }

    persistOrder();
    renderCartCount();
    if (state.cartLayer) renderCartDrawer();
    toast(t('product.added', { name: product.name }), 'success', 2400);
}

function changeQty(id, delta) {
    const item = state.items.find((entry) => Number(entry.id) === Number(id));
    if (!item) return;

    item.qty += delta;
    if (item.qty <= 0) {
        state.items = state.items.filter((entry) => Number(entry.id) !== Number(id));
    }

    persistOrder();
    renderCartCount();
    if (state.cartLayer) renderCartDrawer();
}

function removeFromCart(id) {
    state.items = state.items.filter((item) => Number(item.id) !== Number(id));
    persistOrder();
    renderCartCount();
    if (state.cartLayer) renderCartDrawer();
}

function cartNotePlaceholder() {
    return t({
        table: 'cart.noteTable',
        pickup: 'cart.notePickup',
        delivery: 'cart.noteDelivery',
    }[state.orderType] || 'cart.noteLabel');
}

/* ------------------------------------------------- pedidos: horario y datos */

const ORDER_STATUS_TTL = 60 * 1000;

function phoneDigits(value) {
    return String(value || '').replace(/\D+/g, '');
}

function zonedClock(timezone) {
    const fallback = new Date();
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone || undefined,
            weekday: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).formatToParts(new Date());
        const value = (type) => parts.find((part) => part.type === type)?.value || '';
        const weekday = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(value('weekday'));
        const hours = Number(value('hour')) % 24;
        const minutes = Number(value('minute'));
        const date = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone || undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date());

        if (weekday >= 0 && Number.isFinite(hours) && Number.isFinite(minutes)) {
            return { dayIndex: (weekday + 6) % 7, minutes: hours * 60 + minutes, date };
        }
    } catch {
        /* zona horaria no válida: usamos la hora local */
    }

    return {
        dayIndex: (fallback.getDay() + 6) % 7,
        minutes: fallback.getHours() * 60 + fallback.getMinutes(),
        date: `${fallback.getFullYear()}-${String(fallback.getMonth() + 1).padStart(2, '0')}-${String(fallback.getDate()).padStart(2, '0')}`,
    };
}

function scheduleOf(hours, dayKey) {
    const entry = hours?.[dayKey];
    if (!entry || entry.closed || !entry.open || !entry.close) {
        return { closed: true };
    }

    const start = timeToMinutes(entry.open);
    let end = timeToMinutes(entry.close);
    if (end <= start) {
        end += 1440;
    }

    return { closed: false, start, end };
}

function localOrderStatus() {
    const settings = state.settings || {};
    const business = settings.business || {};
    const hours = settings.opening_hours || {};
    const clock = zonedClock(settings.timezone);
    const todayKey = WEEK_KEYS[clock.dayIndex];
    const today = scheduleOf(hours, todayKey);

    const status = {
        enabled: Boolean(settings.whatsapp_orders_enabled),
        respect_hours: Boolean(settings.order_hours_enabled),
        open: true,
        reason: 'always_open',
        timezone: settings.timezone || '',
        today: {
            day: todayKey,
            open: today.closed ? '' : minutesToTime(today.start),
            close: today.closed ? '' : minutesToTime(today.end),
            closed: Boolean(today.closed),
        },
        closes_at: null,
        opens_at: null,
    };

    if (settings.order_hours_enabled && Object.keys(hours).length > 0) {
        const previous = scheduleOf(hours, WEEK_KEYS[(clock.dayIndex + 6) % 7]);
        const carriedOver = !previous.closed && previous.end > 1440 && clock.minutes < previous.end - 1440;

        if (carriedOver) {
            status.closes_at = minutesToTime(previous.end);
        } else if (today.closed) {
            status.open = false;
            status.reason = 'closed_day';
            status.opens_at = nextOpeningLocal(hours, clock);
        } else if (clock.minutes < today.start) {
            status.open = false;
            status.reason = 'before_open';
            status.opens_at = { date: clock.date, day: todayKey, time: minutesToTime(today.start) };
        } else if (clock.minutes >= today.end) {
            status.open = false;
            status.reason = 'after_close';
            status.opens_at = nextOpeningLocal(hours, clock);
        } else {
            status.closes_at = minutesToTime(today.end);
        }
    }

    const digits = phoneDigits(business.whatsapp);
    const validNumber = digits.length >= 8 && digits.length <= 15 && !digits.startsWith('0');

    if (!status.enabled) {
        status.reason = 'disabled';
    } else if (!validNumber) {
        status.reason = 'invalid_number';
    } else if (!status.open) {
        // conserva el motivo de horario
    } else {
        status.reason = 'ok';
    }

    status.can_order = status.reason === 'ok';

    return status;
}

function nextOpeningLocal(hours, clock) {
    for (let step = 1; step <= 7; step += 1) {
        const index = (clock.dayIndex + step) % 7;
        const schedule = scheduleOf(hours, WEEK_KEYS[index]);
        if (schedule.closed) continue;

        const date = new Date(`${clock.date}T12:00:00`);
        date.setDate(date.getDate() + step);

        return {
            date: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`,
            day: WEEK_KEYS[index],
            time: minutesToTime(schedule.start),
        };
    }

    return null;
}

async function refreshOrderStatus(force = false) {
    if (!state.settings) return null;

    if (!force && state.orderStatus && Date.now() - state.orderStatusAt < ORDER_STATUS_TTL) {
        return state.orderStatus;
    }

    state.orderStatus = localOrderStatus();
    state.orderStatusAt = Date.now();

    try {
        const remote = await api.get('orders/availability');
        if (remote && typeof remote === 'object' && 'can_order' in remote) {
            state.orderStatus = remote;
            state.orderStatusAt = Date.now();
        }
    } catch {
        /* sin conexión: mantenemos la evaluación local */
    }

    updateOrderStatusUi();

    return state.orderStatus;
}

function orderStatusText(status) {
    if (!status) return t('cart.statusChecking');
    if (!status.enabled || status.reason === 'invalid_number') return t('cart.disabled');

    if (status.open) {
        return status.closes_at
            ? t('cart.statusOpen', { time: status.closes_at })
            : t('cart.statusAlways');
    }

    if (!status.opens_at) return t('cart.statusClosedHint');

    const today = zonedClock(status.timezone).date;
    const when = status.opens_at.date === today
        ? t('cart.opensToday', { time: status.opens_at.time })
        : t('cart.opensOn', { day: t(`days.${status.opens_at.day}`), time: status.opens_at.time });

    return t('cart.statusOpensAt', { when });
}

function updateOrderStatusUi() {
    const node = qs('#order-status');
    const button = qs('#cart-whatsapp');
    if (!node) return;

    const status = state.orderStatus || localOrderStatus();
    const open = Boolean(status.can_order);

    node.textContent = orderStatusText(status);
    node.classList.toggle('is-open', open);
    node.classList.toggle('is-closed', !open);

    if (button) {
        button.disabled = !open;
        button.setAttribute('aria-disabled', open ? 'false' : 'true');
    }
}

function cartMessage() {
    const business = state.settings?.business || {};
    const customer = state.customer || {};
    const lines = [t('cart.messageIntro', { business: business.name || '' }), ''];

    for (const item of state.items) {
        const product = productFor(item);
        if (!product) continue;
        lines.push(`• ${item.qty} × ${product.name} — ${money(Number(product.price) * item.qty)}`);
    }

    lines.push('');
    lines.push(`${t('cart.messageTotal')}: ${money(cartTotal())}`);
    lines.push(`${t('cart.messageType')}: ${t(`cart.${state.orderType}`)}`);

    if (String(customer.name || '').trim() !== '') {
        lines.push(`${t('cart.messageName')}: ${String(customer.name).trim()}`);
    }
    if (String(customer.phone || '').trim() !== '') {
        lines.push(`${t('cart.messagePhone')}: ${String(customer.phone).trim()}`);
    }
    if (state.orderType === 'table' && String(customer.table || '').trim() !== '') {
        lines.push(`${t('cart.messageTable')}: ${String(customer.table).trim()}`);
    }
    if (state.orderType === 'delivery' && String(customer.address || '').trim() !== '') {
        lines.push(`${t('cart.messageAddress')}: ${String(customer.address).trim()}`);
    }

    const note = String(state.notes[state.orderType] || '').trim();
    if (note) {
        lines.push(`${t('cart.messageNote')}: ${note}`);
    }

    lines.push('', t('cart.messageThanks'));

    return lines.join('\n');
}

function cartItemsHtml() {
    return state.items.map((item) => {
        const product = productFor(item);
        if (!product) return '';

        const src = product.image_thumb_url || product.image_url;
        const media = src
            ? `<img src="${escapeHtml(src)}" alt="" loading="lazy">`
            : escapeHtml(product.emoji || '🍽️');

        return `
            <div class="cart-item">
                <div class="cart-item-media" aria-hidden="true">${media}</div>
                <div>
                    <div class="cart-item-name">${escapeHtml(product.name)}</div>
                    <div class="cart-item-price">${money(product.price)}${product.unit ? ` / ${escapeHtml(product.unit)}` : ''}</div>
                    <div class="qty">
                        <button type="button" data-cart-dec="${product.id}" aria-label="${t('cart.removeOne')}">${icon('minus', 13)}</button>
                        <span>${item.qty}</span>
                        <button type="button" data-cart-inc="${product.id}" aria-label="${t('cart.addOne')}">${icon('plus', 13)}</button>
                    </div>
                </div>
                <div class="cart-item-end">
                    <button class="icon-link" type="button" data-cart-remove="${product.id}" aria-label="${t('cart.remove')}">${icon('trash', 14)}</button>
                    <span class="cart-item-total">${money(Number(product.price) * item.qty)}</span>
                </div>
            </div>
        `;
    }).join('');
}

function cartCheckoutHtml() {
    const customer = state.customer || {};
    const note = state.notes[state.orderType] || '';
    const status = state.orderStatus || localOrderStatus();
    const total = money(cartTotal());
    const open = Boolean(status.can_order);

    return `
        <form class="cart-checkout" id="cart-order-form" novalidate>
            <div class="order-type" role="group" aria-label="${t('cart.orderType')}">
                ${['table', 'pickup', 'delivery'].map((type) => `
                    <button type="button" data-order-type="${type}" class="${state.orderType === type ? 'is-active' : ''}">${t(`cart.${type}`)}</button>
                `).join('')}
            </div>
            <p class="order-status ${open ? 'is-open' : 'is-closed'}" id="order-status" aria-live="polite">${escapeHtml(orderStatusText(status))}</p>
            <div class="form-grid cart-customer">
                <div class="field">
                    <label class="field-label" for="cart-name">${t('cart.name')}</label>
                    <input class="input" id="cart-name" name="name" type="text" autocomplete="name" maxlength="80" value="${escapeHtml(String(customer.name || ''))}" placeholder="${escapeHtml(t('cart.namePlaceholder'))}">
                    <span class="field-error" data-error-for="name"></span>
                </div>
                <div class="field">
                    <label class="field-label" for="cart-phone">${t('cart.phone')}</label>
                    <input class="input" id="cart-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="${escapeHtml(String(customer.phone || ''))}" placeholder="${escapeHtml(t('cart.phonePlaceholder'))}">
                    <span class="field-error" data-error-for="phone"></span>
                </div>
                ${state.orderType === 'table' ? `
                    <div class="field span-2">
                        <label class="field-label" for="cart-table">${t('cart.tableNumber')}</label>
                        <input class="input" id="cart-table" name="table" type="text" inputmode="numeric" maxlength="12" value="${escapeHtml(String(customer.table || ''))}" placeholder="${escapeHtml(t('cart.tablePlaceholder'))}">
                        <span class="field-error" data-error-for="table"></span>
                    </div>` : ''}
                ${state.orderType === 'delivery' ? `
                    <div class="field span-2">
                        <label class="field-label" for="cart-address">${t('cart.address')}</label>
                        <input class="input" id="cart-address" name="address" type="text" autocomplete="street-address" maxlength="180" value="${escapeHtml(String(customer.address || ''))}" placeholder="${escapeHtml(t('cart.addressPlaceholder'))}">
                        <span class="field-error" data-error-for="address"></span>
                    </div>` : ''}
            </div>
            <div class="field">
                <label class="field-label" for="cart-note">${t('cart.noteLabel')}</label>
                <input class="input" id="cart-note" type="text" maxlength="180" value="${escapeHtml(note)}" placeholder="${escapeHtml(cartNotePlaceholder())}" autocomplete="off">
            </div>
            <div class="cart-total">
                <span>${t('cart.total')}</span>
                <strong>${total}</strong>
            </div>
            <button class="btn whatsapp-btn" type="submit" id="cart-whatsapp" ${open ? '' : 'disabled aria-disabled="true"'}>${icon('whatsapp', 18)} ${t('cart.whatsapp')}</button>
            <p class="cart-legal">${t('cart.legal')}${open ? '' : ` ${t('cart.statusClosedHint')}`}</p>
        </form>
    `;
}

function renderCartDrawer() {
    const layer = state.cartLayer;
    if (!layer) return;

    const count = cartCount();
    const items = cartItemsHtml();

    layer.innerHTML = `
        <div class="drawer-backdrop" data-cart-close></div>
        <aside class="cart-drawer" role="dialog" aria-modal="true" aria-labelledby="cart-title">
            <header class="cart-head">
                <div>
                    <h3 id="cart-title">${t('cart.title')}</h3>
                    <span class="cart-head-sub">${t('cart.items', { count })}</span>
                </div>
                <span class="spacer"></span>
                <button class="btn-icon" type="button" data-cart-close aria-label="${t('common.close')}">${icon('close', 17)}</button>
            </header>
            <div class="cart-body">
                ${count === 0
                    ? `<div class="cart-empty">
                        ${icon('bag', 42)}
                        <h4>${t('cart.empty')}</h4>
                        <p class="small">${t('cart.emptyHint')}</p>
                        <button class="btn btn-outline btn-sm" type="button" data-cart-browse>${t('cart.browse')}</button>
                    </div>`
                    : `<div class="cart-items">${items}</div>`}
            </div>
            ${count > 0 ? cartCheckoutHtml() : ''}
        </aside>
    `;

    const noteInput = qs('#cart-note', layer);
    if (noteInput) {
        noteInput.addEventListener('input', (event) => {
            state.notes[state.orderType] = event.target.value;
            persistOrder();
        });
    }

    [['#cart-name', 'name'], ['#cart-phone', 'phone'], ['#cart-table', 'table'], ['#cart-address', 'address']].forEach(([selector, key]) => {
        const input = qs(selector, layer);
        if (!input) return;

        input.addEventListener('input', (event) => {
            state.customer[key] = event.target.value;
            persistOrder();

            const error = input.parentElement?.querySelector('[data-error-for]');
            if (error) error.textContent = '';
            input.classList.remove('is-invalid');
        });
    });

    qs('#cart-order-form', layer)?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitOrder(event.currentTarget);
    });

    qs('[data-cart-close]', layer)?.focus({ preventScroll: true });
}

function submitOrder(form) {
    const business = state.settings?.business || {};

    if (state.items.length === 0) {
        toast(t('cart.emptyError'), 'error');
        return;
    }

    clearFieldErrors(form);

    const status = state.orderStatus || localOrderStatus();
    if (!status.can_order) {
        toast(orderStatusText(status), 'error');
        updateOrderStatusUi();
        return;
    }

    const customer = state.customer || {};
    const errors = {};

    if (String(customer.name || '').trim().length < 2) {
        errors.name = t('cart.nameMin');
    }
    if (phoneDigits(customer.phone).length < 7) {
        errors.phone = t('cart.phoneMin');
    }
    if (state.orderType === 'table' && String(customer.table || '').trim() === '') {
        errors.table = t('cart.tableMin');
    }
    if (state.orderType === 'delivery' && String(customer.address || '').trim().length < 8) {
        errors.address = t('cart.addressMin');
    }

    if (Object.keys(errors).length > 0) {
        applyFieldErrors(form, errors);
        qs('.is-invalid', form)?.focus();
        return;
    }

    const link = whatsappLink(business.whatsapp, cartMessage());
    if (!link) {
        toast(t('cart.disabled'), 'error');
        return;
    }

    window.open(link, '_blank', 'noopener,noreferrer');
}

function openCart() {
    if (state.cartLayer) return;

    state.cartFocus = document.activeElement;
    const layer = document.createElement('div');
    layer.className = 'drawer-layer';
    document.body.append(layer);
    state.cartLayer = layer;
    lockBody();
    renderCartDrawer();
    refreshOrderStatus();

    layer.addEventListener('click', (event) => {
        if (event.target.closest('[data-cart-close]')) {
            closeCart();
            return;
        }

        if (event.target.closest('[data-cart-browse]')) {
            closeCart();
            qs('#menu')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        const typeButton = event.target.closest('[data-order-type]');
        if (typeButton) {
            state.orderType = typeButton.dataset.orderType;
            persistOrder();
            renderCartDrawer();
            return;
        }

        const inc = event.target.closest('[data-cart-inc]');
        if (inc) {
            changeQty(Number(inc.dataset.cartInc), 1);
            return;
        }

        const dec = event.target.closest('[data-cart-dec]');
        if (dec) {
            changeQty(Number(dec.dataset.cartDec), -1);
            return;
        }

        const remove = event.target.closest('[data-cart-remove]');
        if (remove) {
            removeFromCart(Number(remove.dataset.cartRemove));
            return;
        }
    });
}

function closeCart() {
    if (!state.cartLayer) return;

    state.cartLayer.remove();
    state.cartLayer = null;
    unlockBody();

    if (state.cartFocus instanceof HTMLElement) {
        state.cartFocus.focus({ preventScroll: true });
    }
}

/* ------------------------------------------------------- modal producto */

function openProduct(id) {
    const product = state.productById.get(Number(id));
    if (!product) return;

    let qty = 1;
    const unit = product.unit ? `<small>/${escapeHtml(product.unit)}</small>` : '';

    const modal = openModal({
        className: 'product-modal',
        ariaLabel: product.name,
        body: `
            <div class="product-modal-grid">
                <div class="product-modal-media">
                    ${productMedia(product)}
                </div>
                <div class="product-modal-body">
                    <span class="product-cat">${escapeHtml(product.category_name || '')}</span>
                    <h3>${escapeHtml(product.name)}</h3>
                    <p class="product-desc">${escapeHtml(product.description || '')}</p>
                    ${product.is_available ? '' : `<span class="sold-out-note">${t('product.soldOutNote')}</span>`}
                    <div class="modal-price-row">
                        <span class="price">${money(product.price)}</span>
                        ${unit}
                    </div>
                    <div class="modal-actions">
                        <div class="qty qty-large"${product.is_available ? '' : ' hidden'}>
                            <button type="button" data-qty-dec aria-label="${t('cart.removeOne')}">${icon('minus', 15)}</button>
                            <span data-qty-value>1</span>
                            <button type="button" data-qty-inc aria-label="${t('cart.addOne')}">${icon('plus', 15)}</button>
                        </div>
                        <button class="btn btn-primary" type="button" data-modal-add${product.is_available ? '' : ' disabled'}>
                            ${icon('bag', 17)}
                            ${product.is_available ? t('product.addToOrder') : t('product.soldOut')}
                        </button>
                    </div>
                </div>
                <button class="btn-icon modal-close" type="button" data-modal-close aria-label="${t('common.close')}">
                    ${icon('close', 18)}
                </button>
            </div>
        `,
        initialFocus: '[data-modal-add]',
    });

    const qtyNode = qs('[data-qty-value]', modal.layer);

    modal.layer.addEventListener('click', (event) => {
        if (event.target.closest('[data-qty-inc]')) {
            qty = Math.min(99, qty + 1);
            if (qtyNode) qtyNode.textContent = String(qty);
            return;
        }

        if (event.target.closest('[data-qty-dec]')) {
            qty = Math.max(1, qty - 1);
            if (qtyNode) qtyNode.textContent = String(qty);
            return;
        }

        if (event.target.closest('[data-modal-add]')) {
            addToCart(product.id, qty);
            modal.close();
        }
    });
}

/* ----------------------------------------------------------------- carga */

function renderMenuError(error) {
    const content = qs('#menu-content');
    if (!content) return;

    content.innerHTML = `
        <div class="empty-state menu-empty">
            ${icon('alert', 30)}
            <h3>${t('common.errorTitle')}</h3>
            <p>${escapeHtml(error?.message || t('common.error'))}</p>
            <button class="btn btn-outline btn-sm" type="button" data-retry>${t('common.retry')}</button>
        </div>
    `;
}

async function loadMenu() {
    const token = ++loadToken;
    state.loading = true;

    const content = qs('#menu-content');
    content?.setAttribute('aria-busy', 'true');

    try {
        const data = await api.get(`menu?lang=${encodeURIComponent(state.lang)}`);
        if (token !== loadToken) return;

        state.menu = data;
        state.settings = data.settings || {};
        state.productById = new Map(
            (data.categories || []).flatMap((category) => category.products || []).map((product) => [Number(product.id), product]),
        );

        if (!data.categories?.some((category) => category.slug === state.filter)) {
            state.filter = 'all';
        }

        pruneCart();
        applyStaticI18n();
        renderSettings();
        renderFeatured();
        renderPills();
        renderMenu();
        setupReservation();
        applySeo();
        refreshOrderStatus(true);
        renderCartCount();
        if (state.cartLayer) renderCartDrawer();
    } catch (error) {
        if (token !== loadToken) return;

        if (!state.menu) {
            renderMenuError(error);
        }
        toast(error?.message || t('common.error'), 'error');
    } finally {
        if (token === loadToken) {
            state.loading = false;
            content?.removeAttribute('aria-busy');
        }
    }
}

/* ------------------------------------------------------------- eventos */

function bindEvents() {
    const header = qs('#site-header');
    const navToggle = qs('#nav-toggle');
    const nav = qs('#site-nav');

    const onScroll = () => {
        header?.classList.toggle('is-scrolled', window.scrollY > 8);

        const offset = window.scrollY + 150;
        let current = '';
        for (const id of ['menu', 'featured', 'reservations', 'contact']) {
            const section = qs(`#${id}`);
            if (section && !section.hidden && section.offsetTop <= offset) {
                current = id;
            }
        }

        qsa('[data-nav]').forEach((link) => {
            link.classList.toggle('is-active', link.dataset.nav === current);
        });
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    navToggle?.addEventListener('click', () => {
        const open = !nav?.classList.contains('is-open');
        nav?.classList.toggle('is-open', open);
        navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    qsa('[data-nav]').forEach((link) => {
        link.addEventListener('click', () => {
            nav?.classList.remove('is-open');
            navToggle?.setAttribute('aria-expanded', 'false');
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 860) {
            nav?.classList.remove('is-open');
            navToggle?.setAttribute('aria-expanded', 'false');
        }
    });

    qsa('[data-lang]').forEach((button) => {
        button.addEventListener('click', () => setLang(button.dataset.lang));
    });

    qs('#cart-button')?.addEventListener('click', openCart);
    qs('#cart-fab')?.addEventListener('click', openCart);

    qs('#menu-search')?.addEventListener('input', debounce((event) => {
        state.search = event.target.value;
        renderMenu();
    }, 180));

    qs('#category-pills')?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-category]');
        if (!button) return;
        state.filter = button.dataset.category || 'all';
        renderPills();
        renderMenu();
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-retry]')) {
            loadMenu();
            return;
        }

        if (event.target.closest('[data-reset-filters]')) {
            state.filter = 'all';
            state.search = '';
            const search = qs('#menu-search');
            if (search) search.value = '';
            renderPills();
            renderMenu();
            return;
        }

        const open = event.target.closest('[data-open]');
        if (open) {
            openProduct(Number(open.dataset.open));
            return;
        }

        const add = event.target.closest('[data-add]');
        if (add) {
            addToCart(Number(add.dataset.add), 1);
        }
    });

    qs('#reservation-form')?.addEventListener('submit', submitReservation);

    qs('#res-date')?.addEventListener('change', (event) => {
        const dateInput = event.currentTarget;
        const reservation = state.settings?.reservation;
        const form = qs('#reservation-form');
        if (!reservation || !form) return;

        clearFieldErrors(form);
        const iso = dateInput.value;
        if (iso && !reservationDayAllowed(iso, reservation)) {
            applyFieldErrors(form, { reservation_date: t('reservation.weekdayClosed') });
        }
        fillTimeOptions(qs('#res-time'), iso, reservation);
    });

    qs('#reservation-again')?.addEventListener('click', () => {
        const success = qs('#reservation-success');
        const wrapper = qs('#reservation-form-wrap');
        if (success) success.hidden = true;
        if (wrapper) wrapper.hidden = false;
        dateInputReset();
        qs('#res-name')?.focus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.cartLayer) {
            closeCart();
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key !== CART_KEY) return;
        const next = loadOrderState();
        state.items = next.items;
        state.orderType = next.orderType;
        state.notes = next.notes;
        state.customer = next.customer;
        renderCartCount();
        if (state.cartLayer) renderCartDrawer();
    });
}

/* ------------------------------------------------------------------ init */

function init() {
    applyStaticI18n();
    renderCartCount();
    bindEvents();
    loadMenu();
}

init();
