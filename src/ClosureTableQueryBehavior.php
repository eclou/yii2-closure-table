<?php

namespace eclou\ClosureTable;

use yii\base\Behavior;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * @property \yii\db\ActiveQuery $owner
 */
class ClosureTableQueryBehavior extends Behavior
{
    /**
     * @return \yii\db\ActiveQuery
     */
    public function roots()
    {
        $model = new $this->owner->modelClass();
        $primaryKey = $model->primaryKey()[0];
        $closureTableName = $model->closureTable->tableName();
        $this->owner
            ->leftJoin(['ct1' => $closureTableName], "{$primaryKey} = ct1.{$model->childAttribute}")
            ->leftJoin(['ct2' => $closureTableName], "ct1.{$model->childAttribute} = ct2.{$model->childAttribute} and ct2.{$model->parentAttribute} != ct1.{$model->childAttribute}")
            ->andWhere(["ct2.{$model->parentAttribute}" => null])
            ->andWhere(new Expression("ct1.{$model->parentAttribute} = ct1.{$model->childAttribute}"))
            ->andWhere(["ct1.{$model->depthAttribute}" => 0]);

        return $this->owner;
    }

    /**
     * @param $nodeId
     * @param $alias
     * @param $sortReverse
     * @return \yii\db\ActiveQuery
     */
    public function pathOf($nodeId, $alias = 'ct', $sortReverse = false)
    {
        $model = new $this->owner->modelClass();
        $primaryKeyName = $model->primaryKey()[0];
        $closureTableName = $model->closureTable->tableName();

        $this->owner
            ->innerJoin([$alias => $closureTableName], "{$alias}.{$model->parentAttribute} = {$primaryKeyName}")
            ->andWhere(["{$alias}.{$model->childAttribute}" => $nodeId])
            ->orderBy(["{$alias}.{$model->depthAttribute}" => $sortReverse ? SORT_ASC : SORT_DESC]);

        return $this->owner;
    }

    /**
     * @param $nodeId
     * @return ActiveQuery
     */
    public function fullPathOf($nodeId)
    {
        $model = new $this->owner->modelClass();
        $primaryKeyName = $model->primaryKey()[0];
        $closureTableName = $model->closureTable->tableName();

        $this->owner->innerJoin(['ct1' => $closureTableName])
            ->innerJoin(['ct2' => $closureTableName],"ct1.{$model->parentAttribute} = ct2.{$model->parentAttribute} 
            AND {$primaryKeyName} = ct2.{$model->childAttribute} AND ct2.{$model->depthAttribute} = 1")
            ->andWhere(["ct1.{$model->childAttribute}" => $nodeId])
        ;
        return  $this->owner;
    }

    /**
     * @param $nodeId
     * @param $depth
     * @param $between
     * @return \yii\db\ActiveQuery
     */
    public function parents($nodeId, $depth = null,$between = false)
    {
        $model = new $this->owner->modelClass();
        $query = $this->pathOf($nodeId,'ct1');
        if ($depth === null) {
            $query->andWhere("ct1.{$model->childAttribute} != ct1.{$model->parentAttribute}");
        }else{
            if ($between){
                $query->andWhere(['between',"ct1.{$model->depthAttribute}",1,intval($depth)]);
            }else{
                $query->andWhere(["ct1.{$model->depthAttribute}" => intval($depth)]);
            }
        }

        return $query;
    }

    /**
     * @param $nodeId
     * @param $depth
     * @param $between
     * @return \yii\db\ActiveQuery
     */
    public function children($nodeId,$depth = null,$between = false)
    {
        $model = new $this->owner->modelClass();
        $closureTableName = $model->closureTable->tableName();
        $primaryKeyName = $model->primaryKey()[0];
        $this->owner->innerJoin(['ct1' => $closureTableName],"ct1.{$model->childAttribute} = {$primaryKeyName}")
            ->andWhere(["ct1.{$model->parentAttribute}" => $nodeId])
            ;

        if ($depth === null){
            $this->owner->andWhere("ct1.{$model->childAttribute} != ct1.{$model->parentAttribute}");
        }else{
            if ($between){
                $this->owner->andWhere(['between',"ct1.{$model->depthAttribute}",1,intval($depth)]);
            }else{
                $this->owner->andWhere(["ct1.{$model->depthAttribute}" => intval($depth)]);
            }
        }

        return $this->owner;
    }
}