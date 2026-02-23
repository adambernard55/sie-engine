# tools/kb_sync.py
"""
Knowledge Base Sync Tool
Syncs Obsidian markdown files to WordPress and Pinecone.
Replaces: GitHub Actions workflow + AI Engine Pro

Flow: Obsidian (.md) → WordPress (REST API) → Pinecone (embeddings)
"""

import os
import re
import sys
import json
import yaml
import base64
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

# Ensure the engine root (parent of tools/) is on sys.path so that
# `from tools.x import ...` works regardless of where the script is invoked from.
_engine_root = str(Path(__file__).parent.parent)
if _engine_root not in sys.path:
    sys.path.insert(0, _engine_root)

# Import Pinecone functions
from tools.pinecone_tool import upsert_to_knowledge_core, batch_upsert_to_knowledge_core

# =============================================================================
# Configuration
# =============================================================================

# Module-level globals — populated by _load_config() on import.
KB_ROOT: Optional[Path] = None
WP_SITE_URL: str = ""
WP_USERNAME: str = ""
WP_APP_PASSWORD: str = ""
TOPIC_MAPPING: dict = {}
PARENT_TOPIC_IDS: dict = {}
DEFAULT_TOPIC_ID: int = 0


def _find_config_file(explicit_path: str = None) -> Optional[Path]:
    """Locate config.yaml using a priority chain.

    1. Explicit --config path (if given)
    2. Current working directory  ← instance root in hub-and-spoke
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

    Credentials (WP_APP_PASSWORD, API keys) are always read from .env only.
    """
    global KB_ROOT, WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD
    global TOPIC_MAPPING, PARENT_TOPIC_IDS, DEFAULT_TOPIC_ID

    config_file = _find_config_file(config_path)
    config: dict = {}

    if config_file:
        with open(config_file, encoding="utf-8") as fh:
            config = yaml.safe_load(fh) or {}
        print(f"Config: {config_file}")

    # KB root
    kb_root_str = config.get("kb_root") or os.getenv("KB_ROOT")
    if not kb_root_str:
        raise ValueError(
            "kb_root is not configured. "
            "Set it in config.yaml or as the KB_ROOT environment variable."
        )
    KB_ROOT = Path(kb_root_str).expanduser().resolve()

    # WordPress — non-sensitive values may live in config.yaml
    WP_SITE_URL = config.get("wp_site_url") or os.getenv("WP_SITE_URL", "")
    WP_USERNAME = config.get("wp_username") or os.getenv("WP_USERNAME", "")
    WP_APP_PASSWORD = os.getenv("WP_APP_PASSWORD", "")   # credentials: .env only

    # Topic taxonomy — try live WP endpoint first, fall back to config.yaml
    TOPIC_MAPPING = _fetch_wp_topic_mapping(WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD)
    if TOPIC_MAPPING:
        print(f"Topic mapping: fetched {len(TOPIC_MAPPING)} topics from WordPress")
    else:
        TOPIC_MAPPING = config.get("topic_mapping") or {}
        if TOPIC_MAPPING:
            print(f"Topic mapping: loaded {len(TOPIC_MAPPING)} topics from config.yaml")

    PARENT_TOPIC_IDS = config.get("parent_topics") or {}
    DEFAULT_TOPIC_ID = int(
        config.get("default_topic_id") or os.getenv("DEFAULT_TOPIC_ID", "0")
    )


def _fetch_wp_topic_mapping(site_url: str, username: str, app_password: str) -> dict:
    """Fetch topic path-pattern → ID mapping from the SIE WordPress plugin endpoint.

    Returns an empty dict if the plugin isn't installed, credentials are missing,
    or the request fails — so the caller always falls back to config.yaml gracefully.
    """
    if not all([site_url, username, app_password]):
        return {}

    url = f"{site_url.rstrip('/')}/wp-json/sie/v1/topics"
    auth = base64.b64encode(f"{username}:{app_password}".encode()).decode()

    try:
        response = requests.get(
            url,
            headers={"Authorization": f"Basic {auth}"},
            timeout=10
        )
        if response.status_code == 200:
            data = response.json()
            if isinstance(data, dict) and data:
                return data
    except Exception:
        pass  # network error, plugin not installed, etc. — silently fall back

    return {}


