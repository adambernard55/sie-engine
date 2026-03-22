# tools/sync/base.py
"""
Shared infrastructure for all SIE sync tools.

Provides:
  - SyncConfig dataclass (replaces module-level globals)
  - WordPressClient (unified read/write client)
  - Markdown/HTML converters
  - Slug generators
  - Topic/category resolution
  - Mapping file management
  - Pinecone integration wrapper
  - BaseSyncTool abstract class
"""

import os
import re
import sys
import json
import yaml
import html as html_module
import base64
import requests
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from pathlib import Path
from datetime import datetime
from typing import Optional
from dotenv import load_dotenv

# Fix Windows console encoding
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

load_dotenv()

# Ensure engine root is on sys.path for pinecone_tool imports
_engine_root = str(Path(__file__).parent.parent.parent)
if _engine_root not in sys.path:
    sys.path.insert(0, _engine_root)


# =============================================================================
# Configuration
# =============================================================================

@dataclass
class SyncConfig:
    """All settings needed for a sync run. Replaces module-level globals."""
    kb_root: Path
    wp_site_url: str
    wp_username: str
    wp_app_password: str

    wp_post_type: str = "knowledge"
    wp_taxonomy: str = "knowledge_topics"
    wp_tag_taxonomy: str = "knowledge_tag"
    url_prefix: str = "kb"
    filter_path: str = ""
    default_topic_id: int = 0

    topic_mapping: dict = field(default_factory=dict)
    parent_topic_ids: dict = field(default_factory=dict)
    import_config: dict = field(default_factory=dict)

    # WooCommerce credentials
    wc_consumer_key: str = ""
    wc_consumer_secret: str = ""

    # Pinecone
    pinecone_enabled: bool = True


def _find_config_file(explicit_path: str = None) -> Optional[Path]:
    """Locate config.yaml: explicit path > CWD > project root."""
    if explicit_path:
        p = Path(explicit_path)
        if p.exists():
            return p
        raise FileNotFoundError(f"Config file not found: {explicit_path}")

    cwd_config = Path.cwd() / "config.yaml"
    if cwd_config.exists():
        return cwd_config

    # Two levels up from this file: engine/tools/sync/ -> engine/
    project_config = Path(__file__).parent.parent.parent / "config.yaml"
    if project_config.exists():
        return project_config

    return None


def _fetch_wp_topic_mapping(site_url: str, username: str, app_password: str) -> dict:
    """Fetch topic mapping from the SIE WordPress plugin endpoint.
    Returns empty dict on failure (falls back to config.yaml).
    """
    if not all([site_url, username, app_password]):
        return {}

    url = f"{site_url.rstrip('/')}/wp-json/sie/v1/topics"
    auth = base64.b64encode(f"{username}:{app_password}".encode()).decode()

    try:
        response = requests.get(
            url,
            headers={
                "Authorization": f"Basic {auth}",
                "User-Agent": "Mozilla/5.0 (compatible; SIE-KBSync/1.0)",
                "X-SIE-Sync": "true",
            },
            timeout=10,
        )
        if response.status_code == 200:
            data = response.json()
            if isinstance(data, dict) and data:
                return data
    except Exception:
        pass

    return {}


