"""
SIE MCP Server
==============
Exposes the SIE Knowledge Engine as an MCP server for Claude Desktop
and other MCP-compatible AI assistants.

Tools (read):
  search_knowledge   — semantic search over Pinecone knowledge base
  list_kb_files      — list markdown files in KB_ROOT
  read_kb_file       — read a specific KB markdown file
  preview_sync       — dry-run sync (see what would change)

Tools (write):
  sync_single_file   — sync one file to WordPress + Pinecone
  sync_kb_folder     — sync a folder (or everything) to WordPress + Pinecone

Resources:
  kb://<path>        — read any KB file by relative path

Prompts:
  research_topic     — structured knowledge base research workflow
  publish_article    — article publishing checklist + workflow

Usage:
  python mcp_server.py              # stdio transport (default, for Claude Desktop)
  python mcp_server.py --sse        # SSE transport (for remote clients)
"""

import sys
import os
from pathlib import Path

# ── sys.path fix ─────────────────────────────────────────────────────────────
# When invoked as `python engine/mcp_server.py` from an instance repo,
# Python adds engine/ to sys.path, but `from tools.x import ...` needs
# the engine root on sys.path.  Same fix as tools/kb_sync.py.
_engine_root = str(Path(__file__).parent)
if _engine_root not in sys.path:
    sys.path.insert(0, _engine_root)

from dotenv import load_dotenv
load_dotenv()

from mcp.server.fastmcp import FastMCP

# Lazy-import after path fix so hub-and-spoke invocation works
from tools.pinecone_tool import search_knowledge_core
from tools.kb_sync import KB_ROOT, sync_all, sync_file_by_path, should_sync_file

# =============================================================================
# Server
# =============================================================================

mcp = FastMCP(
    name="SIE Knowledge Engine",
    instructions=(
        "Tools for searching and managing the SIE knowledge base "
        "(Obsidian markdown → WordPress + Pinecone). "
        "Always search_knowledge before external research. "
        "For write operations (sync_*) use preview_sync first."
    ),
)


# =============================================================================
# Tools — Read
# =============================================================================

@mcp.tool()
def search_knowledge(query: str) -> str:
    """Search the knowledge base using semantic similarity (Pinecone).

    Use this FIRST before external web searches to leverage internal
    institutional knowledge and avoid redundant research.

    Args:
        query: Natural language question or search terms

    Returns:
        Relevant articles with similarity scores, titles, and source URLs.
        Returns a message if no relevant content is found.
    """
    return search_knowledge_core(query)


@mcp.tool()
def list_kb_files(folder: str = "") -> str:
    """List syncable markdown files in the knowledge base.

    Args:
        folder: Optional subfolder to filter results.
                Examples: "AI/", "GROWTH/Email/", "CORE/"
                Leave empty to list all files.

    Returns:
        Count + newline-separated relative file paths.
    """
    if KB_ROOT is None:
        return "Error: KB_ROOT is not configured. Check config.yaml or KB_ROOT env var."

    search_root = KB_ROOT
    if folder:
        search_root = KB_ROOT / folder.lstrip("/")
        if not search_root.is_dir():
            return f"Error: Folder not found in KB: {folder}"

    files = [
        str(md_file.relative_to(KB_ROOT)).replace("\\", "/")
        for md_file in sorted(search_root.rglob("*.md"))
        if should_sync_file(md_file)
    ]

    if not files:
        return "No syncable markdown files found."

    return f"Found {len(files)} files:\n" + "\n".join(files)


@mcp.tool()
def read_kb_file(path: str) -> str:
    """Read the raw markdown content of a KB file.

    Args:
        path: Relative path from KB root.
              Example: "AI/3_methods/01_intro.md"

    Returns:
        Full file content including YAML frontmatter.
    """
    if KB_ROOT is None:
        return "Error: KB_ROOT is not configured."

    file_path = KB_ROOT / path.lstrip("/")
    if not file_path.exists():
        return f"Error: File not found: {path}"
    if not file_path.is_file():
        return f"Error: Path is not a file: {path}"

    return file_path.read_text(encoding="utf-8")


