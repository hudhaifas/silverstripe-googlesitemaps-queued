<?php

namespace Hudhaifas\GoogleSitemapsQueued\Job;

use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ArrayData;
use Aws\S3\S3Client;

use Hudhaifas\GoogleSitemapsQueued\Helper\SitemapHelper;
use Hudhaifas\GoogleSitemapsQueued\Model\SitemapBase;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\SSViewer;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

use Wilr\GoogleSitemaps\GoogleSitemap;

/**
 * A queued job that generates static sitemap XML files (chunks + index)
 * and optionally uploads them to S3-compatible storage (e.g., DigitalOcean Spaces).
 *
 * This job is queued as a "LARGE" job and supports multi-locale, multi-domain setups.
 */
class GenerateSitemapJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * List of sitemap entries to process (chunk metadata).
     *
     * @var array
     */
    protected $sitemaps = [];

    /**
     * Absolute filesystem path to the target sitemap output directory.
     *
     * @var string
     */
    protected $sitemapDir;

    /**
     * Constructor allows optional domain and locale setup.
     *
     * @param string|null $locale The Fluent or contextual locale
     * @param string|null $domain The target domain for sitemap link generation
     */
    public function __construct(?string $locale = null, ?string $domain = null)
    {
        if ($locale || $domain) {
            $this->jobData['locale'] = $locale;
            $this->jobData['domain'] = $domain;
        }
    }

    /**
     * Provides a readable label for CMS/admin display.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return "Generate {$this->getLocale()} Sitemap XML (queued)";
    }

    /**
     * Indicates that this job should be processed in the 'large' queue.
     *
     * To execute: `vendor/bin/sake dev/tasks/ProcessJobQueueTask queue=large`
     *
     * @return string
     */
    public function getJobType()
    {
        return QueuedJob::LARGE;
    }

    /**
     * Ensures the sitemap generation job runs as a non-authenticated user.
     * This avoids indexing pages that require login and ensures the sitemap
     * reflects only publicly visible content.
     *
     * @return int Always returns 0 (anonymous user)
     */
    public function getRunAsMemberID()
    {
        return 0;
    }

    /**
     * Initializes the job (runs before `process()` starts).
     * Prepares output directories and loads sitemap metadata from `GoogleSitemap`.
     */
    public function setup(): void
    {
        Versioned::set_stage(Versioned::LIVE);

        // Increase memory limit only for this job
        ini_set('memory_limit', '256M');

        $this->sitemapDir = Controller::join_links(Director::publicFolder(), $this->getSitemapPathPrefix());
        if (!is_dir($this->sitemapDir)) {
            mkdir($this->sitemapDir, 0755, true);
        }

        foreach (GoogleSitemap::inst()->getSitemaps() as $sitemap) {
            $this->sitemaps[] = [
                'ClassName' => $sitemap->ClassName,
                'Page' => $sitemap->Page,
                'Filename' => "sitemap-{$sitemap->ClassName}-{$sitemap->Page}.xml",
                'LastModified' => $sitemap->LastModified ?? null,
            ];
        }

        // Step 0: upload stylesheets
        // Steps 1..N: generate sitemap chunks
        // Step N+1: generate sitemap index
        $this->totalSteps = count($this->sitemaps) + 2;
    }

    /**
     * Executes the current step of the job.
     */
    public function process(): void
    {
        if ($this->currentStep === 0) {
            $this->uploadStylesheets();
        } elseif ($this->currentStep <= count($this->sitemaps)) {
            $sitemapInfo = $this->sitemaps[$this->currentStep - 1];
            $this->generateSitemapChunk($sitemapInfo);
        } elseif ($this->currentStep === count($this->sitemaps) + 1) {
            $this->generateIndexFile();
        }

        $this->currentStep++;
        $this->isComplete = $this->currentStep >= $this->totalSteps;
    }

    /**
     * Generates and uploads XSL stylesheets required for XML rendering in browsers.
     */
    protected function uploadStylesheets(): void
    {
        $domain = $this->jobData['domain'] ?? null;

        $indexXsl = SitemapHelper::withAlternateBaseURL($domain, function () {
            return SSViewer::create('xml-sitemapindex')
                ->process(SitemapBase::create());
        });

        $chunkXsl = SitemapHelper::withAlternateBaseURL($domain, function () {
            return SSViewer::create('xml-sitemap')
                ->process(SitemapBase::create());
        });

        $this->saveAndUploadSitemap("{$this->sitemapDir}/styleSheetIndex.xsl", $indexXsl);
        $this->saveAndUploadSitemap("{$this->sitemapDir}/styleSheet.xsl", $chunkXsl);
    }

    /**
     * Generates a single sitemap XML chunk and uploads it to the CDN.
     *
     * @param array $sitemapInfo Metadata for the chunk (class name, page, etc.)
     */
    protected function generateSitemapChunk($sitemapInfo): void
    {
        $class = str_replace('-', '\\', $sitemapInfo['ClassName']);
        $page = $sitemapInfo['Page'];
        $items = GoogleSitemap::inst()->getItems($class, $page);

        $filtered = $items->filterByCallback(function ($item) {
            return $item->hasMethod('canIncludeInGoogleSitemap')
                && $item->canIncludeInGoogleSitemap()
                && $item->hasMethod('AbsoluteLink');
        });

        $count = $filtered->count();
        $this->addMessage("📝 Indexing {$count} page(s) for chunk '{$sitemapInfo['Filename']}'");

        $domain = $this->jobData['domain'] ?? null;
        $pathPrefix = $this->getSitemapPathPrefix();

        $xml = SitemapHelper::withSitemapContext($domain, $this->getLocale(), function () use ($filtered, $pathPrefix) {
            return SSViewer::create('Wilr\\GoogleSitemaps\\Control\\GoogleSitemapController_sitemap')
                ->process(SitemapBase::create($pathPrefix)->customise(['Items' => $filtered]));
        });

        $this->saveAndUploadSitemap("{$this->sitemapDir}/{$sitemapInfo['Filename']}", $xml);

        // Free memory after upload
        unset($xml);
        gc_collect_cycles();
    }

    /**
     * Generates the sitemap index file that references all sitemap chunks.
     */
    protected function generateIndexFile(): void
    {
        $domain = $this->jobData['domain'] ?? null;
        $pathPrefix = $this->getSitemapPathPrefix();

        $wrapped = new ArrayList();
        foreach ($this->sitemaps as $entry) {
            $wrapped->push(new ArrayData([
                'ClassName' => $this->getLocale(),
                'LastModified' => $entry['LastModified'] ?? null,
                'Page' => $entry['Filename'],
            ]));
        }

        $indexXml = SitemapHelper::withAlternateBaseURL($domain, function () use ($wrapped, $pathPrefix) {
            return SSViewer::create('Wilr\\GoogleSitemaps\\Control\\GoogleSitemapController')
                ->process(SitemapBase::create($pathPrefix)->customise(['Sitemaps' => $wrapped]));
        });

        $this->saveAndUploadSitemap("{$this->sitemapDir}/sitemap.xml", $indexXml);
    }

    /**
     * Writes the sitemap file to disk and uploads it to S3-compatible storage.
     *
     * @param string $filepath Full absolute path to save
     * @param string $xml Sitemap XML or XSL content
     */
    protected function saveAndUploadSitemap(string $filepath, string $xml): void
    {
        file_put_contents($filepath, $xml);
        $this->addMessage("✅ $filepath saved");

        // Release memory before upload
        unset($xml);
        gc_collect_cycles();

        $bucket = Environment::getEnv('AWS_BUCKET_NAME');
        if (!$bucket) {
            return;
        }

        $client = Injector::inst()->get(S3Client::class);
        $key = Controller::join_links('public', $this->getSitemapPathPrefix(), basename($filepath));

        $stream = fopen($filepath, 'r');
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $stream, // streaming upload
            'ACL' => 'public-read',
            'ContentType' => 'application/xml',
        ]);
        fclose($stream);

        $this->addMessage("☁️  $key uploaded to S3 Bucket");
    }

    /**
     * Returns the relative path used for both public URLs and S3 keys.
     * Example: 'sitemap/en'
     */
    protected function getSitemapPathPrefix(): string
    {
        return Controller::join_links('sitemap', $this->getLocale());
    }

    /**
     * Returns the locale provided to the job or defaults to 'default'.
     */
    protected function getLocale(): string
    {
        return $this->jobData['locale'] ?? 'default';
    }
}
