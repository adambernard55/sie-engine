# tools/wp_import.py
"""
WordPress Import Tool
Pulls WordPress content (posts, pages, custom post types, WooCommerce products)
into Obsidian markdown files with YAML frontmatter.

Flow: WordPress (REST API) → Obsidian (.md)

Designed to complement kb_sync.py (Obsidian → WordPress).
After initial import, use kb_sync.py for ongoing sync.
"""

import os
import re
import sys
import json
import yaml
import base64
import html as html_module
import requests
from pathlib import Path
from datetime import datetime
from typing import Optional
from dotenv import load_dotenv

# Fix Windows console encoding for Unicode characters
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

# Load environment variables
load_dotenv()

# =============================================================================
# Configuration
# =============================================================================

# Module-level globals — populated by _load_config().
KB_ROOT: Optional[Path] = None
WP_SITE_URL: str = ""
WP_USERNAME: str = ""
WP_APP_PASSWORD: str = ""
WC_CONSUMER_KEY: str = ""
WC_CONSUMER_SECRET: str = ""
TOPIC_MAPPING: dict = {}
IMPORT_CONFIG: dict = {}


def _find_config_file(explicit_path: str = None) -> Optional[Path]:
    """Locate config.yaml using a priority chain.

    1. Explicit --config path (if given)
    2. Current working directory  <- instance root in hub-and-spoke
    3. Project root (two levels above this file: sie-agent-loop/)
    """
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
    """Load config.yaml and initialise module-level settings.

    Priority for each value:
      config.yaml  >  environment variable  >  built-in default

    Credentials (WP_APP_PASSWORD, WC keys) are always read from .env only.
    """
    global KB_ROOT, WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD
    global WC_CONSUMER_KEY, WC_CONSUMER_SECRET
    global TOPIC_MAPPING, IMPORT_CONFIG

    config_file = _find_config_file(config_path)
    config: dict = {}

    if config_file:
        with open(config_file, encoding="utf-8") as fh:
            config = yaml.safe_load(fh) or {}
        print(f"Config: {config_file}")

    # KB root
    kb_root_str = os.getenv("KB_ROOT") or config.get("kb_root")
    if not kb_root_str:
        raise ValueError(
            "kb_root is not configured. "
            "Set it in config.yaml or as the KB_ROOT environment variable."
        )
    KB_ROOT = Path(kb_root_str).expanduser().resolve()

    # WordPress credentials
    WP_SITE_URL = os.getenv("WP_SITE_URL") or config.get("wp_site_url", "")
    WP_USERNAME = os.getenv("WP_USERNAME") or config.get("wp_username", "")
    WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")

    # WooCommerce credentials (.env only)
    WC_CONSUMER_KEY = os.getenv("WC_CONSUMER_KEY", "")
    WC_CONSUMER_SECRET = os.getenv("WC_CONSUMER_SECRET", "")

    # Topic mapping (from config.yaml — used for reverse lookup)
    TOPIC_MAPPING = config.get("topic_mapping") or {}

    # Import config
    IMPORT_CONFIG = config.get("import") or {}


# =============================================================================
# WordPress Fetcher (Read-Only Client)
# =============================================================================

