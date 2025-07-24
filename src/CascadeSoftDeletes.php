<?php

namespace Dyrynda\Database\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

// Dummy class to hold the soft delete timestamp
// This is used to ensure that the timestamp is shared across all models
// that are using the CascadeSoftDeletes trait.
class CascadeSoftDeleteTimestamp {
    static public $callerModel = null;
    static public $softDeleteTimestamp = null;
}

trait CascadeSoftDeletes
{
    use SoftDeletes;
    /**
     * Boot the trait.
     *
     * Listen for the deleting event of a soft deleting model, and run
     * the delete operation for any configured relationship methods.
     *
     * @throws \LogicException
     */
    protected static function bootCascadeSoftDeletes()
    {
        static::deleting(function ($model) {
            $model->validateCascadingSoftDelete();
            if (CascadeSoftDeleteTimestamp::$softDeleteTimestamp == null) {
                CascadeSoftDeleteTimestamp::$softDeleteTimestamp = Date::now();
                CascadeSoftDeleteTimestamp::$callerModel = $model;
                $model->runCascadingDeletes();
            }
            else {
                $model->runCascadingDeletes();
            }
        });

        static::deleted(function ($model) {
            if (!CascadeSoftDeleteTimestamp::$callerModel->is($model)) return;
            CascadeSoftDeleteTimestamp::$softDeleteTimestamp = null;
            CascadeSoftDeleteTimestamp::$callerModel = null;
        });
    }


    /**
     * Validate that the calling model is correctly setup for cascading soft deletes.
     *
     * @throws \Dyrynda\Database\Support\CascadeSoftDeleteException
     */
    protected function validateCascadingSoftDelete()
    {
        if (! $this->implementsSoftDeletes()) {
            throw CascadeSoftDeleteException::softDeleteNotImplemented(get_called_class());
        }

        if ($invalidCascadingRelationships = $this->hasInvalidCascadingRelationships()) {
            throw CascadeSoftDeleteException::invalidRelationships($invalidCascadingRelationships);
        }
    }


    /**
     * Run the cascading soft delete for this model.
     *
     * @return void
     */
    protected function runCascadingDeletes()
    {
        foreach ($this->getActiveCascadingDeletes() as $relationship) {
            $this->cascadeSoftDeletes($relationship);
        }
    }


    /**
     * Cascade delete the given relationship on the given mode.
     *
     * @param  string  $relationship
     * @return return
     */
    protected function cascadeSoftDeletes($relationship)
    {
        $delete = $this->forceDeleting ? 'forceDelete' : 'delete';
        
        $cb = function($model) use ($delete) {
            isset($model->pivot) ? $model->pivot->{$delete}() : $model->{$delete}();
        };

        $this->handleRecords($relationship, $cb);
    }

    private function handleRecords($relationship, $cb)
    {
        $fetchMethod = $this->fetchMethod ?? 'get';
        $model = $this->{$relationship}()->first();
        $primary = $this->getRelationPrimaryKey($model);
        $hasRelationships = $this->hasDescendantRelationships($model);
        $delete = $this->forceDeleting ? 'forceDelete' : 'delete';

        if ($fetchMethod == 'chunk') {
            while ($models = $this->{$relationship}()->select($primary)->limit($this->chunkSize ?? 500)->get()) {
                if ($models->isEmpty()) {
                    break;
                }
                if (!$hasRelationships) {
                    $this->{$relationship}()->whereIn($primary, $models->pluck($primary))->{$delete}();
                    continue;
                }
                foreach($models as $model) {
                    $cb($model);
                }
            }
        } else {
            if (!$hasRelationships) {
                $this->{$relationship}()->select($primary)->{$delete}();
                return;
            }
            foreach($this->{$relationship}()->select($primary)->$fetchMethod() as $model) {
                $cb($model);
            }
        }
    }


    /**
     * Determine if the current model implements soft deletes.
     *
     * @return bool
     */
    protected function implementsSoftDeletes()
    {
        return method_exists($this, 'runSoftDelete');
    }


    /**
     * Determine if the current model has any invalid cascading relationships defined.
     *
     * A relationship is considered invalid when the method does not exist, or the relationship
     * method does not return an instance of Illuminate\Database\Eloquent\Relations\Relation.
     *
     * @return array
     */
    protected function hasInvalidCascadingRelationships()
    {
        return array_filter($this->getCascadingDeletes(), function ($relationship) {
            return ! method_exists($this, $relationship) || ! $this->{$relationship}() instanceof Relation;
        });
    }


    /**
     * Fetch the defined cascading soft deletes for this model.
     *
     * @return array
     */
    protected function getCascadingDeletes()
    {
        return isset($this->cascadeDeletes) ? (array) $this->cascadeDeletes : [];
    }


    /**
     * For the cascading deletes defined on the model, return only those that are not null.
     *
     * @return array
     */
    public function getActiveCascadingDeletes()
    {
        return array_filter($this->getCascadingDeletes(), function ($relationship) {
            return $this->{$relationship}()->exists();
        });
    }

    /**
     * Get the primary key for the relationship model.
     *
     * @param  Model  $model
     * @return string
     */
    private function getRelationPrimaryKey($model) {
        return $model->getKeyName();
    }

    /**
     * Check if the model has any descendant relationships that also use cascading soft deletes.
     *
     * @param  Model  $model
     * @return bool
     */
    private function hasDescendantRelationships($model)
    {
        if (!method_exists($model, 'getActiveCascadingDeletes')) {
            return false;
        }
        return count($model->getActiveCascadingDeletes()) > 0;
    }

    // Hacky way to sync the soft delete timestamp across models
    // This is used to ensure that the soft delete timestamp is shared across all models and relationships
    // that are using the CascadeSoftDeletes trait.
    public function freshTimestamp() {
        if (isset($this->syncTimestamp) && $this->syncTimestamp) {
            return CascadeSoftDeleteTimestamp::$softDeleteTimestamp ?? Date::now();
        }
        return Date::now();
    }
}
