# tools/cleanup_wp_duplicates.py
"""
WordPress Duplicate Post Cleanup

Fetches all knowledge posts from WordPress, identifies duplicates by title,
and deletes the extras — keeping the original (oldest) post.

Also updates kb_sync_mapping.json to point at the surviving post IDs.

Usage:
    python cleanup_wp_duplicates.py --dry-run          # Preview what would be deleted
    python cleanup_wp_duplicates.py                    # Delete duplicates (with confirmation)
    python cleanup_wp_duplicates.py --force             # Delete without confirmation prompt
    python cleanup_wp_duplicates.py --keep newest       # Keep newest instead of oldest
"""

import os
import re
import sys
import json
import time
import yaml
import base64
import html as html_module
import requests
from pathlib import Path
from datetime import datetime
from typing import Optional
from collections import defaultdict
from dotenv import load_dotenv

# Fix Windows console encoding
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

load_dotenv()

# =============================================================================
# Configuration (reuses kb_sync pattern)
# =============================================================================

KB_ROOT: Optional[Path] = None
WP_SITE_URL: str = ""
WP_USERNAME: str = ""
WP_APP_PASSWORD: str = ""


def _find_config_file(explicit_path: str = None) -> Optional[Path]:
    if explicit_path:
        p = Path(explicit_path)
        if p.exists():
            return p
        raise FileNotFoundError(f"Config file not found: {explicit_path}")

    cwd_config = Path.cwd() / "config.yaml"
    if cwd_config.exists():
        return cwd_config

    project_config = Path(__file__).parent.parent / "config.yaml"
    if project_config.exists():
        return project_config

    return None


def _load_config(config_path: str = None) -> None:
    global KB_ROOT, WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD

    config_file = _find_config_file(config_path)
    config: dict = {}

    if config_file:
        with open(config_file, encoding="utf-8") as fh:
            config = yaml.safe_load(fh) or {}
        print(f"Config: {config_file}")

    kb_root_str = os.getenv("KB_ROOT") or config.get("kb_root")
    if not kb_root_str:
        raise ValueError("kb_root is not configured.")
    KB_ROOT = Path(kb_root_str).expanduser().resolve()

    WP_SITE_URL = os.getenv("WP_SITE_URL") or config.get("wp_site_url", "")
    WP_USERNAME = os.getenv("WP_USERNAME") or config.get("wp_username", "")
    WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")


# =============================================================================
# WordPress API
# =============================================================================

def make_session() -> requests.Session:
    auth = base64.b64encode(f"{WP_USERNAME}:{WP_APP_PASSWORD}".encode()).decode()
    session = requests.Session()
    session.headers.update({
        "Authorization": f"Basic {auth}",
        "Content-Type": "application/json",
        "User-Agent": "Mozilla/5.0 (compatible; SIE-Cleanup/1.0)",
        "X-SIE-Sync": "true",
    })
    return session


def fetch_all_knowledge_posts(session: requests.Session) -> list[dict]:
    """Paginated fetch of ALL knowledge posts (including drafts/trash)."""
    url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge"
    all_posts = []
    page = 1

    # Only fetch published posts — skip drafts (already unpublished by prior runs)
    for status in ["publish"]:
        page = 1
        while True:
            params = {"per_page": 100, "page": page, "status": status}
            try:
                resp = session.get(url, params=params, timeout=30)
            except requests.RequestException as e:
                print(f"  Error fetching page {page} (status={status}): {e}")
                break

            if resp.status_code == 400:
                break
            if resp.status_code >= 400:
                print(f"  HTTP {resp.status_code} on page {page} (status={status})")
                break

            posts = resp.json()
            if not posts:
                break

            all_posts.extend(posts)
            total_pages = int(resp.headers.get("X-WP-TotalPages", 1))

            if page >= total_pages:
                break
            page += 1

    return all_posts