# Initialise on module import.
_load_config()

# =============================================================================
# WordPress API Client
# =============================================================================

class WordPressClient:
    def __init__(self, site_url: str, username: str, app_password: str):
        self.site_url = site_url.rstrip("/")
        self.auth_header = base64.b64encode(f"{username}:{app_password}".encode()).decode()
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Basic {self.auth_header}",
            "Content-Type": "application/json"
        })

    def get_post_by_slug(self, slug: str) -> Optional[dict]:
        """Check if a knowledge post exists by slug."""
        url = f"{self.site_url}/wp-json/wp/v2/knowledge"
        response = self.session.get(url, params={"slug": slug})
        if response.status_code == 200:
            posts = response.json()
            return posts[0] if posts else None
        return None

    def create_post(self, payload: dict) -> dict:
        """Create a new knowledge post."""
        url = f"{self.site_url}/wp-json/wp/v2/knowledge"
        response = self.session.post(url, json=payload)
        if response.status_code >= 400:
            raise Exception(f"{response.status_code} Error: {response.text[:500]}")
        return response.json()

    def update_post(self, post_id: int, payload: dict) -> dict:
        """Update an existing knowledge post."""
        url = f"{self.site_url}/wp-json/wp/v2/knowledge/{post_id}"
        response = self.session.put(url, json=payload)
        if response.status_code >= 400:
            raise Exception(f"{response.status_code} Error: {response.text[:500]}")
        return response.json()

    def get_or_create_tag(self, tag_name: str) -> int:
        """Get tag ID or create if doesn't exist."""
        slug = re.sub(r'[^a-z0-9-]', '-', tag_name.lower())

        # Check if tag exists
        url = f"{self.site_url}/wp-json/wp/v2/knowledge_tag"
        response = self.session.get(url, params={"slug": slug})
        if response.status_code == 200:
            tags = response.json()
            if tags:
                return tags[0]["id"]

        # Create new tag
        response = self.session.post(url, json={"name": tag_name, "slug": slug})
        if response.status_code in (200, 201):
            return response.json()["id"]

        return None

    def update_rankmath_meta(self, post_id: int, focus_keyword: str = None, description: str = None) -> bool:
        """Update Rank Math SEO meta using Rank Math's REST API."""
        if not focus_keyword and not description:
            return True

        url = f"{self.site_url}/wp-json/rankmath/v1/updateMeta"

        meta = {}
        if focus_keyword:
            meta["rank_math_focus_keyword"] = focus_keyword
        if description:
            meta["rank_math_description"] = description

        payload = {
            "objectType": "post",
            "objectID": post_id,
            "meta": meta
        }

        try:
            response = self.session.post(url, json=payload)
            return response.status_code == 200
        except Exception:
            return False


# =============================================================================
# Markdown Parser
# =============================================================================

def parse_frontmatter(content: str) -> tuple[dict, str]:
    """
    Parse YAML frontmatter from markdown content.
    Returns (frontmatter_dict, body_content)

    Properly handles --- appearing in YAML comments (e.g., # --- Section ---)
    by only treating --- as a delimiter when it's on its own line.
    """
    lines = content.split("\n")

    # Must start with --- on first line (with optional trailing whitespace)
    if not lines or not re.match(r'^---\s*$', lines[0]):
        return {}, content

    # Find the closing --- (must be on its own line, not in a comment)
    end_index = None
    for i, line in enumerate(lines[1:], start=1):
        if re.match(r'^---\s*$', line):
            end_index = i
            break

    if end_index is None:
        return {}, content

    # Extract frontmatter and body
    frontmatter_lines = lines[1:end_index]
    body_lines = lines[end_index + 1:]

    frontmatter_text = '\n'.join(frontmatter_lines)
    body = '\n'.join(body_lines).strip()

    try:
        frontmatter = yaml.safe_load(frontmatter_text)
        return frontmatter or {}, body
    except yaml.YAMLError as e:
        print(f"    YAML parse error: {e}")
        return {}, content


