import { ref } from 'vue';

export type Theme = 'emerald' | 'amber';

const stored =
    typeof localStorage !== 'undefined' ? localStorage.getItem('theme') : null;
const theme = ref<Theme>(stored === 'amber' ? 'amber' : 'emerald');

export function useTheme() {
    function initTheme() {
        document.documentElement.setAttribute('data-theme', theme.value);
    }

    function toggleTheme() {
        theme.value = theme.value === 'emerald' ? 'amber' : 'emerald';
        localStorage.setItem('theme', theme.value);
        document.documentElement.setAttribute('data-theme', theme.value);
    }

    return { theme, initTheme, toggleTheme };
}
