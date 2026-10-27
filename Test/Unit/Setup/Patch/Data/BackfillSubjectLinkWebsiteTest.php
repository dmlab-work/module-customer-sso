<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Setup\Patch\Data;

use DmLab\CustomerSso\Setup\Patch\Data\BackfillSubjectLinkWebsite;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

class BackfillSubjectLinkWebsiteTest extends TestCase
{
    public function testCopiesCustomerWebsiteOntoLinks(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('join')->with(
            ['customer' => 'prefixed_customer_entity'],
            'customer.entity_id = link.customer_id',
            self::callback(static fn (array $columns): bool => array_keys($columns) === ['website_id']
                && $columns['website_id'] instanceof Expression
                && (string)$columns['website_id'] === 'COALESCE(customer.website_id, 0)')
        )->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('select')->willReturn($select);
        $connection->expects(self::once())->method('updateFromSelect')
            ->with($select, ['link' => 'prefixed_dmlab_customer_sso_subject'])
            ->willReturn('UPDATE ...');
        $connection->expects(self::once())->method('query')->with('UPDATE ...');

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(fn (string $table): string => 'prefixed_' . $table);

        $patch = new BackfillSubjectLinkWebsite($setup);

        self::assertSame($patch, $patch->apply());
        self::assertSame([], BackfillSubjectLinkWebsite::getDependencies());
        self::assertSame([], $patch->getAliases());
    }
}
