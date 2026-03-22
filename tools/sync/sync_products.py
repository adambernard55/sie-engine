# tools/sync/sync_products.py
"""
Sync WooCommerce products.

Push: Obsidian (.md) → WooCommerce products
Pull: WooCommerce products → Obsidian (.md)

Uses /wc/v3/ API with consumer key/secret authentication.

Field mapping (frontmatter ↔ WooCommerce):
    title           ↔ name
    slug            ↔ slug
    status          ↔ status
    product_type    ↔ type  (simple, variable, grouped, external)
    sku             ↔ sku
    price           ↔ regular_price
    sale_price      ↔ sale_price
    stock_status    ↔ stock_status  (instock, outofstock, onbackorder)
    manage_stock    ↔ manage_stock
    stock_quantity  ↔ stock_quantity
    weight          ↔ weight
    dimensions      ↔ dimensions  {length, width, height}
    categories      ↔ categories  (list of names)
    tags            ↔ tags  (list of names)
    attributes      ↔ attributes  {Name: [options]}
    images          ↔ images  (list of URLs)
    variations      ↔ variations  (list of variation IDs — pull only)
    excerpt         ↔ short_description
    featured        ↔ featured
    primary_keyword ↔ meta_data[rank_math_focus_keyword]
    meta_description↔ meta_data[rank_math_description]
    seo_title       ↔ meta_data[rank_math_title]
"""

import re
import html as html_module
from pathlib import Path
from typing import Optional

from .base import (
    BaseSyncTool, SyncConfig,
    parse_frontmatter, html_to_markdown, markdown_to_html,
    build_frontmatter_string,
    resolve_conflict, save_mapping, generate_slug, title_from_filename,
)