def load_config(config_path: str = None, profile: str = None) -> SyncConfig:
    """Load config.yaml and return a SyncConfig.

    Args:
        config_path: Explicit path to config.yaml.
        profile: Sync profile name from sync_profiles section.
                 Overlays profile-specific values onto base config.
    """
    config_file = _find_config_file(config_path)
    config: dict = {}

    if config_file:
        with open(config_file, encoding="utf-8") as fh:
            config = yaml.safe_load(fh) or {}
        print(f"Config: {config_file}")

    # KB root
    kb_root_str = os.getenv("KB_ROOT") or config.get("kb_root")
    if not kb_root_str:
        raise ValueError("kb_root is not configured.")
    kb_root = Path(kb_root_str).expanduser().resolve()

    # WordPress credentials
    wp_site_url = os.getenv("WP_SITE_URL") or config.get("wp_site_url", "")
    wp_username = os.getenv("WP_USERNAME") or config.get("wp_username", "")
    wp_app_password = os.getenv("WP_APP_PASSWORD", "")

    # WooCommerce credentials
    wc_key = os.getenv("WC_CONSUMER_KEY", "")
    wc_secret = os.getenv("WC_CONSUMER_SECRET", "")

    # Topic mapping — merge live WP with config.yaml
    config_mapping = config.get("topic_mapping") or {}
    live_mapping = _fetch_wp_topic_mapping(wp_site_url, wp_username, wp_app_password)

    if config_mapping and live_mapping:
        topic_mapping = {**config_mapping, **live_mapping}
        print(f"Topic mapping: {len(live_mapping)} from WP + {len(config_mapping)} from config = {len(topic_mapping)} merged")
    elif live_mapping:
        topic_mapping = live_mapping
        print(f"Topic mapping: fetched {len(topic_mapping)} topics from WordPress")
    elif config_mapping:
        topic_mapping = config_mapping
        print(f"Topic mapping: loaded {len(topic_mapping)} topics from config.yaml")
    else:
        topic_mapping = {}

    # Base values (may be overridden by profile)
    wp_post_type = config.get("wp_post_type", "knowledge")
    wp_taxonomy = config.get("wp_taxonomy", "knowledge_topics")
    wp_tag_taxonomy = config.get("wp_tag_taxonomy", "knowledge_tag")
    url_prefix = config.get("url_prefix", "kb")
    default_topic_id = int(config.get("default_topic_id") or os.getenv("DEFAULT_TOPIC_ID", "0"))
    filter_path = ""
    pinecone_enabled = True

    # Profile overlay
    if profile:
        profiles = config.get("sync_profiles") or {}
        if profile not in profiles:
            raise ValueError(f"Sync profile '{profile}' not found in config.yaml. Available: {list(profiles.keys())}")
        p = profiles[profile]
        wp_post_type = p.get("wp_post_type", wp_post_type)
        wp_taxonomy = p.get("wp_taxonomy", wp_taxonomy)
        wp_tag_taxonomy = p.get("wp_tag_taxonomy", wp_tag_taxonomy)
        url_prefix = p.get("url_prefix", url_prefix)
        default_topic_id = int(p.get("default_topic_id", default_topic_id))
        filter_path = p.get("filter_path", "")
        pinecone_enabled = p.get("pinecone", pinecone_enabled)
        print(f"Profile: {profile} → {wp_post_type} ({url_prefix})")

    return SyncConfig(
        kb_root=kb_root,
        wp_site_url=wp_site_url,
        wp_username=wp_username,
        wp_app_password=wp_app_password,
        wp_post_type=wp_post_type,
        wp_taxonomy=wp_taxonomy,
        wp_tag_taxonomy=wp_tag_taxonomy,
        url_prefix=url_prefix,
        filter_path=filter_path,
        default_topic_id=default_topic_id,
        topic_mapping=topic_mapping,
        parent_topic_ids=config.get("parent_topics") or {},
        import_config=config.get("import") or {},
        wc_consumer_key=wc_key,
        wc_consumer_secret=wc_secret,
        pinecone_enabled=pinecone_enabled,
    )


# =============================================================================
# WordPress Client (unified read + write)
# =============================================================================