class WordPressFetcher:
    """Read-only WordPress REST API client for fetching content."""

    def __init__(self, site_url: str, username: str, app_password: str,
                 wc_key: str = "", wc_secret: str = ""):
        self.site_url = site_url.rstrip("/")
        self.auth_header = base64.b64encode(
            f"{username}:{app_password}".encode()
        ).decode()
        self.wc_key = wc_key
        self.wc_secret = wc_secret

        self.session = requests.Session()
        self.session.headers.update({
            "User-Agent": "Mozilla/5.0 (compatible; SIE-WPImport/1.0)",
        })

        # Caches for tag/category resolution
        self._tag_cache: dict[int, str] = {}
        self._category_cache: dict[int, str] = {}

    def _basic_auth_headers(self) -> dict:
        return {
            "Authorization": f"Basic {self.auth_header}",
        }

    def fetch_all(self, endpoint: str, auth_type: str = "basic",
                  since: str = None) -> list[dict]:
        """Paginated REST API fetch. Returns all items from the endpoint.

        Args:
            endpoint: Full REST API path (e.g. /wp-json/wp/v2/posts)
            auth_type: "basic" for WP Basic Auth, "woocommerce" for WC keys
            since: ISO date string — only fetch items modified after this date
        """
        url = f"{self.site_url}{endpoint}"
        all_items = []
        page = 1

        while True:
            params = {"per_page": 100, "page": page}

            if since:
                params["modified_after"] = f"{since}T00:00:00"

            if auth_type == "woocommerce":
                params["consumer_key"] = self.wc_key
                params["consumer_secret"] = self.wc_secret
                headers = {}
            else:
                headers = self._basic_auth_headers()

            try:
                response = self.session.get(url, params=params, headers=headers,
                                            timeout=30)
            except requests.RequestException as e:
                print(f"  Error fetching {endpoint} page {page}: {e}")
                break

            if response.status_code == 400:
                # WP returns 400 when page exceeds total — we're done
                break
            if response.status_code >= 400:
                print(f"  Error {response.status_code} fetching {endpoint}: "
                      f"{response.text[:200]}")
                break

            items = response.json()
            if not items:
                break

            all_items.extend(items)
            total_pages = int(response.headers.get("X-WP-TotalPages", 1))

            if page >= total_pages:
                break

            page += 1

        return all_items

    def resolve_tags(self, tag_ids: list[int], taxonomy_endpoint: str = None) -> list[str]:
        """Resolve tag IDs to tag names, with caching."""
        if not tag_ids:
            return []

        endpoint = taxonomy_endpoint or "/wp-json/wp/v2/tags"
        names = []

        # Find IDs not yet cached
        uncached = [tid for tid in tag_ids if tid not in self._tag_cache]

        if uncached:
            # Fetch in batches of 100
            for i in range(0, len(uncached), 100):
                batch = uncached[i:i + 100]
                url = f"{self.site_url}{endpoint}"
                params = {"include": ",".join(str(t) for t in batch), "per_page": 100}
                try:
                    resp = self.session.get(url, headers=self._basic_auth_headers(),
                                            params=params, timeout=15)
                    if resp.status_code == 200:
                        for tag in resp.json():
                            self._tag_cache[tag["id"]] = html_module.unescape(
                                tag.get("name", "")
                            )
                except requests.RequestException:
                    pass

        for tid in tag_ids:
            name = self._tag_cache.get(tid)
            if name:
                names.append(name)

        return names

    def resolve_categories(self, cat_ids: list[int],
                           taxonomy_endpoint: str = None) -> list[str]:
        """Resolve category IDs to category names, with caching."""
        if not cat_ids:
            return []

        endpoint = taxonomy_endpoint or "/wp-json/wp/v2/categories"
        names = []

        uncached = [cid for cid in cat_ids if cid not in self._category_cache]

        if uncached:
            for i in range(0, len(uncached), 100):
                batch = uncached[i:i + 100]
                url = f"{self.site_url}{endpoint}"
                params = {"include": ",".join(str(c) for c in batch), "per_page": 100}
                try:
                    resp = self.session.get(url, headers=self._basic_auth_headers(),
                                            params=params, timeout=15)
                    if resp.status_code == 200:
                        for cat in resp.json():
                            self._category_cache[cat["id"]] = html_module.unescape(
                                cat.get("name", "")
                            )
                except requests.RequestException:
                    pass

        for cid in cat_ids:
            name = self._category_cache.get(cid)
            if name:
                names.append(name)

        return names


# =============================================================================
# HTML → Markdown Conversion
# =============================================================================

def html_to_markdown(html_content: str) -> str:
    """Convert WordPress HTML to clean markdown.

    - Strips WordPress block editor comments (<!-- wp:* -->)
    - Removes common page builder shortcodes
    - Uses markdownify for HTML → Markdown conversion
    """
    from markdownify import markdownify as md

    if not html_content:
        return ""

    text = html_content

    # Strip WordPress block editor comments (<!-- wp:paragraph -->, etc.)
    text = re.sub(r'<!--\s*/?wp:\S.*?-->', '', text, flags=re.DOTALL)

    # Strip common page builder shortcodes
    # [vc_*], [fusion_*], [et_pb_*], [elementor], [wpbakery], etc.
    text = re.sub(
        r'\[/?(?:vc_|fusion_|et_pb_|elementor|wpbakery|rev_slider|fl_builder)'
        r'[^\]]*\]',
        '', text
    )

    # Convert HTML to markdown using markdownify
    result = md(
        text,
        heading_style="ATX",
        code_language_callback=None,
        strip=["script", "style"],
        convert=["p", "h1", "h2", "h3", "h4", "h5", "h6",
                 "strong", "em", "a", "ul", "ol", "li",
                 "blockquote", "pre", "code", "img", "br",
                 "table", "thead", "tbody", "tr", "th", "td"],
    )

    # Clean up excessive blank lines
    result = re.sub(r'\n{3,}', '\n\n', result)

    return result.strip()


