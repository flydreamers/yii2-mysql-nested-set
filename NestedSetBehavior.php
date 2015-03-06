<?php
/**
 * Created by PhpStorm.
 * User: Nicolas
 * Date: 6/02/15
 * Time: 19:20
 */

namespace flydreamers\db;


use yii\base\Behavior;
use yii\base\Exception;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;
use yii\db\Expression;
use yii\db\Transaction;

/**
 * Class TreeActiveRecord
 * Allows to save and edit active records considering they will represent a tree.
 * See http://falsinsoft.blogspot.com.ar/2013/01/tree-in-sql-database-nested-set-model.html for example
 * @package common\components
 *
 * @property ActiveRecord $owner
 */
class NestedSetBehavior extends Behavior
{
    /**
     * The attribute that will represent the number on the left of the element
     * @var string
     */
    public $leftNoAttribute = 'lft';

    /**
     * The attribute that will represent the number on the right of the element
     * @var string
     */
    public $rightNoAttribute = 'rgt';

    /**
     * The name of the attribute containing the fatherId
     * @var string
     */
    public $fatherIdAttribute;

    /**
     * Insert order. It may have 3 options
     * - alphabetically. will insert the node as parent of $fatherId, in alphabeticall order based upon the alphabeticalOrderAttribute
     * - last. will insert the node as last child of $fatherId
     * - first. will insert the node as first child of $fatherId
     * For each class, this inser order should never be changed to ensure consistency. Add the variable in each subclass to implement
     * @var string
     */
    public $insertOrder = 'alphabetically';

    /**
     * The attribute that should be used to sort the records alphabetically. Should be the same every time the class is called
     * define it in subclasses
     * @var string
     */
    public $alphabeticalOrderAttribute;

    /**
     * Holds the internal transaction to ensure records are only updated if everything is OK
     * @var Transaction
     */
    private $_transaction;

    /**
     * @see Behavior::events()
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'prepareInsert', //Set my and other's lft and rgt
            ActiveRecord::EVENT_AFTER_INSERT => 'saveChanges', //Save the changes
            ActiveRecord::EVENT_AFTER_UPDATE => 'checkMovedTree', //Save the changes
            ActiveRecord::EVENT_BEFORE_DELETE => 'delete', //Delete
            ActiveRecord::EVENT_AFTER_DELETE => 'saveChanges', //Save the changes
        ];
    }

    /**
     * Check if the category has moved. If so, then update all the branches
     * @param AfterSaveEvent $event the event being called
     * @return boolean
     * TODO: Mejorar lo que devuelve
     */
    public function checkMovedTree($event){
        $changedAttributes = $event->changedAttributes;
        if(isset($changedAttributes[$this->fatherIdAttribute])){
            $this->owner->moveBranch($changedAttributes[$this->fatherIdAttribute]);
        }
        return true;
    }

    /**
     * Based on the $fatherId and the insert order, it will leave every active record prepared to be saved
     */
    public function prepareInsert($event)
    {
        //If it is already set, then ignore it, unless it's update, but that's another function
        if(isset($this->owner->{$this->leftNoAttribute}, $this->owner->{$this->rightNoAttribute})){
            return true;
        }
        $this->_transaction = false;
        if (\Yii::$app->db->getTransaction() == null) {
            $this->_transaction = \Yii::$app->db->beginTransaction();
        }

        switch ($this->insertOrder) {
            case "first":
                $this->insertFirst($event);
                break;
            case "last":
                $this->insertLast($event);
                break;
            case "alphabetically":
                $this->insertAlphabetically($event);
                break;
            default:
                throw new Exception('Invalid Insert Order. Should be "alphabetically", "last" or "first"');
        }
        return true;
    }

    /**
     * Holds the right value of the element that will be deleted
     * @var integer
     */
    private $_deletedRight;

    /**
     * Holds the left value of the element that will be deleted
     * @var integer
     */
    private $_deletedLeft;

    /**
     * Holds the width of the element that will be deleted
     * @var integer
     */
    private $_deletedWidth;

