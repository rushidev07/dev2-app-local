# FlxPoint Import — Which Tables Get Written

> **Commands:** `-s` generates the SQL file · `-i` executes it against the database  
> **Source file:** `Helper/CreateSqlFile.php`

---

## How the Import Works (Simple Overview)

```
CSV row
  ↓
Is it a NEW product or an EXISTING product?
  ↓                          ↓
NEW product             EXISTING product
(INSERT rows)           (UPDATE rows only)
```

Every product row in the CSV is wrapped in a `START TRANSACTION / COMMIT` block so if anything fails, that product is rolled back cleanly.

---

## Tables Written — NEW Product

### Simple Product

| # | Table | What gets stored |
|---|-------|-----------------|
| 1 | `catalog_product_entity` | The main product row (sku, type, attribute_set) |
| 2 | `catalog_product_entity_varchar` | Name, URL key, `flxpoint_sku` |
| 3 | `catalog_product_entity_int` | Status (enabled/disabled), Visibility, Tax class |
| 4 | `catalog_product_entity_text` | Description, Short description |
| 5 | `catalog_product_entity_decimal` | Price |
| 6 | `cataloginventory_stock_item` | Qty, Is in stock |
| 7 | `cataloginventory_stock_status` | Qty, Stock status (for search index) |
| 8 | `catalog_product_website` | Links product to website (website_id = 1) |
| 9 | `url_rewrite` | The product URL (e.g. `/my-product.html`) |
| 10 | `catalog_category_product` | Links product to its category |
| 11 | `catalog_product_entity_media_gallery` | Image file path |
| 12 | `catalog_product_entity_media_gallery_value_to_entity` | Links the image to the product |
| 13 | `catalog_product_entity_media_gallery_value` | Image position, label, disabled flag |
| 14 | `marketplace_assignproduct_items` | Assigns the product to the seller |
| 15 | `marketplace_product` | Registers the product in the marketplace with `product_upc` (SKU) |

---

### Configurable Product
Gets everything above **plus** these extra tables for variant linking:

| # | Table | What gets stored |
|---|-------|-----------------|
| 16 | `catalog_product_super_attribute` | Which attributes make this configurable (e.g. Color, Size) |
| 17 | `catalog_product_super_attribute_label` | Display labels for those attributes |
| 18 | `catalog_product_relation` | Parent → child product links |
| 19 | `catalog_product_super_link` | Child → parent links (used by Magento to build the swatch UI) |
| 20 | `catalog_product_entity_text` | `more_specs` custom attribute (tent specs, extra info) |
| 21 | `marketplace_assignproduct_associated_products` | Links each variant to the marketplace configurable |

---

## Tables Written — EXISTING Product (Update Only)

When the product already exists in `marketplace_product`, only these fields are refreshed:

| Table | What gets updated |
|-------|------------------|
| `catalog_product_entity_int` | Status (enabled/disabled) |
| `catalog_product_entity_text` | Description (only if not empty — never wipes existing) |
| `cataloginventory_stock_item` | Qty, Is in stock |
| `cataloginventory_stock_status` | Qty, Stock status |
| `catalog_product_entity_decimal` | Price |
| `catalog_product_entity_varchar` | Brand (`product_brand`), `flxpoint_sku` |
| `catalog_product_entity_int` | Tax class |

> **Note:** Product name, URL key, and URL rewrite are intentionally **NOT updated** on existing products. This protects manually edited names and URLs from being overwritten.

---

## Attribute Auto-Creation Tables

These tables are only written to when a product has a color/size/variation attribute that **doesn't exist yet** in Magento. The stored procedure `InsertProductOptionAttributeProcedure` handles this automatically:

| Table | What gets stored |
|-------|-----------------|
| `eav_attribute` | Creates the new attribute (e.g. `color`, `size`) |
| `catalog_eav_attribute` | Sets attribute properties (filterable, searchable, etc.) |
| `eav_attribute_option` | Creates the option entry for the attribute |
| `eav_attribute_option_value` | Stores the option label (e.g. "Red", "XL") |
| `eav_attribute_option_swatch` | Stores the swatch text value |
| `catalog_product_entity_int` | Sets the chosen option value on the product |

---

## Category Lookup Table (Read Only)

| Table | How it's used |
|-------|--------------|
| `catalog_category_entity_varchar` | Looked up by category name to find the category ID |

If the category name is not found, it defaults to category ID **3196** (hardcoded fallback).

---

## Final Table Written (Always, at End of SQL File)

| Table | What gets stored |
|-------|-----------------|
| `flxpoint_delta` | Updates `last_update_at` timestamp so the next `-j` run only fetches products changed after this import |

---

## Quick Visual Summary

```
NEW SIMPLE PRODUCT
├── catalog_product_entity          ← main row
├── catalog_product_entity_varchar  ← name, url_key, flxpoint_sku
├── catalog_product_entity_int      ← status, visibility, tax
├── catalog_product_entity_text     ← description
├── catalog_product_entity_decimal  ← price
├── cataloginventory_stock_item     ← qty
├── cataloginventory_stock_status   ← qty (for index)
├── catalog_product_website         ← website link
├── url_rewrite                     ← /product-url.html
├── catalog_category_product        ← category link
├── catalog_product_entity_media_gallery              ← image path
├── catalog_product_entity_media_gallery_value_to_entity
├── catalog_product_entity_media_gallery_value
├── marketplace_assignproduct_items ← seller assignment
└── marketplace_product             ← marketplace record (product_upc = SKU)

NEW CONFIGURABLE PRODUCT
└── (everything above) +
    ├── catalog_product_super_attribute               ← Color, Size attributes
    ├── catalog_product_super_attribute_label
    ├── catalog_product_relation                      ← parent→child
    ├── catalog_product_super_link                    ← child→parent
    ├── catalog_product_entity_text (more_specs)
    └── marketplace_assignproduct_associated_products ← variant links

EXISTING PRODUCT UPDATE
├── catalog_product_entity_int      ← status only
├── catalog_product_entity_text     ← description (if not empty)
├── cataloginventory_stock_item     ← qty, stock
├── cataloginventory_stock_status   ← qty, stock
├── catalog_product_entity_decimal  ← price
└── catalog_product_entity_varchar  ← brand, flxpoint_sku
```

---

## How the Upsert Logic Decides NEW vs EXISTING

The procedure `UpsertMarketplaceProduct(seller_id, product_upc)` does this check:

```sql
SELECT mageproduct_id FROM marketplace_product 
WHERE seller_id = p_seller_id AND product_upc = p_product_upc
```

- **Found** → runs UPDATE path (existing product)
- **Not found** → runs INSERT path (new product)

`product_upc` = the SKU used in the import (UPC-based or prefixed UPC).
