# tools/sync/sync_cpt.py
"""
Sync SIE custom post types (FAQ, Insight/Hat Tips, Guide).

All three CPTs share the same structure and sie_topic taxonomy,
so this single handler works for all of them. The specific CPT
is determined by the sync profile's wp_post_type setting.

Push: Obsidian (.md) → WordPress CPT + Pinecone
Pull: WordPress CPT → Obsidian (.md)
"""

from pathlib import Path
from typing import Optional

from .base import (
    BaseSyncTool, SyncConfig,
    parse_frontmatter, html_to_markdown, build_pull_frontmatter,
    get_all_topic_ids, resolve_conflict, save_mapping, sync_to_pinecone,
)


class CptSync(BaseSyncTool):
    """Bidirectional sync for SIE custom post types (FAQ, Insight, Guide)."""

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

            # Process tags
            tags = meta["tags"]
            if isinstance(tags, str):
                tags = [t.strip() for t in tags.split(",")]
            tag_ids = []
            for tag in tags:
                tid = self.wp.get_or_create_tag(tag)
                if tid:
                    tag_ids.append(tid)

            # Build payload with sie_topic taxonomy
            all_topic_ids = get_all_topic_ids(file_path, self.config)
            payload = {
                "title": meta["title"],
                "content": html_content,
                "status": "publish",
                self.config.wp_taxonomy: all_topic_ids,
                self.config.wp_tag_taxonomy: tag_ids,
            }
            if meta["excerpt"]:
                payload["excerpt"] = meta["excerpt"]
            if meta["updated"]:
                payload["date"] = meta["updated"]

            # ACF fields
            acf = {}
            if meta["semantic_summary"]:
                acf["semantic_summary"] = meta["semantic_summary"]
            if meta["synthetic_questions"]:
                acf["synthetic_questions"] = [
                    {"question": q} for q in meta["synthetic_questions"]
                ]
            if meta["key_concepts"]:
                acf["key_concepts"] = [
                    {"concept": c} for c in meta["key_concepts"]
                ]
            if acf:
                payload["acf"] = acf

            # Find or create post
            existing = self._find_existing_post(meta["slug"], meta["title"], file_path)
            if existing:
                post = self.wp.update_post(existing["id"], payload)
                result["status"] = "updated"
            else:
                post = self.wp.create_post(payload)
                result["status"] = "created"

            result["post_id"] = post["id"]
            result["url"] = post.get("link",
                f"{self.config.wp_site_url}/{self.config.url_prefix}/{meta['slug']}/")
            result["title"] = meta["title"]

            # Rank Math SEO
            if meta["keyword"] or meta["meta_description"]:
                self.wp.update_rankmath_meta(
                    post["id"], meta["keyword"], meta["meta_description"])

            # Pinecone
            result["pinecone"] = sync_to_pinecone(
                post["id"], meta["title"], raw_body, meta["slug"],
                self.config, tags=tags, key_concepts=meta["key_concepts"],
                synthetic_questions=meta["synthetic_questions"],
                semantic_summary=meta["semantic_summary"],
                updated=meta["updated"],
            )

        except Exception as e:
            result["status"] = "error"
            result["error"] = str(e)

        return result

    # --- Pull ---

    def pull_all(self, since: str = None, conflict: str = "newer",
                 dry_run: bool = False) -> list[dict]:
        # Find the matching import config by post type
        post_type = self.config.wp_post_type
        endpoint = f"/wp-json/wp/v2/{post_type}"
        folder = self.config.filter_path.rstrip("/") if self.config.filter_path else post_type.upper()

        for pt_cfg in self.config.import_config.get("post_types", []):
            cfg_type = pt_cfg.get("type", "")
            if cfg_type == post_type or cfg_type in ("sie_faq", "sie_insight", "sie_guide"):
                # Match by rest_base (faq, insights, guides)
                endpoint = pt_cfg["endpoint"]
                folder = pt_cfg.get("folder", folder)
                break

        print(f"Pulling {post_type} from {endpoint} → {folder}/")
        posts = self.wp.fetch_all(endpoint, since=since)
        print(f"  Fetched {len(posts)} items")

        conflict_strategy = conflict or self.config.import_config.get(
            "conflict_strategy", "newer")
        results = []

        for post in posts:
            fm = build_pull_frontmatter(post, post_type, self.wp)

            # Resolve sie_topics to names
            topic_ids = post.get("sie_topic") or post.get("sie_topics") or []
            if topic_ids:
                topic_names = self.wp.resolve_categories(
                    topic_ids, "/wp-json/wp/v2/sie-topics")
                if topic_names:
                    fm["sie_topics"] = topic_names

            title = fm.get("title", "Untitled")
            slug = fm.get("slug", "untitled")
            wp_id = fm.get("wp_id")

            # Convert body
            content_html = ""
            if isinstance(post.get("content"), dict):
                content_html = post["content"].get("rendered", "")
            body_md = html_to_markdown(content_html)

            target_file = self.config.kb_root / folder / f"{slug}.md"
            rel_path = str(target_file.relative_to(self.config.kb_root)).replace("\\", "/")

            action = resolve_conflict(target_file, post.get("modified", ""),
                                      conflict_strategy)

            result = {"file": rel_path, "title": title, "wp_id": wp_id,
                      "post_type": post_type}

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
