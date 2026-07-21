const ROLE_PERMISSIONS = {
    'Super Admin': ['*'],
    Manager: [
        'dashboard.view',
        // Managers use reports and correct existing payments only.
        'payments.view',
        'payments.update',
        'reports.view',
        'reports.generate',
        'settings.password',
    ],
    Staff: [
        'dashboard.view',
        'apartments.view',
        'rooms.view',
        'tenants.view',
        'tenants.create',
        'leases.view',
        'leases.create',
        'payments.view',
        'payments.create',
        'rent_deposits.view',
        'rent_deposits.create',
        'maintenance.view',
        'maintenance.create',
        'reports.view',
    ],
};

function getAppBase() {
    const path = window.location.pathname;
    return path.substring(0, path.lastIndexOf('/') + 1);
}

function apiUrl(path) {
    return getAppBase() + path.replace(/^\//, '');
}

function ensureHttpServer() {
    if (window.location.protocol === 'file:') {
        document.body.innerHTML =
            '<div style="font-family:Poppins,sans-serif;max-width:520px;margin:80px auto;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center">' +
            '<h2 style="margin-bottom:12px;color:#222">Open through a web server</h2>' +
            '<p style="color:#555;line-height:1.6;margin-bottom:16px">This app must be served by a PHP-enabled web server, not opened by double-clicking the HTML file.</p>' +
            '<p style="margin-top:20px;font-size:13px;color:#888">Ensure PHP and the database connection are configured on the server hosting this application.</p>' +
            '</div>';
        return false;
    }
    return true;
}

function getCurrentUser() {
    try {
        return JSON.parse(sessionStorage.getItem('rentsys_user') || 'null');
    } catch (e) {
        return null;
    }
}

function can(permission) {
    const user = getCurrentUser();
    if (!user) return false;
    const perms = ROLE_PERMISSIONS[user.role] || [];
    if (perms.includes('*')) return true;
    if (perms.includes(permission)) return true;
    const parts = permission.split('.');
    if (parts.length === 2 && perms.includes(parts[0] + '.*')) return true;
    return false;
}

function requireAuth() {
    if (sessionStorage.getItem('rentsys_auth') !== '1') {
        window.location.href = getAppBase() + 'login.html';
        return false;
    }
    return true;
}

function requirePageAccess(permission) {
    if (!can(permission)) {
        alert('You do not have access to this page.');
        window.location.href = getAppBase() + 'dashboard.html';
        return false;
    }
    return true;
}

function loadUser() {
    const user = getCurrentUser();
    if (!user) return;
    const nameEl = document.getElementById('userName');
    const roleEl = document.getElementById('userRole');
    const avatarEl = document.getElementById('avatarLetter');
    if (nameEl) nameEl.textContent = user.name || 'User';
    if (roleEl) roleEl.textContent = user.role || '';
    if (avatarEl) avatarEl.textContent = (user.name || 'U').charAt(0).toUpperCase();
}

function applyAccessControl() {
    document.querySelectorAll('[data-perm]').forEach((el) => {
        const perm = el.getAttribute('data-perm');
        if (!can(perm)) {
            el.style.display = 'none';
        }
    });

    const pagePerm = document.body.getAttribute('data-page-perm');
    if (pagePerm && !can(pagePerm)) {
        alert('You do not have access to this page.');
        window.location.href = getAppBase() + 'dashboard.html';
    }
}

async function logoutUser() {
    try {
        await apiRequest('api/logout.php', { method: 'POST', body: '{}' });
    } catch (err) {}
    sessionStorage.removeItem('rentsys_auth');
    sessionStorage.removeItem('rentsys_user');
    window.location.href = getAppBase() + 'login.html';
}

function initLayout() {
    const sidebar = document.getElementById('sidebar');
    const topbar = document.getElementById('topbar');
    const content = document.getElementById('content');
    const toggle = document.getElementById('toggle');

    if (toggle && sidebar && topbar && content) {
        toggle.onclick = function () {
            if (window.innerWidth > 900) {
                sidebar.classList.toggle('collapsed');
                topbar.classList.toggle('expand');
                content.classList.toggle('expand');
            } else {
                sidebar.classList.toggle('show');
            }
        };
    }

    document.querySelectorAll('.logout-btn').forEach((logoutBtn) => {
        logoutBtn.addEventListener('click', function (e) {
            e.preventDefault();
            logoutUser();
        });
    });
}

async function apiRequest(url, options = {}) {
    if (window.location.protocol === 'file:') {
        throw new Error('Open the app through the PHP-enabled web server hosting it, not as a local file.');
    }

    const fullUrl = url.startsWith('http') || url.startsWith('/') ? url : apiUrl(url);
    const res = await fetch(fullUrl, {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
        ...options,
    });

    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        throw new Error('Server did not return valid JSON. Ensure this server executes PHP and that the api folder is deployed alongside the app.');
    }

    if (res.status === 401) {
        sessionStorage.removeItem('rentsys_auth');
        sessionStorage.removeItem('rentsys_user');
        window.location.href = getAppBase() + 'login.html';
        throw new Error(data.message || 'Please log in again.');
    }

    if (!res.ok && !data.message) {
        throw new Error('Request failed');
    }

    const method = (options.method || 'GET').toUpperCase();
    if (res.ok && data.success !== false && method !== 'GET' && method !== 'OPTIONS') {
        notifyDataChange(entityFromApiUrl(url));
    }

    return data;
}