# =============================================================================
# Frontmatter Builder
# =============================================================================

def build_frontmatter(post: dict, post_type: str, fetcher: WordPressFetcher) -> dict:
    """Build YAML frontmatter dict from a WordPress post/page/product.

    Matches the schema used by kb_sync.py so round-tripping works.
    """
    fm = {}

    # --- Core fields ---
    title = html_module.unescape(
        post.get("title", {}).get("rendered", "") if isinstance(post.get("title"), dict)
        else post.get("title", "")
    )
    fm["title"] = title

    slug = post.get("slug", "")
    if slug:
        fm["slug"] = slug

    fm["status"] = post.get("status", "publish")

    # Dates
    date_str = post.get("date", "")
    if date_str:
        fm["date"] = date_str[:10]  # YYYY-MM-DD

    modified_str = post.get("modified", "")
    if modified_str:
        fm["updated"] = modified_str[:10]

    # WordPress ID (for mapping)
    wp_id = post.get("id")
    if wp_id:
        fm["wp_id"] = wp_id

    # Excerpt
    excerpt = ""
    if isinstance(post.get("excerpt"), dict):
        excerpt = post["excerpt"].get("rendered", "")
    elif isinstance(post.get("excerpt"), str):
        excerpt = post["excerpt"]
    # Strip HTML from excerpt
    if excerpt:
        excerpt = re.sub(r'<[^>]+>', '', excerpt).strip()
        excerpt = html_module.unescape(excerpt)
        if excerpt:
            fm["excerpt"] = excerpt

    # --- Tags ---
    tag_ids = post.get("tags") or post.get("knowledge_tag") or []
    if tag_ids:
        tag_endpoint = None
        if post_type == "knowledge":
            tag_endpoint = "/wp-json/wp/v2/knowledge_tag"
        names = fetcher.resolve_tags(tag_ids, tag_endpoint)
        if names:
            fm["tags"] = names

    # --- SEO (Rank Math fields from yoast_head_json or meta) ---
    meta = post.get("meta", {}) or {}

    # Rank Math stores these in post meta
    focus_kw = meta.get("rank_math_focus_keyword", "")
    if focus_kw:
        fm["primary_keyword"] = focus_kw

    meta_desc = meta.get("rank_math_description", "")
    if meta_desc:
        fm["meta_description"] = meta_desc

    # Also check yoast_head_json as fallback
    yoast = post.get("yoast_head_json", {}) or {}
    if not focus_kw and yoast.get("title"):
        pass  # don't infer keyword from title
    if not meta_desc and yoast.get("description"):
        fm["meta_description"] = yoast["description"]

    # --- RAG fields (ACF) ---
    acf = post.get("acf", {}) or {}

    semantic_summary = acf.get("semantic_summary", "")
    if semantic_summary:
        fm["semantic_summary"] = semantic_summary

    synthetic_questions = acf.get("synthetic_questions")
    if synthetic_questions:
        # ACF repeater: list of {question: "..."} dicts
        if isinstance(synthetic_questions, list):
            questions = []
            for item in synthetic_questions:
                if isinstance(item, dict):
                    q = item.get("question", "")
                    if q:
                        questions.append(q)
                elif isinstance(item, str) and item:
                    questions.append(item)
            if questions:
                fm["synthetic_questions"] = questions

    key_concepts = acf.get("key_concepts")
    if key_concepts:
        if isinstance(key_concepts, list):
            concepts = []
            for item in key_concepts:
                if isinstance(item, dict):
                    c = item.get("concept", "")
                    if c:
                        concepts.append(c)
                elif isinstance(item, str) and item:
                    concepts.append(item)
            if concepts:
                fm["key_concepts"] = concepts

    # --- Topic (for knowledge posts) ---
    topics = post.get("knowledge_topic") or post.get("topics") or []
    if topics and isinstance(topics, list):
        # Store the first topic ID
        fm["topic"] = topics[0]

    # --- WooCommerce Product fields ---
    if post_type == "product":
        if post.get("regular_price"):
            fm["price"] = post["regular_price"]
        elif post.get("price"):
            fm["price"] = post["price"]

        if post.get("sku"):
            fm["sku"] = post["sku"]

        if post.get("stock_status"):
            fm["stock_status"] = post["stock_status"]

        if post.get("type"):
            fm["product_type"] = post["type"]

        # Product categories
        prod_cats = post.get("categories", [])
        if prod_cats:
            cat_names = [
                html_module.unescape(c.get("name", ""))
                for c in prod_cats if isinstance(c, dict) and c.get("name")
            ]
            if cat_names:
                fm["categories"] = cat_names

        # Product images
        images = post.get("images", [])
        if images:
            image_urls = [
                img.get("src", "") for img in images
                if isinstance(img, dict) and img.get("src")
            ]
            if image_urls:
                fm["images"] = image_urls

        # Product attributes
        attributes = post.get("attributes", [])
        if attributes:
            attr_dict = {}
            for attr in attributes:
                if isinstance(attr, dict) and attr.get("name"):
                    options = attr.get("options", [])
                    if options:
                        attr_dict[attr["name"]] = options if len(options) > 1 else options[0]
            if attr_dict:
                fm["attributes"] = attr_dict

    return fm


