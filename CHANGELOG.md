# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-09-10

### Removed — split: "SQL logins" extracted to the standalone `mssqlsec` plugin
SQL Server login/server-role visibility (added in 3.2.0, below) is unrelated to
UI customization — the same reasoning that split the Impact Map + Computer
Dashboard out into `impact360` at v3.0. It now lives in its own plugin:
[mssqlsec](https://github.com/bacus99/mssqlsec).

- Removed `src/SqlPrincipal.php`, `src/Profile.php`, `src/SqlPrincipalMenu.php`,
  `src/PluginUxcustomizerSqlPrincipal.php`, `front/sqlprincipal.php`, `tools/`
  (all four PowerShell scripts), and the `sqlprincipals`/`sqlprincipals_enabled`
  module toggle (General tab, `Config`).
- `hook.php` no longer creates `glpi_plugin_uxcustomizer_sqlprincipals` or calls
  `Profile::install()`/`::uninstall()` for this feature. Uninstall still drops
  that table `IF EXISTS` as a safety net for installs that still have it. The
  `plugin_uxcustomizer_sqlprincipals` profile right is intentionally left for
  manual cleanup (`ProfileRight::deleteProfileRights()`) rather than silently
  removed in an upgrade hook.
- No data migration: the table's contents were not carried over to
  `mssqlsec` — re-run the collector against the new plugin's itemtype
  (`PluginMssqlsecSqlPrincipal`) to repopulate it there. See
  `mssqlsec`'s own CHANGELOG for the full history of this feature's design and
  the production issues found and fixed while it lived here (rights, the
  legacy-API flat-class-name requirement, a `Content-Type` bug in the push
  script, search-option id collisions, and more) — all fixed there too.
- `uxcustomizer` is back to four modules: Menu Order, Color Palette, Tab
  Order, Lifecycle.

## [3.2.0] - 2026-09-08

### Added
- **SQL logins** (new module): a "SQL logins" tab on core `DatabaseInstance`
  assets, listing SQL Server principals (name, type, server roles, disabled /
  sysadmin flags, last-collected timestamp) pushed by an external PowerShell
  collector through GLPI's legacy REST API — nothing in the plugin connects to
  SQL Server itself. Current-state snapshot only (one row per instance/login,
  upserted and pruned on each collection run); membership *history* stays in
  the DBA-owned MySQL/Metabase table.
  - Gated by its own dedicated right (`plugin_uxcustomizer_sqlprincipals`),
    **not** `DatabaseInstance` READ — a fleet-wide sysadmin-membership list is
    a recon list. New "UX Customizer" tab on GLPI profiles to grant it.
  - Reachable from the search engine (`front/sqlprincipal.php`) for
    cross-entity questions like "which instances have login X" / "show every
    sysadmin membership in the fleet" — including a direct sidebar entry
    (`SqlPrincipalMenu`, under the 'management' menu category) gated on the same
    dedicated right, not `DatabaseInstance` READ or `config` UPDATE, so a
    reviewer with just that right can reach it without needing super-admin
    access or knowing a specific instance to open first.
  - Login-count / sysadmin-count / last-audited roll-up shown both on the tab
    and directly under the `DatabaseInstance` form itself.
  - Read-only fleet summary + top sysadmin-count table
    (`SqlPrincipal::showFleetSummary()`) at the top of the Management → SQL
    logins page, above the search grid — not a separate Setup tab (see Changed
    below for why that moved). Instances are labelled by their linked host's
    name (falling back to `HOST\INSTANCE` for a named instance), not the bare
    `DatabaseInstance` name — that field is frequently just "MSSQLSERVER"
    (SQL Server's own default-instance service name), which made the table
    unreadable when most rows shared the same label
    (`SqlPrincipal::displayNameForInstance()`).
  - New `tools/Get-GlpiMssqlInstance.ps1` (resolves each GLPI `DatabaseInstance`
    to a SQL Server connect target), `tools/Get-SqlServerRoleMembership.ps1`
    (every login/group on an instance — `sys.server_principals` LEFT JOINed to
    `sys.server_role_members`, so a login with **no** elevated role still shows
    up — and whatever server roles it holds), and
    `tools/Push-GlpiSqlPrincipal.ps1`: batched upsert (POST for new logins, PUT
    for existing ones) plus per-instance stale-row cleanup, matching the
    table's `(databaseinstances_id, name)` unicity key. Scoped per instance so
    an unreachable server never has its previously-collected rows wiped.
  - New `glpi_plugin_uxcustomizer_sqlprincipals` table (with `entities_id` /
    `is_recursive`, inherited from the parent `DatabaseInstance` and never
    client-settable, so entity restriction applies the same way it does for
    every other entity-aware asset).
  - New `tools/Invoke-SqlPrincipalPipeline.ps1`: chains the three scripts above
    into one scheduled-task entry point, writing a single consolidated,
    timestamped log per run (default `tools/Logs/`, auto-pruned past a
    configurable retention) ending in a `SUCCESS`/`FAILURE` line, plus a
    matching process exit code — a script that never sets one makes Task
    Scheduler report success regardless of what actually happened internally,
    which this specifically avoids.
  - New `last_active_date` column: the most recent `sys.dm_exec_sessions`
    `login_time` seen for a login among sessions open at collection time
    (`tools/Get-SqlServerRoleMembership.ps1`, requires `VIEW SERVER STATE`).
    Deliberately **not** a true login history — it's blank for a login that
    connected and disconnected between collection runs — since that would
    need SQL Server Audit or an Extended Events session enabled fleet-wide, a
    separate infrastructure decision. Shown on the per-instance tab and as a
    selectable search-page column ("Last active session").
  - New flat `PluginUxcustomizerSqlPrincipal` alias class (`src/`, loaded via
    an explicit `require_once` inside `plugin_init_uxcustomizer()`'s early-boot
    try/catch — NOT at file scope, which broke plugin-state-check on every
    page, see Fixed below), purely so the legacy REST API can address the
    itemtype — confirmed against production that GLPI's legacy API resolves
    itemtypes with a literal `class_exists()` on the URL segment, with **no**
    PSR-4 namespace translation, so `GlpiPlugin\Uxcustomizer\SqlPrincipal`
    alone 400s with `ERROR_RESOURCE_NOT_FOUND_NOR_COMMONDBTM` on
    `/apirest.php/…`. Every other entry point (tabs, `Search::show()`, massive
    actions) keeps using the real namespaced class unchanged.

### Fixed
- **SQL logins**: `Profile::getAllRights()` only ever exposed/granted **READ**
  for `plugin_uxcustomizer_sqlprincipals` — not even to super-admin. Every
  collector push (POST/PUT/DELETE) 400'd because `CommonDBTM::canCreate()`/
  `canUpdate()`/`canPurge()` are enforced by the REST API regardless of there
  being no add/edit form. Now exposes and grants the full
  READ/CREATE/UPDATE/DELETE/PURGE set, matching every other write-capable
  plugin right in this account. Whatever profile the collector's own API
  account uses still needs it granted explicitly (only super-admin gets it
  automatically at install) — Setup → Profiles → that profile → UX Customizer
  tab.
- A file-scope `require_once` for the flat alias class in `setup.php` broke
  GLPI's plugin-state-check pass (fired on **every** page by a post-boot
  listener, which reads `plugin_version_uxcustomizer()` from `setup.php`
  without necessarily having this plugin's own PSR-4 autoloading active yet) —
  manifested as "Unable to load plugin uxcustomizer information" site-wide.
  Moved inside `plugin_init_uxcustomizer()`'s existing try/catch instead.
- `SqlPrincipal::rawSearchOptions()` appended a duplicate search-option id `1`
  ("Login") on top of the one `CommonDBTM::rawSearchOptions()` already reserves
  for "Name" — logged a "Duplicate key" warning and silently dropped one entry.
  Now relabels the inherited id-1 entry in place instead.
- `tools/Push-GlpiSqlPrincipal.ps1`: `Invoke-GlpiApi` set `Content-Type` via the
  `-Headers` hashtable instead of `Invoke-RestMethod`'s dedicated `-ContentType`
  parameter — invisible on every GET call (no body, so it never mattered) but
  meant GLPI's PHP never reliably received a proper JSON content type on
  POST/PUT, so every create/update 400'd even once rights and the itemtype
  alias were fixed. Confirmed live: an identical payload sent via `curl.exe`
  succeeded while the script's own `Invoke-RestMethod` call still failed.
- The fleet search page's "Database Instance" column showed
  `DatabaseInstance::name` as plain, unclickable text — often just
  "MSSQLSERVER" on every row (see above), and with no link through to
  anything. New "Server" column (search option id 9,
  `SqlPrincipal::getSpecificValueToDisplay()`) shows the resolved host name as
  an actual link to the instance's form; the original "Database Instance"
  column is kept as-is for its filterable dropdown. Also added "Date created"
  (id 8, `date_creation`) as a selectable column.

### Changed
- **SQL logins**: removed the "SQL logins" tab from Setup → UX Customizer.
  It duplicated the fleet summary now shown at the top of Management → SQL
  logins (`SqlPrincipal::showFleetSummary()`, above the search grid) — but
  reaching it required `config` UPDATE, the same super-admin-only
  reachability problem the dedicated right and `SqlPrincipalMenu` exist to
  avoid for everything else in this feature. The General tab's module on/off
  toggle is unaffected.

## [3.1.0] - 2026-08-25

### Added
- **Menu Order**: new **global** sub-menu order — reorder the items *within*
  a top-level sidebar category (e.g. within Assets: Computer, Monitor,
  Software, NetworkEquipment, …). Unlike the existing top-level order (saved
  per profile), this new ordering is global and applies to every user
  regardless of profile, mirroring how Tab Order already works. Categories
  and their sub-items are discovered dynamically from the live GLPI menu, so
  new itemtypes/plugins appear automatically. Backed by a new
  `glpi_plugin_uxcustomizer_submenuorders` table and `ajax/submenuorder.php`.
- **Menu Order**: "Sort alphabetically" one-click button on the new
  sub-menu order list.

## [3.0.1] - 2026-07-08

### Fixed
- **Tab Order**: `ajax/taborder.php` now answers unsupported itemtypes with an
  HTTP 200 and an empty order/hidden payload instead of a 400 — external
  scripts may probe it on any page (e.g. config pages), and the 400 polluted
  the browser console and PHP logs.
- **Tab Order**: `tabreorder.js` only issues the order request on the
  supported asset form pages (whitelist mirroring `TabOrder::ITEMTYPES`),
  suppressing needless AJAX calls on non-asset `*.form.php` pages.

### Added
- `CLAUDE.md` development guide (points to the shared GLPI-Shared conventions)
  and the fleet-standard CI lint gate (`.github/workflows/lint.yml`: `php -l`
  + SQL guard). Neither ships in the release tarball.

## [3.0.0] - 2026-06-30

### Changed — split: Impact Map + Computer Dashboard extracted to `impact360`

uxcustomizer now focuses on **UI customization**: Menu Order, Color Palette, Tab
Order, and the asset-retention **Lifecycle** policy. The **Impact Map** (with its
health roll-up / Application Health board / observed-traffic overlay — the
2.2.0–2.6.0 work) and the **Computer Dashboard** moved to the dedicated
**[impact360](https://github.com/bacus99/impact360)** plugin.

### Removed
- **Impact Map** module: `ImpactMapTab`, `ImpactMap`, `ajax/impactmap.php`,
  `front/portfolio.php`, the config-page "Impact Map" tab, and the bundled
  `vis-network` / `dagre` assets + `impactmap.css`.
- **Computer Dashboard** module: `ComputerDashboard`, its template, `dashboard.css`.
- The `dashboard` and `impactmap` module toggles (General tab + install seeds).

### Kept
- **Menu Order**, **Color Palette**, **Tab Order**.
- **Lifecycle** (asset retention policy + its editor under the Lifecycle tab) —
  impact360's Computer Dashboard consumes it when uxcustomizer is active.

### Migration
After upgrading, the Impact Map and Dashboard tabs disappear from uxcustomizer;
install **impact360** to regain them. No data loss — uxcustomizer's config table
(including the retention policy) is untouched, and impact360 reads Lifecycle live.

## [2.6.0] - 2026-06-30

### Added — Application Health board / "wall of apps" (roll-up Phase 4)

A portfolio view of **every Appliance as a health tile**, worst-first, each
linking to that service's Impact Map. The SquaredUp landing screen — grounded in
GLPI's CMDB + observed dependencies. Completes the roll-up arc (Phases 1-4).

- **`front/portfolio.php`** — grid of service tiles colored by rolled-up health
  (Critical / Warning / Healthy / No-data), each showing "N of M components
  degraded" and "N dependencies at risk" (Phase 3). Read-only; **entity-scoped**
  + `Appliance::canView()` gate, so a technician sees only services in their
  entities. Gated by the `impactmap` module.
- **`ImpactMap::portfolio($limit=200)`** — lists entity-visible Appliances and
  rolls each up via `rollupHealth()`, sorted worst-first. Capped (per-appliance
  roll-up runs a few batched queries each).
- **Discoverability:** a button on the config page (admins) and an "All
  applications →" link in every Appliance's roll-up banner (operational entry
  from any one service).

## [2.5.0] - 2026-06-30

### Added — Dependency-aware health roll-up (roll-up Phase 3)

The Appliance roll-up now considers not just its **members** but the CIs those
members **depend on** (1 hop upstream), from native impact relations **and**
observed netstat traffic. A green app member can still put the service at risk if
the database it actually talks to is red — the dependency the app's owner never
declared. This is the moat: SquaredUp rolls up *declared* dashboards; we roll up
*discovered, dependency-aware* CIs.

- **`ImpactMap::dependenciesOf()`** — direct upstream dependencies of the member
  set: native `source → impacted` (member-as-impacted → source is a dependency)
  plus observed netstat rows where a member computer's edge is `depends`
  (outbound consumption). Members excluded; netstat side tableExists-guarded.
- **`rollupHealth()`** gains `$withDeps = true`: dependency health folds into the
  worst-of `level`, entity-scoped like members; worst-offender entries are tagged
  `kind = member | dependency`. Returns new `deps_total` / `deps_degraded`.
- **Appliance banner** now reads e.g. *"Critical · 2 of 7 components degraded ·
  1 dependency at risk · Worst: scormon02 (critical), ScorScomSQLP01 (dependency,
  warning)"*, with the dependency offenders linked + tagged.
- Backward compatible: `rollupHealth(..., false)` restores Phase 1 behaviour;
  per-node and compound health (Phase 1) unchanged.

## [2.4.0] - 2026-06-30

### Added — Explore mode: progressive expand-on-click map (roll-up Phase 2)

The Impact Map item tab now opens **collapsed to the focused CI + its immediate
neighbours**, and **clicking a node reveals its next hop** — you walk the graph
instead of rendering the whole mesh. This is the SquaredUp "click develops a
node" interaction, and it's the structural fix for sprawl on hub hosts (a
monitoring/SQL server that touches everything).

- **"Explore" toolbar toggle**, ON by default on the item tab. Inert on the
  org-wide config page (no seed to expand from) — behaviour there is unchanged.
- Collapsed view = seed(s) ∪ their direct neighbours. Each visible-but-unexpanded
  node shows a **"+N"** cue for how many neighbours are still hidden; click to
  reveal them. "Expand all" reveals the whole graph; "Collapse" returns to the
  seed.
- Visibility is **client-side** over the already-fetched payload (no extra
  fetches); the visible subset is **re-laid-out with dagre on each expand** and
  re-fit. Clustering and saved-position persistence are disabled while exploring
  (the two paradigms don't mix) — toggle Explore off for the grouped overview.
- Fully guarded: with Explore off, render/layout/clustering behave exactly as
  before.

## [2.3.0] - 2026-06-30

### Added — Application health roll-up (SquaredUp-style), Phase 1

One aggregated health status for an **Appliance** (business service), rolled up
worst-of from its members — the capability SquaredUp leads with, but grounded in
GLPI's CMDB + observed dependencies rather than hand-declared dashboards.

- **`ImpactMap::rollupHealth(itemtype, items_id)`** — for an Appliance, reads its
  members (`glpi_appliances_items`), reuses the **same batched signals** as the
  per-node overlay (open tickets + agent staleness) via the new shared
  `healthLevel()` helper, and aggregates worst-of → `{ level, total, counts,
  worst[] }`. Entity-access guarded (same as `getGraph`); no-op for non-Appliance.
- **Appliance Impact Map tab** now shows a **roll-up banner**: overall status
  (Healthy / Warning / Critical), "N of M components degraded", and links to the
  worst offenders. Server-rendered — no extra AJAX.
- **Compound boxes are health-coloured** in the map: `getGraph` rolls member
  health up into each native compound (`compounds[].health`), and the client
  borders the box red/amber — the map reads as a health hierarchy.
- Reuses existing health logic (no schema, no new endpoint). Per-node overlay
  behaviour is unchanged (the inline level logic was extracted into `healthLevel`,
  not altered).

Deliberately **not** in this phase (tracked in `GLPI-Shared/ideas/application-health-rollup.md`):
progressive expand-on-click map (Phase 2, also the sprawl fix), dependency-aware
roll-up (Phase 3), portfolio "wall of apps" (Phase 4).

## [2.2.0] - 2026-06-22

### Added
- **Impact Map: observed-traffic overlay from netstatconnections.** The Impact
  Map now optionally overlays *observed* TCP/UDP dependency edges discovered by
  the [netstatconnections](../NetstatConnections) plugin on top of GLPI's native
  impact relations — turning the dagre/flow view into a live dependency map
  without leaving uxcustomizer.
  - **`ImpactMap::netstatEdges()`** reads `glpi_plugin_netstatconnections_connections`
    (resolved + active/locked rows), aggregates to one edge per directed node
    pair, and carries a **port label** (from the plugin's ports table, mirroring
    its `getPortLabel`: name, else `PROTO PORT`) and a **weight** normalised per
    host from `seen_count`. Direction follows the plugin's semantics
    (`impacts` = Computer→remote, `depends` = remote→Computer; falls back to
    `conn_direction`). Fully guarded by `tableExists` → **no-op when the plugin
    isn't installed**.
  - Observed edges join the same `$rows` list as native relations, so they
    participate in node collection, the bounded BFS scope, and **entity-access
    filtering** identically — the overlay never widens what the session may see.
  - Edge rendering: **confirmed** edges (present in native impact tables) stay
    solid; edges seen **only** in traffic render **dashed/blue**; both inherit
    the port label and weight-driven thickness.
  - New **"Observed traffic"** toolbar toggle on the item tab (on by default).
    Endpoint accepts `&include_observed=0|1` (default on); `ajax/impactmap.php`
    passes it through to `getGraph()`.
  - **Bounded overlay (anti-sprawl):** observed edges are folded in at **depth 1
    only** (they do NOT drive BFS expansion) and **capped to the top 20 by weight
    per node** (`MAX_OBSERVED_PER_NODE`), so a chatty host (e.g. a monitoring
    server that talks to everything) can't explode the graph and shrink "Fit".
  - **Port labels are a toggle, OFF by default** ("Port labels" switch) — always-on
    labels collided into unreadable text on dense hosts. Toggling restyles edges
    with no refetch. Labels render as opaque white pills (horizontal) so they stay
    legible over thick/weighted edges instead of hiding under the line.
  - **Outlier-robust "Fit"** — frames the dense core plus the focused CI rather
    than zooming all the way out to a couple of far-flung nodes (e.g. an SCCM/SCOM
    host reached by one long edge). Stragglers stay reachable by panning.
  - **Focused CI is never clustered** — the selected asset stays a distinct,
    named node instead of being folded into an auto type-group ("Computer (N)")
    or a compound. Selecting it now shows its own chain (server → DB instance →
    management servers → SCOM) instead of an anonymous blob with aggregated
    "N conn." edges.
  - Read-only throughout — the overlay never writes to any impact or plugin table.

### Removed
- **Firewall security card** on the Computer Dashboard. It had no native GLPI
  data source (always rendered "No data source"), so it added noise without
  value. The security row is now Connectivity / Antivirus / Health (3-up grid).

## [2.1.1] - 2026-06-16

### Changed
- **Tab Order now skips self-service users.** The saved tab order is still global and still applies to all standard (central-interface) users, but it is no longer served to profiles on the simplified/self-service (`helpdesk`) interface. The reorder endpoint checks the requesting user's active profile interface and returns an empty order for non-central profiles. In practice self-service users never reached the central asset forms where these tabs live, so this makes an incidental exclusion explicit and guaranteed. Editing remains super-admin only.

## [2.1.0] - 2026-06-16

### Added
- **What-if failure simulation** — a toolbar mode: click any CI and everything reachable by following impact arrows OUT (what it impacts) lights up red, with the count ("N affected if this fails") in the status line. Pure client-side BFS over the existing edges — instant, no new data. Works with collapsed groups (a member's failure lights its cluster).
- **Path highlighting** — a toolbar mode: click two CIs and the shortest dependency chain between them is highlighted (BFS over the undirected edge set) while everything else dims; "No path" when they're unconnected.
- **Mini-map overview** — a toggleable inset (bottom-left) showing the whole graph with a live rectangle for the current viewport; click it to recentre. Shares the main graph's coordinate space, so it stays aligned across Flow/Force/Tree layouts.
- **SVG and PDF export** — alongside the existing PNG: **SVG** is reconstructed from node positions as discrete shapes/lines/labels, so it opens editable in Visio/Illustrator (Device42 parity), with no bundled library; **PDF** opens a print view of the rendered map to "Save as PDF". Export is now a PNG / SVG / PDF button group.

## [2.0.1] - 2026-06-16

### Security
- **SEC-1 (Medium) — entity isolation on the Impact Map.** `ImpactMap::getGraph()` now filters every resulting node through GLPI's `getEntitiesRestrictCriteria()` for the session's active entities, so the BFS neighborhood and ITIL seed expansion can no longer surface item names or health from entities the user can't access. The guard fails closed (drops nodes it can't prove access to) and applies to every scope (org-wide, asset, ITIL). This was latent under 1.9.0's super-admin-only gate but became live in 2.0.0, which lets technicians reach the endpoint with per-asset/per-ITIL `READ`. From the 2026-06-16 security review; see `SECURITY.md` for the full disposition (SEC-2 kept as defense-in-depth, SEC-3 already resolved by 2.0.0).

## [2.0.0] - 2026-06-10

### Added
- **Impact Map on Ticket, Change and Problem forms** (SysAid-style "business impact in the ticket") — a new "Impact Map" tab on ITIL objects shows the **combined neighborhood of every asset linked to the object**: a technician triaging an incident, or a change manager assessing risk, sees the blast radius without leaving the form. Multi-seed bounded BFS (Forward/Backward depth selectors work as on assets); assets linked to the ticket but absent from the impact graph still appear as isolated nodes so nothing linked is invisible. **Seed assets are emphasized** (thicker dark border, larger label) — health colors still take precedence so problem nodes stay red/amber. No GLPI plugin offers this today.

### Changed
- **Per-scope rights model on the data endpoint** (was: super-admin for everything, which silently broke the asset tab for technicians):
  - org-wide view (Setup page) — still requires `config UPDATE`;
  - asset scope — requires **READ on that asset**;
  - ITIL scope — requires **READ on that Ticket/Change/Problem** (entity and visibility rules respected); the linked-asset seed list is resolved **server-side**, never accepted from the client.

## [1.9.0] - 2026-06-10

### Added
- **Health overlay on the map** (SysAid/ServiceNow-style) — nodes with open tickets (status new/assigned/planned/waiting) or a silent inventory agent (>2 days) get a thick **amber** (one issue) or **red** (both) status border. The side panel shows the detail: health level, open-ticket count, agent last-seen. Tooltips carry the same info. Nodes with no signal stay clean — no "unknown" noise. Data comes from two **batched** queries (`glpi_items_tickets`+`glpi_tickets`, `glpi_agents`) — never per-node.
- **Auto-group by type** (iTop-style) — a toolbar switch that collapses loose nodes into one dashed-border cluster per itemtype ("Computer (14)") whenever a type has more than 8 nodes on the canvas. Double-click expands, "N conn." edge labels and the side panel work on type groups exactly like on compounds. Default **on** for the org-wide config view (where hairballs live), **off** on the asset tab (depth-scoped graphs are small).
- **Export PNG** — toolbar button downloads the current view as `impact-map-<date>.png`, composited onto a solid background so it pastes cleanly into documents.

## [1.8.0] - 2026-06-10

### Added
- **"Flow" layout — native Impact Analysis look with our interactions.** The Impact Map gains a layout selector (Flow / Force / Tree). **Flow** computes a left-to-right layered layout with **dagre** — the exact algorithm GLPI's native Impact Analysis uses (cytoscape-dagre, rankdir LR) — so the map reads like the native page (sources left, impacted right), while keeping everything native lacks: collapsible compound groups with member counts, "N conn." merged-edge labels, legend filter pills, search dim, side panel, and persistent positions. Cycles are handled by dagre's greedy acyclicer. dagre 0.8.5 is bundled locally (no CDN).
- **Per-mode edge styling** — horizontal beziers in Flow, vertical in Tree, organic in Force.

### Changed
- The on-asset tab (Computer / Appliance) now **defaults to Flow** so it feels like the native Impact Analysis at first glance; the org-wide config-page view keeps **Force** as default (better for large, disconnected graphs). Switching modes re-lays out in place without re-fetching, and repositions collapsed groups to the average of their members.
- Saved positions (1.7.0) still win over any computed layout in Flow/Force modes — your manual arrangement is never discarded. Tree mode always recomputes (the hierarchical engine owns coordinates there) and never overwrites your saved arrangement.

## [1.7.0] - 2026-06-10

### Added
- **Stable, persistent node positions (ServiceNow-style)** — the Impact Map now remembers where nodes are. After the first layout (and after any manual drag, group expand, or "Expand all"), positions are saved per scope (asset + depths) in the browser's localStorage. The next load applies them as preset coordinates and **skips physics entirely** — instant render, zero drift. Saved scopes are capped at 20 (oldest pruned).

### Changed
- **Deterministic layout** — `layout.randomSeed` is now fixed, so even a first-time render of the same graph produces the same picture on every load (previously each load used a random seed and the layout drifted).
- **Faster first loads** — physics stabilization cut from 220 to 120 iterations, and the costly `improvedLayout` pre-pass is skipped above 150 nodes. Subsequent loads of a known graph skip physics entirely via saved positions.

### Notes
- Evaluated (and rejected) patching GLPI's native Impact Analysis with dagre: GLPI 11's native impact page uses **Cytoscape.js** (not vis.js), already lays out with **dagre LR**, and already persists positions via `glpi_impactcontexts` — the drift/speed issues were in our vis-network module, fixed here without touching core.

## [1.6.1] - 2026-06-09

### Fixed
- **On-asset Impact Map was effectively a global view** on dense CMDBs (629 nodes on ScorScomSQLP01). Cause: the BFS scope from 1.6.0 was undirected and unbounded, so it walked the entire connected component of the asset. Replaced with a **bounded directed BFS** — `forward` hops along arrows OUT (impacts) and `backward` hops along arrows IN (impacted by), independently. Defaults to **2 each** (matches GLPI's native Impact Analysis density). If the start node has no relations, the tab now returns an empty graph instead of silently falling back to the global view.

### Added
- **Depth selectors in the on-asset tab toolbar** — two compact dropdowns (Forward 1–5, Backward 1–5). Changing either re-fetches and re-renders. The config-page (org-wide) view is unchanged and has no depth limit.
- `ajax/impactmap.php` accepts `&forward=<int>&backward=<int>` (clamped 0–10 server-side).

## [1.6.0] - 2026-06-09

### Added
- **Impact Map tab on assets** — the same vis-network topology view is now available as an additional tab on **Computer** and **Appliance** form pages, alongside GLPI's native "Impact Analysis" tab. The new tab is **scoped to the subgraph connected to the current asset** (BFS over impact relations), so you immediately see this CI's neighborhood with collapsible compounds, edge counts, filter pills, tree layout and search dim. Registered via `Plugin::registerClass(ImpactMapTab::class, addtabon=[Computer, Appliance])` — the tab is additive; the native Impact Analysis tab keeps working untouched. Use Tab Order to move it next to or above the native tab.

## [1.5.0] - 2026-06-09

### Added — Impact Map polish
- **Edge merge labels** ("N conn.") — cluster edges now show how many real relations they merge, Faddom-style. Driven by vis-network's `getBaseEdges` API.
- **Filter pills in the legend** — each itemtype swatch is now a clickable pill. Click to hide every raw node of that type (and its edges); click again to bring them back. Cluster nodes are unaffected so groups stay visible while you focus the rest. Hidden state visually mutes the pill (faded + line-through).
- **Hierarchical layout toggle** — new toolbar button switches between force-directed and a top-down tree layout (`layout.hierarchical`, `sortMethod: 'directed'`). Great for "what depends on this DB" walks. Physics auto-disables in tree mode.
- **Search dims non-matches** — typing in the search box now fades non-matching nodes to 15% opacity instead of just selecting them (Faddom behaviour). The first match still gets focused.

## [1.4.0] - 2026-06-09

### Added
- **Impact Map module** — new tab in the UX Customizer config page that renders an org-wide topology view of GLPI's native impact relations using **vis-network** (Faddom-style). Reads `glpi_impactrelations` / `glpi_impactitems` / `glpi_impactcompounds` directly — no new tables. Compounds (named groups) start **collapsed** as a single node showing the member count (e.g. "Management Servers (3)"); double-click expands. Single-click a node opens a side panel with type / id / "Open in GLPI" link. Color-coded by itemtype, automatic legend from nodes actually present, search/highlight box, expand-all / collapse-all / fit buttons. Capped at 750 nodes per render (truncation surfaced in the status line). vis-network is bundled locally (no CDN). New `module_impactmap_enabled` toggle in General.

## [1.3.5] - 2026-06-09

### Changed
- **Vertical gap between dashboard sections** — the System info card, the Tickets/Contracts row, and the Activity timeline were sitting flush against each other. They now have a consistent 1 rem gap (driven by a flex column on `.uxc-ci-detail`), so the page reads as separate stacked sections.

## [1.3.4] - 2026-06-09

### Changed
- More breathing room around the **Volumes** section — extra space above (between Hardware/Lifecycle and the Volumes subhead) and below (before the card's bottom edge), plus a slightly larger gap between volume rows.

## [1.3.3] - 2026-06-09

### Changed
- **Details section removed.** Serial number and Last inventory move under **Hardware** in the System info card. Description and Inventory number are no longer shown on the dashboard (still on GLPI's main form).

## [1.3.2] - 2026-06-09

### Changed
- **System info layout breathes** — more space between the Software / Hardware / Lifecycle columns, the column divider has real padding around it, kv lists have a proper row gap, and the OS vendor pill has its own breathing room next to the "OS" label.
- **Volumes compacted** — empty / unknown / sub-1 GB volumes are now hidden (those produced lots of "—" filler rows); remaining volumes flow into an auto-fit grid (2–3 per row depending on width) with a slimmer bar. The Volumes section is now a small band, not a tall column.

## [1.3.1] - 2026-06-08

### Fixed
- **Dashboard "Edit" button did nothing** — it pointed at the same `computer.form.php?id=<id>` URL the Dashboard tab is already on, so clicking it appeared to do nothing. The button now forces `Computer$main` so it goes to the main (form) tab where you can edit fields.

## [1.3.0] - 2026-06-08

### Changed
- **Dashboard layout consolidated** — Software summary, Hardware, Lifecycle and Details are now one single **System info** card (three columns: Software / Hardware / Lifecycle, with Volumes and Details as full-width sections below). Tickets + Contracts move to a separate 2-column row underneath, keeping the page tidier.

### Added
- **OS vendor brand icons** next to the OS line (Windows / Red Hat / Ubuntu / Debian / Apple / Android / SUSE / Linux / generic) using Tabler brand glyphs with vendor-coloured icons, no bundled logo files.

## [1.2.3] - 2026-06-08

### Changed
- **Health score now includes a lifecycle check** — when a retirement date is known, "past retirement" (overdue for replacement) counts as a failing check (so the total becomes "X of 6"; it stays "X of 5" when there's no purchase date / retention to evaluate).

## [1.2.2] - 2026-06-08

### Fixed
- Dashboard top-bar **CI name was unreadable** (light text on GLPI's light page background). The name/subtitle now use GLPI's own theme text colour (`--tblr-body-color` / `--tblr-secondary`), so they're dark on light themes and light on dark themes.

## [1.2.1] - 2026-06-08

### Added
- **Volumes in the Hardware card** — mount point + used % with a usage bar (green / amber ≥75% / red ≥90%) and total size, from `glpi_items_disks`. Used % is clamped to an integer 0–100 before it drives the bar width (no CSS injection); mount points are HTML-escaped.

## [1.2.0] - 2026-06-08

### Added
- **Asset retention policy** — new **Lifecycle** config tab to set retention years per Computer type (e.g. Laptop 5y, Server 7y) with a default. The Computer Dashboard uses it with the purchase date (Infocom / Management tab) to show a **retirement date** and time remaining (or "overdue").
- **Lifecycle card** on the dashboard — purchase date, warranty end (from `glpi_infocoms`), retention, computed retirement + status pill.
- **Hardware summary card** — model, processor, memory (Σ), disk (Σ) from native inventory device tables.
- **Recent activity timeline** — last changes from `glpi_logs` (date / user / change).

### Changed
- Dashboard polish: status dots on security cards, card-title icons, tidier headers, timeline + status-pill styling (all scoped to `.uxc-ci-detail`).

## [1.1.2] - 2026-06-08

### Fixed
- **Antivirus card was always empty** — queried the wrong table. Corrected to GLPI 11's `glpi_itemantiviruses` (it had been `glpi_items_antiviruses`).

### Added
- **Tickets card: "New ticket"** button that opens a ticket pre-linked to the computer, plus a "View all tickets" link to the native Tickets tab.
- Clearer dashboard template — status dots on the security cards, icons on card titles, tidier card headers.

## [1.1.1] - 2026-06-08

### Changed
- **Computer Dashboard now shows real data** from standard GLPI 11 tables instead of placeholders: Connectivity from the native inventory **agent** (`glpi_agents` last-contact + version), Antivirus from `glpi_items_antiviruses` (active + up-to-date), Tickets broken down by status (open / pending), Contracts type + summed cost, and a **Health** score computed from those native signals. Serial / inventory number / description / last-inventory shown as detail rows. All queries are defensive (table-exists + try/catch).
- Fields with **no native source** (firewall status, uptime, unlicensed count, tags) are labelled honestly ("No data source") rather than shown as blanks — wire them from the lcornoc02 inventory mapping when available.

## [1.1.0] - 2026-06-08

### Added
- **Computer Dashboard module** — adds a card-based **"Dashboard"** tab to the Computer form (additive; native tabs untouched). Registered via `Plugin::registerClass(..., ['addtabon' => ['Computer']])`; data from existing GLPI tables (no new schema). Scaffolded with real basic fields + clear `TODO(lcornoc02)` markers for inventory/security data. Toggle: `module_dashboard_enabled`.
- **Setup menu entry** — UX Customizer now appears under **Setup** (via `menu_toadd` → `Menu::getMenuContent()`), in addition to the Setup → Plugins wrench icon. *(Menu is session-cached: appears after re-login / cache clear.)*
- **Color Palette: matching dark theme** — optional dark variant generated alongside the light palette (both selectable in My Settings).
- **Tab Order: hide/unhide** — eye toggle per tab to hide tabs from asset pages; Reset clears order and hidden state.

### Changed
- Default palette **name** is now the generic **"Custom"** (was "TC Transcontinental") — appropriate for a public-catalog plugin. Default colors are unchanged.

### Fixed
- `build.ps1` infinite `.build` recursion (staging dir copied into itself); bundle SortableJS locally (no CDN); plugin static assets served from `public/`.

## [1.0.0] - 2026-06-01

### Added
- Initial release as a modular super-admin UI customizer (rebranded and expanded from the standalone `taborder` plugin).
- **Menu Order module** — per-profile drag-and-drop reordering of the left navigation menu via the official `redefine_menus` hook. New menu items append at the bottom.
- **Color Palette module** — define a custom color palette that GLPI lists as a **selectable** theme (My Settings + Setup→General). It writes an SCSS file into `GLPI_THEMES_DIR` using GLPI 11's native theme system; it does **not** override users' chosen themes.
- **Tab Order module** — global, per-itemtype reordering of asset detail-page tabs; client-side (`tabreorder.js`) since GLPI 11 exposes no server hook for tab order.
- Independently toggleable modules (General tab) backed by a key/value config table.
- **Absorbs the former `taborder` plugin.** On fresh install, legacy `glpi_plugin_taborder_order` rows are migrated into `glpi_plugin_uxcustomizer_menuorders`, preserving saved per-profile menu order.
- CSRF handled by GLPI 11's `CheckCsrfListener` middleware (no explicit `validateCSRF`).
