"""
Rebuild kb_sync_mapping.json by fetching all WordPress knowledge posts
and matching them to local files. Also identifies and deletes duplicates.

Usage:
    python rebuild_mapping.py --analyze          # Show what would happen (no changes)
    python rebuild_mapping.py --delete-dupes     # Delete duplicate posts from WordPress
    python rebuild_mapping.py --rebuild           # Rebuild mapping file from WordPress
    python rebuild_mapping.py --full              # Delete dupes + rebuild mapping
"""

import os
import sys
import json
import re
import base64
import argparse
from pathlib import Path
from datetime import datetime
from collections import defaultdict

import requests
from dotenv import load_dotenv

# Fix Windows console encoding
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

load_dotenv()

# ── Config ───────────────────────────────────────────────────────────────────

WP_SITE_URL = os.getenv("WP_SITE_URL", "https://adambernard.com").rstrip("/")
WP_USERNAME = os.getenv("WP_USERNAME", "Agents")
WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")

# KB root — try env var, then config.yaml, then common paths
KB_ROOT = None
kb_env = os.getenv("KB_ROOT")
if kb_env:
    KB_ROOT = Path(kb_env).expanduser().resolve()
else:
    for candidate in [
        Path.home() / "Documents/Brain/Adam/kb",
        Path.cwd() / "kb-vault/kb",
    ]:
        if candidate.exists():
            KB_ROOT = candidate
            break

if not KB_ROOT or not KB_ROOT.exists():
    print(f"ERROR: KB_ROOT not found. Set KB_ROOT env var or check paths.")
    sys.exit(1)

auth_header = base64.b64encode(f"{WP_USERNAME}:{WP_APP_PASSWORD}".encode()).decode()
session = requests.Session()
session.headers.update({
    "Authorization": f"Basic {auth_header}",
    "Content-Type": "application/json"
})


# ── Helper: slug generation (must match kb_sync.py) ─────────────────────────

def generate_slug(text: str) -> str:
    slug = text.lower()
    slug = re.sub(r'[\u2010-\u2015\u2212]', '-', slug)
    slug = slug.replace('.', '-').replace('/', '-')
    slug = re.sub(r'[^a-z0-9\s-]', '', slug)
    slug = re.sub(r'[\s_]+', '-', slug)
    slug = re.sub(r'-+', '-', slug)
    return slug.strip('-')


def slug_from_filename(filename: str) -> str:
    name = filename.replace('.md', '')
    name = re.sub(r'^[0-9]+[_-]', '', name)
    return generate_slug(name)


def generate_path_slug(file_path: Path, kb_root: Path) -> str:
    rel_path = file_path.relative_to(kb_root)
    dir_parts = list(rel_path.parent.parts)
    clean_dirs = []
    for part in dir_parts:
        clean = re.sub(r'^[0-9]+[_-]', '', part)
        clean = clean.lower()
        clean = generate_slug(clean)
        if clean:
            clean_dirs.append(clean)
    file_slug = slug_from_filename(file_path.name)
    if clean_dirs:
        return "/".join(clean_dirs) + "/" + file_slug
    return file_slug


# ── Fetch all WP knowledge posts ────────────────────────────────────────────

def fetch_all_wp_posts() -> list[dict]:
    """Fetch every knowledge post from WordPress (paginated)."""
    all_posts = []
    page = 1
    per_page = 100

    print("Fetching all knowledge posts from WordPress...")
    while True:
        url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge"
        resp = session.get(url, params={
            "per_page": per_page,
            "page": page,
        })

        if resp.status_code == 400:
            # No more pages
            break

        if resp.status_code != 200:
            print(f"  ERROR page {page}: {resp.status_code} {resp.text[:200]}")
            break

        posts = resp.json()
        if not posts:
            break

        all_posts.extend(posts)
        total = resp.headers.get("X-WP-Total", "?")
        total_pages = resp.headers.get("X-WP-TotalPages", "?")
        print(f"  Page {page}/{total_pages} — {len(posts)} posts (total: {total})")

        if len(posts) < per_page:
            break
        page += 1

    print(f"  Total fetched: {len(all_posts)}")
    return all_posts