# =============================================================================
# Folder Mapping
# =============================================================================

def _build_reverse_topic_map(topic_mapping: dict) -> dict[int, str]:
    """Invert topic_mapping: topic_id → folder path.

    When multiple paths map to the same ID, the most specific (longest) wins.
    """
    reverse = {}
    # Sort by path length descending so most specific paths take priority
    for path_pattern, topic_id in sorted(topic_mapping.items(),
                                          key=lambda x: len(x[0]),
                                          reverse=True):
        tid = int(topic_id)
        if tid not in reverse:
            # Convert path pattern to folder: /AI/0_fundamentals/ -> AI/0_fundamentals
            folder = path_pattern.strip("/")
            reverse[tid] = folder
    return reverse


def determine_folder(post: dict, post_type: str, import_cfg: dict,
                     reverse_topics: dict) -> str:
    """Determine the target folder for a post.

    - Knowledge posts: use reverse topic_mapping (topic_id -> folder path)
    - Other types: use static folder from import config
    """
    # Find the post_type config entry
    type_config = None
    for pt in import_cfg.get("post_types", []):
        if pt.get("type") == post_type:
            type_config = pt
            break

    if not type_config:
        return post_type.upper()

    static_folder = type_config.get("folder", "")

    # For knowledge posts, use topic mapping for subfolder
    if post_type == "knowledge" and not static_folder:
        topics = post.get("knowledge_topic") or post.get("topics") or []
        if topics:
            # Try each topic until we find a match
            for topic_id in topics:
                folder = reverse_topics.get(int(topic_id))
                if folder:
                    return folder
        # Fallback: top-level of KB
        return ""

    return static_folder


# =============================================================================
# Import Functions
# =============================================================================

