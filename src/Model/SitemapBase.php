<?php

namespace Hudhaifas\GoogleSitemapsQueued\Model;

use SilverStripe\Core\Environment;
use SilverStripe\View\ViewableData;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;

/**
 * A base data context used during sitemap and sitemap index template rendering.
 *
 * This class provides an AbsoluteLink() method to generate fully-qualified URLs
 * for both XSL stylesheets and sitemap files, correctly resolving the base URL
 * using either the CDN or the SilverStripe app domain.
 */
class SitemapBase extends ViewableData
{
    /**
     * The relative path from the public root to the sitemap directory.
     * Typically: 'sitemap/{locale}'
     *
     * @var string
     */
    protected $relativeBasePath;

    /**
     * Create a new SitemapBase instance with a given public-relative path prefix.
     *
     * @param string $relativeBasePath Path like 'sitemap/en', used for generating correct URLs.
     */
    public function __construct(string $relativeBasePath = '')
    {
        $this->relativeBasePath = trim($relativeBasePath, '/');
        parent::__construct();
    }

    /**
     * Generates an absolute URL for a given sitemap-related action or file.
     *
     * - For 'styleSheet' and 'styleSheetIndex', appends `.xsl` and prefixes the relative base path.
     * - For all other actions (e.g., sitemap chunks or sitemap.xml), assumes already-prefixed path.
     * - If a CDN base is defined via `AWS_PUBLIC_CDN_PREFIX`, it is used.
     * - Otherwise, the app's `Director::absoluteBaseURL()` is used.
     *
     * @param string|null $action The file or logical route to resolve (e.g. 'styleSheetIndex', 'sitemap.xml').
     * @return string Fully-qualified absolute URL
     */
    public function AbsoluteLink($action = null)
    {
        $cdnBase = rtrim(Environment::getEnv('AWS_PUBLIC_CDN_PREFIX') ?? '', '/');

        // Append .xsl and apply path prefix for stylesheet actions only
        if (in_array($action, ['styleSheet', 'styleSheetIndex'])) {
            $action .= '.xsl';
            $action = Controller::join_links($this->relativeBasePath, $action);
        }

        // Generate final absolute URL using CDN or fallback to app base
        if ($cdnBase) {
            return Controller::join_links($cdnBase, $action);
        }

        return Controller::join_links(Director::absoluteBaseURL(), $action);
    }
}
