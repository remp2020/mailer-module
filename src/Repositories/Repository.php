<?php
declare(strict_types=1);

namespace Remp\MailerModule\Repositories;

use Closure;
use Nette\Caching\Storage;
use Nette\Utils\DateTime;
use Nette\Database\Context;
use Throwable;

class Repository
{
    protected $database;

    protected $auditLogRepository;

    protected $tableName = 'undefined';

    protected $cacheStorage;

    public function __construct(
        Context $database,
        Storage $cacheStorage = null
    ) {
        $this->database = $database;
        $this->cacheStorage = $cacheStorage;
    }

    public function getTable(): Selection
    {
        return new Selection($this->database, $this->database->getConventions(), $this->tableName, $this->cacheStorage);
    }

    public function find($id): ?ActiveRow
    {
        return $this->getTable()->where(['id' => $id])->fetch();
    }

    public function findBy(string $column, string $value): ?ActiveRow
    {
        return $this->getTable()->where([$column => $value])->fetch();
    }

    public function totalCount(): int
    {
        return $this->getTable()->count('*');
    }

    public function getDatabase(): Context
    {
        return $this->database;
    }

    public function ensure(Closure $callback, int $retryTimes = 1)
    {
        try {
            return $callback($this);
        } catch (Throwable $e) {
            if ($retryTimes === 0) {
                throw $e;
            }
            $this->database->getConnection()->reconnect();
            return $this->ensure($callback, $retryTimes - 1);
        }
    }

    /**
     * Update updates provided record with given $data array and mutates the provided instance. Operation is logged
     * to audit log.
     *
     * @param ActiveRow $row
     * @param array $data values to update
     * @return bool
     *
     * @throws \Exception
     */
    public function update(ActiveRow &$row, array $data): bool
    {
        $oldValues = [];
        if ($row instanceof ActiveRow) {
            $oldValues = $row->toArray();
        }

        $res = $this->getTable()->wherePrimary($row->getPrimary())->update($data);
        if (!$res) {
            return false;
        }

        if ($this->auditLogRepository) {
            // filter internal columns
            $data = $this->filterValues($data);

            // filter unchanged columns
            if (!empty($oldValues)) {
                $oldValues = $this->filterValues($oldValues);

                $oldValues = array_intersect_key($oldValues, $data);
                $data = array_diff_assoc($data, $oldValues); // get changed values
                $oldValues = array_intersect_key($oldValues, $data); // get rid of unchanged $oldValues
            }

            $data = [
                'version' => '1',
                'from' => $oldValues,
                'to' => $data,
            ];
            $this->auditLogRepository->add(AuditLogRepository::OPERATION_UPDATE, $this->tableName, $row->getSignature(), $data);
        }

        $row = $this->getTable()->wherePrimary($row->getPrimary())->fetch();
        return true;
    }

    /**
     * Delete deletes provided record from repository and mutates the provided instance. Operation is logged to audit log.
     *
     * @param ActiveRow $row
     * @return bool
     */
    public function delete(ActiveRow &$row): bool
    {
        $oldValues = [];
        if ($row instanceof ActiveRow) {
            $oldValues = $row->toArray();
        }
        $res = $this->getTable()->wherePrimary($row->getPrimary())->delete();

        if (!$res) {
            return false;
        }

        if ($this->auditLogRepository) {
            $data = [
                'version' => '1',
                'from' => $this->filterValues($oldValues),
                'to' => [],
            ];
            $this->auditLogRepository->add(AuditLogRepository::OPERATION_DELETE, $this->tableName, $row->getSignature(), $data);
        }

        return true;
    }

    /**
     * Insert inserts data to the repository. If single ActiveRow is returned, it attempts to log audit information.
     *
     * @param $data
     * @return bool|int|ActiveRow
     */
    public function insert(array $data)
    {
        $row = $this->getTable()->insert($data);
        if (!$row instanceof ActiveRow) {
            return $row;
        }

        if ($this->auditLogRepository) {
            $data = [
                'version' => '1',
                'from' => [],
                'to' => $this->filterValues($data),
            ];
            $this->auditLogRepository->add(AuditLogRepository::OPERATION_CREATE, $this->tableName, $row->getSignature(), $data);
        }

        return $row;
    }

    private function filterValues(array $values): array
    {
        foreach ($values as $i => $field) {
            if (is_bool($field)) {
                $values[$i] = (int) $field;
            } elseif ($field instanceof DateTime) {
                $values[$i] = $field->format('Y-m-d H:i:s');
            } elseif (!is_scalar($field)) {
                unset($values[$i]);
            }
        }
        return $values;
    }
}
