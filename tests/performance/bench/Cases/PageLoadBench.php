<?php declare(strict_types=1);

namespace Shopware\Tests\Bench\Cases;

use PhpBench\Attributes as Bench;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\Exception\InvalidDomainException;
use Shopware\Tests\Bench\BenchCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal - only for performance benchmarks
 */
class PageLoadBench extends BenchCase
{
    use SalesChannelApiTestBehaviour;

    /**
     * @var string
     */
    private $productUrl;

    /**
     * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
     */
    private $browser;


    public function setup(): void
    {
        parent::setup();

        $salesChannelId = $this->ids->get('sales-channel');

        $this->createSalesChannel([
            'id' => $salesChannelId,
            'languages' => null,
        ]);

        $salesChannelContext = $this->getContainer()
            ->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), $salesChannelId);

        $this->browser = $this->createFrontendSalesChannelBrowser($salesChannelContext);

        $productData = $this->createProduct($salesChannelContext);
        $this->productUrl = $this->buildProductUrl($productData['id'], $salesChannelContext);
    }

    #[Bench\Assert('mode(variant.time.avg) < 110ms')]
    public function bench_loading_home_page(): void
    {
        $this->browser
            ->request(
                'GET',
                '/'
            );

        if ($this->browser->getResponse()->getStatusCode() !== 200) {
            throw new \Exception('Loading Home Page failed.');
        }
    }

    #[Bench\Assert('mode(variant.time.avg) < 140ms')]
    public function bench_loading_product_detail_page(): void
    {
        $this->browser
            ->request(
                'GET',
                $this->productUrl
            );

        if ($this->browser->getResponse()->getStatusCode() !== 200) {
            throw new \Exception('Loading Product Detail Page failed.');
        }
    }

    protected function getKernel(): KernelInterface
    {
        return KernelLifecycleManager::getKernel();
    }

    /**
     * @param  SalesChannelContext  $context
     * @return string
     */
    private function getHost(SalesChannelContext $context): string
    {
        $domains = $context->getSalesChannel()->getDomains();
        $languageId = $context->getLanguageId();

        if ($domains instanceof SalesChannelDomainCollection) {
            foreach ($domains as $domain) {
                if ($domain->getLanguageId() === $languageId) {
                    return $domain->getUrl();
                }
            }
        }

        throw new InvalidDomainException('Empty domain');
    }

    /**
     * @param  SalesChannelContext  $salesChannelContext
     * @return array
     */
    private function createProduct(SalesChannelContext $salesChannelContext): array
    {
        $taxId = $salesChannelContext->getTaxRules()->first()->getId();

        $productData = [
            'id' => Uuid::randomHex(),
            'productNumber' => Uuid::randomHex(),
            'name' => 'test product 1',
            'stock' => 100,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 15, 'net' => 10, 'linked' => false]],
            'tax' => ['id' => $taxId],
            'manufacturer' => ['name' => 'test'],
            'visibilities' => [
                ['salesChannelId' => $salesChannelContext->getSalesChannel()->getId(), 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL],
            ],
        ];

        $this->getContainer()
            ->get('product.repository')
            ->create([$productData], $salesChannelContext->getContext());

        return $productData;
    }

    /**
     * @param  string  $productId
     * @param  SalesChannelContext  $salesChannelContext
     * @return string
     */
    private function buildProductUrl(string $productId, SalesChannelContext $salesChannelContext): string
    {
        $seoUrlReplacer = $this->getContainer()->get(SeoUrlPlaceholderHandlerInterface::class);

        /** @var SeoUrlPlaceholderHandlerInterface $seoUrlReplacer */
        $rawProductUrl = $seoUrlReplacer->generate('frontend.detail.page', [
            'productId' => $productId
        ]);

        return $seoUrlReplacer->replace(
            $rawProductUrl,
            $this->getHost($salesChannelContext),
            $salesChannelContext
        );
    }

    /**
     * @param  SalesChannelContext  $salesChannelContext
     * @return KernelBrowser
     */
    public function createFrontendSalesChannelBrowser(SalesChannelContext $salesChannelContext): KernelBrowser
    {
        /** @var EntityRepository $salesChannelRepository */
        $salesChannelRepository = $this->getContainer()->get('sales_channel.repository');

        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $salesChannelRepository
            ->search(
                (new Criteria([$salesChannelContext->getSalesChannelId()]))
                    ->addAssociation('domains'),
                $salesChannelContext->getContext()
            )
            ->first();

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());

        $headerAccessKey = 'HTTP_' . str_replace('-', '_', mb_strtoupper(PlatformRequest::HEADER_ACCESS_KEY));
        $headerContextToken = 'HTTP_' . PlatformRequest::HEADER_CONTEXT_TOKEN;
        $browser->setServerParameters([
            $headerAccessKey => $salesChannel->getAccessKey(),
            $headerContextToken => Random::getAlphanumericString(32),
        ]);

        return $browser;
    }
}
