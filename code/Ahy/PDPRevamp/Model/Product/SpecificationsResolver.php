<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product;

use Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpSpecificationsAttribute;
use Magento\Catalog\Model\Product;

/**
 * Single source of truth for the PDP "Specifications" tab (see
 * Block\Product\View\Specifications and product/view/specifications.phtml),
 * mirroring the same admin-first/fallback split as
 * Model\Product\KeyFeaturesResolver:
 *
 * - If the admin has filled in the "pdp_specifications" textarea attribute
 *   (one "Label: Value" pair per line - see
 *   Setup\Patch\Data\CreatePdpSpecificationsAttribute), those rows are used
 *   as-is.
 * - Otherwise, falls back to the last <table> found in the product's plain
 *   Description field - the label/value spec table this catalog's
 *   descriptions are commonly authored with (each row a <tr> of two <td>
 *   cells: label, value). Rows without exactly two non-empty cells are
 *   skipped.
 * - Either way, when falling back, that same table (and a bare lead-in
 *   heading immediately before it, e.g. "Technical Specs") is stripped out
 *   of the plain description text (see stripFallbackTableFromDescription()),
 *   so it doesn't render twice - once as a raw table in the description,
 *   once as the Specifications tab's own styled rows.
 */
class SpecificationsResolver
{
    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getSpecifications(Product $product, string $descriptionHtml): array
    {
        $adminRows = $this->getAdminRows($product);
        if ($adminRows) {
            return $adminRows;
        }

        return $this->extractFallbackTable($descriptionHtml);
    }

    /**
     * Only meaningful to call when getSpecifications() ended up using the
     * fallback table (no admin rows) - removes that same table (and any
     * bare lead-in heading immediately before it) from the plain
     * description text so it isn't shown twice.
     */
    public function stripFallbackTableFromDescription(Product $product, string $descriptionHtml): string
    {
        if ($this->getAdminRows($product)) {
            // Admin rows exist, so getSpecifications() didn't use the
            // fallback table - leave the description exactly as authored.
            return $descriptionHtml;
        }

        $found = $this->findFallbackTable($descriptionHtml);
        if ($found === null) {
            return $descriptionHtml;
        }

        foreach ($found['nodes'] as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        return $this->innerHtml($found['doc'], $this->wrapperDiv($found['doc']));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function getAdminRows(Product $product): array
    {
        $raw = (string) $product->getData(CreatePdpSpecificationsAttribute::ATTRIBUTE_CODE);
        if (trim($raw) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = explode(':', $line, 2);
            $label = trim($label);
            $value = trim($value);
            if ($label === '' || $value === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function extractFallbackTable(string $descriptionHtml): array
    {
        $found = $this->findFallbackTable($descriptionHtml);
        if ($found === null) {
            return [];
        }

        $rows = [];
        foreach ($found['nodes'][0]->getElementsByTagName('tr') as $tr) {
            $cells = $tr->getElementsByTagName('td');
            if ($cells->length < 2) {
                continue;
            }

            $label = trim($cells->item(0)->textContent);
            $value = trim($cells->item(1)->textContent);
            if ($label === '' || $value === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @return array{doc: \DOMDocument, nodes: \DOMElement[]}|null
     */
    private function findFallbackTable(string $descriptionHtml): ?array
    {
        // Some descriptions (Page Builder's "HTML" content type) store
        // their tags HTML-entity-encoded as literal text rather than real
        // markup - decode first so DOMDocument actually sees real elements.
        $decoded = html_entity_decode($descriptionHtml, ENT_QUOTES | ENT_HTML5);
        if (trim($decoded) === '') {
            return null;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $decoded . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        // Last <table> in document order - same "trailing content" reading
        // KeyFeaturesResolver applies to its own <ul>/<ol> fallback.
        $tables = $doc->getElementsByTagName('table');
        if ($tables->length === 0) {
            return null;
        }

        $tableNode = $tables->item($tables->length - 1);

        return [
            'doc' => $doc,
            'nodes' => $this->withPrecedingSpecsLabel($tableNode),
        ];
    }

    /**
     * Mirrors KeyFeaturesResolver::withPrecedingFeaturesLabel() - a spec
     * table is frequently introduced by its own bare lead-in line
     * ("Technical Specs", "Specifications", ...) immediately before it,
     * which isn't part of the table itself so must be stripped alongside it.
     *
     * @return \DOMElement[]
     */
    private function withPrecedingSpecsLabel(\DOMElement $node): array
    {
        $sibling = $node->previousSibling;
        while ($sibling instanceof \DOMText && $this->normalizeWhitespace($sibling->textContent) === '') {
            $sibling = $sibling->previousSibling;
        }

        if ($sibling instanceof \DOMElement
            && preg_match(
                '/^(technical\s+)?spec(ification)?s\s*:?\s*$/iu',
                $this->normalizeWhitespace($sibling->textContent)
            )
        ) {
            return [$node, $sibling];
        }

        return [$node];
    }

    /**
     * See KeyFeaturesResolver::normalizeWhitespace() - trim() alone doesn't
     * catch a WYSIWYG editor's trailing "&nbsp;" (U+00A0).
     */
    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    private function wrapperDiv(\DOMDocument $doc): \DOMElement
    {
        return $doc->getElementsByTagName('div')->item(0);
    }

    private function innerHtml(\DOMDocument $doc, \DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }
}
