<?php

/**
 * @file classes/depositObject/Collector.php
 *
 * Copyright (c) 2023 Simon Fraser University
 * Copyright (c) 2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Collector
 *
 * @brief A helper class to configure a query builder to get a collection of deposit objects
 */

namespace APP\plugins\generic\pln\classes\depositObject;

use APP\plugins\generic\pln\PlnPlugin;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PKP\core\interfaces\CollectorInterface;
use PKP\plugins\Hook;

/**
 * @template T of DepositObject
 */
class Collector implements CollectorInterface
{
    public ?int $count = null;

    public ?int $offset = null;

    /** @var int[]|null */
    public ?array $ids = null;

    /** @var int[]|null */
    public ?array $contextIds = null;


    /** @var int[]|null */
    public ?array $depositIds = null;

    /** @var bool|null null = no filter, true = only orphans, false = exclude orphans */
    public ?bool $isOrphaned = false;

    public function __construct(public DAO $dao)
    {
    }

    public function getCount(): int
    {
        return $this->dao->getCount($this);
    }

    /**
     * @return Collection<int,int>
     */
    public function getIds(): Collection
    {
        return $this->dao->getIds($this);
    }

    /**
     * @copydoc DAO::getMany()
     *
     * @return LazyCollection<int,T>
     */
    public function getMany(): LazyCollection
    {
        return $this->dao->getMany($this);
    }

    /**
     * Limit the number of objects retrieved
     */
    public function limit(?int $count): self
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Offset the number of objects retrieved, for example to
     * retrieve the second page of contents
     */
    public function offset(?int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Filter by ID
     */
    public function filterByIds(?array $ids): static
    {
        $this->ids = $ids;
        return $this;
    }

    /**
     * Filter by UUID
     */
    public function filterByDepositIds(?array $depositIds): static
    {
        $this->depositIds = $depositIds;
        return $this;
    }

    /**
     * Limit results to deposit objects in these context IDs
     */
    public function filterByContextIds(?array $contextIds): static
    {
        $this->contextIds = $contextIds;
        return $this;
    }

    /**
     * Include or exclude orphaned deposit objects
     *
     * A deposit object is orphaned when its journal is missing, it references a
     * non-existent deposit, or its submission/issue content is missing.
     */
    public function filterByOrphaned(?bool $isOrphaned): static
    {
        $this->isOrphaned = $isOrphaned;
        return $this;
    }

    /**
     * @copydoc CollectorInterface::getQueryBuilder()
     *
     * @hook PreservationNetwork::DepositObject::Collector [[&$q, $this]]
     */
    public function getQueryBuilder(): Builder
    {
        $q = DB::table('pln_deposit_objects as do')
            ->select('do.*')
            ->when($this->ids !== null, fn (Builder $query) => $query->whereIn('do.deposit_object_id', $this->ids))
            ->when($this->depositIds !== null, fn (Builder $query) => $query->whereIn('do.deposit_id', $this->depositIds))
            ->when($this->contextIds !== null, fn (Builder $query) => $query->whereIn('do.journal_id', $this->contextIds))
            ->when(
                $this->isOrphaned !== null,
                fn (Builder $q) => $this->isOrphaned
                    ? $this->applyOrphanConstraints($q)
                    : $q->whereNot(fn (Builder $q) => $this->applyOrphanConstraints($q))
            );
        // Add app-specific query statements
        Hook::call('PreservationNetwork::DepositObject::Collector', [&$q, $this]);
        return $q;
    }

    /**
     * Constrain the query to orphaned deposit objects
     */
    protected function applyOrphanConstraints(Builder $q): Builder
    {
        return $q->where(
            fn (Builder $q) => $q
                ->whereNotExists(
                    fn (Builder $q) => $q
                        ->from('journals AS j')
                        ->whereColumn('j.journal_id', 'do.journal_id')
                )
                ->orWhere(
                    fn (Builder $q) => $q
                        ->whereNotNull('do.deposit_id')
                        ->whereNotExists(
                            fn (Builder $q) => $q
                                ->from('pln_deposits AS d')
                                ->whereColumn('d.deposit_id', 'do.deposit_id')
                        )
                )
                ->orWhere(
                    fn (Builder $q) => $q
                        ->whereIn('do.object_type', [PlnPlugin::DEPOSIT_TYPE_SUBMISSION, 'PublishedArticle'])
                        ->whereNotExists(
                            fn (Builder $q) => $q
                                ->from('submissions AS s')
                                ->whereColumn('s.submission_id', 'do.object_id')
                                ->whereColumn('s.context_id', 'do.journal_id')
                        )
                )
                ->orWhere(
                    fn (Builder $q) => $q
                        ->where('do.object_type', PlnPlugin::DEPOSIT_TYPE_ISSUE)
                        ->whereNotExists(
                            fn (Builder $q) => $q
                                ->from('issues AS i')
                                ->whereColumn('i.issue_id', 'do.object_id')
                                ->whereColumn('i.journal_id', 'do.journal_id')
                        )
                )
        );
    }
}
