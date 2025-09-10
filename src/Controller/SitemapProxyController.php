<?php

namespace Hudhaifas\GoogleSitemapsQueued\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\i18n\i18n;

class SitemapProxyController extends Controller
{
    private static $allowed_actions = [
        'index',
    ];

    public function index(HTTPRequest $request)
    {
        $locale = i18n::get_locale();
        $lang = i18n::getData()->langFromLocale($locale) ?: $request->param('Locale');

        $file = $request->param('File');
        $ext = $request->getExtension();
        $cdnBase = rtrim(Environment::getEnv('AWS_PUBLIC_CDN_PREFIX') ?? '', '/');
        $cdnLink = Controller::join_links($cdnBase, "sitemap", $lang, "{$file}.{$ext}");

        return $this->redirect($cdnLink, 301);
    }
}