def import_post(post: dict, post_type: str, fetcher: WordPressFetcher,
                import_cfg: dict, reverse_topics: dict,
                conflict: str = "newer", dry_run: bool = False) -> dict:
    """Import a single WordPress post into an Obsidian markdown file.

    Returns a result dict with status info.
    """
    # Build frontmatter
    fm = build_frontmatter(post, post_type, fetcher)
    title = fm.get("title", "Untitled")
    slug = fm.get("slug", "untitled")
    wp_id = fm.get("wp_id")

    # Convert HTML body to markdown
    content_html = ""
    if isinstance(post.get("content"), dict):
        content_html = post["content"].get("rendered", "")
    elif isinstance(post.get("content"), str):
        content_html = post["content"]
    # WooCommerce: description field
    if not content_html and post.get("description"):
        content_html = post["description"]

    body_md = html_to_markdown(content_html)

    # Determine target folder
    folder = determine_folder(post, post_type, import_cfg, reverse_topics)

    # Build file path
    filename = f"{slug}.md"
    if folder:
        target_dir = KB_ROOT / folder
    else:
        target_dir = KB_ROOT
    target_file = target_dir / filename

    # Relative path for mapping (forward slashes)
    rel_path = str(target_file.relative_to(KB_ROOT)).replace("\\", "/")

    result = {
        "file": rel_path,
        "title": title,
        "wp_id": wp_id,
        "post_type": post_type,
    }

    # Conflict resolution
    if target_file.exists():
        if conflict == "skip":
            result["status"] = "skipped"
            result["reason"] = "file exists"
            return result

        if conflict == "newer":
            # Compare WP modified date vs local file mtime
            wp_modified = post.get("modified", "")
            if wp_modified:
                try:
                    wp_dt = datetime.fromisoformat(wp_modified.replace("Z", "+00:00"))
                    # Make naive for comparison if needed
                    if wp_dt.tzinfo:
                        wp_dt = wp_dt.replace(tzinfo=None)
                    local_mtime = datetime.fromtimestamp(target_file.stat().st_mtime)
                    if wp_dt <= local_mtime:
                        result["status"] = "skipped"
                        result["reason"] = "local file is newer"
                        return result
                except (ValueError, OSError):
                    pass  # Can't compare — proceed with overwrite

        # conflict == "overwrite" or "newer" with WP being newer — fall through

    if dry_run:
        action = "would overwrite" if target_file.exists() else "would create"
        result["status"] = f"dry-run ({action})"
        print(f"  [{result['status']}] {rel_path}  ({title})")
        return result

    # Build markdown content
    # Remove wp_id from frontmatter written to file (it's in the mapping)
    fm_for_file = {k: v for k, v in fm.items() if k != "wp_id"}

    frontmatter_str = yaml.dump(
        fm_for_file,
        default_flow_style=False,
        allow_unicode=True,
        sort_keys=False,
        width=120,
    ).rstrip()

    md_content = f"---\n{frontmatter_str}\n---\n\n{body_md}\n"

    # Write file
    target_dir.mkdir(parents=True, exist_ok=True)
    target_file.write_text(md_content, encoding="utf-8")

    action = "overwritten" if target_file.exists() else "created"
    result["status"] = action
    result["slug"] = slug
    print(f"  [{action}] {rel_path}  ({title})")

    return result


def import_all(post_types: list[str] = None, since: str = None,
               conflict: str = None, dry_run: bool = False) -> list[dict]:
    """Import all posts of the specified types from WordPress.

    Args:
        post_types: List of types to import (e.g. ["knowledge", "post"]).
                    None = all configured types.
        since: ISO date string — only import posts modified after this date.
        conflict: Conflict strategy override. None = use config default.
        dry_run: Preview without writing files.

    Returns:
        List of result dicts.
    """
    if not IMPORT_CONFIG.get("post_types"):
        print("Error: No import.post_types configured in config.yaml")
        return []

    if conflict is None:
        conflict = IMPORT_CONFIG.get("conflict_strategy", "newer")

    # Build reverse topic map for knowledge post folder resolution
    reverse_topics = _build_reverse_topic_map(TOPIC_MAPPING)

    fetcher = WordPressFetcher(
        WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD,
        WC_CONSUMER_KEY, WC_CONSUMER_SECRET
    )

    all_results = []

    for type_cfg in IMPORT_CONFIG["post_types"]:
        ptype = type_cfg["type"]

        # Filter by requested types
        if post_types and ptype not in post_types:
            continue

        endpoint = type_cfg["endpoint"]
        auth_type = type_cfg.get("auth", "basic")

        print(f"\n{'=' * 60}")
        print(f"Importing: {ptype}")
        print(f"Endpoint:  {endpoint}")
        print(f"Conflict:  {conflict}")
        if since:
            print(f"Since:     {since}")
        print(f"{'=' * 60}")

        # Fetch all posts of this type
        posts = fetcher.fetch_all(endpoint, auth_type=auth_type, since=since)
        print(f"  Fetched {len(posts)} {ptype}(s) from WordPress")

        for post in posts:
            result = import_post(
                post, ptype, fetcher, IMPORT_CONFIG, reverse_topics,
                conflict=conflict, dry_run=dry_run
            )
            all_results.append(result)

    return all_results


