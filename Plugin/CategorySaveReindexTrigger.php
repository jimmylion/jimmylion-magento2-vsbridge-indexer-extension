<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace CodingMice\VsBridgeIndexerExtension\Plugin;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product\Processor;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Checks if a category has changed products and depends on indexer configuration.
 */
class CategorySaveReindexTrigger implements ObserverInterface
{
    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData('category');
        /**
         * @var $category Category
         */
        $positions = $category->getProductsPosition();

        if (empty($positions)) {
            return;
        }
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $tmpProductIds = [];
        foreach ($positions as $productId => $position) {
            /* only save products with a non-zero value, others can wait till next save */
            if(!empty($position)) {
                $tmpProductIds[] = $productId;
            }
        }

        $collectionFactory = $objectManager->get('\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory');
        /* @var $collectionFactory \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory */
        $productStatus = $objectManager->get('\Magento\Catalog\Model\Product\Attribute\Source\Status');
        /* @var $productStatus \Magento\Catalog\Model\Product\Attribute\Source\Status */
        $productVisibility = $objectManager->get('\Magento\Catalog\Model\Product\Visibility');
        /* @var $productVisibility \Magento\Catalog\Model\Product\Visibility */
        $collection = $collectionFactory->create();
        if($category->getStoreId() > 0) {
            $collection->setStoreId($category->getStoreId()); //should we implement this ?
        }
        $collection->addAttributeToFilter('status', ['in' => $productStatus->getVisibleStatusIds()]);
        $collection->addAttributeToFilter('visibility',['in' => $productVisibility->getVisibleInSiteIds()]);
        $collection->addFieldToFilter('entity_id',['in' => $tmpProductIds]);

        foreach($collection as $product){
            /* @var $product Magento\Catalog\Model\Product */
            if ($product) {
                $product->save();
                /**
                 * @TODO: reindex is already called by Product::afterSave ???
                 * works fast enough, but see if this can also be removed
                 */
                $product->reindex();
            }
        }

    }
}
