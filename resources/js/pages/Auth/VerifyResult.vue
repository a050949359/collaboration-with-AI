<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';

// 直接從 query string 取得 status
const status = new URLSearchParams(window.location.search).get('status') ?? undefined;

const statusMap = {
  success: {
    title: '驗證成功',
    message: '您的信箱已成功驗證，已自動登出，請重新登入！',
    color: 'var(--binary-primary)',
    shadow: 'rgba(107,220,159,0.13)',
    action: { text: '前往登入', href: '/app/login' },
  },
  expired: {
    title: '驗證連結已過期',
    message: '此驗證連結已失效，請重新登入後重寄驗證信。',
    color: 'var(--binary-tertiary)',
    shadow: 'rgba(255,179,178,0.13)',
    action: { text: '前往登入', href: '/app/login' },
  },
  already: {
    title: '已驗證過',
    message: '您的信箱已經驗證過，請直接登入。',
    color: 'var(--binary-secondary)',
    shadow: 'rgba(165,209,180,0.13)',
    action: { text: '前往登入', href: '/app/login' },
  },
  error: {
    title: '驗證失敗',
    message: '驗證連結錯誤或已失效，請確認網址或重寄驗證信。',
    color: 'var(--binary-tertiary)',
    shadow: 'rgba(255,179,178,0.13)',
    action: { text: '回首頁', href: '/app' },
  },
};


const info = statusMap[status as keyof typeof statusMap] ?? statusMap.error;

// 驗證成功時自動登出（確保 session 清除）
if (status === 'success') {
  fetch('/api/auth/logout', { method: 'POST', credentials: 'include' });
}
</script>

<template>
  <AppLayout>
    <div class="flex flex-col items-center justify-center min-h-[70vh] bg-[var(--binary-surface)]">
      <div
        class="w-full max-w-xl px-10 py-12"
        :style="`background:rgba(15,21,17,0.92);backdrop-filter:blur(20px);border-radius:2rem;box-shadow:0 8px 32px 0 ${info.shadow};`"
      >
        <h2 class="text-[2rem] font-bold mb-4" :style="`color:${info.color}`">{{ info.title }}</h2>
        <p class="text-[var(--binary-text)] text-lg mb-8 leading-relaxed">{{ info.message }}</p>
        <div class="flex justify-end">
          <a :href="info.action.href"
            class="px-8 py-3 rounded-md text-base font-semibold"
            style="background:linear-gradient(145deg,var(--binary-primary),var(--binary-primary-container));color:var(--binary-on-primary-container);box-shadow:0 2px 8px 0 rgba(107,220,159,0.13);"
          >{{ info.action.text }}</a>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
