# Style Guide

## Design principles
- White or near-white canvases, generous negative space.
- Electric blue primary, with blue-to-violet gradients for emphasis.
- Rounded, friendly geometry; large, breathable paddings.
- Crisp typography: tight headlines, relaxed body copy.
- Subtle elevation and rings, not heavy shadows.
- Motion that’s quick and understated.

## Typography scale and usage
- **Display:** `text-display font-semibold tracking-tight text-ink-900`
- **H1:** `text-h1 font-semibold tracking-tight text-ink-900`
- **H2:** `text-h2 font-semibold tracking-tight text-ink-900`
- **H3:** `text-h3 font-semibold text-ink-900`
- **Body:** `text-body text-ink-500`
- **Fine print:** `text-fine text-ink-400`
- **Links:** `text-primary-600 hover:text-primary-700 underline decoration-[1.5px] underline-offset-4`

## Layout and grid
- Page container: `mx-auto max-w-content px-g-6 sm:px-g-8`
- Section rhythm: top/bottom padding `py-g-16` for primary sections, `py-g-12` for secondary.
- Two-column content: `grid grid-cols-1 lg:grid-cols-2 gap-g-8 items-center`
- Logo walls: `grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-g-8 items-center opacity-80`

## Components — class recipes
### Top navigation
- Shell: `sticky top-0 z-40 bg-canvas/80 backdrop-blur supports-[backdrop-filter]:bg-canvas/60 border-b border-ink-100`
- Inner: `mx-auto max-w-content flex items-center justify-between gap-g-6 px-g-6 py-g-3`
- Logo: `h-6`
- Links: `text-fine text-ink-500 hover:text-ink-800 transition-colors duration-fast ease-standard`
- Primary CTA: use “Primary button” below

### Hero (headline + supporting text + CTAs)
- Wrapper: `relative overflow-hidden`
- Background accent: `absolute inset-0 bg-brand-radial`
- Content: `relative mx-auto max-w-content px-g-6 py-g-16 text-center`
- Headline: `mx-auto max-w-prose text-display font-semibold`
- Lead: `mx-auto mt-g-4 max-w-prose text-body text-ink-500`
- CTAs: `mt-g-8 flex flex-col sm:flex-row gap-g-4 justify-center`

### Buttons
- **Primary:** `inline-flex items-center justify-center rounded-pill bg-primary-600 px-g-6 py-g-3 text-white font-medium shadow-card hover:shadow-elevate hover:bg-primary-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-600/40 transition-all duration-base ease-standard`
- **Secondary:** `inline-flex items-center justify-center rounded-pill bg-ink-50 px-g-6 py-g-3 text-ink-800 hover:bg-ink-100 border border-ink-100`
- **Ghost:** `inline-flex items-center justify-center rounded-pill px-g-5 py-g-3 text-ink-700 hover:bg-ink-50`
- **With icon:** add `gap-g-2` to the button and `size-5` to the SVG

### Card (feature, use case, or stat)
- Shell: `group rounded-xl bg-canvas border border-ink-100 p-g-6 shadow-card hover:shadow-elevate transition-shadow`
- Title: `text-h3 font-semibold text-ink-900`
- Body: `mt-g-2 text-body text-ink-500`
- Icon chip (optional): `inline-flex items-center justify-center rounded-lg bg-primary-50 text-primary-700 size-10`

### Stat block
- Value: `text-4xl font-semibold tracking-tight text-ink-900`
- Label: `mt-g-1 text-fine text-ink-400`
- Grouping: `grid grid-cols-2 md:grid-cols-3 gap-g-8`

### Logo wall
- Container: `rounded-xl border border-ink-100 bg-canvas p-g-8 shadow-card`
- Item: `grayscale hover:grayscale-0 transition duration-base ease-standard opacity-80 hover:opacity-100`

### Pricing/plan teaser (simple)
- Panel: `rounded-2xl border border-ink-100 bg-canvas p-g-8 shadow-card`
- Plan name: `text-h3 font-semibold`
- Price: `mt-g-2 text-3xl font-semibold`
- Bullets: `mt-g-4 space-y-g-2 text-body text-ink-500`
- CTA: `mt-g-6` then Primary button

### Input + search box
- Input: `w-full rounded-pill border border-ink-200 bg-canvas px-g-5 py-g-3 text-body text-ink-800 placeholder:text-ink-300 focus:outline-none focus:ring-4 focus:ring-primary-600/20 focus:border-primary-600`
- With leading icon: wrap in relative, add `pl-g-12` to input, place icon `absolute left-g-4 top-1/2 -translate-y-1/2 size-5 text-ink-300`

### Tabs/segmented control
- Wrapper: `inline-flex rounded-pill bg-ink-50 p-g-1 border border-ink-100`
- Tab button: `px-g-4 py-g-2 rounded-pill text-fine font-medium text-ink-600 hover:text-ink-900`
- Active state: `bg-canvas shadow-card text-ink-900`

### Accordions (for “use cases”)
- Item: `rounded-xl border border-ink-100 bg-canvas`
- Header: `flex w-full items-center justify-between p-g-5 text-ink-800 hover:bg-ink-50`
- Panel: `px-g-5 pb-g-5 text-body text-ink-500`

### Badges
- **Neutral:** `inline-flex items-center rounded-pill border border-ink-200 bg-ink-50 px-g-3 py-1 text-micro text-ink-700`
- **Brand:** `inline-flex items-center rounded-pill bg-primary-600/10 text-primary-700 px-g-3 py-1 text-micro`

### Footer
- Shell: `border-t border-ink-100 bg-canvas`
- Inner: `mx-auto max-w-content px-g-6 py-g-12`
- Link columns: `grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-g-8`
- Legal strip: `mt-g-12 flex flex-col md:flex-row items-center justify-between gap-g-4 text-fine text-ink-400`

## Imagery and illustration
- Prefer product UI mockups, isometric or layered device frames, and subtle depth.
- Use soft glows behind screenshots: wrap the image in a relative container with a pseudo glow layer using `bg-brand-radial` for depth.

## Motion guidelines
- All interactive elements: `transition duration-base ease-standard`.
- Hover: raise elevation (shadow-card → shadow-elevate), 4–8 px translate-y on floating badges or screenshots.
- Focus: rely on rings, not outline: `focus-visible:ring-4 focus-visible:ring-primary-600/40`.

## Accessibility and contrast
- Ensure text on primary blue is white at WCAG AA at minimum. Use `text-ink-900` on light backgrounds and reserve `text-ink-400` for secondary copy only.
- Increase hit targets: buttons and pills `min-h-[44px]` wherever feasible.

## Copy tone (microcontent)
- Headlines: assertive, benefits-led.
- Body: clear, outcome-oriented; avoid jargon unless it aids precision.
- CTAs: “Get started”, “Request demo”, “Learn more”.

## Implementation notes
- Use the gradient background sparingly (hero, key callouts). Keep most surfaces white.
- Prefer pills and large radii for CTAs and controls; square corners for tables and dense data.
- Keep max line length to ~70ch for readability in hero/feature sections.
- Maintain consistent section spacing: primary `py-g-16`; do not compress to less than `py-g-12`.
