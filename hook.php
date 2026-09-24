<?php

/**
 * UX Customizer - Install / Uninstall
 *
 * @license   GPL-3.0-or-later
 */

/**
 * Install hook — create tables and seed module defaults.
 *
 * Tables:
 *   glpi_plugin_uxcustomizer_configs        key/value store (module toggles, palette JSON)
 *   glpi_plugin_uxcustomizer_menuorders     per-profile top-level menu order (JSON array of keys)
 *   glpi_plugin_uxcustomizer_submenuorders  global per-category sub-item order (JSON array of keys)
 *
 * Schema deltas in the upgrade path must each be gated by $DB->fieldExists()/
 * indexExists() (see ../GLPI-Shared/rules/glpi-migration.md). Raw DDL uses
 * doQueryOrDie() (query()/queryOrDie() are blocked in GLPI 11).
 */
function plugin_uxcustomizer_install(): bool
{
    global $DB;

    // ── Config key/value store ───────────────────────────────────────────
    $configs = 'glpi_plugin_uxcustomizer_configs';
    if (!$DB->tableExists($configs)) {
        $DB->doQueryOrDie("CREATE TABLE `$configs` (
            `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `key`   VARCHAR(191) NOT NULL,
            `value` LONGTEXT     NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC", 'UX Customizer: create configs table');

        // Module toggles — all shipped modules on by default.
        $DB->insert($configs, ['key' => 'module_menuorder_enabled', 'value' => '1']);
        $DB->insert($configs, ['key' => 'module_palette_enabled',   'value' => '1']);
        $DB->insert($configs, ['key' => 'module_taborder_enabled',  'value' => '1']);
        // (v3.0) dashboard + impactmap modules moved to the impact360 plugin.
        // (v4.0) the sqlprincipals module (SQL logins) moved to the mssqlsec
        // plugin — its config key, table, and right are no longer created
        // here; see that plugin's own hook.php.
        // No palette row is seeded: ColorPalette::get() returns sensible defaults
        // until the admin saves one (which writes the SCSS theme file).
    }

    // ── Per-profile menu order ───────────────────────────────────────────
    $menuorders = 'glpi_plugin_uxcustomizer_menuorders';
    if (!$DB->tableExists($menuorders)) {
        $DB->doQueryOrDie("CREATE TABLE `$menuorders` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profiles_id`   INT UNSIGNED NOT NULL DEFAULT 0,
            `menu_order`    LONGTEXT     NOT NULL COMMENT 'JSON array of top-level menu keys',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_profile` (`profiles_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC", 'UX Customizer: create menuorders table');

        // ── Migration from the legacy `taborder` plugin ──────────────────
        // UX Customizer absorbs the old single-purpose `taborder` plugin. If
        // its table is present (its menu orders were configured before this
        // install), copy those rows so the admin's saved per-profile order
        // carries over. Only runs on fresh install (new table just created).
        if ($DB->tableExists('glpi_plugin_taborder_order')) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_taborder_order']) as $row) {
                $DB->insert($menuorders, [
                    'profiles_id' => (int) $row['profiles_id'],
                    'menu_order'  => $row['menu_order'],
                ]);
            }
        }
    }

    // ── Sub-menu order (global, one row per top-level menu category) ─────
    $submenuorders = 'glpi_plugin_uxcustomizer_submenuorders';
    if (!$DB->tableExists($submenuorders)) {
        $DB->doQueryOrDie("CREATE TABLE `$submenuorders` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category`      VARCHAR(100) NOT NULL COMMENT 'Top-level menu key (assets, management, ...)',
            `sub_order`     LONGTEXT     NOT NULL COMMENT 'JSON array of submenu item keys (itemtype/class names)',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC", 'UX Customizer: create submenuorders table');
    }

    // ── Item-form tab order (global, one row per itemtype) ───────────────
    $taborders = 'glpi_plugin_uxcustomizer_taborders';
    if (!$DB->tableExists($taborders)) {
        $DB->doQueryOrDie("CREATE TABLE `$taborders` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `itemtype`      VARCHAR(100) NOT NULL,
            `tab_order`     LONGTEXT     NOT NULL COMMENT 'JSON array of tab keys (e.g. Computer\$main)',
            `hidden_tabs`   LONGTEXT     NULL     COMMENT 'JSON array of hidden tab keys',
            `date_creation` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_itemtype` (`itemtype`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC", 'UX Customizer: create taborders table');
    } elseif (!$DB->fieldExists($taborders, 'hidden_tabs')) {
        // Upgrade: add the hide/unhide column to an existing install.
        $DB->doQueryOrDie("ALTER TABLE `$taborders`
            ADD `hidden_tabs` LONGTEXT NULL COMMENT 'JSON array of hidden tab keys' AFTER `tab_order`",
            'UX Customizer: add hidden_tabs column');
    }

    return true;
}

/**
 * Uninstall hook — drop all plugin tables.
 */
function plugin_uxcustomizer_uninstall(): bool
{
    global $DB;

    // Remove the generated palette theme file from GLPI_THEMES_DIR (if any),
    // before dropping the config that records its key.
    try {
        \GlpiPlugin\Uxcustomizer\ColorPalette::removeThemeFile();
    } catch (\Throwable $e) {
        // Best-effort cleanup; ignore.
    }

    foreach ([
        // Orphaned from the pre-4.0 "SQL logins" module, now the mssqlsec
        // plugin — dropped here too (IF EXISTS, so harmless either way) as a
        // safety net for any install that still has it lying around.
        'glpi_plugin_uxcustomizer_sqlprincipals',
        'glpi_plugin_uxcustomizer_submenuorders',
        'glpi_plugin_uxcustomizer_taborders',
        'glpi_plugin_uxcustomizer_menuorders',
        'glpi_plugin_uxcustomizer_configs',
    ] as $table) {
        $DB->doQueryOrDie("DROP TABLE IF EXISTS `$table`", "UX Customizer: drop $table");
    }

    return true;
}