class WordPressClient:
    """Unified WordPress REST API client for read and write operations."""

    def __init__(self, config: SyncConfig):
        self.config = config
        self.site_url = config.wp_site_url.rstrip("/")
        self.auth_header = base64.b64encode(
            f"{config.wp_username}:{config.wp_app_password}".encode()
        ).decode()

        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Basic {self.auth_header}",
            "Content-Type": "application/json",
            "User-Agent": "Mozilla/5.0 (compatible; SIE-KBSync/1.0)",
            "X-SIE-Sync": "true",
        })

        # Caches for tag/category name resolution
        self._tag_cache: dict[int, str] = {}
        self._category_cache: dict[int, str] = {}

    def _endpoint(self, post_type: str = None) -> str:
        """Build the REST API endpoint URL for the given post type."""
        pt = post_type or self.config.wp_post_type
        return f"{self.site_url}/wp-json/wp/v2/{pt}"

    def _wc_endpoint(self, path: str) -> str:
        """Build a WooCommerce REST API endpoint URL."""
        return f"{self.site_url}/wp-json/wc/v3/{path}"

    # --- Read methods ---

    def get_post_by_slug(self, slug: str, post_type: str = None) -> Optional[dict]:
        """Find a post by slug."""
        url = self._endpoint(post_type)
        response = self.session.get(url, params={"slug": slug})
        if response.status_code == 200:
            posts = response.json()
            return posts[0] if posts else None
        return None

    def search_post_by_title(self, title: str, post_type: str = None) -> Optional[dict]:
        """Find a post by exact title match."""
        url = self._endpoint(post_type)
        response = self.session.get(url, params={"search": title, "per_page": 5})
        if response.status_code == 200:
            posts = response.json()
            for post in posts:
                rendered = post.get("title", {}).get("rendered", "")
                if html_module.unescape(rendered).strip() == title.strip():
                    return post
        return None

    def fetch_all(self, endpoint: str, auth_type: str = "basic",
                  since: str = None) -> list[dict]:
        """Paginated fetch. Returns all items from the endpoint."""
        url = f"{self.site_url}{endpoint}"
        all_items = []
        page = 1

        while True:
            params = {"per_page": 100, "page": page}
            if since:
                params["modified_after"] = f"{since}T00:00:00"

            if auth_type == "woocommerce":
                # WooCommerce uses query param auth, not Basic
                params["consumer_key"] = self.config.wc_consumer_key
                params["consumer_secret"] = self.config.wc_consumer_secret
                headers = {"User-Agent": "Mozilla/5.0 (compatible; SIE-KBSync/1.0)",
                           "X-SIE-Sync": "true"}
            else:
                headers = None  # uses session headers

            try:
                response = self.session.get(url, params=params,
                                            headers=headers, timeout=30)
            except requests.RequestException as e:
                print(f"  Error fetching {endpoint} page {page}: {e}")
                break

            if response.status_code == 400:
                break  # past last page
            if response.status_code >= 400:
                print(f"  Error {response.status_code}: {response.text[:200]}")
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

    def resolve_tags(self, tag_ids: list[int],
                     taxonomy_endpoint: str = None) -> list[str]:
        """Resolve tag IDs to names with caching."""
        if not tag_ids:
            return []

        endpoint = taxonomy_endpoint or "/wp-json/wp/v2/tags"
        uncached = [tid for tid in tag_ids if tid not in self._tag_cache]

        if uncached:
            for i in range(0, len(uncached), 100):
                batch = uncached[i:i + 100]
                url = f"{self.site_url}{endpoint}"
                params = {"include": ",".join(str(t) for t in batch), "per_page": 100}
                try:
                    resp = self.session.get(url, params=params, timeout=15)
                    if resp.status_code == 200:
                        for tag in resp.json():
                            self._tag_cache[tag["id"]] = html_module.unescape(
                                tag.get("name", ""))
                except requests.RequestException:
                    pass

        return [self._tag_cache[tid] for tid in tag_ids if tid in self._tag_cache]

    def resolve_categories(self, cat_ids: list[int],
                           taxonomy_endpoint: str = None) -> list[str]:
        """Resolve category IDs to names with caching."""
        if not cat_ids:
            return []

        endpoint = taxonomy_endpoint or "/wp-json/wp/v2/categories"
        uncached = [cid for cid in cat_ids if cid not in self._category_cache]

        if uncached:
            for i in range(0, len(uncached), 100):
                batch = uncached[i:i + 100]
                url = f"{self.site_url}{endpoint}"
                params = {"include": ",".join(str(c) for c in batch), "per_page": 100}
                try:
                    resp = self.session.get(url, params=params, timeout=15)
                    if resp.status_code == 200:
                        for cat in resp.json():
                            self._category_cache[cat["id"]] = html_module.unescape(
                                cat.get("name", ""))
                except requests.RequestException:
                    pass

        return [self._category_cache[cid] for cid in cat_ids if cid in self._category_cache]

    # --- Write methods ---

    def create_post(self, payload: dict, post_type: str = None) -> dict:
        """Create a new post."""
        url = self._endpoint(post_type)
        response = self.session.post(url, json=payload)
        if response.status_code >= 400:
            raise Exception(f"{response.status_code} Error: {response.text[:500]}")
        return response.json()

    def update_post(self, post_id: int, payload: dict, post_type: str = None) -> dict:
        """Update an existing post."""
        url = f"{self._endpoint(post_type)}/{post_id}"
        response = self.session.put(url, json=payload)
        if response.status_code >= 400:
            raise Exception(f"{response.status_code} Error: {response.text[:500]}")
        return response.json()

    def get_or_create_tag(self, tag_name: str, taxonomy: str = None) -> Optional[int]:
        """Get tag ID or create if it doesn't exist."""
        tax = taxonomy or self.config.wp_tag_taxonomy
        slug = re.sub(r"[^a-z0-9-]", "-", tag_name.lower())
        url = f"{self.site_url}/wp-json/wp/v2/{tax}"

        response = self.session.get(url, params={"slug": slug})
        if response.status_code == 200:
            tags = response.json()
            if tags:
                return tags[0]["id"]

        response = self.session.post(url, json={"name": tag_name, "slug": slug})
        if response.status_code in (200, 201):
            return response.json()["id"]
        return None

    def update_rankmath_meta(self, post_id: int, focus_keyword: str = None,
                             description: str = None) -> bool:
        """Update Rank Math SEO meta fields."""
        if not focus_keyword and not description:
            return True

        meta = {}
        if focus_keyword:
            meta["rank_math_focus_keyword"] = focus_keyword
        if description:
            meta["rank_math_description"] = description

        payload = {"objectType": "post", "objectID": post_id, "meta": meta}
        try:
            response = self.session.post(
                f"{self.site_url}/wp-json/rankmath/v1/updateMeta", json=payload)
            return response.status_code == 200
        except Exception:
            return False


