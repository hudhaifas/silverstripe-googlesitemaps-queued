# SilverStripe Google Sitemaps Queued

This module extends [wilr/silverstripe-googlesitemaps](https://github.com/wilr/silverstripe-googlesitemaps) and [symbiote/silverstripe-queuedjobs](https://github.com/symbiote/silverstripe-queuedjobs) to generate static sitemap XML files (chunks and index) in a queued and scalable way.

## Features

- Generates static sitemap chunks and index files using `wilr/silverstripe-googlesitemaps`
- Runs as a background job using `symbiote/silverstripe-queuedjobs` (supports long-running `LARGE` jobs)
- Supports multilingual sites using [tractorcow/silverstripe-fluent](https://github.com/tractorcow/silverstripe-fluent), with per-locale domain context
- Saves output to `public/sitemap/{locale}`
- Optionally uploads all sitemap files (XML + XSL) to an S3-compatible bucket such as DigitalOcean Spaces
- Includes a proxy controller so sitemap files can be served under your app domain for Google Search Console compliance

## Installation

Install via Composer:

```bash
composer require hudhaifas/silverstripe-googlesitemaps-queued
```

Then run dev/build:

```bash
vendor/bin/sake dev/build flush=all
```

## Configuration

You can schedule the job using the CMS UI or declare it in YAML as a default recurring job:

```yaml
SilverStripe\Core\Injector\Injector:
  Symbiote\QueuedJobs\Services\QueuedJobService:
    properties:
      defaultJobs:
        DailyEnglishSitemapJob:
          type: Hudhaifas\GoogleSitemapsQueued\Job\GenerateSitemapJob
          filter:
            JobTitle: 'Generate en Sitemap XML (queued)'
          construct:
            0: 'en'
            1: 'https://en.example.com'
          startDateFormat: 'Y-m-d H:i:s'
          startTimeString: 'tomorrow 01:00'
          recreate: true

        DailyArabicSitemapJob:
          type: Hudhaifas\GoogleSitemapsQueued\Job\GenerateSitemapJob
          filter:
            JobTitle: 'Generate ar Sitemap XML (queued)'
          construct:
            0: 'ar'
            1: 'https://ar.example.com'
          startDateFormat: 'Y-m-d H:i:s'
          startTimeString: 'tomorrow 02:00'
          recreate: true
```

## Environment Variables

If uploading to S3-compatible storage (e.g. DigitalOcean Spaces):

```env
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
AWS_REGION=fra1
AWS_BUCKET_NAME=my-bucket-name
AWS_PUBLIC_BUCKET_PREFIX=public/assets
AWS_ENDPOINT=https://fra1.digitaloceanspaces.com
AWS_PUBLIC_CDN_PREFIX=https://cdn.example.com/public/
```

If `AWS_BUCKET_NAME` is not set, the sitemap files will only be saved locally to `public/sitemap/{locale}`.

## How Domain and URL Generation Works

* All links and asset references in the generated XML (including `<loc>` and `<?xml-stylesheet?>`) are created using `SitemapBase::AbsoluteLink()`.
* If `AWS_PUBLIC_CDN_PREFIX` is set, it is used as the base for all URLs. Otherwise, the app’s `Director::absoluteBaseURL()` is used.
* For correct domain generation in worker contexts (e.g., `localhost` in cloud CI runners or App Platform), you must explicitly pass the correct domain to the job constructor (e.g., `https://en.example.com`).
* This domain is injected during generation using `SitemapHelper::withAlternateBaseURL()`.

## XSL Stylesheet Support

Generated sitemap XML files include a reference to an XSL file so they are human-readable in the browser. These files:

* `styleSheet.xsl` – used by individual sitemap chunks
* `styleSheetIndex.xsl` – used by the main sitemap index

Both files are rendered dynamically during the job’s first step and uploaded alongside the XML files. URLs are made relative and CDN-compatible to avoid cross-origin issues.

## Proxying Sitemap URLs for Google Search Console

**Google Search Console (GSC) only accepts sitemap URLs that are served from the same domain as the site being verified.**

If your sitemap files are hosted on a CDN like DigitalOcean Spaces, this module includes a `SitemapProxyController` that allows you to serve those files via your app domain.

### Example

If a file is uploaded to:

```
https://cdn.example.com/public/sitemap/en/sitemap.xml
```

And your site domain is:

```
https://en.example.com
```

Then you should **submit this URL to Google Search Console**:

```
https://en.example.com/sitemap/en/sitemap.xml
```

This request is handled by `SitemapProxyController` and will issue a 301 redirect to the CDN-hosted file:

```
https://cdn.example.com/public/sitemap/en/sitemap.xml
```

## Running Large Jobs

This job is registered as a `LARGE` job type and must be processed using the `large` queue. To run it manually or via cron:

```bash
vendor/bin/sake dev/tasks/ProcessJobQueueTask queue=large
```

Example cron job (every 15 minutes):

```cron
*/15 * * * * /path/to/vendor/bin/sake dev/tasks/ProcessJobQueueTask queue=large
```

## Access Control

By default, the `GenerateSitemapJob` runs as an **anonymous (non-authenticated)** user:

```php
public function getRunAsMemberID()
{
    return 0;
}
```

This ensures that the sitemap only includes publicly accessible pages, avoiding pages that require login or specific member roles. If your application requires indexing private pages for a secured crawler or internal search engine, you may override this behavior by extending the job and modifying `getRunAsMemberID()` to return a valid member ID.

## Testing Locally

To queue and run a job manually in code:

```php
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Hudhaifas\GoogleSitemapsQueued\Job\GenerateSitemapJob;

singleton(QueuedJobService::class)->queueJob(
    new GenerateSitemapJob('en', 'https://en.example.com')
);
```

To process it:

```bash
vendor/bin/sake dev/tasks/ProcessJobQueueTask queue=large
```

## License

MIT
