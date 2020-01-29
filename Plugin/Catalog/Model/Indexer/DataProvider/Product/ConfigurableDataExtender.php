<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Product;

use Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category as CategoryResource;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\MediaGalleryData;
use Divante\VsbridgeIndexerCore\Api\DataProviderInterface;
use Divante\VsbridgeIndexerCore\Console\Command\RebuildEsIndexCommand;
use Divante\VsbridgeIndexerCore\Config\IndicesSettings;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;


class ConfigurableDataExtender {

    public $storeId;

    public function beforeAddData(ConfigurableData $subject, $docs, $storeId){
        $this->storeId = $storeId;
    }

    /* @var CategoryResource $categoryResource */
    private $categoryResource;

    /* variable to cache locale for each store */
    private $storeLocales = [];

    /**
     * This method will take ES docs prepared by Divante Extension and modify them
     * before they are added to ES in \Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandler::saveIndex
     * @see: \Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData::addData
     */
    public function afterAddData(ConfigurableData $subject, $docs){
        $storeId = $this->storeId;
        $docs = $this->extendDataWithGallery($subject, $docs,$storeId);

        $objectManager = ObjectManager::getInstance();
        /* @var \Divante\VsbridgeIndexerCore\Index\IndexOperations $indexOperations */
        $this->categoryResource = $objectManager->create("Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category");

        $docs = $this->addHreflangUrls($docs);

        $docs = $this->cloneConfigurableColors($docs,$storeId);

        $docs = $this->extendDataWithCategoryNew($docs,$storeId);

        return $docs;
    }

    private function cloneConfigurableColors($indexData,$storeId)
    {
        $clones = [];

        foreach ($indexData as $product_id => $indexDataItem) {

            if ($indexDataItem['type_id'] !== 'configurable') {
                continue;
            }

            if ( ! isset($indexDataItem['configurable_options']) ) {
                continue;
            }

            $has_colors = false;
            $colors = null;
            foreach ($indexDataItem['configurable_options'] as $option) {
                if ( $option['attribute_code'] === 'color' ) {
                    /**
                     * For some reason, product configurations can be added without adding values in the configurable,
                     * make sure values exist
                     */
                    if(!empty($option['values'])) {
                        $has_colors = true;
                        $colors = $option['values'];
                    }
                    break;
                }
            }

            if ( !$has_colors) {
                $cloneId = $this->getIdForClonedItem($indexDataItem);
                $clones[$cloneId] = $indexDataItem;

                if(!empty($indexDataItem['color'])){
                    $clones[$cloneId]['clone_color_id'] = $indexDataItem['color'];
                    $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$indexDataItem['color'];
                } else {
                    $clones[$cloneId]['sku'] = $indexDataItem['sku'];
                }

                $clones[$cloneId]['is_clone'] = 2;

            } else {
                if(!empty($colors)){
                    foreach ($colors as $color) {
                        $clone_color = strtolower(str_ireplace(' ', '-', $color['label']));
                        $cloneId = $product_id.'-'.$color['value_index'];
                        $clones[$cloneId] = $indexDataItem;
                        $clones[$cloneId]['clone_color_label'] = $color['label'];
                        $clones[$cloneId]['clone_color_id'] = $color['value_index'];
                        $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$color['value_index'];
                        $clones[$cloneId]['is_clone'] = 1;
                        $clones[$cloneId]['url_key'] = $indexDataItem['url_key'].'?color='.$clone_color;
                        $clones[$cloneId]['clone_name'] = $indexDataItem['name'].' '.$color['label'];
                    }
                }
            }
        }

        return $indexData + $clones;
    }

    private function getCategoryData($storeId,$productId){

        $categories =  $this->categoryResource->loadCategoryData($storeId, [$productId]);
        $category_data = [
            'category' => [],
            'category_new' => [],
        ];

        foreach ($categories as $cat) {
            $cat_id = (int) $cat["category_id"];
            $cat_postion = (int) $cat['position'];

            $category_data['category'][] = [
                'category_id' => $cat_id,
                'name' => (string)$cat['name'],
                'position' => $cat_postion,
            ];
            $category_data['category_new'][$cat_id] = $cat_postion;
        }

        return $category_data;
    }