def unpublish_post(session: requests.Session, post_id: int,
                   max_retries: int = 3) -> bool:
    """Set a WordPress post to draft status (unpublish).

    The Agents user can update post status but lacks delete_posts capability,
    so we unpublish duplicates by setting them to draft. They can then be
    bulk-deleted from WP Admin.
    """
    url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge/{post_id}"

    for attempt in range(max_retries):
        try:
            resp = session.post(url, json={"status": "draft"}, timeout=30)
            if resp.status_code == 200:
                return True
            if resp.status_code in (429, 503) and attempt < max_retries - 1:
                wait = 2 ** (attempt + 1)
                print(f"    Rate limited ({resp.status_code}), retrying in {wait}s...")
                time.sleep(wait)
                continue
            print(f"    HTTP {resp.status_code}: {resp.text[:200]}")
            return False
        except requests.RequestException as e:
            if attempt < max_retries - 1:
                time.sleep(2)
                continue
            print(f"    Network error: {e}")
            return False
    return False


# =============================================================================
# Duplicate Detection
# =============================================================================

def find_duplicates(posts: list[dict], keep: str = "oldest") -> dict:
    """Group posts by title and identify duplicates.

    Returns dict: {
        title: {
            "keep": post_dict,        # the one to keep
            "delete": [post_dict, ...]  # the ones to delete
        }
    }
    """
    # Group by normalized title
    by_title = defaultdict(list)
    for post in posts:
        title = html_module.unescape(
            post.get("title", {}).get("rendered", "")
        ).strip()
        if not title:
            title = f"(untitled-{post['id']})"
        by_title[title].append(post)

    duplicates = {}
    for title, group in by_title.items():
        if len(group) < 2:
            continue

        # Sort by post ID (lowest = oldest in WordPress)
        group.sort(key=lambda p: p["id"])

        if keep == "newest":
            keeper = group[-1]
            to_delete = group[:-1]
        else:  # oldest
            keeper = group[0]
            to_delete = group[1:]

        duplicates[title] = {
            "keep": keeper,
            "delete": to_delete,
        }

    return duplicates


# =============================================================================
# Mapping File Update
# =============================================================================

