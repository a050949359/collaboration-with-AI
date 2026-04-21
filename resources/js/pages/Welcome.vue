<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import HeroSection from '../components/welcome/HeroSection.vue';
import ProjectsSection from '../components/welcome/ProjectsSection.vue';
import ArticlesSection from '../components/welcome/ArticlesSection.vue';
import WelcomeAirportSection from '../components/welcome/WelcomeAirportSection.vue';
const featuredProjects = [
    {
        id: '01',
        category: 'ARCHITECTURE',
        title: 'Distributed Event Mesh',
        description:
            '基於 NATS 與 Go 開發的異步事件驅動架構，支援每秒 500k+ 訊息吞吐量，實現跨雲端環境的極低延遲數據同步。',
        tags: ['Go', 'NATS', 'gRPC'],
    },
    {
        id: '02',
        category: 'DATABASE',
        title: 'Sharding Proxy',
        description: '自動化的資料庫分庫分表代理層，解決水平擴展瓶頸。',
        tags: ['Rust', 'PostgreSQL'],
    },
];

const articles = [
    {
        date: '2024.11.14',
        category: 'TECH_LOG',
        title: '深入解析 Go 語言的高併發調度機制與 GC 優化實踐',
        description:
            '探討 GPM 調度模型在處理大規模長連接時的表現，以及如何透過 pprof 進行內存洩漏定位與優化。',
        tags: ['RUNTIME', 'OPTIMIZATION', 'GO'],
    },
    {
        date: '2024.10.28',
        category: 'ARCHITECTURE',
        title: '為什麼在 2024 年你應該考慮將核心邏輯遷移至 Rust',
        description:
            '從內存安全與執行效率的角度出發，分析 Rust 在後端關鍵服務中的落地應用價值。',
        tags: ['RUST', 'SYSTEMS_DESIGN', 'BACKEND'],
    },
    {
        date: '2024.10.05',
        category: 'INFRA',
        title: 'Kubernetes 控制器模式：從原理到自定義 Operator 開發',
        description:
            '理解 Reconcile Loop 的核心邏輯，並分享如何透過 Kubebuilder 開發高效的資源管理工具。',
        tags: ['K8S', 'OPERATOR', 'CLOUD_NATIVE'],
    },
];

const stackInfo = [
    ['Language', 'Go, Rust, TypeScript'],
    ['Database', 'PostgreSQL, Redis'],
    ['Infra', 'K8s, Docker, AWS'],
];
</script>

<template>
    <Head title="Home" />

    <AppLayout :nav-links="[
        { label: 'Projects', href: '#projects' },
        { label: 'Articles', href: '#articles' },
        { label: 'Airports', href: '/airports' },
        { label: 'About', href: '#about' },
    ]">
        <main class="pt-24">
            <HeroSection :stack-info="stackInfo" />
            <ProjectsSection :featured-projects="featuredProjects" />
            <ArticlesSection :articles="articles" />
            <WelcomeAirportSection />
        </main>
    </AppLayout>
</template>