function entityFromApiUrl(url) {
    const match = String(url).match(/api\/([a-z_]+)\.php/i);
    if (!match) return 'all';
    const module = match[1];
    const dashboardEntities = {
        payments: 'payments',
        rent_deposits: 'rent_deposits',
        tenants: 'tenants',
        rooms: 'rooms',
        leases: 'leases',
        maintenance: 'maintenance',
        apartments: 'apartments',
    };
    return dashboardEntities[module] || 'all';
}

async function syncSession() {
    try {
        const data = await apiRequest('api/me.php');
        if (data.success && data.user) {
            sessionStorage.setItem('rentsys_auth', '1');
            sessionStorage.setItem('rentsys_user', JSON.stringify(data.user));
            loadUser();
            applyAccessControl();
        }
    } catch (e) {}
}

function formatMoney(amount) {
    return 'KES ' + Number(amount || 0).toLocaleString();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}

function openModal(id) {
    document.getElementById(id).classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function showAlert(el, message, type) {
    el.textContent = message;
    el.className = 'alert show ' + type;
}

function hideAlert(el) {
    el.className = 'alert';
    el.textContent = '';
}

function getTodayDate() {
    // Date inputs use the visitor's calendar date, not UTC (which can be a day behind in East Africa).
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function initDateInput(input, options = {}) {
    if (!input) return;
    const maxToday = options.maxToday !== false;
    if (maxToday) {
        input.setAttribute('max', getTodayDate());
    }
    if (options.defaultToday && !input.value) {
        input.value = getTodayDate();
    }
}

function validateNotFutureDate(value, label) {
    if (!value) return null;
    const today = getTodayDate();
    if (value > today) {
        return (label || 'Date') + ' cannot be in the future.';
    }
    return null;
}

function validateDateRange(startVal, endVal, startLabel, endLabel) {
    if (!startVal || !endVal) return null;
    if (endVal < startVal) {
        return (endLabel || 'End date') + ' must be on or after ' + (startLabel || 'start date').toLowerCase() + '.';
    }
    return null;
}

function notifyDataChange(entity) {
    const detail = { entity: entity || 'all' };
    window.dispatchEvent(new CustomEvent('rentsys:data-changed', { detail }));
    // The storage event reaches other open application tabs, so their dashboards
    // refresh as soon as a record is saved instead of waiting for polling.
    try {
        localStorage.setItem('rentsys_data_changed', JSON.stringify({ ...detail, at: Date.now() }));
    } catch (e) {}
}

function onDataChange(callback) {
    window.addEventListener('rentsys:data-changed', callback);
    const storageHandler = (event) => {
        if (event.key === 'rentsys_data_changed') callback(event);
    };
    window.addEventListener('storage', storageHandler);
    return () => {
        window.removeEventListener('rentsys:data-changed', callback);
        window.removeEventListener('storage', storageHandler);
    };
}

let dashboardRefreshTimer = null;

function startDashboardAutoRefresh(loadFn, intervalMs) {
    if (typeof loadFn !== 'function') return;
    if (dashboardRefreshTimer) clearInterval(dashboardRefreshTimer);
    onDataChange(() => loadFn());
    dashboardRefreshTimer = setInterval(loadFn, intervalMs || 30000);
}

function formatRelativeTime(timestamp) {
    if (!timestamp) return '';
    const diff = Date.now() - new Date(timestamp).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + ' min ago';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + ' hour' + (hrs > 1 ? 's' : '') + ' ago';
    const days = Math.floor(hrs / 24);
    if (days < 7) return days + ' day' + (days > 1 ? 's' : '') + ' ago';
    return new Date(timestamp).toLocaleDateString();
}

function formatMonthLabel(ym) {
    if (!ym) return '';
    const parts = ym.split('-');
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[parseInt(parts[1], 10) - 1] || parts[1];
}

if (ensureHttpServer()) {
    requireAuth();
    loadUser();
    initLayout();
    applyAccessControl();
    syncSession();
}
