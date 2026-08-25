<?php

/**
 * UX Customizer - Sub-Menu Order (part of the Menu Order module)
 *
 * Reorders the items WITHIN a top-level sidebar category (e.g. within
 * "assets": Computer, Monitor, Software, …). Unlike MenuOrder's per-profile
 * top-level reorder, this ordering is GLOBAL — one saved order per category,
 * applied to every user regardless of profile (same storage model as
 * TabOrder). See MenuOrder::redefineMenus() for how the two passes combine.
 *
 * Category and sub-item keys are discovered dynamically from
 * $_SESSION['glpimenu'][$category]['content'], which GLPI's
 * generateMenuSession() already populates on every page load — no hardcoded
 * itemtype list is needed here (unlike TabOrder::ITEMTYPES, which exists
 * only because enumerating tabs requires actively calling defineAllTabs()).
 *
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Uxcustomizer;

class SubMenuOrder
{
    private const TABLE = 'glpi_plugin_uxcustomizer_submenuorders';

    /** Saved sub-item key order for a category, or null if none saved. */
    public static function getOrder(string $category): ?array
    {
        global $DB;
        $it = $DB->request([
            'SELECT' => ['sub_order'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['category' => $category],
            'LIMIT'  => 1,
        ]);
        if (count($it) === 0) {
            return null;
        }
        $decoded = json_decode((string) $it->current()['sub_order'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Upsert the saved order for a category (global — no profile scoping). */
    public static function saveOrder(string $category, array $order): bool
    {
        global $DB;
        $payload = ['sub_order' => json_encode(array_values($order), JSON_UNESCAPED_UNICODE)];

        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['category' => $category],
            'LIMIT'  => 1,
        ]);
        if (count($exists) > 0) {
            return (bool) $DB->update(self::TABLE, $payload, ['category' => $category]);
        }
        $payload['category'] = $category;
        return (bool) $DB->insert(self::TABLE, $payload);
    }

    /** Delete the saved order for a category (reset to GLPI default). */
    public static function resetOrder(string $category): bool
    {
        global $DB;
        return (bool) $DB->delete(self::TABLE, ['category' => $category]);
    }

    /**
     * Top-level category keys that currently have non-empty sub-items in the
     * live session menu, for the config UI's category selector and for
     * whitelisting submitted values.
     *
     * @return string[]
     */
    public static function getCurrentCategories(): array
    {
        $menu = $_SESSION['glpimenu'] ?? [];
        if (!is_array($menu)) {
            return [];
        }
        $categories = [];
        foreach ($menu as $key => $entry) {
            if (is_array($entry) && is_array($entry['content'] ?? null) && $entry['content'] !== []) {
                $categories[] = (string) $key;
            }
        }
        return $categories;
    }

    /**
     * A category's sub-item keys mapped to human-readable labels, in GLPI's
     * native order. Reads $_SESSION['glpimenu'][$category]['content']
     * directly (populated on every page load by GLPI's generateMenuSession).
     *
     * @return array<string,string>
     */
    public static function getSubItems(string $category): array
    {
        $content = $_SESSION['glpimenu'][$category]['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }
        $items = [];
        foreach ($content as $key => $entry) {
            $items[(string) $key] = self::getSubItemLabel((string) $key, $entry);
        }
        return $items;
    }

    /** Human-readable label for one sub-item key/content-entry pair. */
    private static function getSubItemLabel(string $key, $entry): string
    {
        if (is_array($entry) && is_string($entry['title'] ?? null) && $entry['title'] !== '') {
            return $entry['title'];
        }
        if (class_exists($key) && is_callable([$key, 'getTypeName'])) {
            $name = $key::getTypeName(1);
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Display order for the config UI: saved keys first (that still exist),
     * then any new/unsaved keys appended in GLPI's native relative order.
     *
     * @return array<string,string>
     */
    public static function getDisplayItems(string $category): array
    {
        $items = self::getSubItems($category);
        $saved = self::getOrder($category);
        if ($saved === null) {
            return $items;
        }
        $ordered = [];
        foreach ($saved as $key) {
            if (array_key_exists($key, $items)) {
                $ordered[$key] = $items[$key];
            }
        }
        foreach ($items as $key => $label) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $label;
            }
        }
        return $ordered;
    }

    /**
     * Pass 2 of the redefine_menus hook (called from MenuOrder::redefineMenus):
     * reorder each category's 'content' to its saved global sub-order. Runs
     * unconditionally over every category present in $menu — no profile
     * gating, since this ordering applies to everyone. Categories with no
     * 'content' or no saved order are left untouched.
     */
    public static function redefineSubMenus(array $menu): array
    {
        foreach ($menu as $category => $entry) {
            if (!is_array($entry) || !is_array($entry['content'] ?? null) || $entry['content'] === []) {
                continue;
            }
            $saved = self::getOrder((string) $category);
            if ($saved === null) {
                continue;
            }
            $content   = $entry['content'];
            $reordered = [];
            foreach ($saved as $key) {
                if (is_string($key) && array_key_exists($key, $content)) {
                    $reordered[$key] = $content[$key];
                }
            }
            foreach ($content as $key => $value) {
                if (!array_key_exists($key, $reordered)) {
                    $reordered[$key] = $value;
                }
            }
            $menu[$category]['content'] = $reordered;
        }
        return $menu;
    }
}
