<?php

/**
 * UX Customizer - GLPI Plugin
 *
 * Super-admin UI customization for GLPI 11, organised as independently
 * toggleable modules:
 *   - Menu Order    : reorder the left navigation menu per profile (redefine_menus).
 *   - Color Palette : add a selectable GLPI theme (SCSS file in GLPI_THEMES_DIR).
 *   - Tab Order     : reorder + hide item-form tabs per itemtype (client-side).
 *
 * @license   GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Uxcustomizer\Config;
use GlpiPlugin\Uxcustomizer\Menu;
use GlpiPlugin\Uxcustomizer\MenuOrder;

define('PLUGIN_UXCUSTOMIZER_VERSION',          '4.0.0');
define('PLUGIN_UXCUSTOMIZER_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_UXCUSTOMIZER_MAX_GLPI_VERSION', '11.99.99');

function plugin_init_uxcustomizer(): void
{
    global $PLUGIN_HOOKS;

    // Literal key, not Hooks::CSRF_COMPLIANT: GLPI 12 removed that
    // constant, and referencing it is a fatal at plugin load (it
    // surfaces as the misleading "function plugin_<slug>_install is
    // missing"). GLPI 11 and 12 both read the string key identically.
    $PLUGIN_HOOKS['csrf_compliant']['uxcustomizer'] = true;

    // Config page (wrench icon on Setup > Plugins). Enforces config UPDATE itself.
    $PLUGIN_HOOKS['config_page']['uxcustomizer'] = 'front/config.php';

    // Navigation entry under Setup → UX Customizer (the 'config' top-level menu).
    // Points at front/config.php via Menu::getMenuContent().
    $PLUGIN_HOOKS['menu_toadd']['uxcustomizer'] = ['config' => Menu::class];

    // Module hooks are registered only when the module is enabled. Reading the
    // config here touches the DB during early boot, so it's wrapped defensively
    // (see ../GLPI-Shared/rules/glpi-conventions.md): the configs table may not
    // exist yet during install, and an uncaught error breaks plugin info load.
    try {
        // Menu Order — reorder the left sidebar via the official redefine_menus hook.
        if (Config::isModuleEnabled('menuorder')) {
            $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['uxcustomizer'] = 'plugin_uxcustomizer_redefine_menus';
        }

        // Color Palette — NO runtime hook. It writes a custom palette SCSS file
        // into GLPI_THEMES_DIR so the palette appears as a SELECTABLE theme in
        // My Settings (it must NOT override the user's chosen theme). All the
        // work happens in the config page / ajax endpoint. See ColorPalette.

        // Tab Order — GLPI 11 has no hook to reorder item-form tabs, so we load
        // a small script on every page; it detects asset form pages and reorders
        // the rendered .nav-tabs to the saved per-itemtype order (client-side).
        if (Config::isModuleEnabled('taborder')) {
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['uxcustomizer'] = ['public/js/tabreorder.js'];
        }

        // NB (v3.0): the Computer Dashboard and the Impact Map moved to the
        // dedicated `impact360` plugin. NB (v4.0): "SQL logins" (added v3.2)
        // moved to the dedicated `mssqlsec` plugin — unrelated to UI
        // customization, same reasoning as the v3.0 split. uxcustomizer now
        // owns menu order, color palette, tab order, and the asset-retention
        // policy (Lifecycle), which impact360's dashboard consumes when this
        // plugin is active.
    } catch (\Throwable $e) {
        // Config unavailable during early boot / install — skip module hooks.
    }
}

function plugin_version_uxcustomizer(): array
{
    return [
        'name'         => 'UX Customizer',
        'version'      => PLUGIN_UXCUSTOMIZER_VERSION,
        'author'       => 'Christian Bernard',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bacus99/uxcustomizer',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_UXCUSTOMIZER_MIN_GLPI_VERSION,
                'max' => PLUGIN_UXCUSTOMIZER_MAX_GLPI_VERSION,
            ],
            'php' => ['min' => '8.1'],
        ],
    ];
}

function plugin_uxcustomizer_check_prerequisites(): bool
{
    return true;
}

function plugin_uxcustomizer_check_config(bool $verbose = false): bool
{
    return true;
}

/**
 * redefine_menus callback (Menu Order module). Receives GLPI's menu array and
 * returns it reordered for the active profile. See MenuOrder::redefineMenus.
 */
function plugin_uxcustomizer_redefine_menus($menu)
{
    if (!is_array($menu) || $menu === []) {
        return $menu;
    }
    return MenuOrder::redefineMenus($menu);
}
