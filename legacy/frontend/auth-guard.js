/**
 * =====================================================================================
 * frontend/auth-guard.js — SHARED SESSION GUARD
 * =====================================================================================
 * Every protected page (index.html, admin.html, hr.html, finance.html) includes this
 * script and calls guardPage() before rendering anything else. It is the one place
 * that decides: "is this session valid, and does it belong on THIS page?"
 *
 *   - No session at all              -> redirect to login.html
 *   - Session valid, wrong page      -> redirect to their own landing page
 *   - Session valid, right page      -> resolves with the session object
 *
 * All requests use credentials: 'same-origin' since frontend/ and backend/ are
 * served from the same host — no cross-origin cookie concerns here.
 *
 * IMPORTANT: this file deliberately does NOT declare `const API_BASE` itself — every
 * host page (app.js, admin.html's inline script, etc.) already declares its own
 * top-level `const API_BASE = '../backend'`. Sibling <script> tags on the same page
 * share one global lexical scope, so a second top-level `const API_BASE` here would
 * throw "Identifier 'API_BASE' has already been declared" and break the whole page.
 * As long as this script tag loads before the functions below are actually CALLED
 * (not necessarily before they're defined), the host page's API_BASE will already
 * be assigned by the time guardPage()/fetchSession() run.
 * =====================================================================================
 */


let freshmartCsrfToken = null;
const freshmartNativeFetch = window.fetch.bind(window);
window.fetch = async function(resource, options = {}) {
    const requestOptions = { credentials: 'same-origin', ...options };
    const method = String(requestOptions.method || 'GET').toUpperCase();
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && freshmartCsrfToken) {
        const headers = new Headers(requestOptions.headers || {});
        headers.set('X-CSRF-Token', freshmartCsrfToken);
        requestOptions.headers = headers;
    }
    return freshmartNativeFetch(resource, requestOptions);
};

function landingPageUrl(landingPage) {
    switch (landingPage) {
        case 'admin':    return 'admin.html';
        case 'inventory': return 'inventory.html';
        case 'hr':       return 'hr.html';
        case 'finance':  return 'finance.html';
        case 'employee': return 'employee.html';
        case 'pos':
        default:         return 'index.html';
    }
}

async function fetchSession() {
    try {
        const response = await fetch(API_BASE + '/admin_session.php', { credentials: 'same-origin' });
        const session = await response.json();
        if (session && session.csrf_token) freshmartCsrfToken = session.csrf_token;
        return session;
    } catch (error) {
        return { ok: false, authenticated: false, permissions: [], landing_page: null };
    }
}

/**
 * @param {string[]} requiredPermissions - session must have at least ONE of these
 *   to stay on this page. Pass an empty array to allow any authenticated session.
 * @returns {Promise<object|null>} the session object if access is granted, or null
 *   if guardPage() has already redirected away (caller should stop initializing).
 */
async function guardPage(requiredPermissions = []) {
    const session = await fetchSession();

    if (!session.authenticated) {
        window.location.href = 'login.html';
        return null;
    }

    const permissions = session.permissions || [];
    const hasAccess = requiredPermissions.length === 0
        || requiredPermissions.some(p => permissions.includes(p));

    if (!hasAccess) {
        window.location.href = landingPageUrl(session.landing_page);
        return null;
    }

    return session;
}

async function logoutAndRedirect() {
    try {
        await fetch(API_BASE + '/admin_logout.php', { method: 'POST', credentials: 'same-origin' });
    } catch (error) {
        // Even if the request fails, still send the user back to login —
        // there's nothing more useful to do with a broken logout call here.
    }
    window.location.href = 'login.html';
}