def markdown_to_html(markdown_content: str) -> str:
    """
    Convert markdown to HTML.
    Uses markdown library with extensions.
    """
    import markdown

    # Clean Obsidian-specific syntax first
    content = clean_obsidian_syntax(markdown_content)

    # Convert to HTML
    md = markdown.Markdown(extensions=[
        'tables',
        'fenced_code',
        'codehilite',
        'toc',
        'smarty'
    ])

    html = md.convert(content)

    # Style tables
    html = style_tables(html)

    # Style code blocks for wrapping
    html = style_code_blocks(html)

    return html


def convert_wikilink_to_url(match: re.Match) -> str:
    """
    Convert an Obsidian wiki-link to a WordPress URL.

    Handles:
    - [[page]] → [Page](/kb/page/)
    - [[page|display]] → [display](/kb/page/)
    - [[page#heading]] → [Page](/kb/page/#heading)
    - [[page#heading|display]] → [display](/kb/page/#heading)
    - [[folder/page]] → [Page](/kb/page/)
    """
    full_match = match.group(1)

    # Split by pipe to get display text
    if '|' in full_match:
        target, display_text = full_match.split('|', 1)
    else:
        target = full_match
        display_text = None

    # Extract heading anchor if present
    heading = None
    if '#' in target:
        target, heading = target.split('#', 1)

    # Get just the filename if it's a path (folder/subfolder/page → page)
    if '/' in target:
        target = target.split('/')[-1]

    # Remove file extension if present
    target = re.sub(r'\.md$', '', target, flags=re.IGNORECASE)

    # Generate slug from target
    slug = target.lower()
    slug = re.sub(r'^[0-9]+[_-]', '', slug)  # Remove numeric prefix
    slug = re.sub(r'[^a-z0-9\s-]', '', slug)
    slug = re.sub(r'[\s_]+', '-', slug)
    slug = re.sub(r'-+', '-', slug)
    slug = slug.strip('-')

    # Generate display text if not provided
    if not display_text:
        display_text = target.replace('-', ' ').replace('_', ' ')
        # Remove numeric prefix for display
        display_text = re.sub(r'^[0-9]+[_\s-]*', '', display_text)
        # Title case
        display_text = display_text.title()
        # Fix common acronyms
        for acronym in ['AI', 'SEO', 'LLM', 'NLP', 'API', 'UI', 'UX', 'ML', 'GPT', 'RAG', 'MCP']:
            display_text = re.sub(rf'\b{acronym.title()}\b', acronym, display_text, flags=re.IGNORECASE)

    # Build the URL
    url = f"/kb/{slug}/"
    if heading:
        # Convert heading to anchor format
        anchor = heading.lower()
        anchor = re.sub(r'[^a-z0-9\s-]', '', anchor)
        anchor = re.sub(r'\s+', '-', anchor)
        url += f"#{anchor}"

    return f"[{display_text}]({url})"


def clean_obsidian_syntax(content: str) -> str:
    """Convert Obsidian-specific syntax for WordPress."""
    # Convert internal links to WordPress URLs
    # Pattern matches [[...]] but not ![[...]] (images)
    content = re.sub(r'(?<!!)\[\[([^\]]+)\]\]', convert_wikilink_to_url, content)

    # Image embeds: ![[image.png]] → remove (WordPress handles images differently)
    content = re.sub(r'!\[\[[^\]]*\]\]', '', content)

    # Footnote anchors: ^anchor → remove
    content = re.sub(r'\^[a-zA-Z0-9-]+', '', content)

    # Dataview blocks: ```dataview ... ``` → remove
    content = re.sub(r'```dataview[\s\S]*?```', '', content, flags=re.MULTILINE)

    return content


