---
title: SIE Engine Roadmap
id: 20260318001
version: "1.0"
steward: Adam Bernard
updated: 2026-03-18
status: Active
doc_type: Reference
summary: Architecture plan for SIE plugin enhancements — full-page chat, sync dashboard, and HTML content handling.
tags:
  - sie
  - roadmap
  - architecture
---

# SIE Engine Roadmap

## Phase 1: Full-Page Chat

**Goal:** Replace the floating chat bubble with a full-page ChatGPT-style interface.

### New Files

| File | Purpose |
|------|---------|
| `includes/class-chat-page.php` | Full-page chat shortcode + SSE streaming endpoint |
| `assets/chat-page.js` | Chat UI: conversations, streaming, markdown rendering |
| `assets/chat-page.css` | Full-page chat layout |
| `assets/lib/marked.min.js` | Lightweight markdown renderer |

### REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `sie/v1/chat` | POST | **Modify**: accept `messages[]` array + `model` param (backward compatible) |
| `sie/v1/chat/stream` | POST | **New**: SSE streaming endpoint for typewriter effect |

### Features

- **Multi-turn conversations** — messages array tracks full conversation history
- **Streaming responses** — SSE from PHP, forwarding OpenAI stream chunks
- **Markdown rendering** — assistant messages rendered with marked.js
- **Source citations** — Pinecone results shown as linked chips below responses
- **Suggested questions** — configurable starter prompts on empty state
- **Model selector** — dropdown (gpt-4o-mini, gpt-4o, etc.)
- **Backward compatible** — existing `[sie_chat]` floating widget unchanged

### Shortcode

```
[sie_chat_page]
```

Place on a WordPress page for a full-screen chat experience.

### New Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `sie_chat_models` | `gpt-4o-mini,gpt-4o` | Allowed models (comma-separated) |
| `sie_starter_questions` | (empty) | One question per line |
| `sie_chat_max_history` | `10` | Max conversation turns sent to API |

### Conversation Context Strategy

- System prompt is server-side only (never from client)
- Last N messages included (configurable via `sie_chat_max_history`)
- Pinecone query uses **latest user message only** (not full conversation)
- RAG context injected as system message before the latest user turn

### Streaming Implementation (PHP SSE)

1. Set headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
2. Use cURL with `CURLOPT_WRITEFUNCTION` to forward OpenAI stream chunks
3. Flush output with `ob_flush()` + `flush()` after each chunk
4. Final event includes sources and done flag

---

## Phase 2: Sync Dashboard

**Goal:** WordPress admin page to browse, select, and sync KB files with direction control.

### New Files

| File | Purpose |
|------|---------|
| `includes/class-sync-dashboard.php` | Admin page + REST endpoints |
| `assets/sync-dashboard.js` | File tree UI, sync controls, progress polling |
| `assets/sync-dashboard.css` | Admin page styles |
| `.github/workflows/kb-sync-dispatch.yml` | New workflow for dashboard-triggered syncs |

### REST API

| Endpoint | Method | Description |
|----------|--------|-------------|
| `sie/v1/sync/files` | GET | File tree + status from mapping JSON |
| `sie/v1/sync/trigger` | POST | Dispatch GitHub Action via API |
| `sie/v1/sync/status` | GET | Poll GitHub Action run status |

### Features

- **File tree with checkboxes** — browse KB folders, select files
- **Sync status per file** — synced, outdated, new, error (from mapping JSON)
- **Sync direction selector** — Obsidian → WordPress or WordPress → Obsidian
- **Trigger sync** — dispatches GitHub Action with selected files + direction
- **Real-time progress** — polls GitHub Action run status
- **Respects sync_direction frontmatter** — greys out files that can't sync in selected direction

### Data Flow

```
WordPress Admin UI
  → POST /sie/v1/sync/trigger { direction, files[] }
  → GitHub API: POST /repos/{owner}/{repo}/dispatches
    { event_type: "sie-sync", client_payload: { direction, files } }
  → GitHub Actions runs kb_sync.py or wp_import.py
  → Commits updated mapping back to repo
  → WordPress Admin polls /sie/v1/sync/status
```

### Mapping File Access

Dashboard fetches `kb_sync_mapping.json` via GitHub API:
`GET /repos/{owner}/{repo}/contents/kb/kb_sync_mapping.json`
Cached in WP transient (15-minute TTL).

### New Settings

| Setting | Description |
|---------|-------------|
| `sie_github_token` | PAT with `repo` scope (for workflow dispatch) |
| `sie_github_repo` | Format: `owner/repo` |

---

## Phase 3: HTML / Page Builder Content Handling

**Goal:** Handle pages with complex styling (page builders, custom HTML) without mangling content during sync.

### New Frontmatter Fields

```yaml
sync_direction: bidirectional  # bidirectional | obsidian_only | wp_only
content_type: markdown         # markdown | html
```

### Behavior

| `content_type` | Obsidian → WP | WP → Obsidian |
|----------------|---------------|---------------|
| `markdown` | Convert via markdown lib (current behavior) | Convert via markdownify |
| `html` | Pass HTML straight through, no conversion | Store raw HTML in .md file |

| `sync_direction` | Obsidian → WP | WP → Obsidian |
|-------------------|---------------|---------------|
| `bidirectional` | Sync | Sync |
| `obsidian_only` | Sync | Skip |
| `wp_only` | Skip | Sync |

### Changes to Existing Files

**kb_sync.py:**
- Read `sync_direction` from frontmatter; skip files marked `wp_only`
- Read `content_type`; if `html`, skip markdown-to-HTML conversion

**wp_import.py:**
- Read `sync_direction`; skip files marked `obsidian_only`
- Detect HTML-heavy content; set `content_type: html` in frontmatter
- For HTML content, store raw HTML instead of running markdownify

**sync-kb.sh:**
- Check `content_type: html`; skip Pandoc conversion
- Check `sync_direction: wp_only`; skip file

### GitHub + HTML

GitHub stores HTML in markdown files as-is — no issues. The problem is only at the **conversion step** (Pandoc/markdown lib). The `content_type` flag bypasses conversion entirely.

---

## Implementation Order

1. **Phase 1** — Full-page chat (self-contained, highest user impact)
2. **Phase 2** — Sync dashboard (depends on GitHub API integration)
3. **Phase 3** — HTML handling (narrower scope, touches Python tools)

## Core vs Instance

All three phases are **core engine features** (sie-engine). No instance-specific code needed. Instance configuration stays in `config.yaml` and WordPress options (settings page).

## Plugin Loader Changes

```php
// sie-wp-plugin.php — add after existing requires:
require_once SIE_PLUGIN_DIR . 'includes/class-chat-page.php';
require_once SIE_PLUGIN_DIR . 'includes/class-sync-dashboard.php';

// In plugins_loaded callback — add:
( new SIE_Chat_Page() )->init();
( new SIE_Sync_Dashboard() )->init();
```