    /**
     * deletes the element and updates counters
     * @param ModelEvent $event
     */
    public function delete($event)
    {
        $this->_deletedLeft = $this->owner->{$this->leftNoAttribute};
        $this->_deletedRight = $this->owner->{$this->rightNoAttribute};
        $this->_deletedWidth = $this->_deletedRight - $this->_deletedLeft + 1;

        $table = $this->owner->tableName();

        $this->_transaction = false;
        if (\Yii::$app->db->getTransaction() == null) {
            $this->_transaction = \Yii::$app->db->beginTransaction();
        }

        //Do not delte itself, so that $model->delete() still returns true
        $sql = "DELETE FROM $table where {$this->leftNoAttribute} BETWEEN :left+1 and :right";
        $result = $this->owner->getDb()->createCommand($sql, [':left' => $this->_deletedLeft, ':right' => $this->_deletedRight])->execute();

        if ($result == ($this->_deletedWidth / 2) - 1) {
            $this->owner->updateAll([$this->rightNoAttribute => new Expression($this->rightNoAttribute . '-' . $this->_deletedWidth)],
                new Expression($this->rightNoAttribute . ' > ' . $this->_deletedRight));
            $this->owner->updateAll([$this->leftNoAttribute => new Expression($this->leftNoAttribute . '-' . $this->_deletedWidth)],
                new Expression($this->leftNoAttribute . ' > ' . $this->_deletedRight));

        } else {
            $event->isValid = false;
            if ($this->_transaction instanceof Transaction) {
                $this->_transaction->rollback();
            }
        }
    }

    /**
     * Saves the changes
     */
    public function saveChanges()
    {
        if ($this->_transaction instanceof Transaction) {
            $this->_transaction->commit();
        }
    }

    /**
     * Sets all changes considering the element will be inserted as first child of the father
     * @param ModelEvent $event the event that triggered this call
     */
    public function insertFirst($event)
    {
        $father = $this->owner->findOne($this->owner->{$this->fatherIdAttribute});
        if (!$father instanceof ActiveRecord) {
            $event->isValid = false;
            return;
        }

        $lft = $father->{$this->leftNoAttribute};

        $this->owner->updateAll([$this->rightNoAttribute => new Expression($this->rightNoAttribute . '+2')],
            new Expression($this->rightNoAttribute . ' > ' . $lft));
        $this->owner->updateAll([$this->leftNoAttribute => new Expression($this->leftNoAttribute . '+2')],
            new Expression($this->leftNoAttribute . ' > ' . $lft));

        $this->owner->{$this->leftNoAttribute} = $lft + 1;
        $this->owner->{$this->rightNoAttribute} = $lft + 2;
    }

    /**
     * Sets all changes considering the element will be inserted as last child of the father
     * @param ModelEvent $event the event that triggered this call
     */
    public function insertLast($event)
    {
        $father = $this->owner->findOne($this->owner->{$this->fatherIdAttribute});
        if (!$father instanceof ActiveRecord) {
            $event->isValid = false;
            return;
        }

        $right = $father->{$this->rightNoAttribute};

        $this->owner->updateAll([$this->rightNoAttribute => new Expression($this->rightNoAttribute . '+2')],
            new Expression($this->rightNoAttribute . ' >= ' . $right));
        $this->owner->updateAll([$this->leftNoAttribute => new Expression($this->leftNoAttribute . '+2')],
            new Expression($this->leftNoAttribute . ' >= ' . $right));

        $this->owner->{$this->leftNoAttribute} = $right;
        $this->owner->{$this->rightNoAttribute} = $right + 1;
    }

    /**
     * Sets all changes considering the element will be inserted alphabetically
     * @param ModelEvent $event the event that triggered this call
     */
    public function insertAlphabetically($event)
    {
        $father = $this->owner->findOne($this->owner->{$this->fatherIdAttribute});
        if (!$father instanceof ActiveRecord) {
            $event->isValid = false;
            return;
        }

        $children = $father->findDirectChildren(false);

        if (count($children) == 0) {
            return $this->insertFirst($event);
        }

        $i = 0;
        for (; $i < count($children); $i++) {
            $child = $children[$i];
            if (strcmp($this->owner->{$this->alphabeticalOrderAttribute}, $child->{$this->alphabeticalOrderAttribute}) <= 0) {
                //Insert in position stated by the element
                $i = $i - 1;
                break;
            }
        }

        if ($i <= 0) {
            return $this->insertFirst($event);
        }

        if ($i >= count($children) - 1) {
            return $this->insertLast($event);
        }

        $after = $children[$i];

        $right = $after->{$this->rightNoAttribute};

        $this->owner->updateAll([$this->rightNoAttribute => new Expression($this->rightNoAttribute . '+2')],
            new Expression($this->rightNoAttribute . ' > ' . $right));
        $this->owner->updateAll([$this->leftNoAttribute => new Expression($this->leftNoAttribute . '+2')],
            new Expression($this->leftNoAttribute . ' > ' . $right));

        $this->owner->{$this->leftNoAttribute} = $right + 1;
        $this->owner->{$this->rightNoAttribute} = $right + 2;
    }

