import { api } from './routes';

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

export async function fetchPublicArticles(
    perPage = 50,
): Promise<ArticlePreview[]> {
    const response = await fetch(
        resolveUrl(`${api.publicArticles.index()}?per_page=${perPage}`),
        {
            headers: {
                Accept: 'application/json',
            },
        },
    );

    const payload = await parseJson<ApiEnvelope<ArticlePreview[]>>(response);

    return payload.data || [];
}

export async function fetchAuthArticles(
    scope: 'all' | 'mine',
    page = 1,
    perPage = 10,
): Promise<PaginatedArticles> {
    const response = await fetch(
        resolveUrl(
            `${api.articles.index()}?scope=${scope}&page=${page}&per_page=${perPage}`,
        ),
        {
            credentials: 'include',
            headers: {
                Accept: 'application/json',
            },
        },
    );

    const payload = await parseJson<ApiEnvelope<ArticlePreview[]>>(response);

    return {
        items: payload.data || [],
        currentPage: payload.meta?.current_page || 1,
        lastPage: payload.meta?.last_page || 1,
        total: payload.meta?.total || 0,
        perPage: payload.meta?.per_page || perPage,
    };
}

export async function fetchPublicArticleDetail(
    articleId: number,
): Promise<ArticleDetail> {
    const response = await fetch(
        resolveUrl(api.publicArticles.show(articleId)),
        {
            headers: {
                Accept: 'application/json',
            },
        },
    );

    const payload = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return payload.data;
}

export async function fetchAuthArticleDetail(
    articleId: number,
): Promise<ArticleDetail> {
    const response = await fetch(resolveUrl(api.articles.show(articleId)), {
        credentials: 'include',
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return payload.data;
}

export async function createArticle(title?: string): Promise<ArticlePreview> {
    const response = await fetch(resolveUrl(api.articles.store()), {
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
    data: {
        title?: string | null;
        content?: string | null;
        summary?: string | null;
        tags?: string[];
    },
): Promise<ArticleDetail> {
    const response = await fetch(resolveUrl(api.articles.update(articleId)), {
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
    const response = await fetch(resolveUrl(api.articles.destroy(articleId)), {
        method: 'DELETE',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    await parseJson<ApiEnvelope<null>>(response);
}

export type CommentUser = {
    id: number;
    name: string;
    avatar: string | null;
};

export type ArticleComment = {
    id: number;
    user_id: number | null;
    guest_name: string | null;
    parent_id: number | null;
    body: string;
    created_at: string;
    user: CommentUser | null;
    children: ArticleComment[];
    can_edit: boolean;
};

export async function fetchComments(
    articleId: number,
): Promise<ArticleComment[]> {
    const response = await fetch(
        resolveUrl(api.publicArticles.comments(articleId)),
        {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        },
    );

    return parseJson<ArticleComment[]>(response);
}

export async function postComment(
    articleId: number,
    data: { body: string; guest_name?: string; parent_id?: number | null },
): Promise<void> {
    const response = await fetch(
        resolveUrl(api.publicArticles.comments(articleId)),
        {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
        },
    );

    await parseJson<{ message: string }>(response);
}

export async function updateComment(
    commentId: number,
    data: { body: string; guest_name?: string },
): Promise<void> {
    const response = await fetch(resolveUrl(api.comments.update(commentId)), {
        method: 'PUT',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(data),
    });

    await parseJson<{ message: string }>(response);
}

export async function deleteComment(commentId: number): Promise<void> {
    const response = await fetch(resolveUrl(api.comments.destroy(commentId)), {
        method: 'DELETE',
        credentials: 'include',
        headers: { Accept: 'application/json' },
    });

    if (response.status !== 204) {
        await parseJson<null>(response);
    }
}

export type GenerateContentPayload = {
    topic: string;
    language: string;
    style: string;
    prompt?: string;
};

export async function triggerGenerateContent(
    articleId: number,
    payload: GenerateContentPayload,
): Promise<ArticleDetail> {
    const response = await fetch(
        resolveUrl(api.articles.generateContent(articleId)),
        {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
        },
    );

    const data = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return data.data;
}

export async function triggerGenerateImage(
    articleId: number,
    aspectRatio: string,
): Promise<ArticleDetail> {
    const response = await fetch(
        resolveUrl(api.articles.generateImage(articleId)),
        {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ aspect_ratio: aspectRatio }),
        },
    );

    const data = await parseJson<ApiEnvelope<ArticleDetail>>(response);

    return data.data;
}
