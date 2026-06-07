import { Ref } from 'vue';
import { useCardEffects } from './useCardEffects';
import { useCardEffectsBlob } from './useCardEffectsBlob';
import { useCardEffectsInk } from './useCardEffectsInk';
import { THEME_REGISTRY, useTheme } from './useTheme';

export function useThemeCardEffect(containerRef?: Ref<HTMLElement | null>) {
    const { theme } = useTheme();
    useCardEffects(`.${THEME_REGISTRY.emerald.cardClass}`, theme, containerRef);
    useCardEffectsBlob(`.${THEME_REGISTRY.amber.cardClass}`, theme, containerRef);
    useCardEffectsInk(`.${THEME_REGISTRY['ink-zen'].cardClass}`, theme, containerRef);
}