@mcp.tool()
def preview_sync(folder: str = "") -> str:
    """Preview what would be synced without making any changes.

    Runs a dry-run sync and reports which files would be created or updated.
    Call this before sync_kb_folder to review the scope.

    Args:
        folder: Optional folder prefix to limit the preview.
                Examples: "AI/", "GROWTH/", ""  (empty = all)

    Returns:
        List of files that would be created or updated on WordPress.
    """
    try:
        results = sync_all(dry_run=True, filter_path=folder or None)
    except Exception as e:
        return f"Error running preview: {e}"

    if not results:
        return "No syncable files found."

    to_sync = [r for r in results if r.get("status") == "dry_run"]
    skipped = [r for r in results if r.get("status") == "skipped"]

    lines = [f"Preview: {len(to_sync)} would sync, {len(skipped)} skipped"]
    for r in to_sync:
        title = r.get("title") or r.get("file", "unknown")
        lines.append(f"  [?] {title}")

    return "\n".join(lines)


# =============================================================================
# Tools — Write
# =============================================================================

@mcp.tool()
def sync_single_file(path: str) -> str:
    """Sync a single KB file to WordPress and Pinecone.

    ⚠️ WRITE OPERATION — publishes content to WordPress and upserts to Pinecone.

    Args:
        path: Relative path from KB root.
              Example: "AI/3_methods/01_intro.md"

    Returns:
        Sync result: status (created / updated / skipped / error),
        WordPress post URL, and Pinecone upsert confirmation.
    """
    try:
        result = sync_file_by_path(path)
    except Exception as e:
        return f"Error: {e}"

    status   = result.get("status", "unknown")
    title    = result.get("title", path)
    url      = result.get("url", "")
    pinecone = result.get("pinecone", "")
    error    = result.get("error", "")

    parts = [f"Status:  {status}", f"Title:   {title}"]
    if url:
        parts.append(f"URL:     {url}")
    if pinecone:
        parts.append(f"Pinecone: {pinecone}")
    if error:
        parts.append(f"Error:   {error}")

    return "\n".join(parts)


@mcp.tool()
def sync_kb_folder(folder: str = "") -> str:
    """Sync all KB files in a folder to WordPress and Pinecone.

    ⚠️ WRITE OPERATION — publishes multiple files to WordPress and Pinecone.
    Use preview_sync first to review the scope before running.

    Args:
        folder: Folder prefix to sync.
                Examples: "AI/", "GROWTH/Email/", ""  (empty = everything)

    Returns:
        Summary with counts of created, updated, skipped, and errored files.
    """
    try:
        results = sync_all(dry_run=False, filter_path=folder or None)
    except Exception as e:
        return f"Error: {e}"

    counts: dict[str, int] = {}
    errors: list[str] = []

    for r in results:
        status = r.get("status", "error")
        counts[status] = counts.get(status, 0) + 1
        if status == "error":
            label = r.get("title") or r.get("file", "?")
            errors.append(f"  {label}: {r.get('error', 'unknown error')}")

    lines = [
        f"Sync complete — {len(results)} files processed",
        f"  Created: {counts.get('created', 0)}",
        f"  Updated: {counts.get('updated', 0)}",
        f"  Skipped: {counts.get('skipped', 0)}",
        f"  Errors:  {counts.get('error', 0)}",
    ]
    if errors:
        lines.append("Errors:")
        lines.extend(errors)

    return "\n".join(lines)


# =============================================================================
# Resources
# =============================================================================

@mcp.resource("kb://{path}")
def kb_resource(path: str) -> str:
    """Read a KB file as an MCP resource.

    URI format: kb://relative/path/to/file.md
    Example:    kb://AI/3_methods/01_intro.md
    """
    if KB_ROOT is None:
        raise ValueError("KB_ROOT not configured. Check config.yaml or KB_ROOT env var.")

    file_path = KB_ROOT / path.lstrip("/")
    if not file_path.exists():
        raise FileNotFoundError(f"KB file not found: {path}")

    return file_path.read_text(encoding="utf-8")


