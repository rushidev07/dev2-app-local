<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product;

use Magento\Catalog\Model\Product;

/**
 * Single source of truth for the PDP "Key Features" tile grid (see
 * product/view/key-features.phtml), shared with details-description.phtml
 * so both templates agree on the same decision:
 *
 * - If the product has admin-entered rows in the "key_features" attribute
 *   (see Setup/Patch/Data/CreateKeyFeaturesAttribute.php and the
 *   "Key Features" dynamic-rows grid on the product edit page), those are
 *   used as-is - real titles and descriptions, admin-managed, immune to
 *   FlxPoint's description sync.
 * - Otherwise, falls back to whatever bullet content is already in the
 *   product's plain Description field. Five shapes have been observed
 *   across this catalog and all five are handled:
 *     1. A real <ul>/<ol> list (the last one in the description, on the
 *        assumption of "intro paragraphs, then a trailing feature list").
 *        Title-only tiles.
 *     2. A single <p> built from multiple "&bull; text<br />" lines with
 *        no real list markup at all (seen on FlxPoint-synced products
 *        that were never given a real <ul>). Title-only tiles.
 *     3. A run of "<p><strong>Label:</strong> description text</p>"
 *        paragraphs, typically following a "Key Features" heading (a
 *        common Page Builder / hand-authored shape). Each paragraph
 *        becomes a title+description tile, split on the bold label.
 *     4. A rich-text editor's own serialized document JSON
 *        (`{"type":"root","children":[...]}`, a Slate/Lexical-style tree)
 *        pasted directly into the description as literal text instead of
 *        being converted to HTML - seen on some AI-generated/import
 *        content. Each `list-item` node's `text` children are read - the
 *        first one flagged `"bold":true` becomes the tile's title (like
 *        shape 3), the rest become its description.
 *     5. A run of separate `<p class="MsoNormal">` paragraphs pasted
 *        straight from Microsoft Word - each one's "bullet" isn't a real
 *        list marker or the U+2022 `•` character shape 2 looks for, but
 *        Word's own `●` (U+25CF) glyph (wrapped in a
 *        `font-family:"Noto Sans Symbols"` span plus tab-padding spans)
 *        ahead of the real text. Each paragraph becomes a title-only tile,
 *        the leading bullet glyph stripped off.
 * - Some descriptions (Page Builder's "HTML" content type) store their
 *   tags HTML-entity-encoded as literal text (e.g. "&lt;ul&gt;") rather
 *   than as real markup - decoded before parsing so those are found too.
 * - When falling back, the same bullet content (and, for shapes 3, 4 and
 *   5, the "<Word> Features"/"Features" label it followed - e.g. "Key
 *   Features", "Product Features" - matched generically, not just the
 *   literal phrase "Key Features") must also be stripped out of the plain
 *   description text (see
 *   stripFallbackListFromDescription()), so it doesn't render twice -
 *   once as flat text (or, for shape 4, raw unparsed JSON), once as tiles.
 */
class KeyFeaturesResolver
{
    private const ATTRIBUTE_CODE = 'key_features';

    /**
     * @return array<int, array{title: string, description: string}>
     */
    public function getTiles(Product $product, string $descriptionHtml): array
    {
        $adminRows = $this->getAdminRows($product);
        if ($adminRows) {
            return $adminRows;
        }

        return $this->extractFallbackTiles($descriptionHtml);
    }