class ProductSync(BaseSyncTool):
    """Bidirectional sync for WooCommerce products."""

    def _wc_request(self, method: str, path: str, **kwargs) -> dict:
        """Make an authenticated WooCommerce API request."""
        url = self.wp._wc_endpoint(path)
        params = kwargs.pop("params", {})
        params["consumer_key"] = self.config.wc_consumer_key
        params["consumer_secret"] = self.config.wc_consumer_secret

        func = getattr(self.wp.session, method)
        resp = func(url, params=params, timeout=30, **kwargs)

        if resp.status_code >= 400:
            raise Exception(f"WC {method.upper()} {path}: {resp.status_code} {resp.text[:500]}")
        return resp.json()

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
                payload["description"] = markdown_to_html(body, self.config.url_prefix)

            # Core fields
            if frontmatter.get("excerpt"):
                payload["short_description"] = frontmatter["excerpt"]
            if frontmatter.get("product_type"):
                payload["type"] = frontmatter["product_type"]
            if frontmatter.get("featured") is not None:
                payload["featured"] = bool(frontmatter["featured"])

            # Pricing
            if frontmatter.get("price"):
                payload["regular_price"] = str(frontmatter["price"])
            if frontmatter.get("sale_price"):
                payload["sale_price"] = str(frontmatter["sale_price"])

            # Inventory
            if frontmatter.get("sku"):
                payload["sku"] = frontmatter["sku"]
            if frontmatter.get("stock_status"):
                payload["stock_status"] = frontmatter["stock_status"]
            if frontmatter.get("manage_stock") is not None:
                payload["manage_stock"] = bool(frontmatter["manage_stock"])
            if frontmatter.get("stock_quantity") is not None:
                payload["stock_quantity"] = int(frontmatter["stock_quantity"])

            # Shipping
            if frontmatter.get("weight"):
                payload["weight"] = str(frontmatter["weight"])
            if frontmatter.get("dimensions"):
                dims = frontmatter["dimensions"]
                if isinstance(dims, dict):
                    payload["dimensions"] = {
                        "length": str(dims.get("length", "")),
                        "width": str(dims.get("width", "")),
                        "height": str(dims.get("height", "")),
                    }

            # Categories (by name — WC resolves or creates)
            if frontmatter.get("categories"):
                cats = frontmatter["categories"]
                if isinstance(cats, list):
                    payload["categories"] = [{"name": c} for c in cats]

            # Tags (by name)
            if frontmatter.get("tags"):
                tags = frontmatter["tags"]
                if isinstance(tags, list):
                    payload["tags"] = [{"name": t} for t in tags]

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

            # Check if product exists by slug
            existing = self._wc_request("get", "products", params={"slug": slug})
            if existing:
                product_id = existing[0]["id"]
                post = self._wc_request("put", f"products/{product_id}", json=payload)
                result["status"] = "updated"
            else:
                post = self._wc_request("post", "products", json=payload)
                result["status"] = "created"

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
        """Build comprehensive frontmatter from a WooCommerce product."""
        fm = {}

        # --- Core ---
        fm["title"] = html_module.unescape(product.get("name", ""))
        fm["slug"] = product.get("slug", "")
        fm["status"] = product.get("status", "publish")
        fm["wp_id"] = product.get("id")
        fm["product_type"] = product.get("type", "simple")

        if product.get("date_created"):
            fm["date"] = product["date_created"][:10]
        if product.get("date_modified"):
            fm["updated"] = product["date_modified"][:10]

        if product.get("featured"):
            fm["featured"] = True

        # --- Short description ---
        short_desc = product.get("short_description", "")
        if short_desc:
            short_desc = re.sub(r"<[^>]+>", "", short_desc).strip()
            short_desc = html_module.unescape(short_desc)
            if short_desc:
                fm["excerpt"] = short_desc

        # --- Pricing ---
        if product.get("regular_price"):
            fm["price"] = product["regular_price"]
        elif product.get("price"):
            fm["price"] = product["price"]
        if product.get("sale_price"):
            fm["sale_price"] = product["sale_price"]

        # --- Inventory ---
        if product.get("sku"):
            fm["sku"] = product["sku"]
        fm["stock_status"] = product.get("stock_status", "instock")
        if product.get("manage_stock"):
            fm["manage_stock"] = True
            if product.get("stock_quantity") is not None:
                fm["stock_quantity"] = product["stock_quantity"]

        # --- Shipping ---
        if product.get("weight"):
            fm["weight"] = product["weight"]
        dims = product.get("dimensions", {})
        if dims and any(dims.get(k) for k in ("length", "width", "height")):
            fm["dimensions"] = {
                k: dims[k] for k in ("length", "width", "height") if dims.get(k)
            }

        # --- Categories ---
        prod_cats = product.get("categories", [])
        if prod_cats:
            cat_names = [
                html_module.unescape(c.get("name", ""))
                for c in prod_cats if isinstance(c, dict) and c.get("name")
            ]
            if cat_names:
                fm["categories"] = cat_names

        # --- Tags ---
        prod_tags = product.get("tags", [])
        if prod_tags:
            tag_names = [
                html_module.unescape(t.get("name", ""))
                for t in prod_tags if isinstance(t, dict) and t.get("name")
            ]
            if tag_names:
                fm["tags"] = tag_names

        # --- Attributes ---
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

        # --- Images ---
        images = product.get("images", [])
        if images:
            image_urls = [img.get("src", "") for img in images
                         if isinstance(img, dict) and img.get("src")]
            if image_urls:
                fm["images"] = image_urls

        # --- Variations (pull only — IDs for reference) ---
        variations = product.get("variations", [])
        if variations:
            fm["variation_ids"] = variations

        # --- SEO (Rank Math via meta_data) ---
        meta_data = product.get("meta_data", [])
        if meta_data:
            meta_lookup = {m["key"]: m["value"] for m in meta_data
                          if isinstance(m, dict) and m.get("key")}

            # Rank Math fields
            kw = (meta_lookup.get("rank_math_focus_keyword")
                  or meta_lookup.get("_yoast_wpseo_focuskw") or "")
            if kw:
                fm["primary_keyword"] = kw

            desc = (meta_lookup.get("rank_math_description")
                    or meta_lookup.get("_yoast_wpseo_metadesc") or "")
            if desc:
                fm["meta_description"] = desc

            seo_title = (meta_lookup.get("rank_math_title")
                         or meta_lookup.get("_yoast_wpseo_title") or "")
            if seo_title and seo_title != fm.get("title", ""):
                fm["seo_title"] = seo_title

        return fm