    /**
     * Based on the instance $parentId, get all its immediate subordinates
     * @param boolean $includeMyself whether the current instance should be included in the results or not
     * @return TreeActiveRecord[]
     */
    public function findDirectChildren($includeMyself = true)
    {
        $table = $this->owner->tableName();
        $pks = $this->owner->getTableSchema()->primaryKey;

        if (count($pks) != 1) {
            throw new Exception('Only single key attributes can work with the tree behavior in this instance');
        }

        $pkAttribute = $pks[0];

        $sql = "SELECT node.{$this->alphabeticalOrderAttribute}, node.$pkAttribute, (COUNT(parent.{$this->alphabeticalOrderAttribute}) - (sub_tree.depth + 1)) AS depth,
                node.{$this->leftNoAttribute}, node.{$this->rightNoAttribute}
            FROM $table AS node,
                 $table AS parent,
                 $table AS sub_parent,
                (
                SELECT node.$pkAttribute, (COUNT(parent.{$this->alphabeticalOrderAttribute}) - 1) AS depth
                    FROM $table AS node,
                    $table AS parent
                    WHERE node.{$this->leftNoAttribute} BETWEEN parent.{$this->leftNoAttribute} AND parent.{$this->rightNoAttribute}
                    AND node.$pkAttribute = {$this->owner->getPrimaryKey()}
                    GROUP BY node.{$this->alphabeticalOrderAttribute}
                    ORDER BY node.{$this->leftNoAttribute}
                )AS sub_tree
            WHERE node.lft BETWEEN parent.{$this->leftNoAttribute} AND parent.{$this->rightNoAttribute}
                    AND node.{$this->leftNoAttribute} BETWEEN sub_parent.{$this->leftNoAttribute} AND sub_parent.{$this->rightNoAttribute}
                    AND sub_parent.$pkAttribute = sub_tree.$pkAttribute
            GROUP BY node.$pkAttribute
            HAVING depth " . ($includeMyself ? '<=' : '=') . " 1
            ORDER BY node.{$this->leftNoAttribute};";

        return $this->owner->findBySql($sql)->all();
    }

    /**
     * Moves the current branch to another
     * @param integer $oldFather the id of the father previously set
     * @return true or the amount of nodes moved
     */
    public function moveBranch($oldFather)
    {
        if ($oldFather == $this->owner->{$this->fatherIdAttribute}) {
            return true;
        }

        $newParent = $this->owner->findOne($this->owner->{$this->fatherIdAttribute});

        $table = $this->owner->tableName();
        $currentLeft = $this->owner->{$this->leftNoAttribute};
        $currentRight = $this->owner->{$this->rightNoAttribute};
        $newRight = $newParent->{$this->rightNoAttribute};

        $sql = '';

        if ($newParent->{$this->rightNoAttribute} < $this->owner->{$this->leftNoAttribute}) {
            $sql = "UPDATE $table SET
            {$this->leftNoAttribute} = {$this->leftNoAttribute} +
              CASE
                  WHEN {$this->leftNoAttribute} BETWEEN $currentLeft AND $currentRight THEN $newRight - $currentLeft
                  WHEN {$this->leftNoAttribute} BETWEEN $newRight AND $currentLeft - 1 THEN $currentRight - $currentLeft + 1
                  ELSE 0
              END,
            {$this->rightNoAttribute} = {$this->rightNoAttribute} +
              CASE
                  WHEN {$this->rightNoAttribute} BETWEEN $currentLeft AND $currentRight THEN $newRight - $currentLeft
                  WHEN {$this->rightNoAttribute} BETWEEN $newRight AND $currentLeft - 1 THEN $currentRight - $currentLeft + 1
                  ELSE 0
              END
            WHERE {$this->leftNoAttribute} BETWEEN $newRight AND $currentRight
              OR {$this->rightNoAttribute} BETWEEN $newRight AND $currentRight";
        }else{
            $sql = "UPDATE $table SET
            {$this->leftNoAttribute} = {$this->leftNoAttribute} +
              CASE
                  WHEN {$this->leftNoAttribute} BETWEEN $currentLeft AND $currentRight THEN $newRight - $currentRight - 1
                  WHEN {$this->leftNoAttribute} BETWEEN $currentRight + 1 AND $newRight - 1 THEN $currentLeft - $currentRight - 1
                  ELSE 0
              END,
            {$this->rightNoAttribute} = {$this->rightNoAttribute} +
              CASE
                WHEN {$this->rightNoAttribute} BETWEEN $currentLeft AND $currentRight THEN $newRight - $currentRight - 1
                WHEN {$this->rightNoAttribute} BETWEEN $currentRight + 1 AND $newRight - 1 THEN $currentLeft - $currentRight - 1
                ELSE 0
              END
            WHERE {$this->leftNoAttribute} BETWEEN $currentLeft AND $newRight
              OR {$this->rightNoAttribute} BETWEEN $currentLeft AND $newRight;";
        }

        return \Yii::$app->db->createCommand($sql)->execute();
    }
} 