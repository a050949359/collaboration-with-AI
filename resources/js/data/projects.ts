// 首頁專案資料（共用來源）
// - featured（01，含 commit log）在 Home.vue 定義
// - 以下 gallery（02–11）為一般專案，ProjectsSection 與 ProjectsScrolly 共用

export interface Commit {
    hash: string;
    date: string;
    message: string;
    tag?: string;
}

export interface Project {
    id: string;
    category: string;
    title: string;
    description: string | string[];
    tags: string[];
    status?: string;
    image?: string;
    commits?: Commit[];
    link?: string;
}

export const galleryProjects: Project[] = [
    {
        id: '02',
        category: 'AI_CONTENT',
        title: 'AI Article Studio',
        description:
            'AI 文章工作站：草稿 → 一鍵生成內文＋封面 → 背景佇列非同步處理、前端輪詢進度。整合權限、速率限制、標籤回填，並對封面寫入失敗做容錯。',
        tags: ['Laravel', 'Inertia', 'Vertex AI', 'Queue'],
        image: '/images/projects/project02.webp',
    },
    {
        id: '03',
        category: 'AVIATION_PLATFORM',
        title: 'Global Aviation & Geo Intelligence',
        description: [
            '機場：洲別／類型統計儀表板，加一顆能點國家即時查詢、標大頭針的 D3 互動地球。',
            '航空公司與國家：用 Wikidata 批次匯入，自動補中文名與 ISO 代碼。',
            '城市：即時搜尋候選、非同步寫入，查詢 API 有每分鐘限流。',
        ],
        tags: [
            'Airports',
            'Airlines',
            'Countries',
            'Cities',
            'Wikidata',
            'D3 Globe',
            'Queue',
            'Rate Limit',
        ],
        image: '/images/projects/project03.webp',
    },
    {
        id: '04',
        category: 'LINE_BOT_ASSISTANT',
        title: 'LINE Bot Assistant',
        description: [
            '好友綁定後，在 LINE 一句話即可產生文章（呼叫本站 REST API + Vertex AI）。',
            '串接 Nvidia AI 做 LLM 問答，以背景執行緒處理、完成後主動 Push 回覆。',
            '串接自製金融爬蟲（每日抓 TWSE 評分選股），可在 LINE 查詢台股買進候選。',
        ],
        tags: [
            'Python',
            'Flask',
            'LINE Bot',
            'gRPC',
            'Nvidia AI',
            'TWSE',
            'SQLite',
        ],
        status: 'in_dev',
        image: '/images/projects/project04.webp',
    },
    {
        id: '05',
        category: 'MINI_ORCHESTRATOR',
        title: 'Mini Orchestrator',
        description: [
            '兩台實體機，用 Ansible 自動部署，含憑證自動續期與 SNMP 監控。',
            'Go 寫 Worker、Redis 當佇列，API 走 HTTPS，用 k6 持續壓測驗證穩定性。',
            '在 Laravel 後台一鍵觸發壓測、即時追蹤每次 Run 的狀態。',
        ],
        tags: [
            'Ansible',
            'Go',
            'Python',
            'Redis',
            "Let's Encrypt",
            'SNMP',
            'k6',
            'HTTPS API',
            'Laravel Proxy',
        ],
        image: '/images/projects/project05.webp',
    },
    {
        id: '06',
        category: 'TRAVEL_MANAGEMENT',
        title: 'Tour Playground',
        description: [
            '預載 10 萬旅客、1 千行程假資料，可依角色（付款人／同行人）隨機抽取，模擬真實購票。',
            '訂單用悲觀鎖防超賣，狀態 Reserved → Confirmed，逾時 15 分鐘自動釋放。',
            '背景非同步產出 CSV，前端輪詢進度，完成即可下載。',
        ],
        tags: [
            'Laravel',
            'SQLite',
            'Pessimistic Lock',
            'Queue',
            'CSV Export',
            'Inertia',
        ],
        status: 'online',
        image: '/images/projects/project06.webp',
    },
    {
        id: '07',
        category: 'AI_STORY_RELAY',
        title: '故事接龍 & 角色創造',
        description: [
            '多個 LLM 角色輪流接龍，各有獨立人設、記憶與行動傾向，共推同一個世界。',
            '道具與事件機制讓劇情分岔；每 2 小時自動排程推進，故事持續演化。',
            '玩家自建角色加入世界、與 AI 共寫——規劃中功能。',
        ],
        tags: [
            'Multi-LLM',
            'Gemini',
            'Laravel Queue',
            'Character',
            'World State',
            'Cron',
        ],
        status: 'in_dev',
        image: '/images/projects/project07.webp',
    },
    {
        id: '08',
        category: 'WEBSOCKET_LAB',
        title: 'WebSocket Lab & 抽獎機台',
        description: [
            'Go 寫的多房間 WebSocket server，每房一個獨立 event loop，用 Redis 驗身份。',
            '房主可控制機台、即時廣播給全房；抽卡動畫用 Matter.js 物理引擎跑彈珠。',
            '限制每個 IP 的連線數與訊息頻率，避免單一來源佔滿房間。',
        ],
        tags: [
            'Go',
            'WebSocket',
            'Goroutine',
            'Redis',
            'Matter.js',
            'Vue 3',
            'Cloudflare',
        ],
        status: 'online',
        image: '/images/projects/project08.webp',
    },
    {
        id: '09',
        category: 'WASM_COMPUTER_VISION',
        title: 'WebAssembly + OpenCV',
        description: [
            '把 OpenCV 編成 WebAssembly，在瀏覽器內即時對相機畫面逐幀做邊緣偵測，完全不靠後端。',
            '四種演算法可切換（Canny／Laplacian／Sobel／Scharr），另可疊高斯模糊、反相與原圖。',
            '同場新增手勢辨識（MediaPipe TFLite WASM）：手部關鍵點 + 手勢分類，模型部署中。',
        ],
        tags: [
            'WebAssembly',
            'OpenCV',
            'Canny',
            'Laplacian',
            'Sobel',
            'Camera API',
            'MediaPipe',
            'Vue 3',
        ],
        status: 'online',
        image: '/images/projects/project09.webp',
    },
    {
        id: '10',
        category: 'MCP_TASK_MANAGEMENT',
        title: 'MCP Server & Task Management',
        description: [
            '用 Laravel 自製 MCP Server，提供 Claude Code 能直接呼叫的工具 API，讓 AI 與後端雙向互動。',
            'Task 工具支援狀態（todo／in progress／done）、子項目 checklist、跨專案標籤，AI 可直接建立與追蹤任務。',
            'API Key 用 scope 分權，task 與 memory 兩組工具各自獨立管控。',
        ],
        tags: [
            'MCP',
            'Laravel',
            'Claude Code',
            'Task Management',
            'API Key',
            'Vue 3',
        ],
        status: 'in_dev',
        image: '/images/projects/project10.webp',
    },
    {
        id: '11',
        category: 'KNOWLEDGE_GRAPH',
        title: 'AI Knowledge Graph',
        description: [
            '知識圖譜 MCP Server（Entity／Relation／Observation），讓 AI 跨專案、跨機器共享背景知識。',
            '用 D3 力導向圖呈現節點關係，節點大小代表知識密度；另有分層容器的部署拓樸視圖。',
            '圖譜記「知道什麼」、Task 記「要做什麼」，由 AI 在對話中判斷怎麼連起來。',
        ],
        tags: [
            'MCP',
            'Knowledge Graph',
            'D3.js',
            'Laravel',
            'Claude Code',
            'Vue 3',
        ],
        status: 'in_dev',
        image: '/images/projects/project11.webp',
    },
];
