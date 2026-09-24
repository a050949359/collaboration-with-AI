/**
 * 主題色取用工具。
 *
 * canvas / WebGL 吃不了 `var(--binary-primary)`，畫之前得先把 CSS 變數解成實際色值。
 * 這裡集中處理，讓元件不必各自抄一份 canvas 正規化的把戲。
 */

/** 讀 CSS 變數並正規化成 `#rrggbb` 或 `rgba(...)`（借 canvas 的 fillStyle 解析器）。 */
export function themeColor(varName: string, fallback: string): string {
    const raw = getComputedStyle(document.documentElement)
        .getPropertyValue(varName)
        .trim();

    if (!raw) {
        return fallback;
    }

    const ctx = document.createElement('canvas').getContext('2d');

    if (!ctx) {
        return raw;
    }

    ctx.fillStyle = raw;

    return ctx.fillStyle;
}

/**
 * 給色值加上透明度。
 *
 * themeColor 正規化後只會是 `#rrggbb` 或 `rgba(...)` 兩種形式，這裡兩種都接。
 * 不能直接字串相接：色值若本來就帶 alpha，接出來會是壞值。
 */
export function withAlpha(color: string, alpha: number): string {
    if (/^#[0-9a-f]{6}$/i.test(color)) {
        return (
            color +
            Math.round(alpha * 255)
                .toString(16)
                .padStart(2, '0')
        );
    }

    const channels = color.match(/[\d.]+/g);

    return channels && channels.length >= 3
        ? `rgba(${channels[0]},${channels[1]},${channels[2]},${alpha})`
        : color;
}
