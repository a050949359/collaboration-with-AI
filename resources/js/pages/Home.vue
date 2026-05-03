<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ArticlesSection from '../components/welcome/ArticlesSection.vue';
import HeroSection from '../components/welcome/HeroSection.vue';
import ProjectsSection from '../components/welcome/ProjectsSection.vue';
import AirportSection from '../components/welcome/AirportSection.vue';
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
            '此網站先用 Google Stitch 生成網頁風格，再由 GitHub Copilot 完成前端實作，最後聚焦後端工程師與 AI 協作開發與除錯流程，展示從設計到落地的完整實戰。',
        tags: ['Google Stitch', 'GitHub Copilot', 'Frontend Build', 'AI Debug Flow'],
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
    [t('home.stack.language'), 'Perl, PHP, Bonfire(CodeIgniter), Laravel, Go(Gin)'],
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
        <main class="pt-24">
            <HeroSection :stack-info="stackInfo" />
            <ProjectsSection :featured-projects="featuredProjects" />
            <ArticlesSection :articles="latestArticles" />
            <AirportSection />
        </main>
    </AppLayout>
</template>
