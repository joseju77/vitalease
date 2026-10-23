import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { BASE_URL, PASSWORD } from './config.js';

// Login is measured on its own so it never pollutes the per-operation latency.
export const loginDuration = new Trend('login_duration', true);
export const loginFailures = new Counter('login_failures');

/**
 * Requests are tagged by kind so the summary can report the measured
 * operations ("op") apart from login ("auth") and setup() discovery ("setup").
 */
function requestKind(name) {
    if (name.startsWith('setup.')) {
        return 'setup';
    }

    return name.startsWith('auth.') ? 'auth' : 'op';
}

const INERTIA_HEADERS = {
    Accept: 'text/html, application/xhtml+xml',
    'X-Requested-With': 'XMLHttpRequest',
    'X-Inertia': 'true',
};

/**
 * One authenticated browser-like session: an explicit cookie jar plus the
 * Inertia asset version taken from the first HTML page.
 */
export class Session {
    constructor(email) {
        this.email = email;
        this.jar = new http.CookieJar();
        this.version = '';
    }

    /** Laravel's XSRF-TOKEN cookie is URL-encoded; the header wants it decoded. */
    xsrfToken() {
        const cookies = this.jar.cookiesForURL(`${BASE_URL}/`);

        return cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : '';
    }

    params(name, extraHeaders = {}, expected = [200]) {
        return {
            jar: this.jar,
            redirects: 0,
            tags: { name, kind: requestKind(name) },
            headers: extraHeaders,
            responseCallback: http.expectedStatuses(...expected),
        };
    }

    /** Inertia page visit: the JSON page object ({component, props, url, version}). */
    page(path, name) {
        const headers = { ...INERTIA_HEADERS, 'X-Inertia-Version': this.version };
        let response = http.get(`${BASE_URL}${path}`, this.params(name, headers, [200, 409]));

        if (response.status === 409) {
            // Asset version changed (deploy during the run): re-read it from the
            // plain HTML of the same page and retry once.
            const html = http.get(`${BASE_URL}${path}`, {
                jar: this.jar,
                redirects: 0,
                tags: { name: 'auth.version-refresh', kind: 'auth' },
            });
            this.version = readInertiaVersion(html.body) || this.version;
            response = http.get(
                `${BASE_URL}${path}`,
                this.params(name, { ...INERTIA_HEADERS, 'X-Inertia-Version': this.version }),
            );
        }

        return response;
    }

    /** JSON endpoint (the dashboard fetch calls). */
    json(path, name) {
        return http.get(
            `${BASE_URL}${path}`,
            this.params(name, { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }),
        );
    }

    /**
     * JSON POST with CSRF. `Accept: application/json` keeps validation errors
     * as 422 instead of a redirect, so business rejections are countable.
     * Redirects are not followed: a success is the 302 itself.
     */
    postJson(path, body, name, expected = [302, 422]) {
        return http.post(
            `${BASE_URL}${path}`,
            JSON.stringify(body),
            this.params(
                name,
                {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': this.xsrfToken(),
                },
                expected,
            ),
        );
    }
}

/** Extract the Inertia asset version from a page's embedded JSON. */
export function readInertiaVersion(html) {
    const match = /"version":"([^"]*)"/.exec(String(html));

    return match ? match[1] : '';
}

/**
 * Sign in with the standard Laravel form flow: GET /login (sets the session and
 * XSRF cookies), then POST /login with the token header.
 *
 * @returns {Session | null} null when the credentials were rejected
 */
export function login(email) {
    const session = new Session(email);
    const started = Date.now();

    const page = http.get(`${BASE_URL}/login`, session.params('auth.login-page'));
    session.version = readInertiaVersion(page.body);

    const response = session.postJson('/login', { email, password: PASSWORD }, 'auth.login');

    loginDuration.add(Date.now() - started);

    const ok = check(response, {
        'login redirects after success': (res) =>
            res.status === 302 && !String(res.headers.Location || '').includes('/login'),
    });

    if (!ok) {
        loginFailures.add(1);

        return null;
    }

    return session;
}

const vuSessions = {};

/**
 * The VU's session for an account, created on first use and reused by every
 * later iteration (login once per VU). A failed login is retried next
 * iteration, because the failure is not cached.
 */
export function vuSessionFor(email) {
    if (!vuSessions[email]) {
        const session = login(email);

        if (session === null) {
            return null;
        }

        vuSessions[email] = session;
    }

    return vuSessions[email];
}
