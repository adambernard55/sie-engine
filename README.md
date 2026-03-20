# SIE Engine

Core engine for the **Strategic Intelligence Engine** — a multi-site platform that turns an Obsidian knowledge vault into a WordPress-hosted, AI-searchable knowledge base.

## What It Does

- **Syncs** Obsidian markdown to WordPress custom post types with full frontmatter support
- **Embeds** content into Pinecone for vector-based semantic search
- **Serves** a RAG-powered chat widget that answers questions from your knowledge base
- **Exposes** tools via MCP for AI-assisted content workflows

## Architecture

```
sie-engine/               ← this repo (core, used as git submodule)
├── agents/               ← CrewAI agent definitions
│   ├── analyst_agent.py
│   ├── editor_agent.py
│   └── research_agent.py
├── tools/                ← Python tools
│   ├── kb_sync.py        ← KB → WordPress + Pinecone sync (primary)
│   ├── pinecone_tool.py  ← Vector search / upsert
│   ├── wordpress_tool.py ← WP REST API client
│   ├── rebuild_mapping.py← Duplicate detection and mapping rebuild
│   ├── wp_import.py      ← WordPress → Obsidian import
│   └── ...
├── wp-plugin/            ← SIE WordPress plugin
│   └── sie-wp-plugin/
│       ├── sie-wp-plugin.php
│       └── includes/
│           ├── class-cpt.php        ← FAQ, Pro Tip, Guide post types
│           ├── class-permalink.php  ← Hierarchical /kb/ URLs
│           ├── class-chat-api.php   ← RAG chat endpoint + widget
│           ├── class-topic-api.php  ← Topic taxonomy REST API
│           └── class-settings.php   ← Admin settings page
├── mcp_server.py         ← MCP server for AI tool access
├── config.example.yaml   ← Instance configuration template
└── requirements.txt      ← Python dependencies
```

## Instance Model

This repo is the **core** — never edited directly for a specific site. Each site gets its own **instance** repo that pulls this in as a git submodule:

```
your-instance/
├── config.yaml           ← site-specific settings (topics, URLs)
├── .env                  ← credentials (never committed)
├── setup_taxonomy.py     ← create WP taxonomy terms for this site
└── engine/               ← git submodule → sie-engine
```


## Setup

### 1. Create an instance

```bash
mkdir my-instance && cd my-instance
git init
git submodule add https://github.com/adambernard55/sie-engine engine
cp engine/config.example.yaml config.yaml
```

### 2. Configure

Edit `config.yaml` with your site URL, taxonomy IDs, and KB path.

Create `.env` with credentials:

```env
WP_SITE_URL=https://yoursite.com
WP_USERNAME=YourUser
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
OPENAI_API_KEY=sk-...
PINECONE_API_KEY=pcsk_...
PINECONE_INDEX_NAME=your-index
PINECONE_HOST=https://your-index.svc.pinecone.io
```

### 3. Install dependencies

```bash
pip install -r engine/requirements.txt
```

### 4. Create taxonomy terms

```bash
python setup_taxonomy.py
```

Update `config.yaml` with the generated IDs.

### 5. Sync

```bash
python engine/tools/kb_sync.py                    # full sync
python engine/tools/kb_sync.py --filter AI/        # sync one folder
python engine/tools/kb_sync.py --dry-run           # preview only
```

## WordPress Plugin

Install `wp-plugin/sie-wp-plugin/` on your WordPress site. It provides:

- **3 Custom Post Types** — FAQ (what), Pro Tip (how), Guide (which/when)
- **sie_topic taxonomy** — shared across all three CPTs
- **Hierarchical permalinks** — `/kb/ai/methods/mcp/post-slug/`
- **RAG chat** — `[sie_chat]` shortcode, powered by Pinecone + OpenAI
- **Topic API** — live topic mapping fetch for sync tools
- **Settings page** — API keys, chat access, system prompt

## Updating Instances

When the core engine is updated:

```bash
cd your-instance
cd engine && git pull origin main && cd ..
git add engine
git commit -m "chore: update engine submodule"
```

## Key Sync Features

- **Multi-tier duplicate prevention** — mapping file → path slug → filename slug → title search → create
- **Frontmatter extraction** — title, excerpt, tags, SEO fields, semantic metadata
- **Obsidian syntax cleanup** — wikilinks, embeds, dataview blocks stripped before sync
- **Pinecone chunking** — documents split into chunks with enriched metadata for RAG
- **Mapping file** — `kb_sync_mapping.json` tracks every synced post for reliable updates
