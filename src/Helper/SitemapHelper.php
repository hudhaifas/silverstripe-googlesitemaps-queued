<?php

namespace Hudhaifas\GoogleSitemapsQueued\Helper;

use TractorCow\Fluent\State\FluentState;
use Closure;
use SilverStripe\Control\Director;

/**
 * Helper class for managing sitemap generation context,
 * including setting the base URL and locale during rendering.
 */
class SitemapHelper {
    /**
     * Sets the domain and locale context for the sitemap generation process.
     * Automatically wraps both alternate base URL and Fluent locale (if available).
     *
     * @param string $domain The domain to use for generating absolute links.
     * @param string $locale The locale to switch to if Fluent is enabled.
     * @param Closure $callback The rendering logic to execute in the given context.
     * @return mixed The result of the callback execution.
     */
    public static function withSitemapContext(string $domain, string $locale, Closure $callback) {
        $wrapped = function () use ($domain, $callback) {
            return self::withAlternateBaseURL($domain, $callback);
        };

        if (class_exists(FluentState::class)) {
            return FluentState::singleton()->withState(
                function (FluentState $newState) use ($locale, $wrapped) {
                    $newState->setLocale($locale);
                    return $wrapped();
                }
            );
        }

        return $wrapped();
    }

    /**
     * Temporarily overrides the SilverStripe alternate_base_url setting
     * for the duration of a given callback.
     *
     * @param string $domain The base domain to set temporarily.
     * @param Closure $callback The logic to run while the base URL is overridden.
     * @return mixed The result of the callback execution.
     */
    public static function withAlternateBaseURL(string $domain, Closure $callback) {
        $original = Director::config()->get('alternate_base_url');
        Director::config()->set('alternate_base_url', $domain);

        try {
            return $callback();
        } finally {
            Director::config()->set('alternate_base_url', $original);
        }
    }
}
