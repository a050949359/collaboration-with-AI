let cachedKey: CryptoKey | null = null;

async function loadPublicKey(): Promise<CryptoKey | null> {
    if (cachedKey) return cachedKey;

    try {
        const res  = await fetch('/api/auth/key');
        const { key } = await res.json();
        if (!key) return null;

        const der = Uint8Array.from(atob(
            key.replace(/-----[^-]+-----/g, '').replace(/\s/g, '')
        ), c => c.charCodeAt(0));

        cachedKey = await crypto.subtle.importKey(
            'spki', der.buffer,
            { name: 'RSA-OAEP', hash: 'SHA-1' },
            false, ['encrypt']
        );
        return cachedKey;
    } catch {
        return null;
    }
}

export async function encryptPassword(plain: string): Promise<string> {
    const key = await loadPublicKey();
    if (!key) throw new Error('無法取得加密金鑰，請重新整理後再試。');

    const buf = await crypto.subtle.encrypt(
        { name: 'RSA-OAEP' },
        key,
        new TextEncoder().encode(plain)
    );
    return btoa(String.fromCharCode(...new Uint8Array(buf)));
}
