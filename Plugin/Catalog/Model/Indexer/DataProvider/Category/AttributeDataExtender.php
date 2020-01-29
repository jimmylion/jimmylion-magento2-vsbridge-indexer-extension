<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Category;

use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Category\AttributeData;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Framework\App\ObjectManager;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;

class AttributeDataExtender {

    public $storeId;

    /* variable to cache locale for each store */
    private $storeLocales = [];

    public function beforeAddData(AttributeData $subject, $docs, $storeId){
        $this->storeId = $storeId;
    }

    /**
     * This method will take ES docs prepared by Divante Extension and modify them
     * before they are added to ES in \Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandler::saveIndex
     * @see: \Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Category\AttributeData::addData
     */
    public function afterAddData(AttributeData $subject, $docs){
        $docs = $this->addHreflangUrls($docs);
        return $docs;
    }

    private function addHreflangUrls($indexData)
    {
        $objectManager = ObjectManager::getInstance();
        $storeManager = $objectManager->create("\Magento\Store\Model\StoreManager");
        $stores = $storeManager->getStores();
        $websiteManager = $objectManager->create("\Magento\Store\Model\Website");

        $categoryRepository = $objectManager->create(CategoryRepositoryInterface::class);
        /* @var $categoryRepository CategoryRepositoryInterface */
        $categoryRewrites = $objectManager->create(CategoryUrlPathGenerator::class);

        $configReader = $objectManager->create(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        foreach ($indexData as $categoryId => $indexDataItem) {
            $hrefLangs = [];

            foreach($stores as $store){
                try {
                    $category = $categoryRepository->get($categoryId, $store->getId());
                    /* @TODO: once approved, move out of this loop */
                    if (!isset($this->storeLocales[$store->getId()])) {
                        $website = $websiteManager->load($store->getWebsiteId());
                        $locale = $configReader->getValue('general/locale/code', 'website', $website->getCode());
                        $this->storeLocales[$store->getId()] = $locale;
                    }

                    $hrefLangs[str_replace('_', '-', $this->storeLocales[$store->getId()])] = $categoryRewrites->getUrlPath($category);
                } catch (\Exception $e){

                }
            }

            $indexData[$categoryId]['storecode_url_paths'] = $hrefLangs;
        }
        return $indexData;
    }
}