    private function extendDataWithGallery(\Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData $subject, $docs,$storeId)
    {
        $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->create("Magento\Store\Model\StoreManagerInterface");
        /* @var StoreManagerInterface $storeManager */
        $store = $storeManager->getStore($storeId);
        $index = $this->getIndex($store);
        $type = $index->getType('product');

        /* make this work here */
        $mediaGalleryDataProvider = $type->getDataProvider('media_gallery');

        $configurableResource = $subject->getConfigurableResource();
        $configurableResource->setProducts($docs);

        $allChildren = $configurableResource->getSimpleProducts($storeId);

        if (null === $allChildren) {
            return $docs;
        }

        $stockRowData = $subject->getLoadInventory()->execute($allChildren, $storeId);
        $configurableAttributeCodes = $subject->getConfigurableResource()->getConfigurableAttributeCodes();

        $allChildren = $subject->getChildrenAttributeProcessor()
            ->loadChildrenRawAttributesInBatches($storeId, $allChildren, $configurableAttributeCodes);

        // add Media Gallery
        $allChildren = $mediaGalleryDataProvider->addData($allChildren, $storeId);

        foreach ($allChildren as $childKey => $child) {

            $childId = $child['entity_id'];
            $child['id'] = (int) $childId;
            $parentIds = $child['parent_ids'];

            if (!isset($child['regular_price']) && isset($child['price'])) {
                $child['regular_price'] = $child['price'];
            }

            if (isset($stockRowData[$childId])) {
                $productStockData = $stockRowData[$childId];

                unset($productStockData['product_id']);
                $productStockData = $subject->getInventoryProcessor()->prepareInventoryData($storeId, $productStockData);
                $child['stock'] = $productStockData;
            }

            foreach ($parentIds as $parentId) {
                $child = $subject->filterData($child);

                if (!isset($docs[$parentId]['configurable_options'])) {
                    $docs[$parentId]['configurable_options'] = [];
                }

                $docs[$parentId] = $this->replaceOriginalChild($docs[$parentId],$child);
            }
        }

        $allChildren = null;

        return $docs;
    }

    private function extendDataWithCategoryNew($indexData,$storeId)
    {
        foreach ($indexData as $product_id => $indexDataItem) {

            if ($indexData[$product_id]['type_id'] !== 'configurable') {
                continue;
            }

            if ( ! isset($indexData[$product_id]['configurable_options']) ) {
                continue;
            }

            $has_colors = false;
            $colors = null;
            foreach ($indexData[$product_id]['configurable_options'] as $option) {
                if ( $option['attribute_code'] === 'color' ) {
                    /**
                     * For some reason, product configurations can be added without adding values in the configurable,
                     * make sure values exist
                     */
                    if(!empty($option['values'])) {
                        $has_colors = true;
                        $colors = $option['values'];
                    }
                    break;
                }
            }

            if ( !$has_colors) {
                $wasChildInThisColor = false;

                foreach($indexData[$product_id]['configurable_children'] as $child_data) {

                    if (!$wasChildInThisColor) {
                        $wasChildInThisColor = true;

                        $category_data =  $this->getCategoryData($storeId, $child_data['id']);
                        $indexData[$product_id]['category_new'] = $category_data['category_new'];
                        $indexData[$product_id]['category'] = $category_data['category'];

                        continue;
                    } else {
                        //loop through the children and get the values of the smallest size child with the same color
                        $categories_data =  $this->getCategoryData($storeId, $child_data['id']);
                        foreach ($categories_data['category_new'] as $category_id => $valueToCheck) {
                            if (!isset($indexData[$product_id]['category_new'])) {
                                // Is it even possible?
                                continue;
                            }
                            $currentValue = isset($indexData[$product_id]['category_new'][$category_id]) ? $indexData[$product_id]['category_new'][$category_id] : 0;
                            // If new value is 0, do nothing
                            if ($valueToCheck == 0) {
                                continue;
                            }
                            // If current value is 0, and new is not 0. Set it
                            if ($currentValue == 0) {
                                // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
                                $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
                                continue;
                            }

                            // If both are none 0, compare
                            if ($valueToCheck < $currentValue) {
                                // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
                                $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
                                continue;
                            }
                        }
                    }
                }

            } else {
                if(!empty($colors)){
                    foreach ($colors as $color) {

                        $wasChildInThisColor = false;
                        //loop through the children and get the values of the smallest size child with the same color
                        foreach($indexData[$product_id]['configurable_children'] as $child_data) {
                            if(!empty($child_data['color']) && $child_data['color'] == $color['value_index']){

                                if (!$wasChildInThisColor) {
                                    $wasChildInThisColor = true;

                                    $category_data =  $this->getCategoryData($storeId, $child_data['id']);
                                    $indexData[$product_id]['category_new'] = $category_data['category_new'];
                                    $indexData[$product_id]['category'] = $category_data['category'];
                                    continue;
                                } else {
                                    $categories_data =  $this->getCategoryData($storeId, $child_data['id']);
                                    foreach ($categories_data['category_new'] as $category_id => $valueToCheck) {
                                        if (!isset($indexData[$product_id]['category_new'])) {
                                            // Is it even possible?
                                            continue;
                                        }
                                        $currentValue = isset($indexData[$product_id]['category_new'][$category_id]) ? $indexData[$product_id]['category_new'][$category_id] : 0;
                                        // If new value is 0, do nothing
                                        if ($valueToCheck == 0) {
                                            continue;
                                        }
                                        // If current value is 0, and new is not 0. Set it
                                        if ($currentValue == 0) {
                                            // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
                                            $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
                                            continue;
                                        }

                                        // If both are none 0, compare
                                        if ($valueToCheck < $currentValue) {
                                            // $clones[$cloneId]['category'][$category_id] = $valueToCheck;
                                            $indexData[$product_id]['category_new'][$category_id] = $valueToCheck;
                                            continue;
                                        }
                                    }
                                }

                            }
                        }

                    }
                }
            }

        }
        return $indexData;
    }

