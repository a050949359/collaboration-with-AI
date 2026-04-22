type ApiEnvelope<T> = {
    status: 'success' | 'error';
    message?: string;
    data: T;
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export type ArticlePreview = {
    id: number;
    user_id: number;
    title: string | null;
    category: string | null;
    summary: string | null;
    tags: string[];
    image_url: string | null;
    content_status: string;
    image_status: string;
    created_at: string;
    updated_at: string;
};

export type ArticleDetail = ArticlePreview & {
    prompt: string | null;
    content: string | null;
    content_error?: string | null;
    image_error?: string | null;
};

export type PaginatedArticles = {
    items: ArticlePreview[];
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
};

export class ArticleApiError extends Error {
    status: number;

    constructor(message: string, status: number) {
        super(message);
        this.name = 'ArticleApiError';
        this.status = status;
    }
}

function apiBaseUrl() {
    return (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '');
}

function resolveUrl(path: string) {
    if (/^https?:\/\//.test(path)) {
        return path;
    }

    return `${apiBaseUrl()}${path.startsWith('/') ? path : `/${path}`}`;
}

async function parseJson<T>(response: Response): Promise<T> {
    const payload = await response.json();

    if (!response.ok) {
        const message = payload?.message || 'Request failed.';

        throw new ArticleApiError(message, response.status);
    }

    return payload as T;
}

export async function fetchPublicArticles(perPage = 50): Promise<ArticlePreview[]> {
    const response = await fetch(resolveUrl(`/api/v1/articles?per_page=${perPage}`), {
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await parseJson<ApiEnvelope<ArticlePreview[]>>(response);

    return payload.data || [];
}

export async function fetchAuthArticles(scope: 'all' | 'mine', page = 1, perPage = 10): Promise<PaginatedArticles> {
    const response = await fetch(resolveUrl(`/api/articles?scope=${scope}&page=${page}&per_page=${perPage}`), {
        credentials: 'include',
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await parseJson<ApiEnvelope<ArticlePreview[]>>(response);

    return {
        items: payload.data || [],
        currentPage: payload.meta?.current_page || 1,
        lastPage: payload.meta?.last_page || 1,
        total: payload.meta?.total || 0,
        perPage: payload.meta?.per_page || perPage,
    };
}

export async function fetchPublicArticleDetail(articleId: number): Promise<ArticleDetail> {
    const response = await fetch(resolveUrl(`/api/v1/articles/${articleId}`), {
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return payload.data;
}

export async function fetchAuthArticleDetail(articleId: number): Promise<ArticleDetail> {
    const response = await fetch(resolveUrl(`/api/articles/${articleId}`), {
        credentials: 'include',
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return payload.data;
}

export async function createArticle(title?: string): Promise<ArticlePreview> {
    const response = await fetch(resolveUrl('/api/articles'), {
        method: 'POST',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ title: title || null }),
    });

    const payload = await parseJson<ApiEnvelope<ArticlePreview>>(response);

    return payload.data;
}

export async function updateArticle(
    articleId: number,
    data: { title?: string | null; content?: string | null; summary?: string | null; tags?: string[] },
): Promise<ArticleDetail> {
    const response = await fetch(resolveUrl(`/api/articles/${articleId}`), {
        method: 'PUT',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(data),
    });

    const payload = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return payload.data;
}

export async function deleteArticle(articleId: number): Promise<void> {
    const response = await fetch(resolveUrl(`/api/articles/${articleId}`), {
        method: 'DELETE',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    await parseJson<ApiEnvelope<null>>(response);
}

