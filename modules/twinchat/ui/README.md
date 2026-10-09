# TwinChat Workspace UI

React 18 + Vite 5 + TypeScript bundle for the **TwinChat Workspace** admin
page exposed by `BizCity_TwinChat_Admin_Menu` (WordPress backend).

> Governing rule: [`PHASE-0-RULE-BRAIN-UNIFICATION.md`](../../../PHASE-0-RULE-BRAIN-UNIFICATION.md)
> Spec: [`PHASE-0.5-SPRINT-4-CHAT-WORKSPACE.md`](../PHASE-0.5-SPRINT-4-CHAT-WORKSPACE.md)

## Build

```powershell
cd modules/twinchat/ui
npm install
npm run build
```

The build outputs to `dist/` with a `.vite/manifest.json` that the PHP
admin page reads to inject the hashed entry JS + CSS.

## Dev

```powershell
npm run dev
```

Note: dev server runs at `http://localhost:5180` but the SSE stream + REST
calls require WordPress nonces, so the integrated WP admin page is the
primary test surface. For pure component dev, mock `window.BIZCITY_TWINCHAT`.

## Architecture

```
src/
  api/client.ts            REST helpers + SSE URL builder
  hooks/useTwinChatStream  6+2 SSE event parser (status/thinking/sources/token/token_rollback/complete + kg_highlight/error)
  stores/twinchatStore.ts  Zustand store — citation activation, 2-phase scroll
  components/
    SmartSourcesPanel      Left col (240px) — toggles + sources filter
    ChatPanel              Center — bubbles + streaming + ThinkingTimeline
    VisualPanel            Right col (420px) — Source / Graph / Entities tabs
    PassageViewer          Renders passages of active source with highlight + scroll-to-heading
    KGGraphView            Stats + highlighted entities (full graph lives in kg-hub UI)
    EntityList             Cited entity chips
    ThinkingTimeline       Cloned from NexusRAG — collapsible step list
    CitationLink           [n] button → activates citation in store
  pages/WorkspacePage.tsx  3-col grid host
  main.tsx                 Mount on #bizcity-twinchat-root with QueryClient
```
