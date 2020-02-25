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
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status as AttributeSourceStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;

/**
 * Checks if a category has changed products and depends on indexer configuration.
 */
class CategorySaveReindexTrigger implements ObserverInterface
{
    protected $productCollectionFactory;
    protected $productVisibility;
    protected $productSourceStatus;
    
    public function __construct(
            ProductCollectionFactory $productCollectionFactory,
            AttributeSourceStatus $productSourceStatus,
            ProductVisibility $productVisibility
            ){
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productSourceStatus =$productSourceStatus;
        $this->productVisibility = $productVisibility;
        
    }
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

        $tmpProductIds = [];
        foreach ($positions as $productId => $position) {
            /* only save products with a non-zero value, others can wait till next save */
            if(!empty($position)) {
                $tmpProductIds[] = $productId;
            }
        }
        
        $collection = $this->productCollectionFactory->create();
        if($category->getStoreId() > 0) {
            $collection->setStoreId($category->getStoreId()); //should we implement this ?
        }
        $collection->addAttributeToFilter('status', ['in' => $this->productSourceStatus->getVisibleStatusIds()]);
        $collection->addAttributeToFilter('visibility',['in' => $this->productVisibility->getVisibleInSiteIds()]);
        $collection->addFieldToFilter('entity_id',['in' => $tmpProductIds]);

        foreach($collection as $product){
            /* @var $product Magento\Catalog\Model\Product */
            if ($product) {
                //Removed the $product->save() as provokes an out of memory issue, and is not nacessary to update the indexes  
                $product->reindex();
            }
        }

    }
}
