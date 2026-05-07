/**
 * 集中管理前端路由
 * 所有頁面/API 路徑只在此處定義，各元件透過這些函式取得 URL，
 * 避免硬編碼字串散落於各檔案中。
 */

// ── Web (Inertia) 頁面路由 ───────────────────────────────

const WEB_PREFIX = '/app';

export const routes = {
    home: () => `${WEB_PREFIX}/`,

    // Assets
    assets: {
        avatarDefault: (seed: string) => `${WEB_PREFIX}/avatar/default/${encodeURIComponent(seed)}.svg`,
    },

    // Auth
    login: () => `${WEB_PREFIX}/login`,
    register: () => `${WEB_PREFIX}/register`,

    // Articles
    articles: {
        index: () => `${WEB_PREFIX}/articles`,
        show: (id: number) => `${WEB_PREFIX}/articles/${id}`,
        edit: (id: number) => `${WEB_PREFIX}/articles/${id}/edit`,
        generate: () => `${WEB_PREFIX}/articles/generate`,
    },

    // Airports
    airports: () => `${WEB_PREFIX}/airports`,

    // Airlines
    airlines: () => `${WEB_PREFIX}/airlines`,

    // Countries
    countries: () => `${WEB_PREFIX}/countries`,

    // About
    about: () => `${WEB_PREFIX}/about`,

    // LineBot
    linebot: () => `${WEB_PREFIX}/linebot`,

    // Tour Playground
    tourPlayground: () => `${WEB_PREFIX}/tour-playground`,

    // Admin
    admin: {
        settings: () => `${WEB_PREFIX}/admin/settings`,
    },
} as const;

// ── API 路由 ─────────────────────────────────────────────

export const api = {
    auth: {
        login: () => '/api/auth/login',
        register: () => '/api/auth/register',
        logout: () => '/api/auth/logout',
        me: () => '/api/auth/me',
        googleRedirect: () => '/api/auth/google/redirect',
        resendVerification: () => '/api/auth/email/verification-notification',
    },

    admin: {
        settings: () => '/api/admin/settings',
    },

    articles: {
        index: () => '/api/articles',
        show: (id: number) => `/api/articles/${id}`,
        store: () => '/api/articles',
        update: (id: number) => `/api/articles/${id}`,
        destroy: (id: number) => `/api/articles/${id}`,
        generateContent: (id: number) => `/api/articles/${id}/generate-content`,
        generateImage: (id: number) => `/api/articles/${id}/generate-image`,
    },

    publicArticles: {
        index: () => '/api/v1/articles',
        show: (id: number) => `/api/v1/articles/${id}`,
    },

    about: {
        ask: () => '/api/about/ask',
    },

    airports: {
        index: () => '/api/v1/airports',
        nearby: () => '/api/v1/airports/nearby',
        stats: () => '/api/v1/airports/stats',
    },

    airlines: {
        index: () => '/api/v1/airlines',
    },

    countries: {
        index: () => '/api/v1/countries',
        show:  (code: string) => `/api/v1/countries/${code}`,
    },

    tour: {
        stats: () => '/api/v1/tour/stats',
        passengers: (filter?: string) => `/api/v1/tour/passengers${filter ? `?filter=${filter}` : ''}`,
        tours: (hasVacancy?: boolean) => `/api/v1/tour/tours${hasVacancy ? '?has_vacancy=1' : ''}`,
        storeTour: () => '/api/v1/tour/tours',
        updateTour: (id: number) => `/api/v1/tour/tours/${id}`,
        bookings: () => '/api/v1/tour/bookings',
        storeBooking: () => '/api/v1/tour/bookings',
        exports: () => '/api/v1/tour/exports',
        storeExport: () => '/api/v1/tour/exports',
        exportStatus: (id: number) => `/api/v1/tour/exports/${id}/status`,
        exportDownload: (id: number) => `/api/v1/tour/exports/${id}/download`,
    },
} as const;
