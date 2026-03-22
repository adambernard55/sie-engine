# tools/sync/sync_products.py
"""
Sync WooCommerce products.

Push: Obsidian (.md) → WooCommerce products
Pull: WooCommerce products → Obsidian (.md)

Uses /wc/v3/ API with consumer key/secret authentication.
"""

import re
import html as html_module
from pathlib import Path
from typing import Optional

from .base import (
    BaseSyncTool, SyncConfig,
    parse_frontmatter, html_to_markdown, build_frontmatter_string,
    resolve_conflict, save_mapping, generate_slug, title_from_filename,
)


class ProductSync(BaseSyncTool):
    """Bidirectional sync for WooCommerce products."""

    # --- Push ---

    def push_file(self, file_path: Path, dry_run: bool = False) -> dict:
        result = {"file": str(file_path), "status": "pending",
                  "post_id": None, "pinecone": None, "error": None}

        try:
            content = file_path.read_text(encoding="utf-8")
            frontmatter, body = parse_frontmatter(content)

            if not body.strip() and not frontmatter.get("title"):
                result["status"] = "skipped"
                result["error"] = "No content"
                return result

            title = frontmatter.get("title") or title_from_filename(file_path.name)
            slug = frontmatter.get("slug") or generate_slug(title)

            if dry_run:
                result.update(status="dry_run", title=title, slug=slug)
                return result

            # Build WooCommerce product payload
            payload = {
                "name": title,
                "slug": slug,
                "status": frontmatter.get("status", "publish"),
            }

            if body.strip():
                from .base import markdown_to_html
                payload["description"] = markdown_to_html(body, self.config.url_prefix)

            if frontmatter.get("excerpt"):
                payload["short_description"] = frontmatter["excerpt"]
            if frontmatter.get("price"):
                payload["regular_price"] = str(frontmatter["price"])
            if frontmatter.get("sku"):
                payload["sku"] = frontmatter["sku"]
            if frontmatter.get("stock_status"):
                payload["stock_status"] = frontmatter["stock_status"]
            if frontmatter.get("product_type"):
                payload["type"] = frontmatter["product_type"]

            # Categories
            if frontmatter.get("categories"):
                cats = frontmatter["categories"]
                if isinstance(cats, list):
                    # Resolve category names to IDs via WC API
                    payload["categories"] = [{"name": c} for c in cats]

            # Attributes
            if frontmatter.get("attributes"):
                attrs = frontmatter["attributes"]
                if isinstance(attrs, dict):
                    payload["attributes"] = [
                        {
                            "name": name,
                            "options": opts if isinstance(opts, list) else [opts],
                            "visible": True,
                        }
                        for name, opts in attrs.items()
                    ]

            # WooCommerce uses its own API
            wc_url = self.wp._wc_endpoint("products")

            # Check if product exists by slug
            existing_resp = self.wp.session.get(
                wc_url,
                params={
                    "slug": slug,
                    "consumer_key": self.config.wc_consumer_key,
                    "consumer_secret": self.config.wc_consumer_secret,
                },
                timeout=30,
            )

            if existing_resp.status_code == 200:
                existing_products = existing_resp.json()
                if existing_products:
                    product_id = existing_products[0]["id"]
                    resp = self.wp.session.put(
                        f"{wc_url}/{product_id}",
                        json=payload,
                        params={
                            "consumer_key": self.config.wc_consumer_key,
                            "consumer_secret": self.config.wc_consumer_secret,
                        },
                        timeout=30,
                    )
                    if resp.status_code >= 400:
                        raise Exception(f"{resp.status_code}: {resp.text[:500]}")
                    post = resp.json()
                    result["status"] = "updated"
                else:
                    resp = self.wp.session.post(
                        wc_url,
                        json=payload,
                        params={
                            "consumer_key": self.config.wc_consumer_key,
                            "consumer_secret": self.config.wc_consumer_secret,
                        },
                        timeout=30,
                    )
                    if resp.status_code >= 400:
                        raise Exception(f"{resp.status_code}: {resp.text[:500]}")
                    post = resp.json()
                    result["status"] = "created"
            else:
                raise Exception(f"WC API error: {existing_resp.status_code}")

            result["post_id"] = post["id"]
            result["url"] = post.get("permalink", f"{self.config.wp_site_url}/product/{slug}/")
            result["title"] = title
            result["pinecone"] = "skipped (products)"

        except Exception as e:
            result["status"] = "error"
            result["error"] = str(e)

        return result

    # --- Pull ---

    def pull_all(self, since: str = None, conflict: str = "newer",
                 dry_run: bool = False) -> list[dict]:
        endpoint = "/wp-json/wc/v3/products"
        folder = self.config.filter_path or "03_Products"
        auth_type = "woocommerce"

        for pt_cfg in self.config.import_config.get("post_types", []):
            if pt_cfg.get("type") == "product":
                endpoint = pt_cfg["endpoint"]
                folder = pt_cfg.get("folder", folder)
                auth_type = pt_cfg.get("auth", auth_type)
                break

        print(f"Pulling products from {endpoint} → {folder}/")
        products = self.wp.fetch_all(endpoint, auth_type=auth_type, since=since)
        print(f"  Fetched {len(products)} products")

        conflict_strategy = conflict or self.config.import_config.get(
            "conflict_strategy", "newer")
        results = []

        for product in products:
            fm = self._build_product_frontmatter(product)
            title = fm.get("title", "Untitled")
            slug = fm.get("slug", "untitled")
            wp_id = fm.get("wp_id")

            # Convert body
            description = product.get("description", "")
            body_md = html_to_markdown(description)

            target_file = self.config.kb_root / folder / f"{slug}.md"
            rel_path = str(target_file.relative_to(self.config.kb_root)).replace("\\", "/")

            action = resolve_conflict(target_file, product.get("date_modified", ""),
                                      conflict_strategy)

            result = {"file": rel_path, "title": title, "wp_id": wp_id,
                      "post_type": "product"}

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

    def _build_product_frontmatter(self, product: dict) -> dict:
        """Build frontmatter from a WooCommerce product."""
        fm = {}

        fm["title"] = html_module.unescape(product.get("name", ""))
        fm["slug"] = product.get("slug", "")
        fm["status"] = product.get("status", "publish")
        fm["wp_id"] = product.get("id")

        if product.get("date_created"):
            fm["date"] = product["date_created"][:10]
        if product.get("date_modified"):
            fm["updated"] = product["date_modified"][:10]

        # Short description as excerpt
        short_desc = product.get("short_description", "")
        if short_desc:
            short_desc = re.sub(r"<[^>]+>", "", short_desc).strip()
            short_desc = html_module.unescape(short_desc)
            if short_desc:
                fm["excerpt"] = short_desc

        # Product-specific fields
        if product.get("regular_price"):
            fm["price"] = product["regular_price"]
        elif product.get("price"):
            fm["price"] = product["price"]

        if product.get("sku"):
            fm["sku"] = product["sku"]
        if product.get("stock_status"):
            fm["stock_status"] = product["stock_status"]
        if product.get("type"):
            fm["product_type"] = product["type"]

        # Categories
        prod_cats = product.get("categories", [])
        if prod_cats:
            cat_names = [
                html_module.unescape(c.get("name", ""))
                for c in prod_cats if isinstance(c, dict) and c.get("name")
            ]
            if cat_names:
                fm["categories"] = cat_names

        # Images
        images = product.get("images", [])
        if images:
            image_urls = [img.get("src", "") for img in images
                         if isinstance(img, dict) and img.get("src")]
            if image_urls:
                fm["images"] = image_urls

        # Attributes
        attributes = product.get("attributes", [])
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
