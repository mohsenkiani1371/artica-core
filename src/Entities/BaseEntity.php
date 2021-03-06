<?php
declare(strict_types=1);


namespace Artica\Entities;

use Artica\Entities\Queries\EntityQuery;
use Artica\Exceptions\Entity\EntityNotFoundException;
use Exception;
use RuntimeException;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\StaleObjectException;

/**
 * Class Entity
 * This class contain database logic.
 *
 * @property mixed $id
 *
 * @author  Amin Keshavarz <ak_1596@yahoo.com>
 * @package Artica\Entities
 */
abstract class BaseEntity extends ActiveRecord implements EntityInterface
{
    protected $softDeleteFieldName = 'is_deleted';

    protected static $queryClass = null;

    /** @var array Local cache of entities. */
    protected static $cache = [];

    /**
     * Load entities by ids and cache them in local cache.
     *
     * @param array $ids entity ids.
     * @param bool $throwException
     *
     * @return $this[]
     * @throws EntityNotFoundException
     */
    protected static function loadByIds(array $ids, $throwException = false): array
    {
        $missingIds = [];
        $results = [];

        foreach ($ids as $id) {
            if ($entity = self::getFromCache($id)) {
                $results[$id] = $entity;
            } else {
                $missingIds[] = $id;
            }
        }

        if (!empty($missingIds)) {
            $entities = static::findAll(['id' => $missingIds]);
            foreach ($entities as $entity) {
                self::addToCache($entity);
                $results[$entity->getId()] = $entity;
            }
        }

        $availableIds = array_keys($results);
        $notFoundIds = array_diff($ids, $availableIds);
        if ($throwException and !empty($notFoundIds)) {
            throw new EntityNotFoundException(get_called_class(), $notFoundIds);
        }

        return $results;
    }

    /**
     * Get entities by ids.
     *
     * @param array $ids entity ids.n
     *
     * @return $this[]
     * @throws EntityNotFoundException
     */
    public static function getByIds(array $ids): array
    {
        return self::loadByIds($ids, true);
    }

    /**
     * Find an entity by id.
     * @param int $id
     * @return $this
     * @throws EntityNotFoundException
     */
    public static function getById(int $id)
    {
        $results = self::getByIds([$id]);
        return array_shift($results);
    }

    /**
     * Return cache key for entity.
     * @param int $id
     * @return string
     */
    private static function getCacheKey(int $id): string
    {
        return get_called_class().':'.$id;
    }

    /**
     * Retrieve entity from local cache or return null.
     * @param int $id
     * @return Entity|null
     */
    private static function getFromCache(int $id)
    {
        $key = self::getCacheKey($id);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        return null;
    }

    /**
     * Add entity to local cache.
     *
     * @param Entity $entity
     */
    private static function addToCache(Entity $entity): void
    {
        $key = self::getCacheKey($entity->getId());
        self::$cache[$key] = $entity;
    }

    /**
     * Deletes the table row corresponding to this active record.
     *
     * This method performs the following steps in order:
     *
     * 1. call [[beforeDelete()]]. If the method returns `false`, it will skip the
     *    rest of the steps;
     * 2. delete the record from the database;
     * 3. call [[afterDelete()]].
     *
     * In the above step 1 and 3, events named [[EVENT_BEFORE_DELETE]] and [[EVENT_AFTER_DELETE]]
     * will be raised by the corresponding methods.
     *
     * @param bool $force Force to delete without using soft delete option.
     *
     * @return int|false the number of rows deleted, or `false` if the deletion is unsuccessful for some reason.
     * Note that it is possible the number of rows deleted is 0, even though the deletion execution is successful.
     * @throws StaleObjectException if [[optimisticLock|optimistic locking]] is enabled and the data
     * being deleted is outdated.
     * @throws Throwable in case delete failed.
     */
    public function delete(bool $force = false)
    {
        if ($force or !$this->isSoftDeleteActive()) {
            return parent::delete();
        }

        if (!$this->isTransactional(self::OP_DELETE)) {
            return $this->softDeleteInternal();
        }

        $transaction = static::getDb()->beginTransaction();
        try {
            $result = $this->softDeleteInternal();
            if ($result === false) {
                $transaction->rollBack();
            } else {
                $transaction->commit();
            }

            return $result;
        } catch (Exception $e) {
            $transaction->rollBack();
            throw $e;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Soft Deletes an ActiveRecord without considering transaction.
     *
     * @return int the number of rows deleted
     * @throws StaleObjectException
     * @throws \yii\db\Exception
     */
    protected function softDeleteInternal(): int
    {
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $condition[$lock] = $this->$lock;
        }

        $result = static::updateAll([$this->softDeleteFieldName => true], $condition);

        if ($lock !== null && !$result) {
            throw new StaleObjectException('The object being soft deleted is outdated.');
        }

        $this->setOldAttributes(null);
        $this->afterDelete();

        return $result;
    }

    /**
     * Check if soft delete functionlaity is ok or not.
     *
     * @return bool
     *
     * @author Amin Keshavarz <ak_1596@yahoo.com>
     */
    public function isSoftDeleteActive(): bool
    {
        return ($this->softDeleteFieldName and $this->hasAttribute($this->softDeleteFieldName));
    }

    /**
     * Is record soft deleted or not.
     *
     * @return bool true if soft deleted and false if not.
     *
     * @author Amin Keshavarz <ak_1596@yahoo.com>
     */
    public function isSoftDeleted(): bool
    {
        return $this->isSoftDeleteActive() ? $this->{$this->softDeleteFieldName} : false;
    }

    /**
     * Return id of
     *
     * @return null|int
     *
     * @author Amin Keshavarz <ak_1596@yahoo.com>
     */
    public function getId()
    {
        if ($this->hasAttribute('id')) {
            return $this->id;
        }

        throw new RuntimeException('Not implemented');
    }

    /**
     * {@inheritdoc}
     * @return EntityQuery|object
     * @throws InvalidConfigException
     */
    public static function find(): EntityQuery
    {
        $queryClass = static::$queryClass ? static::$queryClass : EntityQuery::class;

        return Yii::createObject($queryClass, [get_called_class()]);
    }
}
