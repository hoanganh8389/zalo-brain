# TwinShell Learning Hub (Wave C)

Standalone React 18 + Vite app rendering KG-Hub learning aggregator data:

- 5 KPI tiles (`/learning/summary`)
- 3 active jobs + 24h sparkline
- Entity type distribution + top entities (`/learning/analytics`)
- Cleanup panel (status + manual run + log) (`/learning/cleanup/*`)
- Scope toggle: User | Site (Site requires `manage_options`)
- Presence heartbeat every 20s (closes G4 FE — pipeline switches to ajax lane while tab open)

## Build

```bash
cd modules/twinshell/learning-hub
npm install
npm run build      # outputs ../assets/learning-hub/{index.js,index.css}
```

Bundle is enqueued by Wave E in `class-twin-shell-page.php`. Dev server (`npm run dev`)
proxies REST against `/wp-json/bizcity-twinchat/v1/` — start it after `wp serve`.

## Mount target (Wave E)

```html
<div id="bizcity-learning-hub-root"></div>
<script>
  window.BIZCITY_LEARNING_HUB = {
    restRoot: '<wp-rest-url bizcity-twinchat/v1/>',
    nonce:    '<wp_create_nonce("wp_rest")>',
    userId:   <get_current_user_id()>,
    canManageSite:    <bool current_user_can("manage_options")>,
    canManageCleanup: <bool current_user_can("manage_options") || can("bizcity_view_kg_learning")>,
  };
</script>
<link rel="stylesheet" href="<plugin-url>/modules/twinshell/assets/learning-hub/index.css">
<script type="module" src="<plugin-url>/modules/twinshell/assets/learning-hub/index.js"></script>
```