# =============================================================================
# Markdown / HTML Converters
# =============================================================================

def parse_frontmatter(content: str) -> tuple[dict, str]:
    """Parse YAML frontmatter from markdown. Returns (frontmatter_dict, body)."""
    lines = content.split("\n")

    if not lines or not re.match(r"^---\s*$", lines[0]):
        return {}, content

    end_index = None
    for i, line in enumerate(lines[1:], start=1):
        if re.match(r"^---\s*$", line):
            end_index = i
            break

    if end_index is None:
        return {}, content

    frontmatter_text = "\n".join(lines[1:end_index])
    body = "\n".join(lines[end_index + 1:]).strip()

    try:
        frontmatter = yaml.safe_load(frontmatter_text)
        return frontmatter or {}, body
    except yaml.YAMLError as e:
        print(f"    YAML parse error: {e}")
        return {}, content


def markdown_to_html(markdown_content: str, url_prefix: str = "kb") -> str:
    """Convert markdown to HTML for WordPress."""
    import markdown as md_lib

    content = clean_obsidian_syntax(markdown_content, url_prefix)

    converter = md_lib.Markdown(extensions=[
        "tables", "fenced_code", "codehilite", "toc", "smarty"
    ])
    html = converter.convert(content)
    html = style_tables(html)
    html = style_code_blocks(html)
    return html


def html_to_markdown(html_content: str) -> str:
    """Convert WordPress HTML to clean markdown."""
    from markdownify import markdownify as md

    if not html_content:
        return ""

    text = html_content

    # Strip WP block editor comments
    text = re.sub(r"<!--\s*/?wp:\S.*?-->", "", text, flags=re.DOTALL)

    # Strip page builder shortcodes
    text = re.sub(
        r"\[/?(?:vc_|fusion_|et_pb_|elementor|wpbakery|rev_slider|fl_builder)"
        r"[^\]]*\]",
        "", text,
    )

    result = md(
        text,
        heading_style="ATX",
        code_language_callback=None,
        strip=["script", "style"],
    )

    result = re.sub(r"\n{3,}", "\n\n", result)
    return result.strip()


def convert_wikilink_to_url(match: re.Match, url_prefix: str = "kb") -> str:
    """Convert [[wiki-link]] to a WordPress URL."""
    full_match = match.group(1)

    if "|" in full_match:
        target, display_text = full_match.split("|", 1)
    else:
        target = full_match
        display_text = None

    heading = None
    if "#" in target:
        target, heading = target.split("#", 1)

    if "/" in target:
        target = target.split("/")[-1]

    target = re.sub(r"\.md$", "", target, flags=re.IGNORECASE)

    slug = target.lower()
    slug = re.sub(r"^[0-9]+[_-]", "", slug)
    slug = re.sub(r"[^a-z0-9\s-]", "", slug)
    slug = re.sub(r"[\s_]+", "-", slug)
    slug = re.sub(r"-+", "-", slug).strip("-")

    if not display_text:
        display_text = target.replace("-", " ").replace("_", " ")
        display_text = re.sub(r"^[0-9]+[_\s-]*", "", display_text)
        display_text = display_text.title()
        for acronym in ["AI", "SEO", "LLM", "NLP", "API", "UI", "UX", "ML", "GPT", "RAG", "MCP"]:
            display_text = re.sub(
                rf"\b{acronym.title()}\b", acronym, display_text, flags=re.IGNORECASE
            )

    url = f"/{url_prefix}/{slug}/"
    if heading:
        anchor = heading.lower()
        anchor = re.sub(r"[^a-z0-9\s-]", "", anchor)
        anchor = re.sub(r"\s+", "-", anchor)
        url += f"#{anchor}"

    return f"[{display_text}]({url})"


