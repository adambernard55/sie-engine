# tools/sync/__main__.py
"""
Unified CLI for SIE sync tools.

Usage:
    python -m tools.sync --profile posts push
    python -m tools.sync --profile posts push --file 02_Blog/my-post.md
    python -m tools.sync --profile posts pull --since 2026-01-01
    python -m tools.sync --profile pages pull --dry-run
    python -m tools.sync --profile faq push --batch-size 10
"""

import argparse
import json
import sys
from pathlib import Path

from . import get_sync_tool
from .base import save_mapping


def main():
    parser = argparse.ArgumentParser(
        description="SIE Sync — bidirectional WordPress ↔ Obsidian sync"
    )
    parser.add_argument(
        "--profile", type=str, required=True,
        help="Sync profile from config.yaml (e.g., posts, pages, faq, hat-tips, guides)"
    )
    parser.add_argument(
        "--config", type=str,
        help="Path to config.yaml (default: auto-discover)"
    )
    parser.add_argument(
        "direction", choices=["push", "pull"],
        help="push = Obsidian → WP, pull = WP → Obsidian"
    )
    parser.add_argument(
        "--file", type=str,
        help="Push a single file (relative to kb root)"
    )
    parser.add_argument(
        "--filter", type=str,
        help="Filter by path prefix (e.g., '02_Blog/')"
    )
    parser.add_argument(
        "--batch-size", type=int, default=0,
        help="Max files per run (0 = unlimited)"
    )
    parser.add_argument(
        "--offset", type=int, default=0,
        help="Skip this many files before processing"
    )
    parser.add_argument(
        "--since", type=str,
        help="Pull only items modified after this date (YYYY-MM-DD)"
    )
    parser.add_argument(
        "--conflict", type=str, choices=["overwrite", "newer", "skip"],
        help="Conflict resolution for pull (default: from config)"
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Preview without making changes"
    )

    args = parser.parse_args()

    tool = get_sync_tool(profile=args.profile, config_path=args.config)
    config = tool.config

    print("=" * 60)
    print(f"SIE Sync — {args.direction.upper()}")
    print(f"Profile:     {args.profile}")
    print(f"Post type:   {config.wp_post_type}")
    print(f"Source:      {config.kb_root}")
    print(f"Destination: {config.wp_site_url}")
    if args.dry_run:
        print("Mode:        DRY RUN")
    print("=" * 60)

    if args.direction == "push":
        if args.file:
            # Single file push
            file_path = Path(args.file)
            if not file_path.is_absolute():
                file_path = config.kb_root / file_path
            result = tool.push_file(file_path, dry_run=args.dry_run)
            print(json.dumps(result, indent=2))
            results = [result]
        else:
            results = tool.push_all(
                filter_path=args.filter,
                batch_size=args.batch_size,
                offset=args.offset,
                dry_run=args.dry_run,
            )

        # Save mapping
        if not args.dry_run and results:
            save_mapping(config.kb_root, results, config.url_prefix)

    elif args.direction == "pull":
        results = tool.pull_all(
            since=args.since,
            conflict=args.conflict or "newer",
            dry_run=args.dry_run,
        )

        # Save mapping for pulled items
        if not args.dry_run and results:
            save_mapping(config.kb_root, results, config.url_prefix)

    # Summary
    print(f"\n{'=' * 60}")
    print("Summary")
    print("=" * 60)

    counts = {}
    for r in results:
        status = r.get("status", "unknown")
        # Normalize dry-run statuses
        if status.startswith("dry-run"):
            status = "dry-run"
        counts[status] = counts.get(status, 0) + 1

    for status, count in sorted(counts.items()):
        print(f"  {status}: {count}")
    print(f"  Total: {len(results)}")


if __name__ == "__main__":
    main()
