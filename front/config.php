<?php

/**
 * UX Customizer - configuration page
 *
 * Sectioned config (Bootstrap nav-tabs): General (module toggles),
 * Menu Order, Color Palette. The ?tab= param selects the active section.
 *
 * @license   GPL-3.0-or-later
 */

use GlpiPlugin\Uxcustomizer\ColorPalette;
use GlpiPlugin\Uxcustomizer\Config;
use GlpiPlugin\Uxcustomizer\Lifecycle;
use GlpiPlugin\Uxcustomizer\MenuOrder;
use GlpiPlugin\Uxcustomizer\SubMenuOrder;
use GlpiPlugin\Uxcustomizer\TabOrder;

include('../../../inc/includes.php');

// GLPI 11 loads legacy front/ files in method scope — import globals we use.
global $CFG_GLPI, $DB;

$plugin = new Plugin();
if (!$plugin->isInstalled('uxcustomizer') || !$plugin->isActivated('uxcustomizer')) {
    Html::displayNotFoundError();
}

Session::checkRight('config', UPDATE);

// Handle module-toggle save (posted to this page; CSRF via middleware).
if (isset($_POST['save_modules'])) {
    Config::setModuleEnabled('menuorder', !empty($_POST['module_menuorder_enabled']));

    $paletteOn = !empty($_POST['module_palette_enabled']);
    Config::setModuleEnabled('palette', $paletteOn);
    // Disabling removes the custom theme file (so it leaves the picker);
    // enabling (re)writes it from the saved colors.
    if ($paletteOn) {
        $p = ColorPalette::get();
        ColorPalette::save($p['name'], $p['colors'], $p['dark']);
    } else {
        ColorPalette::removeThemeFile();
    }

    Config::setModuleEnabled('taborder', !empty($_POST['module_taborder_enabled']));

    Session::addMessageAfterRedirect(__('Modules updated.', 'uxcustomizer'), true, INFO);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/uxcustomizer/front/config.php?tab=general');
}

