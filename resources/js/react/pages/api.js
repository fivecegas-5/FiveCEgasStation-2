export async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            ...options.headers,
        },
    });

    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json') ? await response.json() : null;

    if (!response.ok) {
        const validationMessages = Object.values(payload?.errors ?? {}).flat();
        const message = validationMessages.length
            ? validationMessages.join(' ')
            : payload?.message ?? `Request failed (HTTP ${response.status}).`;
        throw new Error(message);
    }

    if (!payload) {
        throw new Error('The server returned an unexpected response.');
    }

    return payload;
}