def style_tables(html: str) -> str:
    """Add inline styles to tables for WordPress."""
    html = html.replace(
        '<table>',
        '<table style="border-collapse:collapse;width:100%;border:1px solid #ddd;">'
    )
    html = re.sub(
        r'<th([^>]*)>',
        r'<th\1 style="border:1px solid #ddd;padding:8px;background:#f4f4f4;text-align:left;">',
        html
    )
    html = re.sub(
        r'<td([^>]*)>',
        r'<td\1 style="border:1px solid #ddd;padding:8px;text-align:left;">',
        html
    )
    return html


def style_code_blocks(html: str) -> str:
    """Add inline styles to code blocks for proper wrapping in WordPress."""
    # Style pre blocks (fenced code blocks)
    html = html.replace(
        '<pre>',
        '<pre style="background:#f5f5f5;padding:16px;border-radius:4px;overflow-x:auto;white-space:pre-wrap;word-wrap:break-word;">'
    )
    # Style inline code
    html = re.sub(
        r'<code>(?!</pre>)',  # Inline code (not inside pre)
        '<code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;">',
        html
    )
    return html


def generate_slug(text: str) -> str:
    """Generate URL slug from text (title or filename)."""
    slug = text.lower()
    # Convert various Unicode hyphens/dashes to regular hyphen
    slug = re.sub(r'[\u2010\u2011\u2012\u2013\u2014\u2015\u2212]', '-', slug)
    # Convert dots to hyphens (for names like Writer.com → writer-com)
    slug = slug.replace('.', '-')
    # Convert forward slashes to hyphens (for titles like "AI/ML" → "ai-ml")
    slug = slug.replace('/', '-')
    # Remove non-alphanumeric except spaces and hyphens
    slug = re.sub(r'[^a-z0-9\s-]', '', slug)
    # Convert spaces and underscores to hyphens
    slug = re.sub(r'[\s_]+', '-', slug)
    # Collapse multiple hyphens
    slug = re.sub(r'-+', '-', slug)
    return slug.strip('-')


def slug_from_filename(filename: str) -> str:
    """
    Generate URL slug from filename.
    This matches WordPress slugs better than long title-based slugs.

    Example: 02_title-tags-and-meta.md → title-tags-and-meta
    """
    # Remove extension
    name = filename.replace('.md', '')
    # Remove numeric prefix (e.g., 00_, 01_, 02_)
    name = re.sub(r'^[0-9]+[_-]', '', name)
    # Convert to slug format
    return generate_slug(name)


def generate_path_slug(file_path: Path, kb_root: Path) -> str:
    """
    Generate URL slug including directory structure.

    This creates hierarchical URLs that reflect the knowledge base organization.

    Example: kb/TOOLS/marketing-automation/adobe-target.md
          → tools/marketing-automation/adobe-target

    Example: kb/AI/1_models/1_specific-models/claude/claude-overview.md
          → ai/models/specific-models/claude/claude-overview
    """
    # Get path relative to KB root
    rel_path = file_path.relative_to(kb_root)

    # Get directory parts (excluding the filename)
    dir_parts = list(rel_path.parent.parts)

    # Clean each directory part
    clean_dirs = []
    for part in dir_parts:
        # Remove numeric prefixes (e.g., 1_models → models, 01_fundamentals → fundamentals)
        clean = re.sub(r'^[0-9]+[_-]', '', part)
        # Lowercase
        clean = clean.lower()
        # Apply same slug rules for consistency
        clean = generate_slug(clean)
        if clean:  # Skip empty parts
            clean_dirs.append(clean)

    # Get the filename slug
    file_slug = slug_from_filename(file_path.name)

    # Combine directory path with filename slug
    if clean_dirs:
        return "/".join(clean_dirs) + "/" + file_slug
    return file_slug


