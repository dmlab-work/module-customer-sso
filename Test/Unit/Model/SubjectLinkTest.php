<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\SubjectLink;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SubjectLinkTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var SubjectLink */
    private SubjectLink $subjectLink;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($t) => 'prefix_' . $t);

        $this->subjectLink = new SubjectLink($resource);
    }

    /**
     * A Select stub whose fluent builders return itself.
     *
     * @return Select&\PHPUnit\Framework\MockObject\Stub
     */
    private function selectStub()
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    /**
     * A Select mock asserting the table/column read and recording where predicates.
     *
     * @param string|string[] $column
     * @param list<array{0:string,1:mixed}> $where
     * @return Select&MockObject
     */
    private function recordingSelect($column, array &$where)
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())
            ->method('from')
            ->with('prefix_' . SubjectLink::TABLE, $column)
            ->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($clause, $value) use ($select, &$where) {
            $where[] = [$clause, $value];
            return $select;
        });
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    /**
     * A Select mock recording where predicates of the identity lookup.
     *
     * @param list<array{0:string,1:mixed}> $where
     * @return Select&MockObject
     */
    private function identitySelect(array &$where)
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())
            ->method('from')
            ->with('prefix_' . SubjectLink::TABLE, ['customer_id', 'issuer'])
            ->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($clause, $value) use ($select, &$where) {
            $where[] = [$clause, $value];
            return $select;
        });
        $select->expects(self::once())->method('order')->with('issuer DESC')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    public function testFindCustomerIdBySubjectMatchesIssuerExactlyFirst(): void
    {
        $where = [];
        $select = $this->identitySelect($where);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchRow')->with($select)
            ->willReturn(['customer_id' => '42', 'issuer' => 'https://org1.okta.com']);
        $this->connection->expects(self::never())->method('update');

        self::assertSame(42, $this->subjectLink->findCustomerIdBySubject('okta', 'https://org1.okta.com', 'sub-1'));
        self::assertSame(
            [
                ['provider_code = ?', 'okta'],
                ['subject_id = ?', 'sub-1'],
                ['issuer IN (?)', ['https://org1.okta.com', '']],
            ],
            $where
        );
    }

    public function testFindCustomerIdBySubjectScopesToWebsite(): void
    {
        $where = [];
        $this->connection->method('select')->willReturn($this->identitySelect($where));
        $this->connection->expects(self::once())->method('fetchRow')->willReturn(false);

        self::assertNull($this->subjectLink->findCustomerIdBySubject('okta', 'https://org1.okta.com', 'sub-1', 2));
        self::assertContains(['website_id = ?', 2], $where);
    }

    public function testFindCustomerIdBySubjectAdoptsIssuerOnLegacyLink(): void
    {
        $where = [];
        $this->connection->method('select')->willReturn($this->identitySelect($where));
        $this->connection->expects(self::once())->method('fetchRow')
            ->willReturn(['customer_id' => '42', 'issuer' => '']);
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'prefix_' . SubjectLink::TABLE,
                ['issuer' => 'https://org1.okta.com'],
                ['customer_id = ?' => 42, 'provider_code = ?' => 'okta', 'issuer = ?' => '']
            );

        self::assertSame(42, $this->subjectLink->findCustomerIdBySubject('okta', 'https://org1.okta.com', 'sub-1'));
    }

    public function testFindCustomerIdBySubjectReturnsNullWhenAbsent(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('fetchRow')->willReturn(false);

        self::assertNull($this->subjectLink->findCustomerIdBySubject('okta', 'https://iss', 'sub-x'));
    }

    public function testFindCustomerIdBySubjectShortCircuitsOnEmptySubject(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->findCustomerIdBySubject('okta', 'https://iss', ''));
    }

    public function testFindCustomerIdBySubjectShortCircuitsOnEmptyProvider(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->findCustomerIdBySubject('', 'https://iss', 'sub-1'));
    }

    public function testGetSubjectForProviderReturnsSubject(): void
    {
        $where = [];
        $select = $this->recordingSelect('subject_id', $where);
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('sub-9');

        self::assertSame('sub-9', $this->subjectLink->getSubjectForProvider(7, 'okta'));
        self::assertSame([['customer_id = ?', 7], ['provider_code = ?', 'okta']], $where);
    }

    public function testGetSubjectForProviderReturnsNullWhenAbsent(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('fetchOne')->willReturn(false);

        self::assertNull($this->subjectLink->getSubjectForProvider(7, 'okta'));
    }

    public function testGetSubjectForProviderShortCircuitsOnEmptyArgs(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertNull($this->subjectLink->getSubjectForProvider(0, 'okta'));
        self::assertNull($this->subjectLink->getSubjectForProvider(7, ''));
    }

    public function testGetLinksByCustomerIdListsAllProviders(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())
            ->method('from')
            ->with('prefix_' . SubjectLink::TABLE, ['provider_code', 'subject_id', 'created_at'])
            ->willReturnSelf();
        $select->expects(self::once())->method('where')->with('customer_id = ?', 7)->willReturnSelf();
        $select->expects(self::once())
            ->method('order')
            ->with(['created_at ASC', 'provider_code ASC'])
            ->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchAll')->with($select)->willReturn([
            ['provider_code' => 'okta', 'subject_id' => 'sub-1', 'created_at' => '2026-10-01 10:00:00'],
            ['provider_code' => 'google', 'subject_id' => 'g-2', 'created_at' => '2026-10-02 10:00:00'],
        ]);

        self::assertSame(
            [
                ['provider_code' => 'okta', 'subject_id' => 'sub-1', 'created_at' => '2026-10-01 10:00:00'],
                ['provider_code' => 'google', 'subject_id' => 'g-2', 'created_at' => '2026-10-02 10:00:00'],
            ],
            $this->subjectLink->getLinksByCustomerId(7)
        );
    }

    public function testGetLinksByCustomerIdReturnsEmptyWhenNone(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('fetchAll')->willReturn([]);

        self::assertSame([], $this->subjectLink->getLinksByCustomerId(7));
    }

    public function testGetLinksByCustomerIdShortCircuitsOnNonPositiveId(): void
    {
        $this->connection->expects(self::never())->method('select');

        self::assertSame([], $this->subjectLink->getLinksByCustomerId(0));
    }

    public function testLinkInsertsWithIssuerAndCustomerWebsite(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->with('prefix_customer_entity', 'website_id')->willReturnSelf();
        $select->expects(self::once())->method('where')->with('entity_id = ?', 5)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->expects(self::once())->method('fetchOne')->with($select)->willReturn('3');
        $this->connection->expects(self::never())->method('insertOnDuplicate');
        $this->connection->expects(self::once())
            ->method('insert')
            ->with(
                'prefix_' . SubjectLink::TABLE,
                [
                    'customer_id' => 5,
                    'provider_code' => 'okta',
                    'issuer' => 'https://iss',
                    'subject_id' => 'sub-5',
                    'website_id' => 3,
                ]
            )
            ->willReturn(1);

        $this->subjectLink->link(5, 'okta', 'https://iss', 'sub-5');
    }

    public function testLinkDuplicateBecomesLocalizedException(): void
    {
        $this->connection->method('select')->willReturn($this->selectStub());
        $this->connection->expects(self::once())->method('insert')
            ->willThrowException(new DuplicateException('Duplicate entry'));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This sign-in identity is already linked to another account.');

        $this->subjectLink->link(5, 'okta', 'https://iss', 'sub-5');
    }

    public function testLinkShortCircuitsOnInvalidInput(): void
    {
        $this->connection->expects(self::never())->method('insert');

        $this->subjectLink->link(0, 'okta', 'https://iss', 'sub');
        $this->subjectLink->link(5, 'okta', 'https://iss', '');
        $this->subjectLink->link(5, '', 'https://iss', 'sub');
    }

    public function testUnlinkDeletesOwnProviderRow(): void
    {
        $this->connection->expects(self::once())
            ->method('delete')
            ->with('prefix_' . SubjectLink::TABLE, ['customer_id = ?' => 5, 'provider_code = ?' => 'okta'])
            ->willReturn(1);

        self::assertTrue($this->subjectLink->unlink(5, 'okta'));
    }

    public function testUnlinkReturnsFalseWhenNothingDeleted(): void
    {
        $this->connection->expects(self::once())->method('delete')->willReturn(0);

        self::assertFalse($this->subjectLink->unlink(5, 'google'));
    }

    public function testUnlinkShortCircuitsOnInvalidInput(): void
    {
        $this->connection->expects(self::never())->method('delete');

        self::assertFalse($this->subjectLink->unlink(0, 'okta'));
        self::assertFalse($this->subjectLink->unlink(5, ''));
    }
}