def save_import_mapping(results: list[dict]) -> None:
    """Update kb_sync_mapping.json with imported post mappings.

    Writes to the SAME mapping file that kb_sync.py reads, so that
    subsequent syncs update existing posts instead of creating duplicates.
    """
    mapping_file = KB_ROOT / "kb_sync_mapping.json"

    # Load existing mapping
    existing_mapping = {}
    if mapping_file.exists():
        try:
            existing_mapping = json.loads(mapping_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            existing_mapping = {}

    timestamp = datetime.now().isoformat()
    new_entries = 0

    for result in results:
        if result.get("status") in ("created", "overwritten") and result.get("wp_id"):
            rel_path = result["file"]
            slug = result.get("slug", "")
            wp_id = result["wp_id"]
            title = result.get("title", "")

            # Build URL from slug
            post_type = result.get("post_type", "")
            if post_type == "product":
                url = f"{WP_SITE_URL}/product/{slug}/"
            elif post_type == "page":
                url = f"{WP_SITE_URL}/{slug}/"
            elif post_type in ("knowledge", "post"):
                url = f"{WP_SITE_URL}/kb/{slug}/"
            else:
                url = f"{WP_SITE_URL}/{slug}/"

            existing_mapping[rel_path] = {
                "post_id": wp_id,
                "slug": slug,
                "url": url,
                "title": title,
                "last_synced": timestamp,
            }
            new_entries += 1

    if new_entries > 0:
        mapping_file.write_text(
            json.dumps(existing_mapping, indent=2, ensure_ascii=False),
            encoding="utf-8"
        )
        print(f"\nMapping updated: {mapping_file}  ({new_entries} new entries)")
    else:
        print("\nNo new mapping entries to save.")


# =============================================================================
# CLI Interface
# =============================================================================

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Import WordPress content into Obsidian markdown files"
    )
    parser.add_argument(
        "--config", type=str,
        help="Path to config.yaml (default: auto-discover from CWD or project root)"
    )
    parser.add_argument(
        "--full", action="store_true",
        help="Import all configured post types"
    )
    parser.add_argument(
        "--type", action="append", dest="types",
        help="Post type(s) to import (can be specified multiple times)"
    )
    parser.add_argument(
        "--since", type=str,
        help="Only import posts modified after this date (YYYY-MM-DD)"
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Preview what would be imported without writing files"
    )
    parser.add_argument(
        "--conflict", type=str, choices=["overwrite", "newer", "skip"],
        help="Conflict resolution strategy (default: from config, usually 'newer')"
    )

    args = parser.parse_args()

    # Load config (re-load if explicit path given)
    _load_config(args.config)

    # Determine which types to import
    if not args.full and not args.types:
        parser.error("Specify --full to import all types, or --type <type> for specific types")

    post_types = None if args.full else args.types

    # Validate since date format
    if args.since:
        try:
            datetime.strptime(args.since, "%Y-%m-%d")
        except ValueError:
            parser.error(f"Invalid date format: {args.since}  (expected YYYY-MM-DD)")

    print("=" * 60)
    print("WordPress Import")
    print(f"Source:      {WP_SITE_URL}")
    print(f"Destination: {KB_ROOT}")
    if args.dry_run:
        print("Mode:        DRY RUN")
    print("=" * 60)

    results = import_all(
        post_types=post_types,
        since=args.since,
        conflict=args.conflict,
        dry_run=args.dry_run,
    )

    # Save mapping (unless dry run)
    if not args.dry_run:
        save_import_mapping(results)

    # Summary
    print(f"\n{'=' * 60}")
    print("Summary")
    print("=" * 60)

    created = sum(1 for r in results if r["status"] == "created")
    overwritten = sum(1 for r in results if r["status"] == "overwritten")
    skipped = sum(1 for r in results if r["status"] == "skipped")
    dry_run_count = sum(1 for r in results if r["status"].startswith("dry-run"))

    if args.dry_run:
        print(f"Would create:    {sum(1 for r in results if 'would create' in r['status'])}")
        print(f"Would overwrite: {sum(1 for r in results if 'would overwrite' in r['status'])}")
    else:
        print(f"Created:     {created}")
        print(f"Overwritten: {overwritten}")
        print(f"Skipped:     {skipped}")
    print(f"Total:       {len(results)}")