# =============================================================================
# Prompts
# =============================================================================

@mcp.prompt()
def research_topic(topic: str, depth: str = "comprehensive") -> str:
    """Structured research workflow for adding new content to the knowledge base.

    Args:
        topic: The topic to research and write about
        depth: "quick" (300-500 words) | "standard" (800-1200) | "comprehensive" (1500-2500)
    """
    depth_instructions = {
        "quick":          "Write a concise 300-500 word overview covering the essentials.",
        "standard":       "Write a thorough 800-1200 word article with examples and context.",
        "comprehensive":  "Write an in-depth 1500-2500 word reference article with examples, "
                          "use cases, comparisons, and actionable takeaways.",
    }.get(depth, "Write a thorough, well-structured article.")

    return f"""You are a knowledge base researcher. Your task is to research and write
an article on: **{topic}**

## Step 1 — Search existing knowledge
Run: search_knowledge("{topic}")
Also try related terms. Check for duplicate or overlapping content.

## Step 2 — Review related files
Run: list_kb_files()
Read any closely related files with read_kb_file() to understand coverage gaps.

## Step 3 — Write the article
{depth_instructions}

### Required frontmatter (YAML)
```yaml
---
title: "<clear, descriptive title>"
excerpt: "<1-2 sentence summary for WordPress>"
tags: [tag1, tag2, tag3]
primary_keyword: "<main SEO keyword>"
meta_description: "<155-char meta description>"
semantic_summary: "<2-3 sentences optimized for vector search>"
synthetic_questions:
  - "Question 1 a reader would ask?"
  - "Question 2 a reader would ask?"
  - "Question 3 a reader would ask?"
key_concepts:
  - "Concept 1"
  - "Concept 2"
---
```

### Article structure
1. **Introduction** — what it is and why it matters
2. **Core Concepts** — key ideas explained clearly
3. **Practical Application** — how to use or implement
4. **Examples** — real-world scenarios
5. **Key Takeaways** — bullet-point summary

### Style rules
- Use Obsidian wiki-links for internal references: [[existing-article]]
- Avoid duplicating content already in the KB
- Focus on actionable, strategic value
- Include 2-3 synthetic_questions in frontmatter for RAG optimization

Begin by searching the knowledge base."""


@mcp.prompt()
def publish_article(file_path: str) -> str:
    """Guided workflow for publishing a KB article to WordPress and Pinecone.

    Args:
        file_path: Relative KB path to the article (e.g., "AI/3_methods/01_intro.md")
    """
    return f"""You are helping publish a KB article to WordPress and Pinecone.

**File:** {file_path}

## Step 1 — Read the file
Run: read_kb_file("{file_path}")

## Step 2 — Validate frontmatter
Check that these fields are present and complete:

| Field              | Required | Notes                              |
|--------------------|----------|------------------------------------|
| title              | ✅ Yes   | Clear, descriptive                 |
| excerpt            | ✅ Yes   | 1-2 sentence WordPress excerpt     |
| tags               | ✅ Yes   | At least 2-3 relevant tags         |
| primary_keyword    | ✅ Yes   | Main SEO keyword                   |
| meta_description   | Recommended | ≤155 chars                    |
| semantic_summary   | Recommended | Improves Pinecone search quality |
| synthetic_questions| Recommended | 2-3 questions for RAG           |

If any required fields are missing, suggest the content to add before publishing.

## Step 3 — Preview
Run: preview_sync()
Confirm the file appears in the preview list.

## Step 4 — Publish
Run: sync_single_file("{file_path}")

## Step 5 — Confirm
Report:
- ✅ Status: created / updated
- 🔗 WordPress URL
- 📌 Pinecone: upserted

If any step fails, report the error and suggest a fix."""


# =============================================================================
# Entry point
# =============================================================================

if __name__ == "__main__":
    mcp.run()