    /**
     * Only meaningful to call when getTiles() ended up using the fallback
     * path (no admin rows) - removes the same bullet content (and, for the
     * bold-label-paragraphs shape, the heading it followed) from the plain
     * description text so it isn't shown twice.
     */
    public function stripFallbackListFromDescription(Product $product, string $descriptionHtml): string
    {
        if ($this->getAdminRows($product)) {
            // Admin rows exist, so getTiles() didn't use the fallback list -
            // leave the description exactly as authored.
            return $descriptionHtml;
        }

        $found = $this->findFallbackNode($descriptionHtml);
        if ($found === null) {
            return $descriptionHtml;
        }

        foreach ($found['nodes'] as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }

        if ($found['heading'] !== null && $found['heading']->parentNode !== null) {
            $found['heading']->parentNode->removeChild($found['heading']);
        }

        return $this->innerHtml($found['doc'], $this->wrapperDiv($found['doc']));
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    private function getAdminRows(Product $product): array
    {
        $rows = $product->getData(self::ATTRIBUTE_CODE);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    private function extractFallbackTiles(string $descriptionHtml): array
    {
        $found = $this->findFallbackNode($descriptionHtml);
        if ($found === null) {
            return [];
        }

        switch ($found['type']) {
            case 'list':
                $items = [];
                foreach ($found['nodes'][0]->getElementsByTagName('li') as $li) {
                    $text = trim($li->textContent);
                    if ($text !== '') {
                        $items[] = $this->splitPlainLabelText($text);
                    }
                }
                return $items;

            case 'bullet_paragraph':
                // Split the <p>'s own content on <br> tags, then strip
                // each line's leading bullet character.
                $innerHtml = $this->innerHtml($found['doc'], $found['nodes'][0]);
                $lines = preg_split('/<br\s*\/?>/i', $innerHtml) ?: [];

                $items = [];
                foreach ($lines as $line) {
                    $text = trim(strip_tags($line));
                    $text = preg_replace('/^[\x{2022}\-\*\s]+/u', '', $text) ?? $text;
                    $text = trim($text);
                    if ($text !== '') {
                        $items[] = $this->splitPlainLabelText($text);
                    }
                }
                return $items;

            case 'bold_label_paragraphs':
                $items = [];
                foreach ($found['nodes'] as $p) {
                    $tile = $this->splitBoldLabelParagraph($p);
                    if ($tile !== null) {
                        $items[] = $tile;
                    }
                }
                return $items;

            case 'json_features':
                return $found['tiles'] ?? [];

            case 'bullet_char_paragraphs':
                $items = [];
                foreach ($found['nodes'] as $p) {
                    $text = $this->stripLeadingBulletChar($this->normalizeWhitespace($p->textContent));
                    if ($text !== '') {
                        $items[] = $this->splitPlainLabelText($text);
                    }
                }
                return $items;
        }

        return [];
    }

    /**
     * @return array{doc: \DOMDocument, nodes: \DOMElement[], type: 'list'|'bullet_paragraph'|'bold_label_paragraphs'|'json_features'|'bullet_char_paragraphs', heading: \DOMElement|null, tiles?: array<int, array{title: string, description: string}>}|null
     */
    private function findFallbackNode(string $descriptionHtml): ?array
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

        // Most distinctive signal first: a rich-text editor's own document
        // JSON pasted in as literal text rather than converted to HTML - an
        // unambiguous marker ({"type":"root",...), so checked before the
        // other shapes regardless of whatever else is also in the
        // description.
        $jsonFeatures = $this->findJsonFeaturesList($doc);
        if ($jsonFeatures !== null) {
            return [
                'doc' => $doc,
                'nodes' => $this->withPrecedingFeaturesLabel($jsonFeatures['node']),
                'type' => 'json_features',
                'heading' => null,
                'tiles' => $jsonFeatures['tiles'],
            ];
        }

        // Preferred: a real <ul>/<ol> list. Last one in document order -
        // the common shape across this catalog is intro paragraphs
        // followed by a trailing feature list.
        $lists = [];
        foreach (['ul', 'ol'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $lists[] = $node;
            }
        }
        if ($lists) {
            $listNode = $lists[count($lists) - 1];

            return [
                'doc' => $doc,
                'nodes' => $this->withPrecedingFeaturesLabel($listNode),
                'type' => 'list',
                'heading' => null,
            ];
        }

        // Next: a <p> built from multiple "&bull; text<br />" lines with
        // no real list markup - decoding turns "&bull;" into the literal
        // bullet character (U+2022), which is what's matched here.
        foreach ($doc->getElementsByTagName('p') as $p) {
            if (substr_count($p->textContent, "\u{2022}") >= 2) {
                return [
                    'doc' => $doc,
                    'nodes' => $this->withPrecedingFeaturesLabel($p),
                    'type' => 'bullet_paragraph',
                    'heading' => null,
                ];
            }
        }

        // Next: a run of "<p><strong>Label:</strong> text</p>" paragraphs -
        // no list markup, no bullet characters, just a bold label starting
        // each paragraph.
        $boldParagraphs = $this->findBoldLabelParagraphs($doc);
        if (count($boldParagraphs['nodes']) >= 2) {
            return [
                'doc' => $doc,
                'nodes' => $boldParagraphs['nodes'],
                'type' => 'bold_label_paragraphs',
                'heading' => $boldParagraphs['heading'],
            ];
        }

        // Last resort: a run of separate <p> paragraphs pasted from
        // Microsoft Word, each starting with Word's own bullet glyph (●,
        // U+25CF) rather than a real list marker or the U+2022 character
        // shape 2 looks for.
        $bulletCharParagraphs = $this->findBulletCharParagraphs($doc);
        if (count($bulletCharParagraphs['nodes']) >= 2) {
            return [
                'doc' => $doc,
                'nodes' => $bulletCharParagraphs['nodes'],
                'type' => 'bullet_char_paragraphs',
                'heading' => $bulletCharParagraphs['heading'],
            ];
        }

        return null;
    }

    /**
     * The <ul>/<ol> and bullet-paragraph fallback shapes are frequently
     * introduced by their own bare lead-in line - a paragraph that's just
     * "Features:" or "Key Features:" with nothing else - immediately before
     * the list itself. That line isn't part of the list so the two shapes
     * above never see it, but leaving it behind produces a stray "Features:"
     * line sitting right on top of the tile grid's own "Key Features"
     * heading once the list is promoted into tiles. Bundles it in with the
     * node to strip when found; returns just [$node] otherwise.
     *
     * @return \DOMElement[]
     */
    private function withPrecedingFeaturesLabel(\DOMElement $node): array
    {
        $sibling = $node->previousSibling;
        while ($sibling instanceof \DOMText && $this->normalizeWhitespace($sibling->textContent) === '') {
            $sibling = $sibling->previousSibling;
        }

        if ($sibling instanceof \DOMElement
            && preg_match('/^(?:\w+\s+)?features\s*:?\s*$/iu', $this->normalizeWhitespace($sibling->textContent))
        ) {
            return [$node, $sibling];
        }

        return [$node];
    }

    /**
     * trim() only strips ASCII whitespace - WYSIWYG editors (TinyMCE etc.)
     * routinely leave a trailing "&nbsp;" (U+00A0) where a plain space
     * looks like it should be, which trim() (and \s in a non-/u regex)
     * silently ignores, leaving it stuck to the end of the "matched" text.
     * Collapses that (and other Unicode whitespace) down to plain spaces
     * before any bare-label comparison.
     */
    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    /**
     * @return array{nodes: \DOMElement[], heading: \DOMElement|null}
     */
    private function findBoldLabelParagraphs(\DOMDocument $doc): array
    {
        // Prefer the paragraphs immediately following a "Key Features"
        // heading, so unrelated bold-label paragraphs elsewhere in the
        // description (e.g. specs under a different heading) aren't
        // mistaken for the feature list.
        foreach ($doc->getElementsByTagName('*') as $node) {
            if (!$node instanceof \DOMElement || !preg_match('/^h[1-6]$/i', $node->tagName)) {
                continue;
            }
            if (stripos(trim($node->textContent), 'features') === false) {
                continue;
            }

            $nodes = [];
            $sibling = $node->nextSibling;
            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement
                    && strtolower($sibling->tagName) === 'p'
                    && $this->isBoldLabelParagraph($sibling)
                ) {
                    $nodes[] = $sibling;
                    $sibling = $sibling->nextSibling;
                    continue;
                }
                if ($sibling instanceof \DOMText && trim($sibling->textContent) === '') {
                    // Whitespace-only text node between paragraphs - skip.
                    $sibling = $sibling->nextSibling;
                    continue;
                }
                break;
            }

            if ($nodes) {
                return ['nodes' => $nodes, 'heading' => $node];
            }
        }

