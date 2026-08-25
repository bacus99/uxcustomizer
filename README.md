# UX Customizer — GLPI 11 Plugin

[![GLPI](https://img.shields.io/badge/GLPI-11.0.x-blue.svg)](https://glpi-project.org/)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.1-777BB4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green.svg)](LICENSE)

Super-administrator **interface customization** for GLPI 11, organised as independently toggleable modules. Reorder the navigation menu, ship a branded color theme, and reorder or hide the tabs on asset pages — all from one config screen, without touching GLPI core.

> Each module can be turned **on or off** independently from the **General** tab.

## Modules

| Module | What it does | Scope | Mechanism |
|--------|--------------|-------|-----------|
| **Menu Order** | Drag-and-drop reorder of the left navigation menu (Assets, Assistance, Management, Tools, Administration, Setup…), plus reordering the items *within* a category (e.g. within Assets: Computer, Monitor, Software…). | Top-level: per **profile**. Sub-menu: **global** | `redefine_menus` hook (official GLPI API) |
| **Color Palette** | Define a custom color theme (primary, accent, page background, sidebar) and offer it — plus a matching **dark** variant — as a **selectable** palette. | Opt-in per **user** | Native GLPI theme (SCSS in the themes directory) |
| **Tab Order** | Reorder **and hide/unhide** the tabs on asset detail pages (Computer, Monitor, Network equipment, Printer, Software…). | **Global** per itemtype | Client-side reorder of the rendered tab bar |
| **Lifecycle** | Set an asset **retention period** (years) per Computer type, with a default. Consumed by the Impact360 plugin's Computer Dashboard to compute a retirement date. | Global | Config (retention key) |

New items always append at the bottom — a freshly installed plugin's menu entry or asset tab never disrupts your saved order.

## Requirements

| Component     | Version            |
|---------------|--------------------|
| GLPI          | 11.0.0 – 11.0.x    |
| PHP           | ≥ 8.1              |
| MySQL/MariaDB | 8.0+ / 10.5+       |

## Installation

1. Download the latest release tarball from the [Releases page](https://github.com/bacus99/uxcustomizer/releases) and extract it into your GLPI `plugins/` directory — the folder **must** be named `uxcustomizer`:
   ```
   <glpi>/plugins/uxcustomizer/
   ```
   (Or clone this repository there directly.)
2. In GLPI, go to **Setup → Plugins**.
3. Find **UX Customizer**, click **Install**, then **Enable**.
4. Open the configuration page (the wrench icon next to the plugin) and set up each module.

## Usage

Open **Setup → Plugins → UX Customizer**. The page has one tab per module:

### General
Enable/disable each module with a switch. Disabling a module instantly removes its effect (e.g. disabling Color Palette removes the generated theme files).

### Menu Order
Pick a **profile**, drag the top-level menu items into the order you want — changes save instantly. Each profile keeps its own order; **Reset to default** restores GLPI's native order for that profile.

Below that, **Sub-menu order** lets you drag the items *within* a category (e.g. within Assets: Computer, Monitor, Software…) — this applies **globally to all users**, regardless of profile. Use **Sort alphabetically** for a one-click A-Z order, or **Reset to default** to restore GLPI's native order for that category.

### Color Palette
Choose the colors (primary, accent, page background, sidebar background/text), name the palette, and **Save**. This writes a GLPI theme; it appears in every user's **My Settings → Color palette** picker (and the **Setup → General** site default). It is **opt-in** — it does **not** override anyone's chosen theme. Tick **"Also generate a matching dark theme"** to publish a dark variant alongside the light one.

### Tab Order
Pick an **asset type** (Computer, Monitor, Network equipment, Peripheral, Phone, Printer, Software, Rack, Enclosure, PDU, Cluster). Drag its tabs into the desired order, and use the **eye icon** to hide a tab from the asset page (hidden tabs stay listed here, greyed out, so you can restore them). The order applies **globally to all standard (central-interface) users** — editing is super-admin only, but the result is what everyone sees. Simplified/self-service (`helpdesk`-interface) profiles are excluded (they don't use these asset forms). **Reset** restores GLPI's default order and shows all tabs again.

### Lifecycle
Set how many years each Computer type is kept before replacement (with a default fallback). The **[Impact360](https://github.com/bacus99/impact360)** plugin's Computer Dashboard reads this — together with the purchase date — to show a retirement date. Editing stays here even though the Dashboard and Impact Map moved to Impact360.
## How it works

- **Menu Order** registers a `redefine_menus` callback. GLPI renders the sidebar from the array this hook returns, so the plugin re-keys it into the saved per-profile top-level order, then re-keys each category's items into the saved global sub-menu order. It never mutates session state directly.
- **Color Palette** writes an SCSS palette file into GLPI's themes directory (`GLPI_THEMES_DIR`). GLPI discovers it automatically and lists it as a selectable theme — there is no plugin API to register a theme in code, so this is the supported, non-intrusive route.
- **Tab Order** — GLPI 11 exposes no server hook to reorder item-form tabs, so a small script loaded on every page detects the asset type and reorders/hides the rendered Bootstrap tab bar (`#tabspanel`) according to the saved settings. It is DOM-based by necessity and degrades gracefully (does nothing if GLPI's markup changes).

## Compatibility & limitations

- Built and tested against **GLPI 11.0.x**. The Tab Order module depends on GLPI's rendered tab markup; a future GLPI release could change it and require a plugin update (the other two modules use stable APIs).
- Tab Order and Color Palette apply to **everyone** (global / opt-in per user respectively); Menu Order's top-level reorder is **per profile**, while its sub-menu reorder is **global**.

## Building a release

```powershell
.\build.ps1                  # uses the version from setup.php
.\build.ps1 -Version 1.0.1   # or override
```

Produces `dist/glpi-uxcustomizer-<VERSION>.tar.bz2`, excluding everything listed in `.glpiignore`. The tarball extracts to a folder named `uxcustomizer/`.

## Publishing to the GLPI plugin catalog

1. Replace the `LICENSE` stub with the full GPL-3.0 text:
   ```powershell
   Invoke-WebRequest https://www.gnu.org/licenses/gpl-3.0.txt -OutFile LICENSE
   ```
2. Push this repository to `github.com/bacus99/uxcustomizer` (must be public).
3. Tag and publish the build:
   ```bash
   git tag -a 3.0.1 -m "Release 3.0.1"
   git push --tags
   gh release create 3.0.1 dist/glpi-uxcustomizer-3.0.1.tar.bz2 \
       --title "3.0.1" --notes-from-tag
   ```
4. Verify every URL in `plugin.xml` resolves (logo, homepage, issues, readme, and the `download_url`).
5. Submit to the [GLPI plugin catalog](https://plugins.glpi-project.org/) by opening a Pull Request to [pluginsGLPI/data](https://github.com/pluginsGLPI/data) adding your `plugin.xml` URL to `xml/plugins.json`.

## Contributing

Issues and pull requests are welcome at [github.com/bacus99/uxcustomizer](https://github.com/bacus99/uxcustomizer/issues). Translations: the template is `locales/uxcustomizer.pot` (target languages `en_GB`, `fr_FR`).

## License

[GPL-3.0-or-later](LICENSE) — © Christian Bernard.

## Author

**Christian Bernard** — [github.com/bacus99](https://github.com/bacus99)
