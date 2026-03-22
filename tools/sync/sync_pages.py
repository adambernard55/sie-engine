# tools/sync/sync_pages.py
"""
Sync WordPress pages with parent/child hierarchy.

Push: Obsidian (.md) → WordPress pages
Pull: WordPress pages → Obsidian (.md) with nested folder structure
"""

import html as html_module
from pathlib import Path
from typing import Optional

from .base import (
    BaseSyncTool, SyncConfig,
    parse_frontmatter, html_to_markdown, build_pull_frontmatter,
    resolve_conflict, save_mapping, generate_slug,
)


class PageSync(BaseSyncTool):
    """Bidirectional sync for WordPress pages (hierarchical)."""

    def __init__(self, config: SyncConfig):
        super().__init__(config)
        self._page_tree: dict[int, dict] = {}  # id → page data cache

    def _build_page_tree(self, pages: list[dict]) -> None:
        """Cache all pages indexed by ID for hierarchy resolution."""
        self._page_tree = {p["id"]: p for p in pages}

    def _get_parent_slug_chain(self, page_id: int) -> list[str]:
        """Walk up the parent chain and return slugs from root to page."""
        chain = []
        current_id = page_id
        seen = set()

        while current_id and current_id not in seen:
            seen.add(current_id)
            page = self._page_tree.get(current_id)
            if not page:
                break
            chain.append(page.get("slug", ""))
            current_id = page.get("parent", 0)

        chain.reverse()
        return chain

    def _resolve_parent_id(self, parent_slug: str) -> Optional[int]:
        """Find a page ID by slug."""
        existing = self.wp.get_post_by_slug(parent_slug, post_type="pages")
        if existing:
            return existing["id"]
        return None

    # --- Push ---

    def push_file(self, file_path: Path, dry_run: bool = False) -> dict:
        result = {"file": str(file_path), "status": "pending",
                  "post_id": None, "pinecone": None, "error": None}

        try:
            frontmatter, html_content, raw_body = self._read_and_parse(file_path)
            if not html_content:
                result["status"] = "skipped"
                result["error"] = "No body content"
                return result

            meta = self._extract_metadata(frontmatter, file_path)

            if dry_run:
                result.update(status="dry_run", title=meta["title"],
                              slug=meta["slug"])
                return result

            # Build payload — pages don't use categories/tags
            payload = {
                "title": meta["title"],
                "content": html_content,
                "status": "publish",
            }
            if meta["excerpt"]:
                payload["excerpt"] = meta["excerpt"]
            if meta["updated"]:
                payload["date"] = meta["updated"]

            # Resolve parent page from frontmatter or folder structure
            parent_slug = frontmatter.get("parent_slug", "")
            if parent_slug:
                parent_id = self._resolve_parent_id(parent_slug)
                if parent_id:
                    payload["parent"] = parent_id

            # Find or create page
            existing = self._find_existing_post(meta["slug"], meta["title"], file_path)
            if existing:
                post = self.wp.update_post(existing["id"], payload, post_type="pages")
                result["status"] = "updated"
            else:
                post = self.wp.create_post(payload, post_type="pages")
                result["status"] = "created"

            result["post_id"] = post["id"]
            result["url"] = post.get("link", f"{self.config.wp_site_url}/{meta['slug']}/")
            result["title"] = meta["title"]

            # Rank Math SEO
            if meta["keyword"] or meta["meta_description"]:
                self.wp.update_rankmath_meta(
                    post["id"], meta["keyword"], meta["meta_description"])

            # No Pinecone for pages by default
            result["pinecone"] = "skipped (pages)"

        except Exception as e:
            result["status"] = "error"
            result["error"] = str(e)

        return result

    # --- Pull ---

    def pull_all(self, since: str = None, conflict: str = "newer",
                 dry_run: bool = False) -> list[dict]:
        endpoint = "/wp-json/wp/v2/pages"
        folder = self.config.filter_path or "01_Pages"
        for pt_cfg in self.config.import_config.get("post_types", []):
            if pt_cfg.get("type") == "page":
                endpoint = pt_cfg["endpoint"]
                folder = pt_cfg.get("folder", folder)
                break

        print(f"Pulling pages from {endpoint} → {folder}/")
        pages = self.wp.fetch_all(endpoint, since=since)
        print(f"  Fetched {len(pages)} pages")

        # Build tree for hierarchy resolution
        self._build_page_tree(pages)

        conflict_strategy = conflict or self.config.import_config.get(
            "conflict_strategy", "newer")
        results = []

        for page in pages:
            fm = build_pull_frontmatter(page, "page", self.wp)
            title = fm.get("title", "Untitled")
            slug = fm.get("slug", "untitled")
            wp_id = fm.get("wp_id")

            # Build nested folder path from page hierarchy
            parent_id = page.get("parent", 0)
            if parent_id and parent_id in self._page_tree:
                # Get parent slug chain (excluding the page itself)
                parent_chain = self._get_parent_slug_chain(parent_id)
                subfolder = "/".join(parent_chain)
                target_file = self.config.kb_root / folder / subfolder / f"{slug}.md"
            else:
                target_file = self.config.kb_root / folder / f"{slug}.md"

            # Store parent info in frontmatter
            if parent_id:
                parent_page = self._page_tree.get(parent_id)
                if parent_page:
                    fm["parent_slug"] = parent_page.get("slug", "")

            rel_path = str(target_file.relative_to(self.config.kb_root)).replace("\\", "/")

            # Convert body
            content_html = ""
            if isinstance(page.get("content"), dict):
                content_html = page["content"].get("rendered", "")
            body_md = html_to_markdown(content_html)

            action = resolve_conflict(target_file, page.get("modified", ""),
                                      conflict_strategy)

            result = {"file": rel_path, "title": title, "wp_id": wp_id,
                      "post_type": "page"}

            if action == "skip":
                result["status"] = "skipped"
                print(f"  [-] {rel_path}")
            elif dry_run:
                result["status"] = f"dry-run (would {'overwrite' if target_file.exists() else 'create'})"
                print(f"  [?] {rel_path}  ({title})")
            else:
                self._write_markdown_file(fm, body_md, target_file)
                result["status"] = "overwritten" if target_file.exists() else "created"
                result["slug"] = slug
                result["post_id"] = wp_id
                print(f"  [+] {rel_path}  ({title})")

            results.append(result)

        return results