def clean_obsidian_syntax(content: str, url_prefix: str = "kb") -> str:
    """Convert Obsidian-specific syntax for WordPress."""
    content = re.sub(
        r"(?<!!)\[\[([^\]]+)\]\]",
        lambda m: convert_wikilink_to_url(m, url_prefix),
        content,
    )
    content = re.sub(r"!\[\[[^\]]*\]\]", "", content)
    content = re.sub(r"\^[a-zA-Z0-9-]+", "", content)
    content = re.sub(r"```dataview[\s\S]*?```", "", content, flags=re.MULTILINE)
    return content


def style_tables(html: str) -> str:
    """Add inline styles to tables."""
    html = html.replace(
        "<table>",
        '<table style="border-collapse:collapse;width:100%;border:1px solid #ddd;">',
    )
    html = re.sub(
        r"<th([^>]*)>",
        r'<th\1 style="border:1px solid #ddd;padding:8px;background:#f4f4f4;text-align:left;">',
        html,
    )
    html = re.sub(
        r"<td([^>]*)>",
        r'<td\1 style="border:1px solid #ddd;padding:8px;text-align:left;">',
        html,
    )
    return html


def style_code_blocks(html: str) -> str:
    """Add inline styles to code blocks."""
    html = html.replace(
        "<pre>",
        '<pre style="background:#f5f5f5;padding:16px;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-wrap:break-word;">',
    )
    html = re.sub(
        r"<code>(?!</pre>)",
        '<code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;">',
        html,
    )
    return html


def build_frontmatter_string(fm: dict) -> str:
    """Serialize a frontmatter dict to YAML string (without --- delimiters)."""
    return yaml.dump(
        fm, default_flow_style=False, allow_unicode=True,
        sort_keys=False, width=120,
    ).rstrip()


# =============================================================================
# Slug Generators
# =============================================================================

def generate_slug(text: str) -> str:
    """Generate URL slug from text."""
    slug = text.lower()
    slug = re.sub(r"[\u2010\u2011\u2012\u2013\u2014\u2015\u2212]", "-", slug)
    slug = slug.replace(".", "-").replace("/", "-")
    slug = re.sub(r"[^a-z0-9\s-]", "", slug)
    slug = re.sub(r"[\s_]+", "-", slug)
    slug = re.sub(r"-+", "-", slug)
    return slug.strip("-")


def slug_from_filename(filename: str) -> str:
    """Generate slug from filename. Strips numeric prefix and extension."""
    name = filename.replace(".md", "")
    name = re.sub(r"^[0-9]+[_-]", "", name)
    return generate_slug(name)


def generate_path_slug(file_path: Path, kb_root: Path) -> str:
    """Generate slug including directory hierarchy."""
    rel_path = file_path.relative_to(kb_root)
    dir_parts = list(rel_path.parent.parts)

    clean_dirs = []
    for part in dir_parts:
        clean = re.sub(r"^[0-9]+[_-]", "", part).lower()
        clean = generate_slug(clean)
        if clean:
            clean_dirs.append(clean)

    file_slug = slug_from_filename(file_path.name)
    if clean_dirs:
        return "/".join(clean_dirs) + "/" + file_slug
    return file_slug


def title_from_filename(filename: str) -> str:
    """Generate title from filename."""
    name = re.sub(r"^[0-9]+_", "", filename).replace(".md", "")
    title = name.replace("_", " ").replace("-", " ").title()

    acronyms = ["AI", "SEO", "LLM", "NLP", "API", "UI", "UX", "ML",
                "GPT", "RAG", "HTML", "CSS", "URL", "IO", "MCP"]
    for acronym in acronyms:
        title = re.sub(rf"\b{acronym.title()}\b", acronym, title, flags=re.IGNORECASE)
    return title


# =============================================================================
# Topic / Category Resolution
# =============================================================================

def get_topic_id(file_path: Path, config: SyncConfig) -> int:
    """Determine topic ID from file path (most specific match)."""
    relative_path = str(file_path.relative_to(config.kb_root)).replace("\\", "/")
    for pattern, topic_id in sorted(config.topic_mapping.items(), key=lambda x: -len(x[0])):
        if pattern.strip("/") in relative_path:
            return topic_id
    return config.default_topic_id


def get_all_topic_ids(file_path: Path, config: SyncConfig) -> list[int]:
    """Return all matching topic IDs for a file path (full hierarchy)."""
    relative_path = str(file_path.relative_to(config.kb_root)).replace("\\", "/")
    topic_ids = []
    seen = set()

    for pattern, topic_id in sorted(config.topic_mapping.items(), key=lambda x: -len(x[0])):
        if pattern.strip("/") in relative_path and topic_id not in seen:
            topic_ids.append(topic_id)
            seen.add(topic_id)

    if not topic_ids:
        topic_ids.append(config.default_topic_id)
    return topic_ids


