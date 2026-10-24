import { check } from 'k6';

/** Assert a request answered 200 (page or JSON); returns whether it did. */
export function expectOk(response, label) {
    return check(response, { [`${label} answered 200`]: (res) => res.status === 200 });
}
