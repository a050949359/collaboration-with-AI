import { ref } from 'vue';

export const THEME_REGISTRY = {
    emerald: { cardClass: 'js-tilt-card', primaryColor: '#6bdc9f' },
    amber: { cardClass: 'blob-card', primaryColor: '#ffb690' },
    'ink-zen': { cardClass: 'ink-card', primaryColor: '#6b6d6a' },
} satisfies Record<string, { cardClass: string; primaryColor: string }>;

export type Theme = keyof typeof THEME_REGISTRY;

export const themes = Object.keys(THEME_REGISTRY) as Theme[];

const stored = (() => {
    try {
        return localStorage.getItem('theme');
    } catch {
        return null;
    }
})();
const theme = ref<Theme>(
    themes.includes(stored as Theme) ? (stored as Theme) : 'emerald',
);

export function useTheme() {
    function initTheme() {
        document.documentElement.setAttribute('data-theme', theme.value);
    }

    function toggleTheme() {
        theme.value = themes[(themes.indexOf(theme.value) + 1) % themes.length];

        try {
            localStorage.setItem('theme', theme.value);
        } catch {
            // storage blocked — theme still applied in-memory for this session
        }

        document.documentElement.setAttribute('data-theme', theme.value);
    }

    return { theme, initTheme, toggleTheme };
}
