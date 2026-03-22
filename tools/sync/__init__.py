# tools/sync/__init__.py
"""
SIE Sync Package — modular bidirectional sync tools.

Usage:
    python -m tools.sync --profile posts push
    python -m tools.sync --profile posts pull --since 2026-01-01
    python -m tools.sync --profile pages pull --dry-run
    python -m tools.sync --profile faq push --file 04_FAQ/example.md
"""

from .base import SyncConfig, load_config, BaseSyncTool, should_sync_file
from .sync_posts import PostSync
from .sync_pages import PageSync
from .sync_products import ProductSync
from .sync_cpt import CptSync

# Registry: wp_post_type value → sync tool class
SYNC_TOOLS = {
    # Standard WP types
    "posts": PostSync,
    "pages": PageSync,
    # WooCommerce
    "products": ProductSync,
    # SIE CPTs (all use the same handler)
    "faq": CptSync,
    "insights": CptSync,
    "pro-tips": CptSync,
    "guides": CptSync,
    # Knowledge base (legacy)
    "knowledge": CptSync,
    "knowledge-base": CptSync,
}


def get_sync_tool(profile: str = None, config_path: str = None) -> BaseSyncTool:
    """Factory: load config with profile overlay, return the right sync tool."""
    config = load_config(config_path, profile=profile)
    tool_cls = SYNC_TOOLS.get(config.wp_post_type, CptSync)
    return tool_cls(config)
