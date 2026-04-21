# Design System Strategy: The Emerald Terminal

## 1. Overview & Creative North Star
**Creative North Star: "The Sophisticated Architect"**

This design system moves away from the jarring "neon-on-black" aesthetic of hobbyist code editors toward a high-end, editorial workspace for serious engineering. We are blending the precision of a technical terminal with the luxury of a premium publication. 

The system breaks the standard "bootstrap" grid by embracing **Intentional Asymmetry**. We utilize heavy typographic scales and expansive breathing room to create an environment that feels less like a tool and more like a curated experience. By replacing harsh neon with a deep, layered emerald palette, we signal maturity, stability, and intellectual depth.

---

## 2. Colors & Surface Philosophy

The color logic transitions from high-frequency vibration to tonal resonance. The primary emerald (`#6bdc9f`) acts as a precise surgical tool—used sparingly against deep, organic obsidian backgrounds.

### Core Palette
- **Primary (`#6bdc9f`):** Our "Tech Emerald." It retains a digital soul but feels grounded.
- **Surface (`#0f1511`):** A deep, forest-infused black. Never use pure `#000000`.
- **Secondary (`#a5d1b4`):** A muted sage used for supportive UI elements.
- **Tertiary (`#ffb3b2`):** A warm, desaturated coral used for high-contrast accents and "breaking" the green monochromatic flow.

### The "No-Line" Rule
**Explicit Instruction:** Designers are prohibited from using 1px solid borders to define sections. Layout boundaries must be established through **Background Color Shifts** only.
*   *Correct:* A `surface-container-low` sidebar sitting against a `surface` main content area.
*   *Incorrect:* A `#3e4a41` border separating the sidebar.

### The "Glass & Gradient" Rule
To escape the "flat" look, use **Signature Textures**. 
- **CTA Depth:** Buttons should use a linear gradient from `primary` (`#6bdc9f`) to `primary_container` (`#2ca46d`) at a 145° angle.
- **Glassmorphism:** For floating modals or navigation bars, use `surface_container` at 70% opacity with a `20px` backdrop-blur. This allows the underlying emerald "glow" to bleed through, creating an integrated, atmospheric feel.

---

## 3. Typography: Space Grotesk Editorial

We use **Space Grotesk** not as a monospaced font, but as a Swiss-style display face. The key to this system is the extreme contrast between `display-lg` and `label-sm`.

| Level | Size | Weight | Intent |
| :--- | :--- | :--- | :--- |
| **Display-LG** | 3.5rem | Bold | Hero statements; often shifted off-center for asymmetry. |
| **Headline-SM** | 1.5rem | Medium | Section headers; used to anchor the eye. |
| **Body-LG** | 1.0rem | Regular | Primary reading; 1.6x line-height for maximum breathability. |
| **Label-MD** | 0.75rem | Bold/Caps | Technical metadata; tracked out (+5%) for a "blueprint" feel. |

---

## 4. Elevation & Depth: Tonal Layering

Shadows and borders are secondary to the **Layering Principle**. We build "Up" by shifting the darkness of the green-black base.

*   **Stacking Order:** 
    1.  `surface_dim` (The Void - far background)
    2.  `surface` (The Canvas - main working area)
    3.  `surface_container_low` (In-set components like code blocks)
    4.  `surface_container_high` (Raised elements like active cards)

*   **Ambient Shadows:** For floating elements, use a 32px blur with 6% opacity. The shadow color should be `#000000` mixed with 10% `primary` to create a "glow" rather than a "dark spot."
*   **The "Ghost Border" Fallback:** If accessibility requires a border (e.g., in high-glare environments), use `outline_variant` at **15% opacity**. It should be felt, not seen.

---

## 5. Components

### Buttons
- **Primary:** Gradient fill (`primary` to `primary_container`). Border-radius: `md` (0.375rem). Text: `on_primary`.
- **Secondary:** `surface_container_highest` background with a `primary` label. No border.
- **Tertiary:** Transparent background. Underline on hover using a 2px `primary` stroke.

### Input Fields
- Avoid "box" inputs. Use `surface_container_lowest` for the field background. 
- On focus, the background transitions to `surface_container_low` with a subtle `primary` glow on the bottom edge only.

### Cards & Lists
- **The "No-Divider" Rule:** Forbid the use of line dividers between list items. Use 16px of vertical whitespace or a 2% shift in background color on hover to indicate separation.
- **Editorial Cards:** Large padding (32px+) with `title-lg` typography. Use `surface_container` for the card body.

### Chips
- Use `9999px` (full) roundedness. 
- Background: `secondary_container`. Text: `on_secondary_container`. This provides a "muted tech" look that doesn't compete with primary CTAs.

---

## 6. Do's and Don'ts

### Do
- **Do** use large amounts of negative space. If a section feels crowded, double the padding.
- **Do** use `primary_fixed_dim` for icons to ensure they remain "techy" but legible.
- **Do** overlap elements. A floating image or code snippet should partially break the container boundary of the section below it.

### Don't
- **Don't** use 100% white text. Use `on_surface` (`#dee4dd`) to reduce eye strain.
- **Don't** use standard "drop shadows." Use tonal shifts first.
- **Don't** center-align everything. Use left-aligned headlines with right-aligned body copy to create a sophisticated, asymmetrical tension.
- **Don't** use "Alert Red" for everything. Use `tertiary` (`#ffb3b2`) for a softer, more premium warning state.