    /**
     * @param StoreInterface $store
     *
     * @return IndexInterface
     */
    private function getIndex(StoreInterface $store)
    {

        $objectManager = ObjectManager::getInstance();
        /* @var \Divante\VsbridgeIndexerCore\Index\IndexOperations $indexOperations */
        $indexOperations = $objectManager->create("Divante\VsbridgeIndexerCore\Index\IndexOperations");

        try {
            $index = $indexOperations->getIndexByName(RebuildEsIndexCommand::INDEX_IDENTIFIER, $store);
        } catch (\Exception $e) {
            $index = $indexOperations->createIndex(RebuildEsIndexCommand::INDEX_IDENTIFIER, $store);
        }

        return $index;
    }

    /**
     * @param $indexDataItem
     * @return string
     */
    private function getIdForClonedItem($indexDataItem): string
    {
        if (!empty($indexDataItem['color'])) {
            $cloneId = $indexDataItem['id'] . '-' . $indexDataItem['color'];
        } else {
            $cloneId = $indexDataItem['id'];
        }
        return (string) $cloneId;
    }

    private function addHreflangUrls($indexData)
    {
        $objectManager = ObjectManager::getInstance();
        $storeManager = $objectManager->create("\Magento\Store\Model\StoreManager");
        $stores = $storeManager->getStores();
        $websiteManager = $objectManager->create("\Magento\Store\Model\Website");

        $productRepository = $objectManager->create(ProductRepositoryInterface::class);
        $productRewrites = $objectManager->create(ProductUrlPathGenerator::class);

        $configReader = $objectManager->create(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        foreach ($indexData as $product_id => $indexDataItem) {
            $hrefLangs = [];
            if ($indexData[$product_id]['type_id'] == 'simple') {
                continue;
            }

            foreach($stores as $store){
                try {
                    $product = $productRepository->get($indexData[$product_id]['sku'], false, $store->getId());

                    /* @TODO: once approved, move out of this loop */
                    if (!isset($this->storeLocales[$store->getId()])) {
                        $website = $websiteManager->load($store->getWebsiteId());
                        $locale = $configReader->getValue('general/locale/code', 'website', $website->getCode());
                        $this->storeLocales[$store->getId()] = $locale;
                    }

                    $hrefLangs[str_replace('_', '-', $this->storeLocales[$store->getId()])] = $productRewrites->getUrlPath($product);
                } catch (\Exception $e){

                }
            }

            $indexData[$product_id]['storecode_url_paths'] = $hrefLangs;
        }
        return $indexData;
    }

    private function replaceOriginalChild($parentIndexData,$newChildData){
        foreach($parentIndexData['configurable_children'] as $childKey =>$childData){
            if($childData['sku'] == $newChildData['sku']) {
                $parentIndexData['configurable_children'][$childKey] = $newChildData;
            }
        }

        return $parentIndexData;
    }

}