Html::header(
    __('UX Customizer', 'uxcustomizer'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$activeTab       = $_GET['tab'] ?? 'general';
$selectedProfile = (int) ($_GET['profiles_id'] ?? $_SESSION['glpiactiveprofile']['id'] ?? 0);
$rootDoc         = $CFG_GLPI['root_doc'];

// Sub-menu order (global, no profile scoping): categories with live content.
$subCategories = SubMenuOrder::getCurrentCategories();
$selCategory   = (string) ($_GET['category'] ?? '');
if (!in_array($selCategory, $subCategories, true)) {
    $selCategory = $subCategories[0] ?? '';
}

// Build a cache-busted URL for a plugin asset (uses the file's mtime so any
// edit busts the browser cache — these assets are loaded with plain <script>/
// <link> tags that GLPI does NOT version automatically).
$asset = static function (string $rel) use ($rootDoc): string {
    $v = @filemtime(__DIR__ . '/../' . $rel) ?: PLUGIN_UXCUSTOMIZER_VERSION;
    return $rootDoc . '/plugins/uxcustomizer/' . $rel . '?v=' . $v;
};

echo '<link rel="stylesheet" type="text/css" href="' . $asset('public/css/uxcustomizer.css') . '">';
echo '<div class="container-fluid mt-3">';
echo '<h2><i class="ti ti-adjustments me-2"></i>' . __('UX Customizer', 'uxcustomizer') . '</h2>';

// ── Section tabs ─────────────────────────────────────────────────────────
$tabs = [
    'general'       => ['ti-settings',      __('General', 'uxcustomizer')],
    'menuorder'     => ['ti-menu-2',        __('Menu Order', 'uxcustomizer')],
    'palette'       => ['ti-palette',       __('Color Palette', 'uxcustomizer')],
    'taborder'      => ['ti-layout-navbar', __('Tab Order', 'uxcustomizer')],
    'lifecycle'     => ['ti-recycle',       __('Lifecycle', 'uxcustomizer')],
];
echo '<ul class="nav nav-tabs mt-3" role="tablist">';
foreach ($tabs as $key => [$icon, $label]) {
    $active = ($key === $activeTab) ? ' active' : '';
    echo '<li class="nav-item"><a class="nav-link' . $active . '" href="?tab=' . $key . '">'
        . '<i class="ti ' . $icon . ' me-1"></i>' . $label . '</a></li>';
}
echo '</ul>';

echo '<div class="card border-top-0"><div class="card-body">';

// ── General: module toggles ──────────────────────────────────────────────
if ($activeTab === 'general') {
    echo '<form method="post">';
    echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
    echo '<p class="text-muted">' . __('Enable or disable each customization module.', 'uxcustomizer') . '</p>';

    foreach ([
        'menuorder'     => [__('Menu Order', 'uxcustomizer'), __('Reorder the left navigation menu per profile, and the items within each category for everyone.', 'uxcustomizer')],
        'palette'       => [__('Color Palette', 'uxcustomizer'), __('Add a selectable custom color theme.', 'uxcustomizer')],
        'taborder'      => [__('Tab Order', 'uxcustomizer'), __('Reorder the tabs on asset detail pages (Computer, Printer, …).', 'uxcustomizer')],
    ] as $mod => [$label, $desc]) {
        $checked = Config::isModuleEnabled($mod) ? ' checked' : '';
        echo '<label class="form-check form-switch">';
        echo '<input class="form-check-input" type="checkbox" name="module_' . $mod . '_enabled" value="1"' . $checked . '>';
        echo '<span class="form-check-label fw-bold">' . $label . '</span>';
        echo '<div class="text-muted small ms-4">' . $desc . '</div>';
        echo '</label>';
    }

    echo '<button type="submit" name="save_modules" value="1" class="btn btn-primary mt-3">'
        . '<i class="ti ti-device-floppy me-1"></i>' . __('Save') . '</button>';
    echo '</form>';
}

// ── Menu Order ────────────────────────────────────────────────────────────
if ($activeTab === 'menuorder') {
    if (!Config::isModuleEnabled('menuorder')) {
        echo '<div class="alert alert-warning">' . __('The Menu Order module is disabled (see General).', 'uxcustomizer') . '</div>';
    } else {
        $menuKeys = MenuOrder::getCurrentMenuKeys();
        $saved    = MenuOrder::getOrder($selectedProfile);
        $display  = [];
        if ($saved !== null) {
            foreach ($saved as $k) { if (in_array($k, $menuKeys, true)) { $display[] = $k; } }
        }
        foreach ($menuKeys as $k) { if (!in_array($k, $display, true)) { $display[] = $k; } }

        echo '<div class="alert alert-info d-flex align-items-center"><i class="ti ti-info-circle me-2"></i>'
            . __('Drag the handle to reorder the left sidebar for the selected profile. Changes save instantly. New items appear at the bottom.', 'uxcustomizer')
            . '</div>';

        // Profile selector
        echo '<form method="get" class="row g-2 align-items-center mb-3">';
        echo '<input type="hidden" name="tab" value="menuorder">';
        echo '<input type="hidden" name="category" value="' . htmlspecialchars($selCategory, ENT_QUOTES, 'UTF-8') . '">';
        echo '<div class="col-auto"><label class="col-form-label fw-bold" for="profiles_id">' . __('Profile', 'uxcustomizer') . '</label></div>';
        echo '<div class="col-auto"><select id="profiles_id" name="profiles_id" class="form-select" onchange="this.form.submit()">';
        foreach ((new Profile())->find([], ['name ASC']) as $p) {
            $sel = ((int) $p['id'] === $selectedProfile) ? ' selected' : '';
            echo '<option value="' . (int) $p['id'] . '"' . $sel . '>' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><span class="uxc-status" id="uxc-menu-status" aria-live="polite"></span></div>';
        echo '</form>';

        echo '<ul id="uxc-menu-list" class="list-group" data-profile-id="' . $selectedProfile . '">';
        foreach ($display as $key) {
            echo '<li class="list-group-item d-flex align-items-center uxc-menu-item" data-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">';
            echo '<span class="uxc-handle me-2" title="' . __('Drag to reorder', 'uxcustomizer') . '"><i class="ti ti-grip-vertical"></i></span>';
            echo '<span class="fw-semibold">' . htmlspecialchars(MenuOrder::getMenuTitle($key), ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<span class="badge bg-secondary-lt ms-auto font-monospace">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        // Reset
        echo '<form method="post" action="' . $rootDoc . '/plugins/uxcustomizer/ajax/menuorder.php" class="mt-3"'
            . ' onsubmit="return confirm(\'' . htmlspecialchars(__('Reset menu order for this profile?', 'uxcustomizer'), ENT_QUOTES, 'UTF-8') . '\');">';
        echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
        echo '<input type="hidden" name="action" value="reset">';
        echo '<input type="hidden" name="profiles_id" value="' . $selectedProfile . '">';
        echo '<button type="submit" class="btn btn-outline-danger"><i class="ti ti-rotate me-1"></i>' . __('Reset to default', 'uxcustomizer') . '</button>';
        echo '</form>';

        // ── Sub-menu order: items WITHIN a category, GLOBAL for all users ──
        echo '<hr class="my-4">';
        echo '<h4>' . __('Sub-menu order (within a category)', 'uxcustomizer') . '</h4>';
        echo '<div class="alert alert-info d-flex align-items-center"><i class="ti ti-info-circle me-2"></i>'
            . __('Drag to reorder the items WITHIN a top-level category (e.g. within Assets: Computer, Monitor, Software…). This applies to ALL users regardless of profile — unlike the profile-specific order above. New items appear at the bottom.', 'uxcustomizer')
            . '</div>';

        if ($subCategories === []) {
            echo '<div class="alert alert-warning">' . __('No categories with sub-items found in the current menu.', 'uxcustomizer') . '</div>';
        } else {
            // Category selector (preserves the profile selection above)
            echo '<form method="get" class="row g-2 align-items-center mb-3">';
            echo '<input type="hidden" name="tab" value="menuorder">';
            echo '<input type="hidden" name="profiles_id" value="' . $selectedProfile . '">';
            echo '<div class="col-auto"><label class="col-form-label fw-bold" for="uxc-category">' . __('Category', 'uxcustomizer') . '</label></div>';
            echo '<div class="col-auto"><select id="uxc-category" name="category" class="form-select" onchange="this.form.submit()">';
            foreach ($subCategories as $cat) {
                $selAttr = ($cat === $selCategory) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . '"' . $selAttr . '>'
                    . htmlspecialchars(MenuOrder::getMenuTitle($cat), ENT_QUOTES, 'UTF-8') . '</option>';
            }
            echo '</select></div>';
            echo '<div class="col"><span class="uxc-status" id="uxc-submenu-status" aria-live="polite"></span></div>';
            echo '</form>';

            $subItems = SubMenuOrder::getDisplayItems($selCategory);
            if ($subItems === []) {
                echo '<div class="alert alert-warning">' . __('No sub-items found for this category.', 'uxcustomizer') . '</div>';
            } else {
                echo '<ul id="uxc-submenu-list" class="list-group" data-category="' . htmlspecialchars($selCategory, ENT_QUOTES, 'UTF-8') . '">';
                foreach ($subItems as $key => $label) {
                    echo '<li class="list-group-item d-flex align-items-center uxc-submenu-item" data-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">';
                    echo '<span class="uxc-handle me-2" title="' . __('Drag to reorder', 'uxcustomizer') . '"><i class="ti ti-grip-vertical"></i></span>';
                    echo '<span class="fw-semibold">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '<span class="badge bg-secondary-lt ms-auto font-monospace">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</span>';
                    echo '</li>';
                }
                echo '</ul>';

                echo '<div class="d-flex gap-2 mt-3">';
                echo '<button type="button" id="uxc-submenu-sort-az" class="btn btn-outline-secondary">'
                    . '<i class="ti ti-sort-ascending-letters me-1"></i>' . __('Sort alphabetically', 'uxcustomizer') . '</button>';
                echo '<form method="post" action="' . $rootDoc . '/plugins/uxcustomizer/ajax/submenuorder.php"'
                    . ' onsubmit="return confirm(\'' . htmlspecialchars(__('Reset sub-menu order for this category? This affects ALL users.', 'uxcustomizer'), ENT_QUOTES, 'UTF-8') . '\');">';
                echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
                echo '<input type="hidden" name="action" value="reset">';
                echo '<input type="hidden" name="category" value="' . htmlspecialchars($selCategory, ENT_QUOTES, 'UTF-8') . '">';
                echo '<button type="submit" class="btn btn-outline-danger"><i class="ti ti-rotate me-1"></i>' . __('Reset to default', 'uxcustomizer') . '</button>';
                echo '</form>';
                echo '</div>';
            }
        }
    }
}

// ── Color Palette ───────────────────────────────────────────────────────────
if ($activeTab === 'palette') {
    if (!Config::isModuleEnabled('palette')) {
        echo '<div class="alert alert-warning">' . __('The Color Palette module is disabled (see General).', 'uxcustomizer') . '</div>';
    } else {
        $p       = ColorPalette::get();
        $palette = $p['colors'];
        $fields  = [
            'primary'    => __('Primary color (buttons, links, active)', 'uxcustomizer'),
            'accent'     => __('Accent (hover / active highlight)', 'uxcustomizer'),
            'body_bg'    => __('Page background', 'uxcustomizer'),
            'sidebar_bg' => __('Sidebar background', 'uxcustomizer'),
            'sidebar_fg' => __('Sidebar text', 'uxcustomizer'),
        ];

        echo '<div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>'
            . __('This adds a selectable theme to GLPI — it does NOT override anyone\'s choice. After saving, each user picks it under their avatar → My Settings → Color palette. Set the site default in Setup → General.', 'uxcustomizer')
            . '</div>';

        echo '<form id="uxc-palette-form" action="' . $rootDoc . '/plugins/uxcustomizer/ajax/palette.php">';
        echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';

        // Palette name → theme name shown in the picker.
        echo '<div class="row g-2 align-items-center mb-3" style="max-width:420px">';
        echo '<div class="col"><label class="form-label mb-0" for="uxc-palette-name">' . __('Palette name', 'uxcustomizer') . '</label></div>';
        echo '<div class="col-auto"><input type="text" class="form-control" id="uxc-palette-name" name="palette_name" value="'
            . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') . '" maxlength="60"></div>';
        echo '</div>';

        foreach ($fields as $key => $label) {
            $val = htmlspecialchars($palette[$key], ENT_QUOTES, 'UTF-8');
            echo '<div class="row g-2 align-items-center mb-2" style="max-width:420px">';
            echo '<div class="col"><label class="form-label mb-0" for="uxc-' . $key . '">' . $label . '</label></div>';
            echo '<div class="col-auto"><input type="color" class="form-control form-control-color uxc-color" id="uxc-' . $key . '" name="' . $key . '" value="' . $val . '"></div>';
            echo '<div class="col-auto"><code class="uxc-hex">' . $val . '</code></div>';
            echo '</div>';
        }

        // Dark variant toggle.
        $darkChecked = $p['dark'] ? ' checked' : '';
        echo '<label class="form-check form-switch mt-2">';
        echo '<input class="form-check-input" type="checkbox" id="uxc-palette-dark" name="palette_dark" value="1"' . $darkChecked . '>';
        echo '<span class="form-check-label">' . __('Also generate a matching dark theme', 'uxcustomizer') . '</span>';
        echo '</label>';

        echo '<div class="text-muted small mt-2">'
            . sprintf(__('Theme keys: %s and %s (each max 20 chars — GLPI stores the choice in a char(20) column).', 'uxcustomizer'),
                '<code>' . htmlspecialchars(ColorPalette::keyFromName($p['name']), ENT_QUOTES, 'UTF-8') . '</code>',
                '<code>' . htmlspecialchars(ColorPalette::darkKeyFromName($p['name']), ENT_QUOTES, 'UTF-8') . '</code>')
            . '</div>';
        echo '<div class="uxc-status mt-1" id="uxc-palette-status" aria-live="polite"></div>';
        echo '<div class="mt-3 d-flex gap-2">';
        echo '<button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>' . __('Save') . '</button>';
        echo '<button type="button" id="uxc-palette-reset" class="btn btn-outline-danger"><i class="ti ti-rotate me-1"></i>' . __('Reset to default', 'uxcustomizer') . '</button>';
        echo '</div>';
        echo '</form>';
    }
}

// ── Tab Order ───────────────────────────────────────────────────────────────
if ($activeTab === 'taborder') {
    if (!Config::isModuleEnabled('taborder')) {
        echo '<div class="alert alert-warning">' . __('The Tab Order module is disabled (see General).', 'uxcustomizer') . '</div>';
    } else {
        // Selected itemtype (slug). Default to Computer.
        $sel   = strtolower((string) ($_GET['itemtype'] ?? 'computer'));
        $class = TabOrder::resolveItemtype($sel) ?? \Computer::class;

        echo '<div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>'
            . __('Drag to reorder the tabs shown on this asset type\'s detail pages. Applies to all users. New tabs (e.g. from future plugins) appear at the bottom.', 'uxcustomizer')
            . '</div>';

        // Itemtype selector
        echo '<form method="get" class="row g-2 align-items-center mb-3">';
        echo '<input type="hidden" name="tab" value="taborder">';
        echo '<div class="col-auto"><label class="col-form-label fw-bold" for="itemtype">' . __('Asset type', 'uxcustomizer') . '</label></div>';
        echo '<div class="col-auto"><select id="itemtype" name="itemtype" class="form-select" onchange="this.form.submit()">';
        foreach (TabOrder::ITEMTYPES as $slug => $cls) {
            $selAttr = ($slug === $sel) ? ' selected' : '';
            $label   = class_exists($cls) ? $cls::getTypeName(1) : $cls;
            echo '<option value="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '"' . $selAttr . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col"><span class="uxc-status" id="uxc-tab-status" aria-live="polite"></span></div>';
        echo '</form>';

        $tabs   = TabOrder::getDisplayTabs($class);
        $hidden = TabOrder::getSettings($class)['hidden'];
        if ($tabs === []) {
            echo '<div class="alert alert-warning">' . __('No tabs found for this asset type.', 'uxcustomizer') . '</div>';
        } else {
            echo '<p class="text-muted small">'
                . __('Drag to reorder. Use the eye icon to hide a tab from the asset page (hidden tabs stay listed here, greyed out). Reset restores GLPI\'s default order and shows all tabs.', 'uxcustomizer')
                . '</p>';
            echo '<ul id="uxc-tab-list" class="list-group" data-itemtype="' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') . '">';
            foreach ($tabs as $key => $label) {
                $isHidden = in_array($key, $hidden, true);
                $cls      = 'list-group-item d-flex align-items-center uxc-tab-item' . ($isHidden ? ' uxc-tab-hidden' : '');
                echo '<li class="' . $cls . '" data-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">';
                echo '<span class="uxc-handle me-2" title="' . __('Drag to reorder', 'uxcustomizer') . '"><i class="ti ti-grip-vertical"></i></span>';
                echo '<span class="uxc-tab-label fw-semibold">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<span class="badge bg-secondary-lt ms-auto me-2 font-monospace">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</span>';
                // Hide/unhide toggle
                echo '<button type="button" class="btn btn-sm btn-ghost-secondary uxc-tab-eye p-1"'
                    . ' title="' . __('Hide / show this tab', 'uxcustomizer') . '"'
                    . ' aria-pressed="' . ($isHidden ? 'true' : 'false') . '">'
                    . '<i class="ti ' . ($isHidden ? 'ti-eye-off' : 'ti-eye') . '"></i></button>';
                echo '</li>';
            }
            echo '</ul>';

            // Reset
            echo '<form method="post" action="' . $rootDoc . '/plugins/uxcustomizer/ajax/taborder.php" class="mt-3"'
                . ' onsubmit="return confirm(\'' . htmlspecialchars(__('Reset tab order for this asset type?', 'uxcustomizer'), ENT_QUOTES, 'UTF-8') . '\');">';
            echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';
            echo '<input type="hidden" name="action" value="reset">';
            echo '<input type="hidden" name="itemtype" value="' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') . '">';
            echo '<button type="submit" class="btn btn-outline-danger"><i class="ti ti-rotate me-1"></i>' . __('Reset to default', 'uxcustomizer') . '</button>';
            echo '</form>';
        }
    }
}

// ── Lifecycle: asset retention policy (years per Computer type) ──────────────
if ($activeTab === 'lifecycle') {
    $map = Lifecycle::getRetentionMap();

    echo '<div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>'
        . __('Set how many years each Computer type is kept before replacement. The Computer Dashboard uses this with the purchase date (Management tab) to show a retirement date. Leave a type blank to use the default.', 'uxcustomizer')
        . '</div>';

    echo '<form method="post" action="' . $rootDoc . '/plugins/uxcustomizer/ajax/lifecycle.php" style="max-width:520px">';
    echo '<input type="hidden" name="_glpi_csrf_token" class="glpi-csrf-token" value="">';

    // Default
    echo '<div class="row g-2 align-items-center mb-2">';
    echo '<div class="col"><label class="col-form-label fw-bold" for="retention_default">' . __('Default retention (years)', 'uxcustomizer') . '</label></div>';
    echo '<div class="col-auto"><input type="number" min="1" max="50" class="form-control" id="retention_default" name="retention_default" value="' . (int) $map['default'] . '" style="width:90px"></div>';
    echo '</div>';

    echo '<hr><div class="text-muted small mb-2">' . __('Per Computer type (optional override)', 'uxcustomizer') . '</div>';
    foreach ((new ComputerType())->find([], ['name ASC']) as $ct) {
        $tid = (int) $ct['id'];
        $val = isset($map[(string) $tid]) ? (int) $map[(string) $tid] : '';
        echo '<div class="row g-2 align-items-center mb-2">';
        echo '<div class="col"><label class="col-form-label" for="ret-' . $tid . '">' . htmlspecialchars($ct['name'], ENT_QUOTES, 'UTF-8') . '</label></div>';
        echo '<div class="col-auto"><input type="number" min="1" max="50" class="form-control" id="ret-' . $tid . '" name="retention[' . $tid . ']" value="' . $val . '" placeholder="' . (int) $map['default'] . '" style="width:90px"></div>';
        echo '</div>';
    }

    echo '<button type="submit" class="btn btn-primary mt-3"><i class="ti ti-device-floppy me-1"></i>' . __('Save') . '</button>';
    echo '</form>';
}

echo '</div></div>'; // card-body / card
echo '</div>';       // container

// Config for JS + i18n
echo '<script>window.UxcConfig = ' . json_encode([
    'menuAjax'    => $rootDoc . '/plugins/uxcustomizer/ajax/menuorder.php',
    'subMenuAjax' => $rootDoc . '/plugins/uxcustomizer/ajax/submenuorder.php',
    'paletteAjax' => $rootDoc . '/plugins/uxcustomizer/ajax/palette.php',
    'tabAjax'     => $rootDoc . '/plugins/uxcustomizer/ajax/taborder.php',
    'i18n'        => [
        'saving' => __('Saving…', 'uxcustomizer'),
        'saved'  => __('Saved', 'uxcustomizer'),
        'failed' => __('Save failed:', 'uxcustomizer'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

// Fill CSRF hidden fields from GLPI's meta tag.
echo '<script>(function(){'
    . 'const t=document.querySelector("meta[property=\'glpi:csrf_token\']")?.getAttribute("content")??"";'
    . 'document.querySelectorAll(".glpi-csrf-token").forEach(el=>{el.value=t;});'
    . '})();</script>';

// SortableJS (menu order) — bundled inside the plugin. No CDN: corporate
// networks block external CDNs, and GLPI 11's /public/lib/sortablejs.js path
// 404s. Loading our own copy is the only reliable option.
echo '<script src="' . $asset('public/js/Sortable.min.js') . '"></script>';
echo '<script src="' . $asset('public/js/menuorder.js') . '"></script>';
echo '<script src="' . $asset('public/js/submenuorder.js') . '"></script>';
echo '<script src="' . $asset('public/js/palette.js') . '"></script>';
echo '<script src="' . $asset('public/js/tabconfig.js') . '"></script>';

Html::footer();
