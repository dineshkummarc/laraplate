import http from 'k6/http';

export const options = JSON.parse(__ENV.PEST_STRESS_TEST_OPTIONS);

export default () => {

    let userAgent = 'Pest Plugin Stressless (https://pestphp.com) + K6 (https://k6.io)';
    let url = __ENV.PEST_STRESS_TEST_URL;
    let payload = options.payload ? options.payload : {};
    let method = options.method ? options.method : 'get';

    let headers = options.headers ? options.headers : {}

    headers['user-agent'] = userAgent;

    switch (method) {
        case 'delete':
            http.del(url, null, {
                headers,
            });
            break;
        case 'get':
            http.get(url, {
                headers,
            });
            break;
        case 'head':
            http.head(url, {
                headers,
            });
            break;
        case 'options':
            http.options(url, Object.keys(payload).length ? JSON.stringify(payload) : null, {
                headers,
            });
            break;
        case 'patch':
            http.patch(url, Object.keys(payload).length ? JSON.stringify(payload) : null, {
                headers,
            });
            break;
        case 'put':
            http.put(url, Object.keys(payload).length ? JSON.stringify(payload) : null, {
                headers,
            });
            break;
        case 'post':
            http.post(url, JSON.stringify(payload), {
                headers,
            });
            break;
    }
}

export function handleSummary(data) {
    return {
        [__ENV.PEST_STRESS_TEST_SUMMARY_PATH]: JSON.stringify(data),
    };
}
