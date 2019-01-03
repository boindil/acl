<?php
/**
 * CakePHP :  Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Acl\Model\Behavior;

use Acl\Model\Behavior\AclBehavior;
use Cake\Core\App;
use Cake\Core\Exception;
use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

/**
 * ACL behavior
 *
 * Enables objects to easily tie into an ACL system
 *
 * @link http://book.cakephp.org/2.0/en/core-libraries/behaviors/acl.html
 */
class AbmAclBehavior extends AclBehavior
{

    /**
     * Creates a new ARO/ACO node bound to this record
     *
     * @param Event $event The afterSave event that was fired
     * @param Entity $entity The entity being saved
     * @return void
     */
    public function afterSave(Event $event, Entity $entity)
    {
        $model = $event->subject();
        $types = $this->_typeMaps[$this->config('type')];
        if (!is_array($types)) {
            $types = [$types];
        }
        foreach ($types as $type) {
            $parent = $entity->parentNode();
            if (!empty($parent)) {
				$current = current($parent);
				
				if(!isset($current['id'])) {
					$parenttmp = [];
					$key = key($parent);

					foreach($current as $role) {
						$node = [
							$type => $this->node(
								[$key => $role],
								$type
							)->first()->toArray()
						];
						
						$parenttmp[] = $node;
					}
					$parent = array_map("unserialize", array_unique(array_map("serialize", $parenttmp)));
				} else {
					$parent = [
						$type => $this->node(
							$parent,
							$type
						)->first()->toArray()
					];
				}
            }
			
			// Get nodes here ffs (is the same anyways)
			$nodes = $this->node($entity, $type)->all();
			
			for($i = 0; $i < count($parent); $i++) {
				$data = [
					'parent_id' => isset($parent[$i][$type]['id']) ? $parent[$i][$type]['id'] : null,
					'model' => $model->alias(),
					'foreign_key' => $entity->id,
				];
				$parentIds = [];
				
				if (!$entity->isNew()) {
					$checkForRemovals = true;
					$parentIds[] = $data['parent_id'];
					
					foreach($nodes as $node) {
						if(isset($node->id) && isset($node->parent_id) && $node->parent_id === $data['parent_id'] && isset($node->model) && $node->model === $data['model']) {
							$data['id'] = $node->id;
						}
					}
				} else {
					$checkForRemovals = false;
				}
				
           	 	$newData = $model->{$type}->newEntity($data);
           	 	$saved = $model->{$type}->target()->save($newData);
			}
			
			if($checkForRemovals) {
				// Get nodes object again since it might have changed!
				$nodes = $this->node($entity, $type)->all();
				
				// Count of nodes in DB should be equal to the parent count
				// If not, some roles have to be removed manually
				if($nodes->count() != count($parent)) {
					foreach($nodes as $node) {
						// If parentId is not null and was not saved in the last step and th modelAlias is matching, remove it!
						if(isset($node->parent_id) && !in_array($node->parent_id, $parentIds) && isset($node->model) && $node->model === $model->alias()) {
							$event->subject()->{$type}->delete($node);
						}
					}
				}
			}
        }
    }

    /**
     * Destroys the ARO/ACO node bound to the deleted record
     *
     * @param Event $event The afterDelete event that was fired
     * @param Entity $entity The entity being deleted
     * @return void
     */
    public function afterDelete(Event $event, Entity $entity)
    {
		$model = $event->subject();
		
        $types = $this->_typeMaps[$this->config('type')];
        if (!is_array($types)) {
            $types = [$types];
        }
        foreach ($types as $type) {
            $node = $this->node($entity, $type)->all()->toArray();
			
            if (!empty($node)) {
				foreach($node as $role) {
					if($role->model	== $model->alias()) {
						$event->subject()->{$type}->delete($role);
					}
				}
            }
        }
    }
}