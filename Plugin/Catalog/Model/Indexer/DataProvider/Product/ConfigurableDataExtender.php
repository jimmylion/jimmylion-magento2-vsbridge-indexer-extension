<?php

namespace CodingMice\VsBridgeIndexerExtension\Plugin\Catalog\Model\Indexer\DataProvider\Product;

use Divante\VsbridgeIndexerCatalog\Model\Attribute\LoadOptionLabelById;
use Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category as CategoryResource;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData;
use Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\MediaGalleryData;
use Divante\VsbridgeIndexerCore\Api\DataProviderInterface;
use Divante\VsbridgeIndexerCore\Console\Command\RebuildEsIndexCommand;
use Divante\VsbridgeIndexerCore\Config\IndicesSettings;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Api\Data\StoreInterface;


class ConfigurableDataExtender {

    public $storeId;

    /* @var CategoryResource $categoryResource */
    private $categoryResource;

    /* @var LoadOptionById $loadOptionById */
    private $loadOptionById;

    /* variable to cache locale for each store */
    private $storeLocales = [];

    private $storeManager;
    private $indexOperations;
    private $websiteManager;
    private $productRepository;
    private $urlPathGenerator;
    private $scopeConfig;

    public function __construct(
            \Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product\Category $categoryResource,
            \Divante\VsbridgeIndexerCatalog\Model\Attribute\LoadOptionById $loadOptionById,
            \Divante\VsbridgeIndexerCore\Index\IndexOperations $indexOperations,
            \Magento\Store\Model\StoreManagerInterface $storeManager,
            \Magento\Store\Model\Website $websiteManager,
            ProductRepositoryInterface $productRepository,
            ProductUrlPathGenerator $urlPathGenerator,
            \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ){
        $this->categoryResource = $categoryResource;
        $this->loadOptionById = $loadOptionById;
        $this->indexOperations = $indexOperations;
        $this->storeManager = $storeManager;
        $this->websiteManager = $websiteManager;
        $this->productRepository = $productRepository;
        $this->urlPathGenerator = $urlPathGenerator;
        $this->scopeConfig = $scopeConfig;

    }

