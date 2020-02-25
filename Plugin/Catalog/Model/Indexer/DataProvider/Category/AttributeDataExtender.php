<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Category;

use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Category\AttributeData;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;

class AttributeDataExtender {

    public $storeId;

    /* variable to cache locale for each store */
    private $storeLocales = [];
    
    protected $storeManager;
    protected $websiteManager;
    protected $categoryUrlPathGenerator;
    protected $categoryRepository;
    protected $scopeConfig;
    
    public function __construct(
            \Magento\Store\Model\StoreManager $storeManager,
            \Magento\Store\Model\Website $websiteManager,
            CategoryUrlPathGenerator $categoryUrlPathGenerator,
            CategoryRepositoryInterface $categoryRepository,
            \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
            ){
                $this->storeManager = $storeManager;
                $this->websiteManager = $websiteManager;
                $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
                $this->categoryRepository = $categoryRepository;
                $this->scopeConfig = $scopeConfig;
                
            }

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
        $stores = $this->storeManager->getStores();
        foreach ($indexData as $categoryId => $indexDataItem) {
            $hrefLangs = [];

            foreach($stores as $store){
                try {
                    $category = $this->categoryRepository->get($categoryId, $store->getId());
                    /* @TODO: once approved, move out of this loop */
                    if (!isset($this->storeLocales[$store->getId()])) {
                        $website = $this->websiteManager->load($store->getWebsiteId());
                        $locale = $this->scopeConfig->getValue('general/locale/code', 'website', $website->getCode());
                        $this->storeLocales[$store->getId()] = $locale;
                    }

                    $hrefLangs[str_replace('_', '-', $this->storeLocales[$store->getId()])] = $this->categoryUrlPathGenerator->getUrlPath($category);
                } catch (\Exception $e){
                    
                }
            }
            $indexData[$categoryId]['storecode_url_paths'] = $hrefLangs;
        }
        return $indexData;
    }
}
