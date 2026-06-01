<script setup lang="ts">
import { onMounted, ref } from 'vue';

const props = defineProps({
    siteKey: {
        type: String,
        default: () => import.meta.env.VITE_TURNSTILE_SITE_KEY,
    },
});

// 定義 v-model 綁定事件
const emit = defineEmits(['update:modelValue']);
const turnstileBox = ref(null);
const widgetId = ref(null);

onMounted(() => {
    // 確保 Turnstile script 只載入一次
    if (!window.turnstile) {
        const script = document.createElement('script');
        script.src =
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);

        script.onload = () => renderWidget();
    } else {
        renderWidget();
    }
});

const renderWidget = () => {
    widgetId.value = window.turnstile.render(turnstileBox.value, {
        sitekey: props.siteKey,
        theme: 'auto', // 支援 'light', 'dark', 'auto'
        size: 'normal',
        callback: (token) => {
            // 驗證成功，將 token 傳回父元件
            emit('update:modelValue', token);
        },
        'error-callback': () => {
            // 驗證失敗，清空 token
            emit('update:modelValue', null);
        },
        'expired-callback': () => {
            // 驗證過期，清空 token 要求重做
            emit('update:modelValue', null);
        },
    });
};
</script>

<template>
    <!-- Turnstile 會渲染在這個 div 裡面 -->
    <div ref="turnstileBox"></div>
</template>
