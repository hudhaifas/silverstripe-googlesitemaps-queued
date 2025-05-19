<?php

namespace Hudhaifas\GoogleSitemapsQueued\Controller;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;

class SitemapProxyController extends Controller
{
    private static $allowed_actions = [
        'index',
    ];

    public function index(HTTPRequest $request)
    {
        $locale = $request->param('Locale');
        $file = $request->param('File');
        $ext = $request->getExtension();

        return $this->redirect("https://cdn.amheritance.com/public/sitemap/{$locale}/{$file}.{$ext}", 301);
    }
}
