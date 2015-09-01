<?php
/**
 * Add original_qty field to the movement table, to keep track of the stock item qty before the update, allowing reporting
 * on actual the stock movement per stock item.
 */

$installer = $this;
/* @var $installer Mage_Core_Model_Resource_Setup */

$installer->startSetup();

$tableMovement  = $installer->getTable('bubble_stock_movement');

$installer->run("
    ALTER TABLE `{$tableMovement}` ADD COLUMN `original_qty` decimal(12,4) NOT NULL AFTER `created_at`;
");

$installer->endSetup();