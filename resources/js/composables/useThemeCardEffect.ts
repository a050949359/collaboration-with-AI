import { useCardEffects } from './useCardEffects';
import { useCardEffectsBlob } from './useCardEffectsBlob';
import { useCardEffectsInk } from './useCardEffectsInk';
import { THEME_REGISTRY, useTheme } from './useTheme';

export function useThemeCardEffect() {
    const { theme } = useTheme();
    useCardEffects(`.${THEME_REGISTRY.emerald.cardClass}`, theme);
    useCardEffectsBlob(`.${THEME_REGISTRY.amber.cardClass}`, theme);
    useCardEffectsInk(`.${THEME_REGISTRY['ink-zen'].cardClass}`, theme);
}