    public function beforeAddData(ConfigurableData $subject, $docs, $storeId){
        $this->storeId = $storeId;
    }
    /**
     * This method will take ES docs prepared by Divante Extension and modify them
     * before they are added to ES in \Divante\VsbridgeIndexerCore\Indexer\GenericIndexerHandler::saveIndex
     * @see: \Divante\VsbridgeIndexerCatalog\Model\Indexer\DataProvider\Product\ConfigurableData::addData
     */
    public function afterAddData(ConfigurableData $subject, $docs){

        $storeId = $this->storeId;
        $docs = $this->extendDataWithGallery($subject, $docs,$storeId);

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

								if ( ! empty($indexDataItem['color'] ) || (isset($indexDataItem['configurable_children'][0]['color']) && $indexDataItem['configurable_children'][0]['color']) ) {

                    $attributeCode = 'color';
                    $clones[$cloneId]['clone_color_id'] = isset($indexDataItem['color']) && is_numeric($indexDataItem['color']) ? $indexDataItem['color'] : $indexDataItem['configurable_children'][0]['color'];
                    $clones[$cloneId]['sku'] = $indexDataItem['sku'].'-'.$clones[$cloneId]['clone_color_id'];
                    $clone_color_option = $this->loadOptionById->execute($attributeCode, $clones[$cloneId]['clone_color_id'], $storeId);
                    $clones[$cloneId]['clone_color_label'] = $clone_color_option['label'];
                    $clone_color = strtolower(str_ireplace(' ', '-', $clones[$cloneId]['clone_color_label']));
                    $clones[$cloneId]['is_clone'] = 1; // there is no difference now
                    $clones[$cloneId]['url_key'] = $indexDataItem['url_key'].'?color='.$clone_color;
                    $clones[$cloneId]['clone_name'] = $indexDataItem['name'].' '.$clones[$cloneId]['clone_color_label'];

                    // Add attributes
                    $firstChild = isset($indexDataItem['configurable_children']) ? $indexDataItem['configurable_children'][0] : null;
                    if ($firstChild) {
                        if (isset($firstChild['color_group'])) {
                            $clones[$cloneId]['color_group'] = $firstChild['color_group'];
                        }
                        if (isset($firstChild['length'])) {
                            $clones[$cloneId]['length'] = intval($firstChild['length']);
                        }
                        if (isset($firstChild['style'])) {
                            $clones[$cloneId]['style'] = intval($firstChild['style']);
                        }
                        if (isset($firstChild['featured'])) {
                            $clones[$cloneId]['featured'] = $firstChild['featured'];
                        }
                        if (!isset($clones[$cloneId]['talla_options'])) {
                            $tallaId = isset($firstChild['talla']) ? $firstChild['talla'] : null;
                            if (!$tallaId && isset($clones[$cloneId]['talla'])) {
                                $tallaId = isset($clones[$cloneId]['talla']) ? $clones[$cloneId]['talla'] : null;
                            }
                            if ($tallaId) {
                                $clones[$cloneId]['talla_options'] = array(
                                    $tallaId
                                );
                            }
                        }
                    }

                }


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

                        // Add attributes
                        $firstChild = null;
                        foreach ($clones[$cloneId]['configurable_children'] as $child) {
                            if (!isset($child['color'])) {
                                continue;
                            }
                            if (intval($child['color']) == intval($color['value_index'])) {
                                $firstChild = $child;
                                break;
                            }
                        }

                        if ($firstChild) {
                            if (isset($firstChild['color_group'])) {
                                $clones[$cloneId]['color_group'] = $firstChild['color_group'];
                            }
                            if (isset($firstChild['length'])) {
                                $clones[$cloneId]['length'] = intval($firstChild['length']);
                            }
                            if (isset($firstChild['style'])) {
                                $clones[$cloneId]['style'] = intval($firstChild['style']);
                            }
                            if (isset($firstChild['featured'])) {
                                $clones[$cloneId]['featured'] = $firstChild['featured'];
                            }
                            if (!isset($clones[$cloneId]['talla_options'])) {
                                $tallaId = isset($firstChild['talla']) ? $firstChild['talla'] : null;
                                if (!$tallaId && isset($clones[$cloneId]['talla'])) {
                                    $tallaId = isset($clones[$cloneId]['talla']) ? $clones[$cloneId]['talla'] : null;
                                }
                                if ($tallaId) {
                                    $clones[$cloneId]['talla_options'] = array(
                                        $tallaId
                                    );
                                }
                            }
                        }
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

        $store = $this->storeManager->getStore($storeId);
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

	    $smallest_tallas = array_flip(['4','130','62','102']);

        foreach ($indexData as $product_id => $indexDataItem) {

		    if ( !isset($indexDataItem['clone_color_id']) ) {
				continue;
	        }

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

					if ( !isset($smallest_tallas[$child_data['talla']]) ) {
						continue;
                    }

                    $category_data =  $this->getCategoryData($storeId, $child_data['id']);
                    $indexData[$product_id]['category_new'] = $category_data['category_new'];
                    $indexData[$product_id]['category'] = $category_data['category'];
                    break;

                }

            } else {

                if(!empty($colors)){

                    foreach ($colors as $color) {

                        if ( $color['value_index'] !== $indexDataItem['clone_color_id'] ) {
                                continue;
                        }

                        //loop through the children and get the values of the smallest size child with the same color
                        foreach($indexData[$product_id]['configurable_children'] as $child_data) {

                            if ( empty($child_data['color']) || $child_data['color'] != $color['value_index'] ) {
                                continue;
	                        }

							if ( !isset($smallest_tallas[$child_data['talla']]) ) {
								continue;
                            }

                            $category_data =  $this->getCategoryData($storeId, $child_data['id']);
                            $indexData[$product_id]['category_new'] = $category_data['category_new'];
                            $indexData[$product_id]['category'] = $category_data['category'];
                            break;

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

        try {
            $index = $this->indexOperations->getIndexByName(RebuildEsIndexCommand::INDEX_IDENTIFIER, $store);
        } catch (\Exception $e) {
            $index = $this->indexOperations->createIndex(RebuildEsIndexCommand::INDEX_IDENTIFIER, $store);
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
        } elseif(isset($indexDataItem['configurable_children'][0]['color'])) {
            $cloneId = $indexDataItem['id'] . '-' . $indexDataItem['configurable_children'][0]['color'];
        } else {
	        	$cloneId = $indexDataItem['id'];
        }
        return (string) $cloneId;
    }

    private function addHreflangUrls($indexData)
    {
        $stores = $this->storeManager->getStores();

        foreach ($indexData as $product_id => $indexDataItem) {
            $hrefLangs = [];
            if ($indexData[$product_id]['type_id'] == 'simple') {
                continue;
            }

            foreach($stores as $store){
                try {
                    $product = $this->productRepository->get($indexData[$product_id]['sku'], false, $store->getId());

                    /* @TODO: once approved, move out of this loop */
                    if (!isset($this->storeLocales[$store->getId()])) {
                        $website = $this->websiteManager->load($store->getWebsiteId());
                        $locale = $this->scopeConfig->getValue('general/locale/code', 'website', $website->getCode());
                        $this->storeLocales[$store->getId()] = $locale;
                    }

                    $hrefLangs[str_replace('_', '-', $this->storeLocales[$store->getId()])] = $this->urlPathGenerator->getUrlPath($product);
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
