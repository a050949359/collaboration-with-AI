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
        avatarDefault: (seed: string) =>
            `${WEB_PREFIX}/avatar/default/${encodeURIComponent(seed)}`,
    },

    // Auth
    forgotPassword: () => `${WEB_PREFIX}/forgot-password`,
    resetPassword: (token: string, email: string) =>
        `${WEB_PREFIX}/reset-password?token=${encodeURIComponent(token)}&email=${encodeURIComponent(email)}`,

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

    // Mini Orch
    miniOrch: () => `${WEB_PREFIX}/mini-orch`,

    // WS Lab
    wsLab: () => `${WEB_PREFIX}/ws-lab`,

    // Gacha
    gacha: () => `${WEB_PREFIX}/gacha`,

    // Task（MCP task UI；MCP server endpoint 見 api.mcp）
    task: () => `${WEB_PREFIX}/task`,

    // Memory Graph
    memory: () => `${WEB_PREFIX}/memory`,

    // Computer Vision Lab（CV 下拉群組）
    computerVision: () => `${WEB_PREFIX}/computer-vision`, // 邊緣偵測
    gesture: () => `${WEB_PREFIX}/gesture`, // 手勢辨識

    // Story Relay
    storyRelay: () => `${WEB_PREFIX}/story-relay`,

    // Admin
    admin: {
        system: () => `${WEB_PREFIX}/admin`,
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
        forgotPassword: () => '/api/auth/forgot-password',
        resetPassword: () => '/api/auth/reset-password',
        changePassword: () => '/api/auth/change-password',
        updateName: () => '/api/auth/name',
    },

    mcp: () => '/api/mcp',
    memory: {
        graph: () => '/api/memory/graph',
        geo: () => '/api/memory/observations/geo',
        typed: (entityId: number) => `/api/memory/entities/${entityId}/typed`,
        observationStore: () => '/api/memory/observations',
        observationUpdate: (id: number) => `/api/memory/observations/${id}`,
        observationDestroy: (id: number) => `/api/memory/observations/${id}`,
    },

    tasks: {
        index: () => '/api/v1/tasks',
        show: (id: number) => `/api/v1/tasks/${id}`,
        store: () => '/api/v1/tasks',
        update: (id: number) => `/api/v1/tasks/${id}`,
        destroy: (id: number) => `/api/v1/tasks/${id}`,
        itemStore: (taskId: number) => `/api/v1/tasks/${taskId}/items`,
        itemUpdate: (taskId: number, itemId: number) =>
            `/api/v1/tasks/${taskId}/items/${itemId}`,
        itemDestroy: (taskId: number, itemId: number) =>
            `/api/v1/tasks/${taskId}/items/${itemId}`,
    },

    userApiKeys: {
        index: () => '/api/v1/user-api-keys',
        store: () => '/api/v1/user-api-keys',
        update: (id: number) => `/api/v1/user-api-keys/${id}`,
        destroy: (id: number) => `/api/v1/user-api-keys/${id}`,
    },

    shareTokens: {
        check: () => '/api/share-tokens/check',
    },

    line: {
        aboutToken: () => '/api/line/about-token',
    },

    admin: {
        settings: () => '/api/admin/settings',
        llmTest: () => '/api/admin/settings/llm/test',
        shareTokens: () => '/api/admin/share-tokens',
        shareTokenDestroy: (id: number) => `/api/admin/share-tokens/${id}`,
        microHostStatus: () => '/api/admin/micro-host/status',
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
        comments: (articleId: number) =>
            `/api/v1/articles/${articleId}/comments`,
    },

    comments: {
        update: (id: number) => `/api/v1/comments/${id}`,
        destroy: (id: number) => `/api/v1/comments/${id}`,
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
        show: (code: string) => `/api/v1/countries/${code}`,
    },

    cities: {
        index: () => '/api/v1/cities',
        preview: () => '/api/v1/cities/preview',
        search: {
            index: () => '/api/v1/cities/search',
            show: (id: number) => `/api/v1/cities/search/${id}`,
        },
    },

    miniOrch: {
        dashboard: () => '/api/mini-orch/dashboard',
        createRun: () => '/api/mini-orch/runs',
        getRun: (runId: string) => `/api/mini-orch/runs/${runId}`,
    },

    wsLab: {
        status: () => '/api/ws-lab/status',
        rooms: () => '/api/ws-lab/rooms',
        authToken: () => '/api/ws-lab/auth-token',
        start: () => '/api/ws-lab/start',
        stop: () => '/api/ws-lab/stop',
        streamStart: () => '/api/ws-lab/stream/start',
        streamStop: () => '/api/ws-lab/stream/stop',
    },

    characters: {
        list: () => '/api/v1/characters',
        create: () => '/api/v1/characters',
        show: (id: number) => `/api/v1/characters/${id}`,
        update: (id: number) => `/api/v1/characters/${id}`,
        destroy: (id: number) => `/api/v1/characters/${id}`,
        aiGenerate: () => '/api/v1/characters/ai/generate',
        aiRefine: () => '/api/v1/characters/ai/refine',
        imagePrompt: (id: number) => `/api/v1/characters/${id}/image-prompt`,
    },

    gacha: {
        rooms: () => '/api/v1/gacha/rooms',
        store: () => '/api/v1/gacha/rooms',
        destroy: (code: string) => `/api/v1/gacha/rooms/${code}`,
        join: (code: string) => `/api/v1/gacha/rooms/${code}/join`,
        draw: (code: string) => `/api/v1/gacha/rooms/${code}/draw`,
        resetDraws: (code: string) => `/api/v1/gacha/rooms/${code}/reset-draws`,
    },

    story: {
        setupGenerate: () => '/api/v1/story/setup/generate',
        setupRefine: () => '/api/v1/story/setup/refine',
        sessions: () => '/api/v1/story/sessions',
        session: (id: number) => `/api/v1/story/sessions/${id}`,
        sessionStatus: (id: number) => `/api/v1/story/sessions/${id}/status`,
        playerTurn: (id: number) => `/api/v1/story/sessions/${id}/player-turn`,
        publicKey: () => '/api/auth/key',
    },

    tour: {
        stats: () => '/api/v1/tour/stats',
        passengers: (filter?: string) =>
            `/api/v1/tour/passengers${filter ? `?filter=${filter}` : ''}`,
        tours: (hasVacancy?: boolean) =>
            `/api/v1/tour/tours${hasVacancy ? '?has_vacancy=1' : ''}`,
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