def update_mapping_for_survivors(duplicates: dict) -> int:
    """Update kb_sync_mapping.json so entries point to surviving post IDs.

    Returns count of entries updated.
    """
    mapping_file = KB_ROOT / "kb_sync_mapping.json"
    if not mapping_file.exists():
        print("  No mapping file found — skipping mapping update.")
        return 0

    try:
        mapping = json.loads(mapping_file.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        print("  Mapping file is corrupt — skipping mapping update.")
        return 0

    # Build a lookup: deleted_post_id → surviving_post_id
    redirect_map = {}
    for title, info in duplicates.items():
        survivor_id = info["keep"]["id"]
        survivor_slug = info["keep"].get("slug", "")
        survivor_link = info["keep"].get("link", "")
        for dup in info["delete"]:
            redirect_map[dup["id"]] = {
                "post_id": survivor_id,
                "slug": survivor_slug,
                "link": survivor_link,
            }

    updated = 0
    timestamp = datetime.now().isoformat()

    for rel_path, data in mapping.items():
        old_id = data.get("post_id")
        if old_id in redirect_map:
            survivor = redirect_map[old_id]
            data["post_id"] = survivor["post_id"]
            if survivor["slug"]:
                data["slug"] = survivor["slug"]
            if survivor["link"]:
                data["url"] = survivor["link"]
            data["last_synced"] = timestamp
            updated += 1

    if updated:
        mapping_file.write_text(
            json.dumps(mapping, indent=2, ensure_ascii=False),
            encoding="utf-8",
        )

    return updated


# =============================================================================
# Main
# =============================================================================

def run_cleanup(dry_run: bool = False, force: bool = False,
                keep: str = "oldest", config_path: str = None) -> None:
    _load_config(config_path)

    print("=" * 60)
    print("WordPress Duplicate Cleanup")
    print(f"Site: {WP_SITE_URL}")
    if dry_run:
        print("Mode: DRY RUN")
    print("=" * 60)

    session = make_session()

    # 1. Fetch all posts
    print("\nFetching all knowledge posts...")
    posts = fetch_all_knowledge_posts(session)
    print(f"  Total posts found: {len(posts)}")

    if not posts:
        print("No posts found. Check credentials and site URL.")
        return

    # 2. Find duplicates
    duplicates = find_duplicates(posts, keep=keep)

    if not duplicates:
        print("\nNo duplicates found. All clear!")
        return

    total_dupes = sum(len(d["delete"]) for d in duplicates.values())
    unique_titles = len(duplicates)

    print(f"\nDuplicate titles: {unique_titles}")
    print(f"Posts to delete:  {total_dupes}")
    print(f"Posts to keep:    {len(posts) - total_dupes}")
    print(f"Keep strategy:    {keep}")

    # 3. Show details
    print(f"\n{'─' * 60}")
    print("Duplicate details:")
    print(f"{'─' * 60}")

    for title, info in sorted(duplicates.items()):
        keep_post = info["keep"]
        delete_posts = info["delete"]
        copies = len(delete_posts) + 1

        print(f"\n  \"{title}\" ({copies} copies)")
        print(f"    KEEP:   ID {keep_post['id']}  slug={keep_post.get('slug', '?')}")
        for dup in delete_posts:
            print(f"    DELETE: ID {dup['id']}  slug={dup.get('slug', '?')}")

    if dry_run:
        print(f"\n{'=' * 60}")
        print(f"DRY RUN complete. Would delete {total_dupes} posts.")
        print("=" * 60)
        return

    # 4. Confirmation
    if not force:
        print(f"\n{'=' * 60}")
        print(f"About to PERMANENTLY DELETE {total_dupes} posts from WordPress.")
        print(f"This cannot be undone.")
        print(f"{'=' * 60}")
        answer = input("Type 'yes' to proceed: ").strip().lower()
        if answer != "yes":
            print("Aborted.")
            return

    # 5. Unpublish duplicates (set to draft)
    print(f"\nUnpublishing {total_dupes} duplicate posts (setting to draft)...")
    unpublished = 0
    failed = 0

    for title, info in duplicates.items():
        for dup in info["delete"]:
            success = unpublish_post(session, dup["id"])
            if success:
                unpublished += 1
                if unpublished % 50 == 0:
                    print(f"  Progress: {unpublished}/{total_dupes} unpublished...")
            else:
                failed += 1
                print(f"  FAILED  ID {dup['id']}: {title}")
            # Delay to avoid WAF/rate limiting (Cloudflare)
            time.sleep(0.5)

    # 6. Update mapping file
    print("\nUpdating kb_sync_mapping.json...")
    mapping_updated = update_mapping_for_survivors(duplicates)
    print(f"  Mapping entries updated: {mapping_updated}")

    # 7. Summary
    print(f"\n{'=' * 60}")
    print("Cleanup Summary")
    print("=" * 60)
    print(f"Posts unpublished:  {unpublished}")
    print(f"Failures:           {failed}")
    print(f"Mapping updated:    {mapping_updated} entries")
    print(f"Published remaining: {len(posts) - unpublished}")
    if unpublished:
        print(f"\nDraft posts can be bulk-deleted from WP Admin:")
        print(f"  {WP_SITE_URL}/wp-admin/edit.php?post_type=knowledge_base&post_status=draft")


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Delete duplicate WordPress knowledge posts"
    )
    parser.add_argument(
        "--config", type=str,
        help="Path to config.yaml"
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Preview duplicates without deleting"
    )
    parser.add_argument(
        "--force", action="store_true",
        help="Skip confirmation prompt"
    )
    parser.add_argument(
        "--keep", choices=["oldest", "newest"], default="oldest",
        help="Which copy to keep (default: oldest)"
    )

    args = parser.parse_args()

    run_cleanup(
        dry_run=args.dry_run,
        force=args.force,
        keep=args.keep,
        config_path=args.config,
    )
