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

use Cake\Core\App;
use Cake\Core\Exception;
use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;
use Acl\Model\Behavior\AclBehavior;

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
     * Table instance
     */
    protected $_table = null;

    /**
     * Maps ACL type options to ACL models
     *
     * @var array
     */
    protected $_typeMaps = ['requester' => 'Aro', 'controlled' => 'Aco', 'both' => ['Aro', 'Aco']];

    /**
     * Sets up the configuration for the model, and loads ACL models if they haven't been already
     *
     * @param Table $model Table instance being attached
     * @param array $config Configuration
     * @return void
     */
    public function __construct(Table $model, array $config = [])
    {
        $this->_table = $model;
        if (isset($config[0])) {
            $config['type'] = $config[0];
            unset($config[0]);
        }
        if (isset($config['type'])) {
            $config['type'] = strtolower($config['type']);
        }
        parent::__construct($model, $config);

        $types = $this->_typeMaps[$this->config()['type']];

        if (!is_array($types)) {
            $types = [$types];
        }
        foreach ($types as $type) {
            $alias = Inflector::pluralize($type);
            $className = App::className($alias . 'Table', 'Model/Table');
            if ($className == false) {
                $className = App::className('Acl.' . $alias . 'Table', 'Model/Table');
            }
            $config = [];
            if (!TableRegistry::exists($alias)) {
                $config = ['className' => $className];
            }
            $model->hasMany($type, [
                'targetTable' => TableRegistry::get($alias, $config),
            ]);
        }

        if (!method_exists($model->entityClass(), 'parentNode')) {
            trigger_error(__d('cake_dev', 'Callback {0} not defined in {1}', ['parentNode()', $model->entityClass()]), E_USER_WARNING);
        }
    }

    /**
     * Retrieves the Aro/Aco node for this model
     *
     * @param string|array|Model $ref Array with 'model' and 'foreign_key', model object, or string value
     * @param string $type Only needed when Acl is set up as 'both', specify 'Aro' or 'Aco' to get the correct node
     * @return Cake\ORM\Query
     * @link http://book.cakephp.org/2.0/en/core-libraries/behaviors/acl.html#node
     * @throws \Cake\Core\Exception\Exception
     */
    public function node($ref = null, $type = null)
    {
        if (empty($type)) {
            $type = $this->_typeMaps[$this->config('type')];
            if (is_array($type)) {
                trigger_error(__d('cake_dev', 'AclBehavior is setup with more then one type, please specify type parameter for node()'), E_USER_WARNING);
                return null;
            }
        }
        if (empty($ref)) {
            throw new Exception\Exception(__d('cake_dev', 'ref parameter must be a string or an Entity'));
        }
        return $this->_table->{$type}->node($ref);
    }

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
				// Neu: Zeile 133-143 + else-Zweig
				// $parent -> array oder object?
				$current = current($parent);
				if(!isset($current['id'])) {
					$parenttmp = [];
					$key = key($parent);

					foreach($current as $role) {
						// Übergabe als array?!
						$parenttmp = array_merge($parenttmp, $this->node(array($key => $role), $type)->first());
					}
					$parent = array_map("unserialize", array_unique(array_map("serialize", $parenttmp)));
				} else {
					$parent = $this->node($parent, $type)->first();
				}
            }
			// Neu: for-Schleife
			for($i = 0; $i < count($parent); $i++) {
				// Neu: $i in parent_id
				$data = [
					'parent_id' => isset($parent[$i]->id) ? $parent[$i]->id : null,
					'model' => $model->alias(),
					'foreign_key' => $entity->id,
				];
				if (!$entity->isNew()) {
					// Neu: all
					$node = $this->node($entity, $type)->all();
					// Neu: foreach
					// Struktur (object)
					foreach($node as $val) {
						if(isset($val->id) && isset($val->parent_id) && $val->parent_id === $data['parent_id'] && isset($val->model) && $val->model === $data['model']) {
							$data['id'] = $val->id;
						}
					}
				}
			}
            $newData = $model->{$type}->newEntity($data);
            $saved = $model->{$type}->target()->save($newData);
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