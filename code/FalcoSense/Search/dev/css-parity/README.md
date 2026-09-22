# CSS parity harness

Guards the Tailwind → semantic-CSS rewrite. Run it after converting any component.

```bash
./run.sh            # both checks, exits non-zero if parity breaks
./run.sh parity     # computed-style diff: old Tailwind markup vs new semantic CSS
./run.sh base       # isolation layer vs a deliberately hostile host theme
```

Needs Chrome (found automatically on macOS, or set `CHROME=/path/to/chrome`).
Not Playwright — this repo's Node is v14 and Playwright needs 16+.

## What each file is

| File | Purpose |
|---|---|
| `parity.html` | Renders the same component twice — once with the **original Tailwind class lists copied verbatim from the templates**, once with the new `fs-*` classes — then diffs `getComputedStyle` property by property. |
| `base-test.html` | Renders FalcoSense markup inside a stub theme that is hostile on purpose (`button{background:navy;padding:20px}`, `ul{padding-left:44px}`, `*{box-sizing:content-box}`, …) and asserts nothing leaks in, and nothing leaks out. |
| `reference.css` | Tailwind compiled from the module's own templates. The **oracle** — what the UI used to compute to. Build-time only, never shipped. |
| `utility-dictionary.json` | Every utility the templates use → its exact declarations, media query and pseudo-variant. Use this when writing a component's CSS instead of guessing values. |

## Why this exists

Translating utility classes to semantic CSS by eye looks easy and isn't. Two
examples the harness caught on the very first component:

1. **`.fs-card__title` line-height.** The original markup carried both
   `!text-lg` (`line-height:1.75rem !important`) and `leading-normal` (`1.5`).
   The `!important` won, so `leading-normal` never applied and the real value
   was 28px. Translating the classes literally would have produced 27px and
   reflowed every two-line product title. Invisible by eye, obvious to the diff.

2. **`border-2` on the "See Options" button.** `border-2` sets *width* only;
   `border-style: solid` comes from Tailwind's Preflight. `reference.css` is
   built with preflight off, so the reference side computed `border-width: 0`.
   `parity.html` re-adds the preflight border defaults to the reference side —
   without that, the harness reports a false failure.

## Adding a component

1. Look its utilities up in `utility-dictionary.json`.
2. Write the `.fs-*` rule in `view/frontend/web/css/fs-components.css`.
3. Add a row to the `PAIRS` array in `parity.html`: a label, the old selector,
   the new selector, and the properties to compare.
4. `./run.sh parity` — must report `differing: 0`.

## Known limits

- **Default state only.** Screenshots and computed styles here capture the
  resting state. The 92 dynamic `:class` bindings (loading, selected, disabled,
  collapsed) are *not* covered — those need interaction tests or manual checks.
- **Chrome only.** No Safari/Firefox rendering differences are caught.
- **Not a layout test.** It compares computed properties, not visual position.
  A wrong `flex` value can pass here and still look wrong.
