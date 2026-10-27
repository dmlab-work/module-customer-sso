<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Setup\Patch\Data;

use DmLab\CustomerSso\Model\SubjectLink;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Copies each linked customer's website onto its identity link, so per-website
 * lookups keep resolving links created before the column existed.
 */
class BackfillSubjectLinkWebsite implements DataPatchInterface
{
    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()->join(
            ['customer' => $this->moduleDataSetup->getTable('customer_entity')],
            'customer.entity_id = link.customer_id',
            ['website_id' => new Expression('COALESCE(customer.website_id, 0)')]
        );
        $connection->query(
            $connection->updateFromSelect($select, ['link' => $this->moduleDataSetup->getTable(SubjectLink::TABLE)])
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