# ── Build file index ─────────────────────────────────────────────────────────

def build_file_index() -> dict:
    """
    Build lookup indexes from local markdown files.
    Returns dict with:
      - by_path_slug: {path_slug: file_path}
      - by_filename_slug: {filename_slug: [file_paths]}
      - by_title_slug: {title_slug: [file_paths]}
    """
    by_path_slug = {}
    by_filename_slug = defaultdict(list)

    for md_file in KB_ROOT.rglob("*.md"):
        # Skip files that kb_sync.py would skip
        if md_file.name == "index.md":
            continue
        if md_file.stem == md_file.parent.name:
            continue
        if any(part.startswith('.') for part in md_file.parts):
            continue

        path_slug = generate_path_slug(md_file, KB_ROOT)
        fname_slug = slug_from_filename(md_file.name)

        by_path_slug[path_slug] = md_file
        by_filename_slug[fname_slug].append(md_file)

    return {
        "by_path_slug": by_path_slug,
        "by_filename_slug": dict(by_filename_slug),
    }


# ── Match WP posts to local files ───────────────────────────────────────────

def match_posts_to_files(wp_posts: list[dict], file_index: dict, existing_mapping: dict) -> dict:
    """
    Match each WordPress post to a local file.

    Returns dict keyed by post_id with:
      - post: the WP post dict
      - matched_file: relative path or None
      - match_method: how it was matched
    """
    results = {}
    # Reverse the existing mapping: post_id -> rel_path
    id_to_path = {}
    for rel_path, data in existing_mapping.items():
        pid = data.get("post_id")
        if pid:
            id_to_path[pid] = rel_path

    for post in wp_posts:
        pid = post["id"]
        slug = post["slug"]
        title = post.get("title", {}).get("rendered", "")

        matched_file = None
        match_method = None

        # Method 1: existing mapping (by post_id)
        if pid in id_to_path:
            rel = id_to_path[pid]
            full = KB_ROOT / rel
            if full.exists():
                matched_file = rel
                match_method = "mapping"

        # Method 2: path slug match (new-style slug in WordPress)
        if not matched_file and slug in file_index["by_path_slug"]:
            fp = file_index["by_path_slug"][slug]
            matched_file = str(fp.relative_to(KB_ROOT)).replace("\\", "/")
            match_method = "path_slug"

        # Method 3: slug matches a filename slug
        if not matched_file:
            # Try the slug directly as a filename slug
            for fname_slug, files in file_index["by_filename_slug"].items():
                if slug == fname_slug or slug.endswith("/" + fname_slug):
                    if len(files) == 1:
                        matched_file = str(files[0].relative_to(KB_ROOT)).replace("\\", "/")
                        match_method = "filename_slug"
                    break

        # Method 4: slug is contained in a path slug
        if not matched_file:
            for path_slug, fp in file_index["by_path_slug"].items():
                if path_slug.endswith("/" + slug) or path_slug == slug:
                    matched_file = str(fp.relative_to(KB_ROOT)).replace("\\", "/")
                    match_method = "partial_slug"
                    break

        # Method 5: title-based match (exact slug match only, no prefix matching)
        if not matched_file and title:
            title_slug = generate_slug(title)
            for path_slug, fp in file_index["by_path_slug"].items():
                file_part = path_slug.split("/")[-1]
                # Only match if the title slug IS the file slug (exact) or
                # the title slug equals the full path slug
                if file_part == title_slug or path_slug == title_slug:
                    matched_file = str(fp.relative_to(KB_ROOT)).replace("\\", "/")
                    match_method = "title_match"
                    break

        # Method 6: check if WP slug matches file slug from frontmatter
        if not matched_file:
            # Try reading frontmatter slug from local files
            for path_slug, fp in file_index["by_path_slug"].items():
                try:
                    content = fp.read_text(encoding="utf-8")
                    import yaml as _yaml
                    lines = content.split("\n")
                    if lines and re.match(r'^---\s*$', lines[0]):
                        end_idx = None
                        for i, line in enumerate(lines[1:], start=1):
                            if re.match(r'^---\s*$', line):
                                end_idx = i
                                break
                        if end_idx:
                            fm_text = '\n'.join(lines[1:end_idx])
                            fm = _yaml.safe_load(fm_text) or {}
                            fm_slug = fm.get("slug", "")
                            if fm_slug and fm_slug == slug:
                                matched_file = str(fp.relative_to(KB_ROOT)).replace("\\", "/")
                                match_method = "frontmatter_slug"
                                break
                except Exception:
                    continue

        results[pid] = {
            "post": post,
            "matched_file": matched_file,
            "match_method": match_method,
        }

    return results