def title_from_filename(filename: str) -> str:
    """Generate title from filename."""
    # Remove numeric prefix and extension
    name = re.sub(r'^[0-9]+_', '', filename)
    name = name.replace('.md', '')

    # Convert to title case
    title = name.replace('_', ' ').replace('-', ' ').title()

    # Fix common acronyms
    acronyms = ['AI', 'SEO', 'LLM', 'NLP', 'API', 'UI', 'UX', 'ML', 'GPT', 'RAG', 'HTML', 'CSS', 'URL', 'IO', 'MCP']
    for acronym in acronyms:
        title = re.sub(rf'\b{acronym.title()}\b', acronym, title, flags=re.IGNORECASE)

    return title


def get_topic_id(file_path: Path, kb_root: Path) -> int:
    """Determine WordPress topic ID from file path."""
    relative_path = str(file_path.relative_to(kb_root)).replace("\\", "/")

    # Check mappings (most specific first - sorted by length descending)
    for pattern, topic_id in sorted(TOPIC_MAPPING.items(), key=lambda x: -len(x[0])):
        if pattern.strip("/") in relative_path:
            return topic_id

    return DEFAULT_TOPIC_ID


def get_parent_topic_id(file_path: Path, kb_root: Path) -> int:
    """Determine parent WordPress topic ID from file path's top-level folder."""
    relative_path = str(file_path.relative_to(kb_root)).replace("\\", "/")

    # Get top-level folder (AI, SEO, TOOLS, CORE)
    top_folder = relative_path.split("/")[0] if "/" in relative_path else ""

    return PARENT_TOPIC_IDS.get(top_folder, DEFAULT_TOPIC_ID)


# =============================================================================
# Sync Functions
# =============================================================================

def should_sync_file(file_path: Path) -> bool:
    """Check if a file should be synced to WordPress."""
    # Skip index.md files
    if file_path.name == "index.md":
        return False

    # Skip Make.md folder files (folder/folder.md)
    if file_path.stem == file_path.parent.name:
        return False

    # Skip hidden files/folders
    if any(part.startswith('.') for part in file_path.parts):
        return False

    return True


