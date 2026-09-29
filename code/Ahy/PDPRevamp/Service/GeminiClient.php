<?php

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Helper\Data as PDPRevampHelper;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Generates the short punchy phrase stored in the pdp_descriptors attribute
 * (e.g. "Ultralight. Waterproof. Trail-Ready") via the Gemini API, from a
 * product's name, short description and category. See
 * Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpDescriptorsAttribute for the
 * attribute this feeds and Console\Command\GenerateAiDescriptors for the
 * bulk command that calls this.
 */
class GeminiClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Reject anything implausibly long - a sign the model ignored the format instruction. */
    private const MAX_RESULT_LENGTH = 120;

    /** Full descriptions can run long; cap what's sent so the prompt (and token cost) stays small. */
    private const MAX_DESCRIPTION_LENGTH = 2000;

    private ClientFactory $clientFactory;
    private Json $serializer;
    private LoggerInterface $logger;
    private PDPRevampHelper $helper;

    public function __construct(
        ClientFactory $clientFactory,
        Json $serializer,
        LoggerInterface $logger,
        PDPRevampHelper $helper
    ) {
        $this->clientFactory = $clientFactory;
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function isConfigured(): bool
    {
        return $this->helper->getGeminiApiKey() !== null;
    }

    /**
     * Returns a period-separated, 3-phrase descriptor line, or null when the
     * key isn't configured, the request fails, or the model's answer doesn't
     * look usable.
     */
    public function generateDescriptors(string $productName, string $description, string $categoryPath): ?string
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] generateDescriptors skipped: no Gemini API key configured');
            return null;
        }

        $prompt = $this->buildPrompt($productName, $this->stripHtml($description), $categoryPath);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'maxOutputTokens' => 40,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error('[GeminiClient] generateDescriptors got no usable text for "' . $productName . '"');
                return null;
            }

            return $this->sanitize($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] request failed for "' . $productName . '": ' . $exception->getMessage());
            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] could not decode response for "' . $productName . '": ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Batched sibling of generateDescriptors(): resolves several products'
     * descriptor lines in a single Gemini request instead of one request
     * per product, mirroring resolveColorHexBatch()'s approach. See
     * Console\Command\GenerateAiDescriptors, which chunks its catalog scan
     * into batches instead of looping one product at a time - this is what
     * cuts that command's wall-clock time down, since network round-trips
     * (not tokens) dominate it for a large backfill.
     *
     * Matches products back to descriptors by position (1-based index in
     * the prompt), same as resolveColorHexBatch(), rather than trying to
     * echo an identifier back through the model.
     *
     * @param array<int|string, array{name: string, description: string, categoryPath: string}> $products
     * @return array<int|string, string> same keys as $products - a product
     *  Gemini didn't return a usable line for is simply absent, same as a
     *  single-item failure.
     */
    public function generateDescriptorsBatch(array $products): array
    {
        if (!$products) {
            return [];
        }

        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] generateDescriptorsBatch skipped: no Gemini API key configured');
            return [];
        }

        $keys = array_keys($products);
        $prompt = $this->buildBatchPrompt($products);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            // Each line is short ("3: Ultralight. Waterproof. Trail-Ready"),
                            // but generous per-product budget plus a fixed floor for small batches.
                            'maxOutputTokens' => count($products) * 50 + 50,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error(
                    '[GeminiClient] generateDescriptorsBatch got no usable text for '
                    . count($products) . ' product(s)'
                );
                return [];
            }

            return $this->parseDescriptorsBatchResponse($text, $keys);
        } catch (GuzzleException $exception) {
            $this->logger->error(
                '[GeminiClient] generateDescriptorsBatch request failed: ' . $exception->getMessage()
            );
            return [];
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error(
                '[GeminiClient] generateDescriptorsBatch could not decode response: ' . $exception->getMessage()
            );
            return [];
        }
    }

    /**
     * @param array<int|string, array{name: string, description: string, categoryPath: string}> $products
     */
    private function buildBatchPrompt(array $products): string
    {
        $blocks = '';
        $index = 1;
        foreach ($products as $product) {
            $context = 'Name: ' . $product['name'];
            $description = $this->stripHtml($product['description']);
            if ($description !== '') {
                $context .= "\nDescription: " . $description;
            }
            if ($product['categoryPath'] !== '') {
                $context .= "\nCategory: " . $product['categoryPath'];
            }
            $blocks .= "Product $index:\n$context\n\n";
            $index++;
        }

        return <<<PROMPT
You are writing short product highlight lines shown directly under a product title on an e-commerce site, for multiple products at once.

$blocks
For EACH numbered product above, respond with one line in the exact format:
N: Phrase One. Phrase Two. Phrase Three

...where N is that product's number, and the rest is EXACTLY 3 short selling-point phrases separated by ". " - each phrase 1-2 words, Title Case, describing a real, concrete attribute of that specific product (material, use case, key feature) from its own context above. Do not invent specs not implied by that product's own context, and do not mix up details between products.

Example line: 3: Ultralight. Waterproof. Trail-Ready

Respond with only the numbered lines, one per product, in the same order. No blank lines, no explanation, no markdown, no trailing period on a line.
PROMPT;
    }

    /**
     * @param array<int, int|string> $keys original array_keys($products), in order - position N corresponds to $keys[N-1]
     * @return array<int|string, string>
     */
    private function parseDescriptorsBatchResponse(string $text, array $keys): array
    {
        $resultByKey = [];

        foreach (preg_split('/\r?\n/', trim($text)) as $line) {
            if (!preg_match('/^\s*(\d+)\s*:\s*(.+)$/', $line, $matches)) {
                continue;
            }

            $position = (int) $matches[1] - 1;
            if (!isset($keys[$position])) {
                continue;
            }

            $sanitized = $this->sanitize($matches[2]);
            if ($sanitized !== null) {
                $resultByKey[$keys[$position]] = $sanitized;
            }
        }

        return $resultByKey;
    }

    /**
     * Returns up to 3 short highlight tags extracted from a customer review
     * (e.g. "Great for Kids", "Waterproof", "Lightweight"), or an empty array
     * when the key isn't configured, the request fails, or the review text
     * doesn't support any confident tag. Consumed by
     * Ahy\PDPRevamp\Service\YotpoClient, which caches the result per review
     * so this is only ever called once per review, not on every page view.
     */
    public function generateReviewTags(string $reviewTitle, string $reviewContent): array
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            return [];
        }

        $reviewText = trim(trim($reviewTitle) . '. ' . trim($this->stripHtml($reviewContent)));
        if ($reviewText === '' || $reviewText === '.') {
            return [];
        }

        $prompt = $this->buildReviewTagsPrompt($reviewText);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'maxOutputTokens' => 30,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                return [];
            }

            return $this->sanitizeTags($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] generateReviewTags request failed: ' . $exception->getMessage());
            return [];
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] generateReviewTags could not decode response: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * Resolves a creative/marketing color-option label (e.g. a fishing-lure
     * paint name like "Candy Apple Craw") to a single representative hex
     * code, for labels that don't match any standard CSS color name.
     * Returns null when the key isn't configured, the request fails, or the
     * model's answer isn't a valid #RRGGBB code. Consumed by
     * Ahy\PDPRevamp\Service\AiColorHexResolver, which saves the result into
     * Magento's own native visual swatch (eav_attribute_option_swatch) so a
     * given option is only ever sent to Gemini once, never from a live
     * storefront request.
     */
    public function resolveColorHex(string $colorLabel): ?string
    {
        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] resolveColorHex skipped: no Gemini API key configured');
            return null;
        }

        $label = trim($colorLabel);
        if ($label === '') {
            return null;
        }

        $prompt = $this->buildColorHexPrompt($label);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 10,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error('[GeminiClient] resolveColorHex got no usable text for "' . $label . '"');
                return null;
            }

            return $this->sanitizeHex($text);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHex request failed for "' . $label . '": ' . $exception->getMessage());
            return null;
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHex could not decode response for "' . $label . '": ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * Batched sibling of resolveColorHex(): resolves several creative/
     * marketing color labels in a single Gemini request instead of one
     * request per label. This is what AiColorHexResolver::runAiQueue()
     * actually calls (in chunks of AI_BATCH_SIZE there) - it was missing
     * here entirely, which made every AI-queued color option fail with
     * "Gemini returned no usable hex" regardless of the label, since the
     * call threw "Call to undefined method" every time (caught by
     * runAiQueue's try/catch, so it logged and skipped rather than
     * crashing the whole backfill run).
     *
     * Matches labels back to hexes by position (1-based index in the
     * prompt) rather than by echoing the label text back, since labels can
     * contain quotes/slashes/punctuation a model might not reproduce
     * exactly. A label the model didn't return a valid line for is simply
     * absent from the returned map, same as a single-label failure.
     *
     * @param string[] $labels
     * @return array<string, string> label => "#RRGGBB"
     */
    public function resolveColorHexBatch(array $labels): array
    {
        $labels = array_values(array_filter(
            array_map('trim', $labels),
            static fn (string $label): bool => $label !== ''
        ));
        if (!$labels) {
            return [];
        }

        $apiKey = $this->helper->getGeminiApiKey();
        if ($apiKey === null) {
            $this->logger->error('[GeminiClient] resolveColorHexBatch skipped: no Gemini API key configured');
            return [];
        }

        $prompt = $this->buildColorHexBatchPrompt($labels);
        $model = $this->helper->getGeminiModel();

        try {
            $client = $this->clientFactory->create();
            $response = $client->request(
                'POST',
                self::API_BASE . rawurlencode($model) . ':generateContent',
                [
                    'query' => ['key' => $apiKey],
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            // Each line is ~10 tokens ("12: #RRGGBB"); generous
                            // per-label budget plus a fixed floor for short batches.
                            'maxOutputTokens' => count($labels) * 20 + 50,
                        ],
                    ],
                    'headers' => ['Accept' => 'application/json'],
                ]
            );

            $payload = $this->serializer->unserialize((string) $response->getBody());
            $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $this->logger->error(
                    '[GeminiClient] resolveColorHexBatch got no usable text for ' . count($labels) . ' label(s)'
                );
                return [];
            }

            return $this->parseColorHexBatchResponse($text, $labels);
        } catch (GuzzleException $exception) {
            $this->logger->error('[GeminiClient] resolveColorHexBatch request failed: ' . $exception->getMessage());
            return [];
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error(
                '[GeminiClient] resolveColorHexBatch could not decode response: ' . $exception->getMessage()
            );
            return [];
        }
    }

    /**
     * @param string[] $labels
     */
    private function buildColorHexBatchPrompt(array $labels): string
    {
        $numbered = '';
        foreach ($labels as $index => $label) {
            $numbered .= ($index + 1) . '. ' . $label . "\n";
        }

        return <<<PROMPT
You are matching creative/marketing product color names to a single
representative hex color code each, for color swatches shown on an
e-commerce site. These names are often used for fishing lures, apparel,
or similar retail products and may describe a multi-color pattern (e.g.
"Chartreuse Black Back") or an abstract/thematic name (e.g. "Ghost",
"Sleepover").

Color names (numbered):
$numbered
For EACH numbered color name above, respond with one line in the exact
format:
N: #RRGGBB

...where N is that name's number and #RRGGBB is a single 6-digit hex
code that best visually represents it. If a name describes a multi-color
pattern, pick its single most dominant or most distinctive color. Make
your best reasonable guess even for abstract or thematic names - never
refuse, and never skip a number.

Respond with only the numbered hex lines, one per color name, in the
same order. No explanation, no markdown, no blank lines.
PROMPT;
    }

    /**
     * @param string[] $labels
     * @return array<string, string>
     */
    private function parseColorHexBatchResponse(string $text, array $labels): array
    {
        $hexByLabel = [];

        foreach (preg_split('/\r?\n/', trim($text)) as $line) {
            if (!preg_match('/^\s*(\d+)\s*[:.\-]\s*(#[0-9A-Fa-f]{6})\s*$/', $line, $matches)) {
                continue;
            }

            $index = (int) $matches[1] - 1;
            if (!isset($labels[$index])) {
                continue;
            }

            $hexByLabel[$labels[$index]] = strtoupper($matches[2]);
        }

        return $hexByLabel;
    }

      private function buildColorHexPrompt(string $colorLabel): string
    {
        return <<<PROMPT
You are matching a creative/marketing product color name to a single
representative hex color code, for a color swatch shown on an e-commerce
site. These names are often used for fishing lures, apparel, or similar
retail products and may describe a multi-color pattern (e.g. "Chartreuse
Black Back") or an abstract/thematic name (e.g. "Ghost", "Sleepover").

Color name: "$colorLabel"

Respond with ONLY a single 6-digit hex color code in the exact format
#RRGGBB that best visually represents this color name. If the name
describes a multi-color pattern, pick its single most dominant or most
distinctive color. Make your best reasonable guess even for abstract or
thematic names - never refuse.

Respond with only the hex code. No explanation, no markdown, no quotes.
PROMPT;
    }

    private function sanitizeHex(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $text) === 1 ? strtoupper($text) : null;
    }

    private function buildReviewTagsPrompt(string $reviewText): string
    {
        if (mb_strlen($reviewText) > self::MAX_DESCRIPTION_LENGTH) {
            $reviewText = mb_substr($reviewText, 0, self::MAX_DESCRIPTION_LENGTH);
        }

        return <<<PROMPT
You are extracting short highlight tags from a customer product review shown on an e-commerce site, in the style of "Great for Kids", "Waterproof", "Lightweight".

Review: "$reviewText"

Respond with 1 to 3 short tags separated by ", " - each tag 1-4 words, Title Case, describing a real, concrete point actually made in the review (a use case, a quality, who it's good for). Do not invent anything not stated or clearly implied by the review text. If the review doesn't support any confident tag, respond with just: none

Example of the exact format required: Great for Kids, Waterproof, Lightweight

Respond with only the comma-separated tags (or the word none). No quotes, no markdown, no explanation.
PROMPT;
    }

    /**
     * @return string[]
     */
    private function sanitizeTags(string $text): array
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");
        if ($text === '' || strcasecmp($text, 'none') === 0) {
            return [];
        }

        $tags = array_map('trim', explode(',', $text));
        $tags = array_filter($tags, static function (string $tag): bool {
            return $tag !== '' && mb_strlen($tag) <= 40;
        });

        return array_values(array_slice($tags, 0, 3));
    }

    private function buildPrompt(string $productName, string $description, string $categoryPath): string
    {
        $context = 'Product name: ' . $productName;
        if ($description !== '') {
            $context .= "\nDescription: " . $description;
        }
        if ($categoryPath !== '') {
            $context .= "\nCategory: " . $categoryPath;
        }

        return <<<PROMPT
You are writing a short product highlight line shown directly under a product title on an e-commerce site.

$context

Respond with EXACTLY 3 short selling-point phrases separated by ". " - each phrase 1-2 words, Title Case, describing a real, concrete attribute of this product (material, use case, key feature). Do not invent specs not implied by the context above.

Example of the exact format required: Ultralight. Waterproof. Trail-Ready

Respond with only the 3 period-separated phrases. No quotes, no markdown, no explanation, no trailing period.
PROMPT;
    }

    /**
     * Product descriptions are rich-text/HTML; Gemini only needs the plain wording.
     */
    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string) $text);

        return mb_strlen($text) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH)
            : $text;
    }

    private function sanitize(string $text): ?string
    {
        $text = trim($text, " \t\n\r\0\x0B\"'.");
        $text = preg_replace('/\s+/', ' ', $text);

        if ($text === '' || strlen($text) > self::MAX_RESULT_LENGTH || !str_contains($text, '.')) {
            return null;
        }

        return $text;
    }
}