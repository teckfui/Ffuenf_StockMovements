<?php
/**
 * @category    Bubble
 * @package     Bubble_StockMovements
 * @version     1.2.2
 * @copyright   Copyright (c) 2015 BubbleShop (https://www.bubbleshop.net)
 */
class Bubble_StockMovements_Model_Stock_Observer
{
    public function addStockMovementsTab()
    {
        $layout = Mage::getSingleton('core/layout');
        /** @var Mage_Adminhtml_Block_Catalog_Product_Edit_Tabs $block */
        $block = $layout->getBlock('product_tabs');
        if ($block && $block->getProduct() && $block->getProduct()->getTypeId() == 'simple') {
            $block->addTab('stock_movements', array(
                'after' => 'inventory',
                'label' => Mage::helper('bubble_stockmovements')->__('Stock Movements'),
                'content' => $layout->createBlock('bubble_stockmovements/adminhtml_stock_movement_grid')->toHtml(),
            ));
        }
    }

    /**
     * This method overrides the core listener (core listener disabled in this module's config.xml).
     *
     * @param $observer
     * @return $this
     */
    public function cancelOrderItem($observer)
    {
        $item = $observer->getEvent()->getItem();

        $children = $item->getChildrenItems();
        $qty = $item->getQtyOrdered() - max($item->getQtyShipped(), $item->getQtyInvoiced()) - $item->getQtyCanceled();

        if ($item->getId() && ($productId = $item->getProductId()) && empty($children) && $qty) {
            Mage::getSingleton('cataloginventory/stock')->backItemQty($productId, $qty);
            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($item->getProductId());
            $this->insertStockMovement(
                $stockItem,
                sprintf('Product restocked after order cancellation (order: %s)',$item->getOrder()->getIncrementId()),
                $stockItem->getQty() - $qty
            );
        }

        return $this;
    }

    public function catalogProductImportFinishBefore($observer)
    {
        $productIds = array();
        $adapter = $observer->getEvent()->getAdapter();
        $resource = Mage::getResourceModel('bubble_stockmovements/stock_movement');

        if ($adapter instanceof Mage_Catalog_Model_Convert_Adapter_Product) {
            $productIds = $adapter->getAffectedEntityIds();
        } else {
            Mage_ImportExport_Model_Import::getDataSourceModel()->getIterator()->rewind();
            $skus = array();
            while ($bunch = $adapter->getNextBunch()) {
                foreach ($bunch as $rowData) {
                    if (null !== $rowData['sku']) {
                        $skus[] = $rowData['sku'];
                    }
                }
            }
            if (!empty($skus)) {
                $productIds = $resource->getProductsIdBySku($skus);
            }
        }

        if (!empty($productIds)) {
            $stock = Mage::getSingleton('cataloginventory/stock');
            $stocks = Mage::getResourceModel('cataloginventory/stock')->getProductsStock($stock, $productIds);
            $stocksMovements = array();
            $datetime = Varien_Date::formatDate(time());
            foreach ($stocks as $stockData) {
                $stocksMovements[] = array(
                    'item_id'     => $stockData['item_id'],
                    'user'        => $this->_getUsername(),
                    'user_id'     => $this->_getUserId(),
                    'qty'         => $stockData['qty'],
                    'is_in_stock' => (int) $stockData['is_in_stock'],
                    'message'     => 'Product import',
                    'created_at'  => $datetime,
                );
            }

            if (!empty($stocksMovements)) {
                $resource->insertStocksMovements($stocksMovements);
            }
        }
    }

    public function checkoutAllSubmitAfter($observer)
    {
        if ($observer->getEvent()->hasOrders()) {
            $orders = $observer->getEvent()->getOrders();
        } else {
            $orders = array($observer->getEvent()->getOrder());
        }
        $stockItems = array();
        foreach ($orders as $order) {
            if ($order) {
                foreach ($order->getAllItems() as $orderItem) {
                    /** @var Mage_Sales_Model_Order_Item $orderItem */
                    if ($orderItem->getQtyOrdered() && $orderItem->getProductType() == 'simple') {
                        $stockItem = Mage::getModel('cataloginventory/stock_item')
                            ->loadByProduct($orderItem->getProductId());
                        if (!isset($stockItems[$stockItem->getId()])) {
                            $stockItems[$stockItem->getId()] = array(
                                'item' => $stockItem,
                                'orders' => array($order->getIncrementId()),
                                'orig_qty' => $stockItem->getQty() + $orderItem->getQtyOrdered()
                            );
                        } else {
                            $stockItems[$stockItem->getId()]['orders'][] = $order->getIncrementId();
                        }
                    }
                }
            }
        }

        if (!empty($stockItems)) {
            foreach ($stockItems as $data) {
                $this->insertStockMovement(
                    $data['item'],
                    sprintf('Product ordered (order%s: %s)',count($data['orders']) > 1 ? 's' : '', implode(', ', $data['orders'])),
                    $data['orig_qty']
                );
            }
        }
    }