# ── Identify duplicates ──────────────────────────────────────────────────────

def find_duplicates(match_results: dict) -> dict:
    """
    Find files that have multiple WordPress posts.
    Returns {rel_path: [post_ids]} for files with >1 post.
    """
    file_to_posts = defaultdict(list)

    for pid, data in match_results.items():
        if data["matched_file"]:
            file_to_posts[data["matched_file"]].append({
                "post_id": pid,
                "slug": data["post"]["slug"],
                "date": data["post"].get("date", ""),
                "modified": data["post"].get("modified", ""),
                "status": data["post"].get("status", ""),
                "match_method": data["match_method"],
            })

    # Only return entries with duplicates
    return {f: posts for f, posts in file_to_posts.items() if len(posts) > 1}


# ── Delete posts ─────────────────────────────────────────────────────────────

def delete_post(post_id: int) -> tuple[bool, str]:
    """Delete a WordPress knowledge post permanently."""
    import time
    url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge/{post_id}?force=true"
    try:
        resp = session.delete(url)
        if resp.status_code in (200, 201):
            return True, "ok"
        else:
            return False, f"HTTP {resp.status_code}: {resp.text[:200]}"
    except Exception as e:
        return False, str(e)


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="Rebuild KB sync mapping and clean duplicates")
    parser.add_argument("--analyze", action="store_true", help="Analyze only — no changes")
    parser.add_argument("--delete-dupes", action="store_true", help="Delete duplicate posts")
    parser.add_argument("--rebuild", action="store_true", help="Rebuild mapping file")
    parser.add_argument("--full", action="store_true", help="Delete dupes + rebuild mapping")
    args = parser.parse_args()

    if not any([args.analyze, args.delete_dupes, args.rebuild, args.full]):
        args.analyze = True
        print("No action specified — running in analyze mode.\n")

    # Load existing mapping
    mapping_file = KB_ROOT / "kb_sync_mapping.json"
    existing_mapping = {}
    if mapping_file.exists():
        existing_mapping = json.loads(mapping_file.read_text(encoding="utf-8"))

    print(f"KB Root: {KB_ROOT}")
    print(f"WordPress: {WP_SITE_URL}")
    print(f"Existing mapping entries: {len(existing_mapping)}")
    print()

    # Fetch all WP posts
    wp_posts = fetch_all_wp_posts()
    print()

    # Build file index
    file_index = build_file_index()
    total_files = len(file_index["by_path_slug"])
    print(f"Local markdown files (syncable): {total_files}")
    print()

    # Match posts to files
    match_results = match_posts_to_files(wp_posts, file_index, existing_mapping)

    # Stats
    matched = sum(1 for r in match_results.values() if r["matched_file"])
    unmatched = sum(1 for r in match_results.values() if not r["matched_file"])
    methods = defaultdict(int)
    for r in match_results.values():
        if r["match_method"]:
            methods[r["match_method"]] += 1

    print("=" * 60)
    print("MATCHING RESULTS")
    print("=" * 60)
    print(f"WP posts matched to files: {matched}")
    print(f"WP posts with NO file match: {unmatched}")
    print(f"Match methods: {dict(methods)}")
    print()

    # Show unmatched posts (orphans in WordPress)
    if unmatched:
        print(f"--- Unmatched WP posts (orphans) ---")
        for pid, data in sorted(match_results.items()):
            if not data["matched_file"]:
                p = data["post"]
                print(f"  ID {pid}: slug={p['slug']}  title={p.get('title',{}).get('rendered','?')[:60]}")
        print()

    # ── Find ALL duplicates: both multi-matched files AND orphan slug duplicates ──

    # Method 1: files matched to multiple WP posts
    dupes = find_duplicates(match_results)
    to_delete = []

    if dupes:
        print("=" * 60)
        print(f"MULTI-MATCHED FILES: {len(dupes)} files with multiple WP posts")
        print("=" * 60)

        for rel_path, posts in sorted(dupes.items()):
            posts_sorted = sorted(posts, key=lambda p: p["post_id"])
            keep = posts_sorted[0]
            delete = posts_sorted[1:]

            print(f"\n  {rel_path}")
            print(f"    KEEP:   ID {keep['post_id']} (slug={keep['slug']})")
            for d in delete:
                print(f"    DELETE: ID {d['post_id']} (slug={d['slug']})")
                to_delete.append(d["post_id"])

    # Method 2: orphan posts whose base slug matches a matched post
    # e.g. "some-article-2" is a dupe of "some-article"
    matched_slugs = {}
    for pid, data in match_results.items():
        if data["matched_file"]:
            matched_slugs[data["post"]["slug"]] = pid

    orphan_dupes = []
    orphan_unknown = []

    for pid, data in sorted(match_results.items()):
        if data["matched_file"]:
            continue

        slug = data["post"]["slug"]
        # Strip WordPress auto-increment suffix (-2, -3, etc.)
        base_slug = re.sub(r'-(\d+)$', '', slug)
        suffix_match = re.search(r'-(\d+)$', slug)

        if suffix_match and base_slug in matched_slugs:
            # Clear duplicate — base slug exists as a matched post
            orphan_dupes.append(pid)
            to_delete.append(pid)
        elif base_slug in matched_slugs:
            # Same slug as a matched post (shouldn't happen, but just in case)
            orphan_dupes.append(pid)
            to_delete.append(pid)
        else:
            orphan_unknown.append(pid)

    if orphan_dupes:
        print()
        print("=" * 60)
        print(f"ORPHAN DUPLICATES (slug-N pattern): {len(orphan_dupes)} posts")
        print("=" * 60)
        for pid in orphan_dupes:
            p = match_results[pid]["post"]
            print(f"  DELETE: ID {pid} slug={p['slug']}")

    if orphan_unknown:
        print()
        print("=" * 60)
        print(f"UNMATCHED POSTS (no clear original): {len(orphan_unknown)} posts")
        print("  These may be new content from the sync that don't have local files,")
        print("  or they may be duplicates whose original was also unmatched.")
        print("=" * 60)

        # Group unknowns by base slug to find clusters
        unknown_by_base = defaultdict(list)
        for pid in orphan_unknown:
            slug = match_results[pid]["post"]["slug"]
            base_slug = re.sub(r'-(\d+)$', '', slug)
            unknown_by_base[base_slug].append(pid)

        for base_slug, pids in sorted(unknown_by_base.items()):
            if len(pids) > 1:
                # Multiple posts with same base slug — keep oldest, delete rest
                pids_sorted = sorted(pids)
                keep_pid = pids_sorted[0]
                p = match_results[keep_pid]["post"]
                print(f"\n  Base: {base_slug} ({len(pids)} posts)")
                print(f"    KEEP:   ID {keep_pid} slug={p['slug']}")
                for dpid in pids_sorted[1:]:
                    dp = match_results[dpid]["post"]
                    print(f"    DELETE: ID {dpid} slug={dp['slug']}")
                    to_delete.append(dpid)
            else:
                p = match_results[pids[0]]["post"]
                print(f"  KEEP (unique): ID {pids[0]} slug={p['slug']}")

    print()
    print(f"{'=' * 60}")
    print(f"TOTAL POSTS TO DELETE: {len(to_delete)}")
    print(f"{'=' * 60}")
    print()

    # Delete duplicates
    if to_delete and (args.delete_dupes or args.full):
        import time
        print(f"DELETING {len(to_delete)} DUPLICATES...")

        # Use a fresh session for deletes (avoids stale auth issues)
        del_session = requests.Session()
        del_auth = base64.b64encode(f"{WP_USERNAME}:{WP_APP_PASSWORD}".encode()).decode()
        del_session.headers.update({
            "Authorization": f"Basic {del_auth}",
            "Content-Type": "application/json"
        })

        deleted = 0
        failed = 0
        for i, pid in enumerate(to_delete):
            url = f"{WP_SITE_URL}/wp-json/wp/v2/knowledge/{pid}?force=true"
            try:
                resp = del_session.delete(url)
                if resp.status_code in (200, 201):
                    deleted += 1
                else:
                    print(f"  FAILED {pid}: HTTP {resp.status_code}: {resp.text[:200]}")
                    failed += 1
            except Exception as e:
                print(f"  FAILED {pid}: {e}")
                failed += 1
            if (i + 1) % 20 == 0:
                print(f"  Progress: {i+1}/{len(to_delete)} (deleted={deleted})")
                time.sleep(0.5)
        print(f"\nDeleted: {deleted}, Failed: {failed}")
        print()

    # Rebuild mapping
    if args.rebuild or args.full:
        print("=" * 60)
        print("REBUILDING MAPPING FILE")
        print("=" * 60)

        # Re-fetch if we deleted posts, to get clean state
        if args.delete_dupes or args.full:
            wp_posts = fetch_all_wp_posts()
            match_results = match_posts_to_files(wp_posts, file_index, existing_mapping)

        new_mapping = {}
        file_to_best_post = {}

        # For each matched file, pick the best (oldest) post
        for pid, data in match_results.items():
            rel = data["matched_file"]
            if not rel:
                continue
            post = data["post"]
            if rel not in file_to_best_post or pid < file_to_best_post[rel]["post"]["id"]:
                file_to_best_post[rel] = data

        for rel, data in sorted(file_to_best_post.items()):
            post = data["post"]
            new_mapping[rel] = {
                "post_id": post["id"],
                "slug": post["slug"],
                "url": post.get("link", f"{WP_SITE_URL}/kb/{post['slug']}/"),
                "title": post.get("title", {}).get("rendered", ""),
                "last_synced": datetime.now().isoformat()
            }

        # Write mapping
        mapping_file.write_text(
            json.dumps(new_mapping, indent=2, ensure_ascii=False),
            encoding="utf-8"
        )

        mapped_files = len(new_mapping)
        unmapped_files = total_files - mapped_files

        print(f"Mapping saved: {mapping_file}")
        print(f"  Entries: {mapped_files}")
        print(f"  Files still unmapped (will be created on next sync): {unmapped_files}")
        print()

        # Show unmapped files
        if unmapped_files > 0:
            print(f"--- Unmapped files (new, no WP post yet) ---")
            mapped_set = set(new_mapping.keys())
            count = 0
            for path_slug, fp in sorted(file_index["by_path_slug"].items()):
                rel = str(fp.relative_to(KB_ROOT)).replace("\\", "/")
                if rel not in mapped_set:
                    print(f"  {rel}")
                    count += 1
                    if count >= 20:
                        print(f"  ... and {unmapped_files - 20} more")
                        break


if __name__ == "__main__":
    main()
