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
			
			for($i = 0; $i < count($parent); $i++) {
				$data = [
					'parent_id' => isset($parent[$i][$type]['id']) ? $parent[$i][$type]['id'] : null,
					'model' => $model->alias(),
					'foreign_key' => $entity->id,
				];
				if (!$entity->isNew()) {
					$node = $this->node($entity, $type)->all();
					
					foreach($node as $val) {
						if(isset($val->id) && isset($val->parent_id) && $val->parent_id === $data['parent_id'] && isset($val->model) && $val->model === $data['model']) {
							$data['id'] = $val->id;
						}
					}
				}
				
           	 	$newData = $model->{$type}->newEntity($data);
           	 	$saved = $model->{$type}->target()->save($newData);
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
        $types = $this->_typeMaps[$this->config('type')];
        if (!is_array($types)) {
            $types = [$types];
        }
        foreach ($types as $type) {
            $node = $this->node($entity, $type)->toArray();
			// Aus altem System übernommen
			// $node = Hash::extract($this->node($model, null, $type), "{n}.{$type}[model=" . $model->name . "].id");
			// Altes System, original
			// $node = Hash::extract($this->node($model, null, $type), "0.{$type}.id");
            if (!empty($node)) {
				// Neu: foreach
				foreach($node as $role) {
					$event->subject()->{$type}->delete($role);
					// $event->subject()->{$type}->delete($node[0]);
				}
            }
        }
    }
}