<?php

namespace Ahy\ThemeCustomization\Plugin;

use Magento\Framework\UrlInterface;

class MediaBaseUrlPlugin
{
    private const TARGET_HOST = 'www.everest.com';

    /**
     * Rewrite host to www.everest.com for media base URLs only.
     * Static JS/CSS is excluded so dev2 serves its own built assets.
     */
    public function afterGetBaseUrl(
        \Magento\Store\Model\Store $subject,
        string $result,
        $type = UrlInterface::URL_TYPE_LINK,
        $secure = null
    ): string {
        if ($type !== UrlInterface::URL_TYPE_MEDIA) {
            return $result;
        }

        $parsed = parse_url($result);
        if (empty($parsed['host'])) {
            return $result;
        }

        return ($parsed['scheme'] ?? 'https') . '://' . self::TARGET_HOST
            . ($parsed['path'] ?? '/');
    }
}
