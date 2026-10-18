<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Storage for the IdP-subject → customer link (see etc/db_schema.xml).
 *
 * The subject (`sub`) is the stable re-login key: once a customer is linked, every
 * later SSO login resolves them by subject rather than by the mutable email. This
 * lives in its own table because storefront customers are EAV/API entities — unlike
 * `admin_user`, where {@see \DmLab\AdminSso\Model\UserProvisioner} keeps the
 * subject as a plain column on the table.
 */
class SubjectLink
{
    /** Link table name (see etc/db_schema.xml). */
    public const TABLE = 'dmlab_customer_sso_subject';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Customer id linked to the given IdP subject under the given provider, or null.
     *
     * Scoped by provider code because `sub` is only unique per issuer: a collision
     * across providers must not resolve to another provider's customer.
     *
     * @param string $providerCode active SSO provider code
     * @param string $subjectId
     */
    public function findCustomerIdBySubject(string $providerCode, string $subjectId): ?int
    {
        if ($providerCode === '' || $subjectId === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), 'customer_id')
            ->where('provider_code = ?', $providerCode)
            ->where('subject_id = ?', $subjectId)
            ->limit(1);

        $customerId = $connection->fetchOne($select);

        return $customerId === false || $customerId === null || $customerId === ''
            ? null
            : (int)$customerId;
    }

    /**
     * Subject the customer holds under the given provider, or null.
     *
     * @param int $customerId
     * @param string $providerCode SSO provider code
     */
    public function getSubjectForProvider(int $customerId, string $providerCode): ?string
    {
        if ($customerId <= 0 || $providerCode === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), 'subject_id')
            ->where('customer_id = ?', $customerId)
            ->where('provider_code = ?', $providerCode)
            ->limit(1);

        $subjectId = $connection->fetchOne($select);

        return is_string($subjectId) && $subjectId !== '' ? $subjectId : null;
    }

    /**
     * All identities linked to the customer, oldest first.
     *
     * @param int $customerId
     * @return list<array{provider_code:string,subject_id:string,created_at:string}>
     */
    public function getLinksByCustomerId(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['provider_code', 'subject_id', 'created_at'])
            ->where('customer_id = ?', $customerId)
            ->order(['created_at ASC', 'provider_code ASC']);

        $links = [];
        foreach ($connection->fetchAll($select) as $row) {
            $links[] = [
                'provider_code' => (string)$row['provider_code'],
                'subject_id' => (string)$row['subject_id'],
                'created_at' => (string)$row['created_at'],
            ];
        }

        return $links;
    }

    /**
     * Link a subject to a customer. Never overwrites an existing link.
     *
     * @param int $customerId
     * @param string $providerCode SSO provider code that issued the subject
     * @param string $subjectId
     * @throws LocalizedException when the customer already holds an identity for the
     *         provider, or the identity belongs to another customer
     */
    public function link(int $customerId, string $providerCode, string $subjectId): void
    {
        if ($customerId <= 0 || $providerCode === '' || $subjectId === '') {
            return;
        }

        try {
            $this->resource->getConnection()->insert(
                $this->resource->getTableName(self::TABLE),
                ['customer_id' => $customerId, 'provider_code' => $providerCode, 'subject_id' => $subjectId]
            );
        } catch (DuplicateException $e) {
            throw new LocalizedException(__('This sign-in identity is already linked to another account.'), $e);
        }
    }

    /**
     * Remove the customer's link for the given provider.
     *
     * @param int $customerId
     * @param string $providerCode
     * @return bool true when a link was removed
     */
    public function unlink(int $customerId, string $providerCode): bool
    {
        if ($customerId <= 0 || $providerCode === '') {
            return false;
        }

        $deleted = $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['customer_id = ?' => $customerId, 'provider_code = ?' => $providerCode]
        );

        return $deleted > 0;
    }
}
