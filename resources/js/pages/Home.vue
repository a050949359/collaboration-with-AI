<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ArticlesSection from '../components/welcome/ArticlesSection.vue';
import HeroSection from '../components/welcome/HeroSection.vue';
import ProjectsSection from '../components/welcome/ProjectsSection.vue';
import StackContactSection from '../components/welcome/StackContactSection.vue';
import AppLayout from '../layouts/AppLayout.vue';

interface ArticlePreview {
    date: string;
    category: string;
    title: string;
    description: string;
    tags: string[];
}

defineProps<{
    latestArticles: ArticlePreview[];
}>();

const { t } = useI18n();

const featuredProjects = [
    {
        id: '01',
        category: 'AI_COLLAB_DEV',
        title: 'AI Co-Build Web Studio',
        description:
            '此網站以 Google Stitch 生成視覺風格、GitHub Copilot 完成前端實作為起點，部署於 VPS + Cloudflare + Nginx，後端採 Laravel + Redis Queue，整合 Vertex AI 與 Gemini API，完整呈現後端工程師與 AI 協作從設計、開發到生產落地的全流程。',
        tags: [
            'Google Stitch',
            'GitHub Copilot',
            'Laravel',
            'Redis',
            'Nginx',
            'Cloudflare',
            'Vertex AI',
            'Gemini API',
        ],
        commits: [
            {
                hash: '252a05d',
                date: '2026-05-26',
                message:
                    'feat(home+bg): canvas matrix rain with arc parallax, add projects 09/10',
                tag: 'HEAD',
            },
            {
                hash: '128957e',
                date: '2026-05-25',
                message:
                    'feat(mcp+tasks): add MCP JSON-RPC endpoint and Task CRUD',
            },
            {
                hash: '79e4cff',
                date: '2026-05-23',
                message:
                    'feat(lab): add Computer Vision page with edge detection (WASM)',
            },
            {
                hash: '1330a7e',
                date: '2026-04-29',
                message: 'ci: 調整 CI/CD workflow，deploy 改為本地 shell 指令',
            },
            {
                hash: '0bb22ad',
                date: '2026-04-30',
                message: 'feat(auth): 完整整合信箱驗證、Google OAuth、全域通知',
            },
            {
                hash: 'd76a837',
                date: '2026-04-30',
                message: 'feat(auth): 整合 Cloudflare Turnstile 機器人驗證',
            },
            {
                hash: '85120cf',
                date: '2026-04-22',
                message: 'feature: articles complete',
            },
            { hash: '1df037d', date: '2026-04-21', message: 'init' },
        ],
    },
    {
        id: '02',
        category: 'AI_CONTENT',
        title: 'AI Article Studio',
        description:
            'Article 模組支援草稿建立、AI 文章/封面生成、Queue 非同步處理與狀態輪詢，並整合權限控管、速率限制、標籤回填；圖片寫入失敗時可自動 fallback 到 /tmp 避免流程中斷。',
        tags: ['Laravel', 'Inertia', 'Vertex AI', 'Queue'],
        image: '/images/projects/project02.webp',
    },
];

const stackInfo = computed<[string, string][]>(() => [
    [
        t('home.stack.language'),
        'Perl, PHP, Bonfire(CodeIgniter), Laravel, Go(Gin)',
    ],
    [t('home.stack.frontend'), 'jQuery, Vue'],
    [t('home.stack.database'), 'MySQL, MongoDB, InfluxDB, Redis'],
    [t('home.stack.os'), 'Linux'],
    [t('home.stack.visualization'), 'Grafana'],
    [t('home.stack.container'), 'Docker'],
]);
</script>

<template>
    <Head title="Home" />

    <AppLayout>
        <main class="nav-pt">
            <HeroSection />
            <ProjectsSection :featured-projects="featuredProjects" />
            <ArticlesSection :articles="latestArticles" />
            <StackContactSection :stack-info="stackInfo" />
        </main>
    </AppLayout>
</template>
