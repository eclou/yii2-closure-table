# yii2-closure-table

#### Configure 
configure query model: 
```php
class ClosureQuery extends ActiveQuery
{
    public function behaviors()
    {
        return [
            ClosureTableQueryBehavior::class
        ];
    }
}
``` 
configure active model:
```php
class ClosureTable extends ActiveRecord
{
    public function behaviors()
    {
        return [
            [
                'class' => ClosureTableBehavior::class,
                'closureTable' => ClosureTableTree::class, //closure table defined model
                'parentAttribute' => 'parent',
                'childAttribute' => 'child',
                'depthAttribute' => 'depth'
            ]
        ];
    }

    public static function find()
    {
        return new ClosureQuery(static::class);
    }
}

class ClosureTableTree extends ActiveRecord
{
    public static function tableName()
    {
        return 'closure_table_tree';
    }
    
    public function rules()
    {
        return [
            [['parent','child','depth'],'integer']
        ];
    }
}

```


#### Usage  
```php
ClosureTable::findOne(['_id' => 1])->parents()->all()

$node1 = new ClosureTable();
$node1->saveAsRoot();

$node2 = new ClosureTable();
$node2->appenTo($node1);

```