        // No matching heading found - fall back to the last contiguous run
        // of bold-label paragraphs anywhere in the description, same
        // "trailing feature list" assumption as the <ul> case.
        $runs = [];
        $current = [];
        foreach ($doc->getElementsByTagName('p') as $p) {
            if ($this->isBoldLabelParagraph($p)) {
                $current[] = $p;
            } elseif ($current) {
                $runs[] = $current;
                $current = [];
            }
        }
        if ($current) {
            $runs[] = $current;
        }

        return ['nodes' => $runs ? $runs[count($runs) - 1] : [], 'heading' => null];
    }

    private function isBoldLabelParagraph(\DOMElement $p): bool
    {
        foreach ($p->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['strong', 'b'], true)) {
                return trim($child->textContent) !== '';
            }
            if ($child instanceof \DOMText && trim($child->textContent) !== '') {
                // Non-empty text before any bold tag - doesn't start with a label.
                return false;
            }
        }

        return false;
    }

    /**
     * Mirrors findBoldLabelParagraphs()'s heading-then-run structure, but
     * for Word-pasted bullet paragraphs (shape 5) - qualifying test is
     * isBulletCharParagraph() instead of isBoldLabelParagraph().
     *
     * @return array{nodes: \DOMElement[], heading: \DOMElement|null}
     */
    private function findBulletCharParagraphs(\DOMDocument $doc): array
    {
        foreach ($doc->getElementsByTagName('*') as $node) {
            if (!$node instanceof \DOMElement || !preg_match('/^h[1-6]$/i', $node->tagName)) {
                continue;
            }
            if (stripos(trim($node->textContent), 'features') === false) {
                continue;
            }

            $nodes = [];
            $sibling = $node->nextSibling;
            while ($sibling !== null) {
                if ($sibling instanceof \DOMElement
                    && strtolower($sibling->tagName) === 'p'
                    && $this->isBulletCharParagraph($sibling)
                ) {
                    $nodes[] = $sibling;
                    $sibling = $sibling->nextSibling;
                    continue;
                }
                if ($sibling instanceof \DOMText && trim($sibling->textContent) === '') {
                    $sibling = $sibling->nextSibling;
                    continue;
                }
                break;
            }

            if ($nodes) {
                return ['nodes' => $nodes, 'heading' => $node];
            }
        }

        // No matching heading found - fall back to the last contiguous run
        // of bullet-char paragraphs anywhere in the description, same
        // "trailing feature list" assumption as the other shapes.
        $runs = [];
        $current = [];
        foreach ($doc->getElementsByTagName('p') as $p) {
            if ($this->isBulletCharParagraph($p)) {
                $current[] = $p;
            } elseif ($current) {
                $runs[] = $current;
                $current = [];
            }
        }
        if ($current) {
            $runs[] = $current;
        }

        return ['nodes' => $runs ? $runs[count($runs) - 1] : [], 'heading' => null];
    }

    /**
     * A paragraph's own text (all descendant text concatenated, regardless
     * of how many spans wrap it) starts with a common non-ASCII bullet
     * glyph (● U+25CF, • U+2022, ▪ U+25AA, ‣ U+2023) followed by whitespace
     * and real content. Deliberately excludes plain ASCII "-"/"*" - those
     * are common in ordinary prose and would false-positive far too often;
     * the 2+ consecutive paragraphs requirement at the call site is the
     * main safety net, but the glyph set itself stays conservative too.
     */
    private function isBulletCharParagraph(\DOMElement $p): bool
    {
        $text = $this->normalizeWhitespace($p->textContent);

        return $text !== '' && preg_match('/^[\x{2022}\x{25CF}\x{25AA}\x{2023}]\s+\S/u', $text) === 1;
    }

    private function stripLeadingBulletChar(string $text): string
    {
        return preg_replace('/^[\x{2022}\x{25CF}\x{25AA}\x{2023}]\s+/u', '', $text) ?? $text;
    }

    /**
     * Finds a <p> whose text contains a pasted-in rich-text-editor document
     * JSON (signature: `{"type":"root"`) and successfully yields at least
     * one list-item tile from it. Returns null if no such JSON is found, or
     * if it's found but decodes to nothing usable (garbled paste, unrelated
     * JSON that happens to start the same way, etc.) - never throws on
     * malformed JSON.
     *
     * @return array{node: \DOMElement, tiles: array<int, array{title: string, description: string}>}|null
     */
    private function findJsonFeaturesList(\DOMDocument $doc): ?array
    {
        foreach ($doc->getElementsByTagName('p') as $p) {
            $text = $p->textContent;
            $start = strpos($text, '{"type":"root"');
            if ($start === false) {
                continue;
            }

            $json = $this->extractBalancedJson($text, $start);
            if ($json === null) {
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $tiles = [];
            $this->collectJsonListItemTiles($decoded, $tiles);
            if ($tiles) {
                return ['node' => $p, 'tiles' => $tiles];
            }
        }

        return null;
    }

    /**
     * json_decode() can't be pointed at "the JSON object starting somewhere
     * inside this larger string" on its own - manually scans forward from
     * the opening '{' at $start, tracking brace depth, until it finds the
     * matching close.
     */
    private function extractBalancedJson(string $text, int $start): ?string
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * Recursively walks a decoded document-JSON tree (structure otherwise
     * unknown/unvalidated - only `list-item` nodes are meaningful here) and
     * collects a tile for each `list-item` node found, at any depth.
     *
     * @param array<int, array{title: string, description: string}> $tiles
     */
    private function collectJsonListItemTiles(array $node, array &$tiles): void
    {
        if (($node['type'] ?? null) === 'list-item' && is_array($node['children'] ?? null)) {
            $tile = $this->tileFromJsonListItemChildren($node['children']);
            if ($tile !== null) {
                $tiles[] = $tile;
            }
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectJsonListItemTiles($value, $tiles);
            }
        }
    }

    /**
     * A list-item's children are text runs, e.g.
     * [{"type":"text","value":"Ultralight:","bold":true},
     *  {"type":"text","value":" Just 1 lb weight."}] - the first one
     * flagged bold becomes the title (matching splitBoldLabelParagraph()'s
     * convention for shape 3), the rest concatenate into the description.
     * Falls back to a title-only tile (the full concatenated text) if
     * nothing is flagged bold.
     *
     * @return array{title: string, description: string}|null
     */
    private function tileFromJsonListItemChildren(array $children): ?array
    {
        $title = null;
        $parts = [];

        foreach ($children as $child) {
            if (!is_array($child) || ($child['type'] ?? null) !== 'text') {
                continue;
            }
            $text = (string) ($child['value'] ?? '');
            if ($text === '') {
                continue;
            }
            if ($title === null && !empty($child['bold'])) {
                $title = trim(rtrim($text, ": \t\n\r\0\x0B"));
                continue;
            }
            $parts[] = $text;
        }

        if ($title === null) {
            $full = trim(implode('', $parts));
            return $full !== '' ? ['title' => $full, 'description' => ''] : null;
        }

        $description = trim(implode('', $parts));
        $description = ltrim($description, ": \t\n\r\0\x0B");
        $description = trim($description);

        return ['title' => $title, 'description' => $description];
    }

    /**
     * @return array{title: string, description: string}|null
     */
    private function splitBoldLabelParagraph(\DOMElement $p): ?array
    {
        $label = null;
        foreach ($p->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['strong', 'b'], true)) {
                $label = trim($child->textContent);
                break;
            }
        }

        if ($label === null || $label === '') {
            return null;
        }

        $title = trim(rtrim($label, ": \t\n\r\0\x0B"));

        $rest = trim($p->textContent);
        $description = $rest;
        if (str_starts_with($rest, $label)) {
            $description = substr($rest, strlen($label));
        }
        $description = ltrim($description, ": \t\n\r\0\x0B");
        $description = trim($description);

        return ['title' => $title, 'description' => $description];
    }

    /**
     * Mirrors splitBoldLabelParagraph()'s title/description split, but for
     * plain text with no <strong>/<b> tag marking where the label ends -
     * the "list" (<li>), "bullet_paragraph" and "bullet_char_paragraphs"
     * shapes all hand this a single line of plain text, e.g. "1 Minute Set
     * Up: The Rapid Shelter Canopy is quick and easy to set up...". Splits
     * on the first colon into a short label (the title) and the rest (the
     * description), same as the tile grid renders for the bold-label shape.
     *
     * Guarded so an ordinary sentence that happens to contain a colon deep
     * in its text (not a real label) doesn't get chopped in half: the label
     * portion must be short (a real label reads like "Waterproof Top", not
     * a full clause), and there must be real content on both sides.
     *
     * @return array{title: string, description: string}
     */
    private function splitPlainLabelText(string $text): array
    {
        $colonPos = strpos($text, ':');
        if ($colonPos === false || $colonPos > 60) {
            return ['title' => $text, 'description' => ''];
        }

        $title = trim(substr($text, 0, $colonPos));
        $description = trim(substr($text, $colonPos + 1));

        if ($title === '' || $description === '') {
            return ['title' => $text, 'description' => ''];
        }

        return ['title' => $title, 'description' => $description];
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