def sync_file(file_path: Path, wp_client: WordPressClient, dry_run: bool = False, existing_mapping: dict = None) -> dict:
    """
    Sync a single markdown file to WordPress and Pinecone.

    Args:
        file_path: Path to the markdown file
        wp_client: WordPress API client
        dry_run: If True, only show what would be synced
        existing_mapping: Dict mapping relative paths to post info (with post_id)

    Returns dict with status, post_id, and any errors.
    """
    result = {
        "file": str(file_path),
        "status": "pending",
        "post_id": None,
        "pinecone": None,
        "error": None
    }

    try:
        # Read file
        content = file_path.read_text(encoding="utf-8")

        # Parse frontmatter
        frontmatter, body = parse_frontmatter(content)

        # Skip if no body content
        if not body.strip():
            result["status"] = "skipped"
            result["error"] = "No body content"
            return result

        # Extract metadata
        title = frontmatter.get("title") or title_from_filename(file_path.name)
        # Use frontmatter slug if specified, otherwise generate from title
        slug = frontmatter.get("slug") or generate_path_slug(file_path, KB_ROOT)
        excerpt = frontmatter.get("excerpt") or frontmatter.get("summary", "")
        tags = frontmatter.get("tags", [])
        if isinstance(tags, str):
            tags = [t.strip() for t in tags.split(",")]

        topic_id = frontmatter.get("topic") or get_topic_id(file_path, KB_ROOT)

        # SEO fields
        keyword = frontmatter.get("primary_keyword") or frontmatter.get("keyword", "")
        meta_desc = frontmatter.get("meta_description", "")

        # AI/RAG fields
        semantic_summary = frontmatter.get("semantic_summary", "")
        synthetic_questions = frontmatter.get("synthetic_questions", [])
        key_concepts = frontmatter.get("key_concepts", [])

        # Date - WordPress requires full ISO 8601 with time
        updated = frontmatter.get("updated", "")
        if updated:
            try:
                if isinstance(updated, str):
                    # Handle date-only format (2026-01-15)
                    if len(updated) == 10 and "-" in updated:
                        updated = f"{updated}T00:00:00"
                    updated = datetime.fromisoformat(updated.replace("Z", "+00:00"))
                # Ensure we have a datetime, not just date
                if hasattr(updated, 'isoformat'):
                    updated = updated.isoformat()
                    # Ensure time component exists
                    if "T" not in updated:
                        updated = f"{updated}T00:00:00"
            except:
                updated = None

        # Convert markdown to HTML
        html_content = markdown_to_html(body)

        if dry_run:
            result["status"] = "dry_run"
            result["title"] = title
            result["slug"] = slug
            result["topic_id"] = topic_id
            return result

        # Process tags
        tag_ids = []
        for tag in tags:
            tag_id = wp_client.get_or_create_tag(tag)
            if tag_id:
                tag_ids.append(tag_id)

        # Build WordPress payload
        parent_topic_id = get_parent_topic_id(file_path, KB_ROOT)
        payload = {
            "title": title,
            "content": html_content,
            "status": "publish",
            "knowledge_topics": [parent_topic_id, topic_id],
            "knowledge_tag": tag_ids
        }

        if excerpt:
            payload["excerpt"] = excerpt

        if updated:
            payload["date"] = updated

        # Note: Rank Math SEO fields are set via dedicated API after post creation

        # ACF fields
        acf = {}
        if semantic_summary:
            acf["semantic_summary"] = semantic_summary
        if synthetic_questions:
            acf["synthetic_questions"] = [{"question": q} for q in synthetic_questions]
        if key_concepts:
            acf["key_concepts"] = [{"concept": c} for c in key_concepts]
        if acf:
            payload["acf"] = acf

        # Check if post exists - first check mapping file for known post_id
        rel_path = str(file_path.relative_to(KB_ROOT)).replace("\\", "/")
        existing_post_id = None

        if existing_mapping and rel_path in existing_mapping:
            existing_post_id = existing_mapping[rel_path].get("post_id")

        if existing_post_id:
            # Update existing post by ID (handles slug changes)
            post = wp_client.update_post(existing_post_id, payload)
            result["status"] = "updated"
        else:
            # Fall back to slug lookup for new files
            existing = wp_client.get_post_by_slug(slug)
            if existing:
                post = wp_client.update_post(existing["id"], payload)
                result["status"] = "updated"
            else:
                post = wp_client.create_post(payload)
                result["status"] = "created"

        result["post_id"] = post["id"]
        result["url"] = post.get("link", f"{WP_SITE_URL}/kb/{slug}/")
        result["title"] = title

        # Update Rank Math SEO meta (uses dedicated Rank Math API)
        if keyword or meta_desc:
            rankmath_success = wp_client.update_rankmath_meta(
                post_id=post["id"],
                focus_keyword=keyword,
                description=meta_desc
            )
            result["rankmath"] = "updated" if rankmath_success else "failed"

        # Sync to Pinecone
        pinecone_content = f"{title}\n\n{body}"
        if semantic_summary:
            pinecone_content = f"{title}\n\n{semantic_summary}\n\n{body}"

        pinecone_result = upsert_to_knowledge_core(
            content=pinecone_content,
            doc_id=f"kb-{post['id']}",
            title=title,
            source=f"{WP_SITE_URL}/kb/{slug}/",
            date=updated or datetime.now().isoformat(),
            post_type="knowledge_base",
            url=result["url"]
        )
        result["pinecone"] = pinecone_result

    except Exception as e:
        result["status"] = "error"
        result["error"] = str(e)

    return result