    /**
     * Creates a new StockMovement object and commits to database.
     *
     * @param Mage_CatalogInventory_Model_Stock_Item $stockItem
     * @param string $message
     * @param null $origQty
     */
    public function insertStockMovement($stockItem, $message = '', $origQty = null)
    {
        if ($stockItem->getId()) {

            $origQty = $origQty !== null ? $origQty : $stockItem->getOriginalInventoryQty();

            // Do not create entry if the quantity hasn't changed
            if ($origQty == $stockItem->getQty()) return;

            Mage::getModel('bubble_stockmovements/stock_movement')
                ->setItemId($stockItem->getId())
                ->setUser($this->_getUsername())
                ->setUserId($this->_getUserId())
                ->setIsAdmin((int) Mage::getSingleton('admin/session')->isLoggedIn())
                ->setQty($stockItem->getQty())
                ->setOriginalQty($origQty !== null ? $origQty : $stockItem->getOriginalInventoryQty())
                ->setIsInStock((int) $stockItem->getIsInStock())
                ->setMessage($message)
                ->save();
        }
    }

    public function saveStockItemAfter($observer)
    {
        $stockItem = $observer->getEvent()->getItem();
        if (!$stockItem->getStockStatusChangedAutomaticallyFlag() || ($stockItem->getOriginalInventoryQty() !== null && ($stockItem->getOriginalInventoryQty() != $stockItem->getQty()))) {
            if (!$message = $stockItem->getSaveMovementMessage()) {
                if (Mage::getSingleton('api/session')->getSessionId()) {
                    $message = 'Stock saved from Magento API';
                } else {
                    $message = 'Stock saved manually';
                }
            }
            $this->insertStockMovement($stockItem, $message);
        }
    }

    public function stockRevertProductsSale($observer)
    {
        $items = $observer->getEvent()->getItems();
        foreach ($items as $productId => $item) {
            $product = Mage::getModel('catalog/product')->load($productId);
            if ($product->getTypeId() != "simple") continue;

            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
            if ($stockItem->getId()) {
                $message = 'Product restocked';
                if ($creditMemo = Mage::registry('current_creditmemo')) {
                    $message = sprintf(
                        'Product restocked after credit memo creation (credit memo: %s)',
                        $creditMemo->getIncrementId()
                    );
                }

                // If there is a quote, and its inventory has already been processed, ignore this action
                if (Mage::getSingleton('checkout/session')->getQuote() &&
                    Mage::getSingleton('checkout/session')->getQuote()->getInventoryProcessed()) {
                    return;
                }
                $this->insertStockMovement($stockItem, $message, $stockItem->getQty() - $item['qty']);
            }
        }
    }

    protected function _getUserId()
    {
        $userId = null;
        if (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $userId = Mage::getSingleton('customer/session')->getCustomerId();
        } elseif (Mage::getSingleton('admin/session')->isLoggedIn()) {
            $userId = Mage::getSingleton('admin/session')->getUser()->getId();
        }

        return $userId;
    }

    protected function _getUsername()
    {
        $username = '-';
        if (Mage::getSingleton('api/session')->isLoggedIn()) {
            $username = Mage::getSingleton('api/session')->getUser()->getUsername();
        } elseif (Mage::getSingleton('customer/session')->isLoggedIn()) {
            $username = Mage::getSingleton('customer/session')->getCustomer()->getName();
        } elseif (Mage::getSingleton('admin/session')->isLoggedIn()) {
            $username = Mage::getSingleton('admin/session')->getUser()->getUsername();
        }

        return $username;
    }
}
