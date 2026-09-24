# CLAUDE.md — UX Customizer (GLPI 11)

## Shared conventions

Conventions for all of my GLPI 11 plugins live in [`../GLPI-Shared/`](../GLPI-Shared/CLAUDE.md). **Read those rules first** for any task: versioning, namespacing, hooks, DB API, validation workflow, migrations, AJAX endpoints, build/release. This file only covers what's specific to *this* plugin.

## Project scope

UX Customizer is the **super-admin interface customization** plugin for GLPI 11, organized as four independently toggleable modules:

1. **Menu Order** — drag-and-drop reorder of the left navigation menu **per GLPI profile** (top-level categories), plus a **global** reorder of the items *within* a category (e.g. within Assets: Computer, Monitor, …) that applies to every user regardless of profile. Both use the official `redefine_menus` hook.
2. **Color Palette** — custom color theme (+ optional dark variant) offered as a *selectable* palette in users' My Settings (opt-in, never overrides a chosen theme). Written as native GLPI SCSS theme files.
3. **Tab Order** — reorder **and hide** tabs on asset detail pages, globally per itemtype. Client-side DOM reorder (`tabreorder.js`) — GLPI 11 has no server-side hook for form tabs.
4. **Lifecycle** — asset retention periods (years) per Computer type + default. **Consumed by the sibling `impact360` plugin** (retirement dates on its Computer Dashboard).

History: v3.0 split the Impact Map and Computer Dashboard out into [`../impact360`](../impact360/CLAUDE.md). v3.2 briefly added a "SQL logins" module (SQL Server login/role visibility on `DatabaseInstance`); v4.0 extracted it into its own plugin, [`../mssqlsec`](../mssqlsec/CLAUDE.md) — that data was unrelated to UI customization, the same reasoning as the v3.0 split. If you're looking for that feature, it now lives there (including the full design/debugging history in its `sqlprincipals-handoff.md` and `CHANGELOG.md`).

## Architecture

```
uxcustomizer/
├── setup.php                 version (4.x), conditional module hooks
├── hook.php                  install/uninstall — 3 tables, legacy taborder migration
├── src/                      PSR-4: GlpiPlugin\Uxcustomizer\
│   ├── Config.php            key/value store + module toggles
│   ├── ColorPalette.php      SCSS theme generation → GLPI_THEMES_DIR
│   ├── Lifecycle.php         retention policy (read by impact360)
│   ├── Menu.php              Setup menu entry
│   ├── MenuOrder.php         per-profile top-level menu reordering
│   ├── SubMenuOrder.php      global per-category sub-item reordering
│   └── TabOrder.php          per-itemtype tab order + hidden tabs
├── ajax/                     menuorder.php, submenuorder.php, palette.php, taborder.php, lifecycle.php
├── front/config.php          tabbed config UI (General / Menu / Palette / Tabs / Lifecycle)
├── public/js/                menuorder.js, submenuorder.js, palette.js, tabconfig.js, tabreorder.js, Sortable.min.js
├── public/css/uxcustomizer.css
├── build.ps1                 release tarball script (version from setup.php)
└── .glpiignore               build exclusions
```

Tables: `glpi_plugin_uxcustomizer_configs` (key/value: module toggles, palette JSON, retention JSON), `_menuorders` (per-profile JSON top-level order), `_submenuorders` (global per-category JSON sub-item order), `_taborders` (per-itemtype JSON order + hidden_tabs).

## Points specific to this project

- **Module gating at boot:** conditional hooks in `plugin_init` call `Config::isModuleEnabled()`, which hits the DB — this is wrapped in try/catch because the configs table may not exist yet during install/early boot. Preserve that guard when touching `setup.php`.
- **Palette has no runtime hook** — it writes SCSS files to `GLPI_THEMES_DIR` at config time; GLPI discovers them itself. Disabling the module deletes the theme files.
- **Tab reorder is client-side by design** (no server hook exists in GLPI 11): `tabreorder.js` reorders the rendered tab DOM. `ajax/taborder.php?action=get` is the one **GET** endpoint (read-only, any central user); all writes are POST + `config` UPDATE right.
- **Sub-menu order is deliberately dynamic, not a hardcoded list:** `SubMenuOrder::getCurrentCategories()`/`getSubItems()` read `$_SESSION['glpimenu'][$category]['content']` live (GLPI's `generateMenuSession()` already populates it every page load). This differs from `TabOrder::ITEMTYPES`, which is hardcoded only because enumerating tabs requires actively calling `defineAllTabs()`. Don't "fix" `SubMenuOrder` by copying the `TabOrder::ITEMTYPES` pattern — it would needlessly fight plugins that add their own menu categories/items via `menu_toadd`.
- **Contract with impact360:** `Lifecycle` is read by impact360 via `Plugin::isPluginActive` + `class_exists` guard on their side. Renaming/moving `Lifecycle` or changing the retention JSON shape breaks that consumer — coordinate both repos.
- **Legacy migration:** install copies data from the old `taborder` plugin table (`glpi_plugin_taborder_order`) if present.
- **No local PHP** on the dev machine; `php -l` runs on the GLPI server / CI. Use PowerShell (the Bash tool fails here).
- **v4.0 removed the "SQL logins" module** (`SqlPrincipal`/`Profile`/`SqlPrincipalMenu` classes, `front/sqlprincipal.php`, `tools/`, the `glpi_plugin_uxcustomizer_sqlprincipals` table) — it now lives in the standalone `mssqlsec` plugin. `hook.php`'s uninstall still drops that table `IF EXISTS` as a safety net for installs that still have it; the profile right (`plugin_uxcustomizer_sqlprincipals`) was left for the admin to clean up manually if desired (`ProfileRight::deleteProfileRights()`), since removing rights out from under a running install is a data-affecting action better done deliberately, not silently in an upgrade hook.

## Global rules (reminder)

The non-negotiables from [`../GLPI-Shared/CLAUDE.md`](../GLPI-Shared/CLAUDE.md) apply: GLPI 11 first, read before modify, minimal/reversible changes, preserve behavior, reuse GLPI mechanisms, never trust raw input. 95% validation workflow: [`../GLPI-Shared/rules/glpi-validation.md`](../GLPI-Shared/rules/glpi-validation.md).