def sync_all(dry_run: bool = False, filter_path: str = None) -> list[dict]:
    """
    Sync all markdown files in the knowledge base.

    Args:
        dry_run: If True, only show what would be synced
        filter_path: Optional path filter (e.g., "AI/" to only sync AI folder)

    Returns:
        List of sync results
    """
    wp_client = WordPressClient(WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD)
    results = []

    # Load existing mapping file for post_id lookups
    mapping_file = KB_ROOT / "kb_sync_mapping.json"
    existing_mapping = {}
    if mapping_file.exists():
        try:
            existing_mapping = json.loads(mapping_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            existing_mapping = {}

    # Find all markdown files
    for md_file in KB_ROOT.rglob("*.md"):
        # Apply filter if specified
        if filter_path:
            relative = str(md_file.relative_to(KB_ROOT))
            if not relative.startswith(filter_path.replace("/", os.sep)):
                continue

        if not should_sync_file(md_file):
            continue

        print(f"Processing: {md_file.relative_to(KB_ROOT)}")
        result = sync_file(md_file, wp_client, dry_run=dry_run, existing_mapping=existing_mapping)
        results.append(result)

        status_icon = {
            "created": "[+] Created",
            "updated": "[~] Updated",
            "skipped": "[-] Skipped",
            "error": "[!] Error",
            "dry_run": "[?] Would sync"
        }.get(result["status"], result["status"])

        print(f"  {status_icon}: {result.get('title', md_file.name)}")
        if result.get("error"):
            print(f"    Error: {result['error']}")
        if result.get("pinecone"):
            print(f"    Pinecone: {result['pinecone']}")

    return results


def sync_file_by_path(file_path: str, dry_run: bool = False) -> dict:
    """Sync a single file by path."""
    path = Path(file_path)
    if not path.is_absolute():
        path = KB_ROOT / path

    if not path.exists():
        return {"status": "error", "error": f"File not found: {path}"}

    # Load existing mapping for post_id lookup
    mapping_file = KB_ROOT / "kb_sync_mapping.json"
    existing_mapping = {}
    if mapping_file.exists():
        try:
            existing_mapping = json.loads(mapping_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            existing_mapping = {}

    wp_client = WordPressClient(WP_SITE_URL, WP_USERNAME, WP_APP_PASSWORD)
    return sync_file(path, wp_client, dry_run=dry_run, existing_mapping=existing_mapping)


def save_mapping_file(results: list[dict]) -> None:
    """
    Save a mapping file connecting Obsidian files to WordPress posts.

    Creates/updates kb_sync_mapping.json in the kb root folder with:
    - Obsidian file path (relative)
    - WordPress post ID
    - WordPress slug
    - WordPress URL
    - Last sync timestamp
    """
    mapping_file = KB_ROOT / "kb_sync_mapping.json"

    # Load existing mapping
    existing_mapping = {}
    if mapping_file.exists():
        try:
            existing_mapping = json.loads(mapping_file.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            existing_mapping = {}

    # Update with new results
    timestamp = datetime.now().isoformat()
    for result in results:
        if result.get("status") in ("created", "updated") and result.get("post_id"):
            # Get relative path from file path
            file_path = result.get("file", "")
            try:
                rel_path = str(Path(file_path).relative_to(KB_ROOT)).replace("\\", "/")
            except ValueError:
                rel_path = file_path

            existing_mapping[rel_path] = {
                "post_id": result.get("post_id"),
                "slug": result.get("url", "").split("/kb/")[-1].rstrip("/") if "/kb/" in result.get("url", "") else "",
                "url": result.get("url"),
                "title": result.get("title", ""),
                "last_synced": timestamp
            }

    # Save updated mapping
    mapping_file.write_text(
        json.dumps(existing_mapping, indent=2, ensure_ascii=False),
        encoding="utf-8"
    )
    print(f"\nMapping saved to: {mapping_file}")


def export_redirects_csv(output_path: Path = None) -> Path:
    """
    Export a CSV file mapping old URLs to new path-based URLs for Rank Math redirect import.

    Format: source,destination
    Example: /kb/adobe-target/,/kb/tools/marketing-automation/adobe-target/
    """
    import csv

    if output_path is None:
        output_path = KB_ROOT / "redirects_export.csv"

    mapping_file = KB_ROOT / "kb_sync_mapping.json"
    if not mapping_file.exists():
        print(f"Error: Mapping file not found: {mapping_file}")
        print("Run a sync first to generate the mapping file.")
        return None

    with open(mapping_file, "r", encoding="utf-8") as f:
        mapping = json.load(f)

    redirects = []

    for rel_path, data in mapping.items():
        old_slug = data.get("slug", "")
        old_url = f"/kb/{old_slug}/"

        # Calculate new path-based slug
        file_path = KB_ROOT / rel_path
        if file_path.exists():
            new_slug = generate_path_slug(file_path, KB_ROOT)
            new_url = f"/kb/{new_slug}/"

            # Only add if the slug actually changed
            if old_slug != new_slug:
                redirects.append({
                    "source": old_url,
                    "destination": new_url,
                    "file": rel_path
                })

    if not redirects:
        print("No URL changes detected - all slugs match the new format.")
        return None

    # Write CSV for Rank Math
    with open(output_path, "w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["source", "destination"])
        for r in redirects:
            writer.writerow([r["source"], r["destination"]])

    print(f"\nRedirects CSV exported: {output_path}")
    print(f"Total redirects: {len(redirects)}")
    print("\nSample redirects:")
    for r in redirects[:5]:
        print(f"  {r['source']} → {r['destination']}")
    if len(redirects) > 5:
        print(f"  ... and {len(redirects) - 5} more")

    return output_path


# =============================================================================
# CLI Interface
# =============================================================================

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Sync Knowledge Base to WordPress + Pinecone")
    parser.add_argument("--config", type=str, help="Path to config.yaml (default: auto-discover from CWD or project root)")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be synced without making changes")
    parser.add_argument("--file", type=str, help="Sync a specific file (relative to kb root)")
    parser.add_argument("--filter", type=str, help="Filter by path prefix (e.g., 'AI/' or 'SEO/')")
    parser.add_argument("--export-redirects", action="store_true", help="Export CSV of old→new URL redirects for Rank Math")

    args = parser.parse_args()

    # Re-load config if an explicit path was given (overrides the import-time load).
    if args.config:
        _load_config(args.config)

    # Handle redirect export (before sync)
    if args.export_redirects:
        print("=" * 60)
        print("Exporting URL Redirects for Rank Math")
        print("=" * 60)
        export_redirects_csv()
        exit(0)

    print("=" * 60)
    print("Knowledge Base Sync")
    print(f"Source: {KB_ROOT}")
    print(f"Destination: {WP_SITE_URL}")
    print("=" * 60)

    if args.file:
        result = sync_file_by_path(args.file, dry_run=args.dry_run)
        print(json.dumps(result, indent=2))
    else:
        results = sync_all(dry_run=args.dry_run, filter_path=args.filter)

        # Summary
        print("\n" + "=" * 60)
        print("Summary")
        print("=" * 60)

        created = sum(1 for r in results if r["status"] == "created")
        updated = sum(1 for r in results if r["status"] == "updated")
        skipped = sum(1 for r in results if r["status"] == "skipped")
        errors = sum(1 for r in results if r["status"] == "error")

        print(f"Created: {created}")
        print(f"Updated: {updated}")
        print(f"Skipped: {skipped}")
        print(f"Errors:  {errors}")
        print(f"Total:   {len(results)}")

        # Save mapping file
        save_mapping_file(results)