def build_reverse_topic_map(topic_mapping: dict) -> dict[int, str]:
    """Invert topic_mapping: topic_id → folder path."""
    reverse = {}
    for path_pattern, topic_id in sorted(topic_mapping.items(),
                                          key=lambda x: len(x[0]), reverse=True):
        tid = int(topic_id)
        if tid not in reverse:
            reverse[tid] = path_pattern.strip("/")
    return reverse


# =============================================================================
# Mapping File Management
# =============================================================================

def load_mapping(kb_root: Path) -> dict:
    """Read kb_sync_mapping.json."""
    mapping_file = kb_root / "kb_sync_mapping.json"
    if mapping_file.exists():
        try:
            return json.loads(mapping_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            pass
    return {}


def save_mapping(kb_root: Path, results: list[dict], url_prefix: str) -> None:
    """Write/merge results into kb_sync_mapping.json."""
    mapping_file = kb_root / "kb_sync_mapping.json"
    existing = load_mapping(kb_root)

    timestamp = datetime.now().isoformat()
    new_count = 0

    for result in results:
        if result.get("status") in ("created", "updated", "overwritten") and result.get("post_id"):
            file_path = result.get("file", "")
            try:
                rel_path = str(Path(file_path).relative_to(kb_root)).replace("\\", "/")
            except ValueError:
                rel_path = file_path

            url = result.get("url", "")
            slug_part = ""
            prefix_marker = f"/{url_prefix}/"
            if prefix_marker in url:
                slug_part = url.split(prefix_marker)[-1].rstrip("/")

            existing[rel_path] = {
                "post_id": result.get("post_id"),
                "slug": slug_part,
                "url": url,
                "title": result.get("title", ""),
                "last_synced": timestamp,
            }
            new_count += 1

    if new_count > 0 or not mapping_file.exists():
        mapping_file.write_text(
            json.dumps(existing, indent=2, ensure_ascii=False), encoding="utf-8"
        )
        print(f"\nMapping saved: {mapping_file} ({new_count} entries updated)")


def should_sync_file(file_path: Path) -> bool:
    """Check if a file should be synced."""
    if file_path.name == "index.md":
        return False
    if file_path.stem == file_path.parent.name:
        return False
    if any(part.startswith(".") for part in file_path.parts):
        return False
    if "ARCHIVE" in file_path.parts:
        return False
    return True


# =============================================================================
# Pinecone Integration
# =============================================================================

def sync_to_pinecone(post_id: int, title: str, body: str, slug: str,
                     config: SyncConfig, tags: list = None,
                     key_concepts: list = None, synthetic_questions: list = None,
                     semantic_summary: str = "", updated: str = None) -> str:
    """Chunk and upsert a document to Pinecone. Returns status string."""
    if not config.pinecone_enabled:
        return "skipped (pinecone disabled)"

    try:
        from tools.pinecone_tool import (
            upsert_to_knowledge_core,
            delete_from_knowledge_core,
            chunk_document,
        )
    except ImportError:
        return "skipped (pinecone_tool not available)"

    base_id = f"kb-{post_id}"
    old_ids = [base_id] + [f"{base_id}-chunk-{i}" for i in range(20)]
    delete_from_knowledge_core(old_ids)

    chunks = chunk_document(
        title=title, body=body,
        semantic_summary=semantic_summary,
        synthetic_questions=synthetic_questions or [],
        key_concepts=key_concepts or [],
        tags=tags or [],
    )

    url = f"{config.wp_site_url}/{config.url_prefix}/{slug}/"

    for chunk in chunks:
        vector_id = f"{base_id}-chunk-{chunk['chunk_index']}"
        upsert_to_knowledge_core(
            content=chunk["content"],
            doc_id=vector_id,
            title=title,
            source=url,
            date=updated or datetime.now().isoformat(),
            post_type=config.wp_post_type,
            url=url,
            tags=tags or [],
            key_concepts=key_concepts or [],
            synthetic_questions=synthetic_questions or [],
            section_header=chunk["section_header"],
            chunk_index=chunk["chunk_index"],
            total_chunks=len(chunks),
        )

    return f"{len(chunks)} chunk(s) upserted"


# =============================================================================
# Pull Helpers
# =============================================================================

def build_pull_frontmatter(post: dict, post_type: str,
                           wp_client: WordPressClient) -> dict:
    """Build YAML frontmatter dict from a WordPress post/page/product."""
    fm = {}

    # Title
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
        fm["date"] = date_str[:10]

    modified_str = post.get("modified", "")
    if modified_str:
        fm["updated"] = modified_str[:10]

    # WP ID
    wp_id = post.get("id")
    if wp_id:
        fm["wp_id"] = wp_id

    # Excerpt
    excerpt = ""
    if isinstance(post.get("excerpt"), dict):
        excerpt = post["excerpt"].get("rendered", "")
    elif isinstance(post.get("excerpt"), str):
        excerpt = post["excerpt"]
    if excerpt:
        excerpt = re.sub(r"<[^>]+>", "", excerpt).strip()
        excerpt = html_module.unescape(excerpt)
        if excerpt:
            fm["excerpt"] = excerpt

    # Tags
    tag_ids = post.get("tags") or post.get("knowledge_tag") or []
    if tag_ids:
        tag_endpoint = None
        if post_type == "knowledge":
            tag_endpoint = "/wp-json/wp/v2/knowledge_tag"
        names = wp_client.resolve_tags(tag_ids, tag_endpoint)
        if names:
            fm["tags"] = names

    # SEO (Rank Math)
    meta = post.get("meta", {}) or {}
    focus_kw = meta.get("rank_math_focus_keyword", "")
    if focus_kw:
        fm["primary_keyword"] = focus_kw
    meta_desc = meta.get("rank_math_description", "")
    if not meta_desc:
        yoast = post.get("yoast_head_json", {}) or {}
        meta_desc = yoast.get("description", "")
    if meta_desc:
        fm["meta_description"] = meta_desc

    # ACF / RAG fields
    acf = post.get("acf", {}) or {}
    if acf.get("semantic_summary"):
        fm["semantic_summary"] = acf["semantic_summary"]
    if acf.get("synthetic_questions"):
        qs = [item.get("question", "") if isinstance(item, dict) else str(item)
              for item in acf["synthetic_questions"] if item]
        if qs:
            fm["synthetic_questions"] = qs
    if acf.get("key_concepts"):
        cs = [item.get("concept", "") if isinstance(item, dict) else str(item)
              for item in acf["key_concepts"] if item]
        if cs:
            fm["key_concepts"] = cs

    return fm


def resolve_conflict(target_file: Path, wp_modified: str,
                     strategy: str = "newer") -> str:
    """Determine whether to overwrite, skip, or create.
    Returns: 'write', 'skip', or 'create'.
    """
    if not target_file.exists():
        return "create"

    if strategy == "skip":
        return "skip"

    if strategy == "overwrite":
        return "write"

    # strategy == "newer"
    if wp_modified:
        try:
            wp_dt = datetime.fromisoformat(wp_modified.replace("Z", "+00:00"))
            if wp_dt.tzinfo:
                wp_dt = wp_dt.replace(tzinfo=None)
            local_mtime = datetime.fromtimestamp(target_file.stat().st_mtime)
            if wp_dt <= local_mtime:
                return "skip"
        except (ValueError, OSError):
            pass

    return "write"


# =============================================================================
# Base Sync Tool
# =============================================================================

class BaseSyncTool(ABC):
    """Abstract base for all sync tools."""

    def __init__(self, config: SyncConfig):
        self.config = config
        self.wp = WordPressClient(config)
        self.mapping = load_mapping(config.kb_root)

    # --- Push (Obsidian → WP) ---

    @abstractmethod
    def push_file(self, file_path: Path, dry_run: bool = False) -> dict:
        """Push a single Obsidian file to WordPress."""

    def push_all(self, filter_path: str = None, batch_size: int = 0,
                 offset: int = 0, dry_run: bool = False) -> list[dict]:
        """Push all matching files."""
        search_filter = filter_path or self.config.filter_path
        results = []

        all_files = []
        for md_file in sorted(self.config.kb_root.rglob("*.md")):
            if search_filter:
                relative = str(md_file.relative_to(self.config.kb_root))
                if not relative.startswith(search_filter.replace("/", os.sep)):
                    continue
            if not should_sync_file(md_file):
                continue
            all_files.append(md_file)

        total = len(all_files)
        print(f"Found {total} syncable files")

        if offset > 0:
            all_files = all_files[offset:]
            print(f"Skipping first {offset} files (offset)")
        if batch_size > 0:
            all_files = all_files[:batch_size]
            print(f"Processing batch of {len(all_files)} files")
        else:
            print(f"Processing all {len(all_files)} files")

        for i, md_file in enumerate(all_files, 1):
            print(f"\n[{i}/{len(all_files)}] Processing: {md_file.relative_to(self.config.kb_root)}")
            result = self.push_file(md_file, dry_run=dry_run)
            results.append(result)

            status_icon = {
                "created": "[+] Created", "updated": "[~] Updated",
                "skipped": "[-] Skipped", "error": "[!] Error",
                "dry_run": "[?] Would sync",
            }.get(result["status"], result["status"])

            print(f"  {status_icon}: {result.get('title', md_file.name)}")
            if result.get("error"):
                print(f"    Error: {result['error']}")
            if result.get("pinecone"):
                print(f"    Pinecone: {result['pinecone']}")

        if batch_size > 0 and offset + batch_size < total:
            print(f"\n--- Batch complete. Next: --offset {offset + batch_size} ---")

        return results

    # --- Pull (WP → Obsidian) ---

    @abstractmethod
    def pull_all(self, since: str = None, conflict: str = "newer",
                 dry_run: bool = False) -> list[dict]:
        """Pull all posts of this type from WordPress to Obsidian."""

    def _write_markdown_file(self, fm: dict, body_md: str,
                             target_file: Path) -> None:
        """Write a markdown file with frontmatter."""
        # Remove wp_id from written frontmatter (stored in mapping instead)
        fm_for_file = {k: v for k, v in fm.items() if k != "wp_id"}
        fm_str = build_frontmatter_string(fm_for_file)
        content = f"---\n{fm_str}\n---\n\n{body_md}\n"
        target_file.parent.mkdir(parents=True, exist_ok=True)
        target_file.write_text(content, encoding="utf-8")

    # --- Shared push helpers ---

    def _read_and_parse(self, file_path: Path) -> tuple[dict, str, str]:
        """Read file, parse frontmatter, convert body to HTML.
        Returns (frontmatter, html_content, raw_body).
        """
        content = file_path.read_text(encoding="utf-8")
        frontmatter, body = parse_frontmatter(content)

        if not body.strip():
            return frontmatter, "", ""

        html_content = markdown_to_html(body, self.config.url_prefix)
        return frontmatter, html_content, body

    def _extract_metadata(self, frontmatter: dict, file_path: Path) -> dict:
        """Extract common metadata fields from frontmatter."""
        return {
            "title": frontmatter.get("title") or title_from_filename(file_path.name),
            "slug": frontmatter.get("slug") or generate_path_slug(file_path, self.config.kb_root),
            "excerpt": frontmatter.get("excerpt") or frontmatter.get("summary", ""),
            "tags": frontmatter.get("tags") or [],
            "keyword": frontmatter.get("primary_keyword") or frontmatter.get("keyword", ""),
            "meta_description": frontmatter.get("meta_description", ""),
            "semantic_summary": frontmatter.get("semantic_summary", ""),
            "synthetic_questions": frontmatter.get("synthetic_questions") or [],
            "key_concepts": frontmatter.get("key_concepts") or [],
            "updated": self._normalize_date(frontmatter.get("updated", "")),
        }

    def _normalize_date(self, date_val) -> Optional[str]:
        """Normalize a date value to ISO format string."""
        if not date_val:
            return None
        try:
            if isinstance(date_val, str):
                if len(date_val) == 10 and "-" in date_val:
                    date_val = f"{date_val}T00:00:00"
                dt = datetime.fromisoformat(date_val.replace("Z", "+00:00"))
            elif hasattr(date_val, "isoformat"):
                dt = date_val
            else:
                return None
            result = dt.isoformat()
            if "T" not in result:
                result = f"{result}T00:00:00"
            return result
        except Exception:
            return None

    def _find_existing_post(self, slug: str, title: str, file_path: Path) -> Optional[dict]:
        """Multi-tier lookup to find an existing WP post and prevent duplicates."""
        rel_path = str(file_path.relative_to(self.config.kb_root)).replace("\\", "/")

        # Tier 1: mapping file
        if rel_path in self.mapping:
            post_id = self.mapping[rel_path].get("post_id")
            if post_id:
                return {"id": post_id, "_tier": "mapping"}

        # Tier 2: path-based slug
        existing = self.wp.get_post_by_slug(slug)
        if existing:
            return existing

        # Tier 3: filename-only slug
        filename_slug = slug_from_filename(file_path.name)
        if filename_slug != slug:
            existing = self.wp.get_post_by_slug(filename_slug)
            if existing:
                return existing

        # Tier 4: title search
        existing = self.wp.search_post_by_title(title)
        if existing:
            return existing

        return None
