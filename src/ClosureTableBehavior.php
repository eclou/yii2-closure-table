<?php

namespace eclou\ClosureTable;

use yii\base\Behavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;
use yii\di\Instance;

/**
 * @property ActiveRecord $owner
 */
class ClosureTableBehavior extends Behavior
{
    /**
     * @var string
     */
    public $parentAttribute = 'parent';
    /**
     * @var string
     */
    public $childAttribute = 'child';
    /**
     * @var string
     */
    public $depthAttribute = 'depth';

    /**
     * @var ActiveRecord | null
     */
    public $closureTable;

    public function attach($owner)
    {
        $this->owner = $owner;
        $this->closureTable = Instance::ensure($this->closureTable);
        parent::attach($owner);
    }

    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete'
        ];
    }

    public function saveAsRoot($runValidation = true, $attributes = null)
    {
        $trans = $this->owner->getDb()->beginTransaction();
        try {
            if (!$this->owner->save($runValidation, $attributes)) {
                $trans->rollBack();
                return false;
            }
            $this->markAsRoot();
            $trans->commit();
        } catch (\Exception $e) {
            $trans->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * @return false
     */
    public function isRoot()
    {
        $keyValue = $this->owner->primaryKey;
        if (empty($keyValue)) {
            return false;
        }

        return $this->closureTable::find()
                ->where([$this->parentAttribute => $keyValue, $this->childAttribute => $keyValue, $this->depthAttribute => 0])
                ->exists() && !$this->parents(1)->exists();
    }


    /**
     * @return ActiveQuery
     */
    public function root()
    {
        $primaryKeyName = $this->owner->primaryKey()[0];
        $closureTableName = $this->closureTable->tableName();

        $query = $this->owner::find()
            ->leftJoin(['ct1' => $closureTableName], "ct1.{$this->parentAttribute} = {$primaryKeyName} and ct1.{$this->childAttribute} != ct1.{$this->parentAttribute}")
            ->leftJoin(['ct2' => $closureTableName], "ct1.{$this->parentAttribute} = ct2.{$this->childAttribute} and ct2.{$this->childAttribute} != ct2.{$this->parentAttribute}")
            ->andWhere(["ct1.{$this->childAttribute}" => $this->owner->primaryKey, "ct2.{$this->parentAttribute}" => null]);
        return $query;
    }

    public function markAsRoot($runValidation = true)
    {
        if ($this->isRoot()) {
            return true;
        }
        $primaryKey = $this->owner->primaryKey;
        $this->closureTable->setAttributes([
            $this->parentAttribute => $primaryKey,
            $this->childAttribute  => $primaryKey,
            $this->depthAttribute  => 0
        ]);
        return $this->closureTable->save($runValidation);
    }

    /**
     * @param $node ActiveRecord
     * @param $runValidation
     * @param $attributes
     * @return bool
     * @throws \yii\db\Exception
     */
    public function appendTo($node, $runValidation = true, $attributes = null)
    {
        $trans = $this->owner::getDb()->beginTransaction();
        try {
            if (empty($this->owner->primaryKey)) {
                $this->owner->save($runValidation, $attributes);
            }

            if ($this->isRoot()) {
                $sql = /** @lang text */
                    <<<SQL
                     INSERT INTO {$this->closureTable::tableName()} ({$this->parentAttribute},{$this->childAttribute},{$this->depthAttribute})
                     SELECT {$this->parentAttribute},:newNodeId,{$this->depthAttribute}+1 
                     FROM {$this->closureTable::tableName()}
                     WHERE {$this->childAttribute} = :parentNodeId and {$this->parentAttribute} != {$this->childAttribute}
SQL;
                $this->owner::getDb()->createCommand($sql)->bindValues([':newNodeId' => $this->owner->primaryKey, ':parentNodeId' => $node->primaryKey])->execute();

                $sql = /** @lang text */
                    <<<SQL
                     INSERT INTO {$this->closureTable::tableName()} ({$this->parentAttribute},{$this->childAttribute},{$this->depthAttribute})
                     SELECT :parentNodeId,{$this->childAttribute},{$this->depthAttribute}+1 
                     FROM {$this->closureTable::tableName()}
                     WHERE {$this->parentAttribute} = :newNodeId
SQL;
                $this->owner::getDb()->createCommand($sql)->bindValues([':newNodeId' => $this->owner->primaryKey, ':parentNodeId' => $node->primaryKey])->execute();

            } else {
                $sql = /** @lang text */
                    <<<SQL
                    INSERT INTO {$this->closureTable::tableName()} ({$this->parentAttribute},{$this->childAttribute},{$this->depthAttribute})
                    SELECT {$this->parentAttribute},:newNodeId,{$this->depthAttribute}+1 
                    FROM {$this->closureTable::tableName()}
                    WHERE {$this->childAttribute} = :parentNodeId
                    UNION ALL
                    SELECT :newNodeId,:newNodeId,0
SQL;
                $this->owner::getDb()->createCommand($sql)->bindValues([':newNodeId' => $this->owner->primaryKey, ':parentNodeId' => $node->primaryKey])->execute();

            }

            $trans->commit();
        } catch (\Exception $e) {
            $trans->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * @param $node ActiveRecord
     * @return void
     * @throws \yii\db\Exception
     */
    public function moveTo($node)
    {
        $trans = $this->owner::getDb()->beginTransaction();
        try {
            $sql =
                /** @lang text */
                <<<SQL
                 DELETE ct FROM {$this->closureTable::tableName()}  ct
                 JOIN {$this->closureTable::tableName()} AS ct2 ON ct.{$this->childAttribute} = ct2.{$this->childAttribute}
                 LEFT JOIN {$this->closureTable::tableName()} AS ct3 ON ct3.{$this->parentAttribute} = ct2.{$this->parentAttribute} AND ct3.{$this->childAttribute} = ct.{$this->parentAttribute}
                 WHERE ct2.{$this->parentAttribute} = :nodeId AND ct3.{$this->parentAttribute} IS NULL
SQL;
            $this->owner::getDb()->createCommand($sql)->bindValues([':nodeId' => $this->owner->primaryKey])->execute();

            $sql = /** @lang text */
                <<<SQL
                INSERT INTO {$this->closureTable::tableName()} ({$this->parentAttribute},{$this->childAttribute},{$this->depthAttribute})
                SELECT ct1.{$this->parentAttribute},ct2.{$this->childAttribute},ct1.{$this->depthAttribute}+ct2.{$this->depthAttribute}+1
                FROM {$this->closureTable::tableName()} AS ct1 
                JOIN {$this->closureTable::tableName()} AS ct2 ON ct2.{$this->parentAttribute} = :nodeId 
                WHERE ct1.{$this->childAttribute} = :targetNodeId
SQL;
            $this->owner::getDb()->createCommand($sql)->bindValues([':nodeId' => $this->owner->primaryKey, ':targetNodeId' => $node->primaryKey])->execute();
            $trans->commit();
        } catch (\Exception $e) {
            $trans->rollBack();
        }
    }

    public function moveAsRoot()
    {
        $sql =
            /** @lang text */
            <<<SQL
                 DELETE ct FROM {$this->closureTable::tableName()}  ct
                 JOIN {$this->closureTable::tableName()} AS ct2 ON ct.{$this->childAttribute} = ct2.{$this->childAttribute}
                 WHERE ct2.{$this->parentAttribute} = :nodeId AND ct.{$this->parentAttribute} != :nodeId AND ct.parent != ct.child
SQL;

        return $this->owner::getDb()->createCommand($sql)->bindValues([':nodeId' => $this->owner->primaryKey])->execute();
    }


    /**
     * @return int
     * @throws \yii\db\Exception
     */
    public function deleteNodeTree()
    {
        $sql = /** @lang text */
            <<<SQL
                 DELETE ct FROM {$this->closureTable::tableName()}  ct
                 JOIN {$this->closureTable::tableName()} AS ct2 ON ct.{$this->childAttribute} = ct2.{$this->childAttribute} 
                 WHERE ct2.{$this->parentAttribute} = :nodeId
SQL;
        return $this->owner::getDb()->createCommand($sql)->bindValues([':nodeId' => $this->owner->primaryKey])->execute();
    }

    public function afterDelete()
    {
        $this->deleteNodeTree();
    }

    /**
     * @param $depth
     * @return ActiveQuery
     */
    public function parents($depth = null)
    {
        return $this->owner::find()->parents($this->owner->primaryKey, $depth);
    }

    /**
     * @param $depth
     * @return ActiveQuery
     */
    public function children($depth = 1)
    {
        return $this->owner::find()->children($this->owner->primaryKey, $depth);
    }

    public function isTreeNode()
    {
        return $this->closureTable::find()
            ->where([
                "{$this->parentAttribute}" => $this->owner->primaryKey,
                "{$this->childAttribute}"  => $this->owner->primaryKey,
                'depth'                    => 0
            ])->exists();
    }

    /**
     * @param $node ActiveRecord
     * @return bool
     */
    public function isChildOf($node)
    {
        return $this->closureTable::find()
            ->where([
                "{$this->parentAttribute}" => $node->primaryKey,
                "{$this->childAttribute}"  => $this->owner->primaryKey
            ])->exists();
    }
}