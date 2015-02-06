<?php
	namespace Acl\Model\Table;
	
	use Acl\Model\Table\PermissionsTable;
	use Cake\Core\Configure;
	use Cake\Network\Request;
	use Cake\ORM\TableRegistry;
	use Cake\Utility\Hash;
	
	class AbmPermissionsTable extends PermissionsTable {
		private $request;
		private $teamid;
		private $roleTable;
		private $team_foreignkey;
		
		public function initialize(array $config) {
			parent::initialize($config);
			
			$this->roleTable = TableRegistry::get(Configure::read('acl.aro.role.table'));
			$this->team_foreignkey = Configure::read('acl.aro.team.foreign_key');
		}
		
		public function setRequest(Request &$request) {
			$this->request = $request;
		}
		
		public function getChildrenIDs($aco_id, $aco) {
			$results = $this->{$aco}->find()->join([
				't' => [
					'table' => strtolower($aco),
					'foreignKey' => false, // valid as of 3.0?
					'conditions' => [
						$aco . '.parent_id = t.id'
					]
				]
			])->where([
				$aco . '.parent_id' => $aco_id
			])->hydrate(false)->toArray();

			foreach($results as $result) {
				$tmp = $this->getChildrenIDs($result['id'], $aco);
				if($tmp != []) {
					if(!isset($results_tmp)) {
						$results_tmp = array_merge($results,$tmp);
					} else {
						$results_tmp = array_merge($results_tmp,$tmp);
					}
					array_map('unserialize', array_unique(array_map('serialize', $results_tmp)));
				}
			}

			if(isset($results_tmp)) {
				return $results_tmp;
			} else {
				return $results;
			}
		}
		
		public function getChildrenIDs_helper($id, $aco) {
			$result = $this->getChildrenIDs($id, $aco);
			$return = array();
			
			foreach($result as $value) {
				$return[$value['id']] = $value['model'];
			}
			
			return $return;
		}
		
		function arrayRecursiveDiff($aArray1, $aArray2) {
			$aReturn = array();

			foreach ($aArray1 as $mKey => $mValue) {
				if (array_key_exists($mKey, $aArray2)) {
					if (is_array($mValue)) {
						$aRecursiveDiff = $this->arrayRecursiveDiff($mValue, $aArray2[$mKey]);
						
						if (count($aRecursiveDiff)) {
							$aReturn[$mKey] = $aRecursiveDiff;
						}
					} else {
						if ($mValue != $aArray2[$mKey]) {
							$aReturn[$mKey] = $mValue;
						}
					}
				} else {
					$aReturn[$mKey] = $mValue;
				}
			}
			return $aReturn;
		} 
		
	/**
	 * Checks if the given $aro has access to action $action in $aco
	 *
	 * @param string $aro ARO The requesting object identifier.
	 * @param string $aco ACO The controlled object identifier.
	 * @param string $action Action (defaults to *)
	 * @return boolean Success (true if ARO has access to action in ACO, false otherwise)
	 */
		public function check($aro, $aco, $action = "*", $overwrite = true) {
			if (!$aro || !$aco) {
				return false;
			}

			$permKeys = $this->getAcoKeys($this->schema()->columns());
			$aroPath = $this->Aro->node($aro);
			$acoPath = $this->Aco->node($aco);
			
			if(is_a($aroPath, 'Cake\ORM\Query', false) && is_a($acoPath, 'Cake\ORM\Query', false) && $aroPath->count() && $acoPath->count()) {
				// BEFORE (Cake 2): Check for ACL-Node OR Admin-Path OR True (Was useless whatsoever)
				
				// Get the Team-Id to filter permissions with
				if(Configure::read('Config.team.backup_id')) {
					$this->teamid = Configure::read('Config.team.backup_id');
				} else {
					$this->teamid = Configure::read('Config.team.current_id');
				}

				// Get roles, the user is allowed to access
				$allowed_roles = $this->roleTable->find()->select([
					$this->roleTable->alias() . '.id'
				])->where([
					'or' => [
						$this->roleTable->alias() . '.team_id' => $this->teamid,
						$this->roleTable->alias() . '.global' => 1,
					]
				])->hydrate(false)->toArray();
				
				$allowed_roles = Hash::extract($allowed_roles, '{n}.id');
				
				// Unset roles the user currently has no access to (based on currently logged in team)
				$aroPathArray = $aroPath->toArray();
				foreach($aroPathArray as $key => $aro) {
					if($aro->model == Configure::read('acl.aro.role.model') && !in_array($aro->foreign_key, $allowed_roles)) {
						unset($aroPathArray[$key]);
					}
				}

				foreach($aroPathArray as $key => $aro) {
					$parentid = $aro->parent_id;
					// In case we got a parent, we need to check if it survived filtering
					if($parentid !== null) {
						$parent = [];
						foreach($aroPathArray as $skey => $saro) {
							if($parentid == $saro->id) {
								$parent[] = $saro;
							}
						}
						
						if(empty($parent)) {
							unset($aroPathArray[$key]);
						}
					}
				}
				
				$aroPathArray = array_values($aroPathArray);
				// Cake 2: probably useless by now
				// $aroPath = array_values($this->arrayRecursiveDiff($aroPath,array()));
			} else {
				return false;
			}
			
			if (!$aroPathArray) {
				trigger_error(
					__d(
						'cake_dev',
						"{0} - Failed ARO node lookup in permissions check. Node references:\nAro: {1}\nAco: {2}",
						'DbAcl::check()',
						print_r($aro, true),
						print_r($aco, true)
					),
					E_USER_WARNING
				);
				return false;
			}

			if (!$acoPath) {
				trigger_error(
					__d(
						'cake_dev',
						"{0} - Failed ACO node lookup in permissions check. Node references:\nAro: {1}\nAco: {2}",
						'DbAcl::check()',
						print_r($aro, true),
						print_r($aco, true)
					),
					E_USER_WARNING
				);
				return false;
			}

			if ($action !== '*' && !in_array('_' . $action, $permKeys)) {
				trigger_error(__d('cake_dev', "ACO permissions key {0} does not exist in {1}", $action, 'DbAcl::check()'), E_USER_NOTICE);
				return false;
			}

			$inherited = array();
			$lock = array();
			$acoIDs = $acoPath->extract('id')->toArray();
			
			$count = count($aroPathArray);
			$aroPaths = $aroPathArray;
			
			// Cake 2: ToDo > Check if needed
			$aco_child_ids = array();
			$aco_ids_plain = array();

			for ($i = 0; $i < $count; $i++) {
				$inherited[$i] = array();
				$permAlias = $this->alias();

				$perms = $this->find('all', array(
					'conditions' => array(
						$permAlias . '.aro_id' => $aroPaths[$i]->id,
						$permAlias . '.aco_id IN' => $acoIDs
					),
					'order' => [$this->Aco->alias() . '.lft' => 'asc'], // Cake 2 > Cake 3: asc > desc | needs to be checked! seems to be modified by @boindil
					'contain' => $this->Aco->alias(),
				));
				
				if ($perms->count() == 0) {
					continue;
				}
				
				$perms = $perms->hydrate(false)->toArray();
				foreach ($perms as $perm) {
					// Introduce some new variables for parent-child associations
					$parent = false;
					$parent_user = false;
					$parent_model = false;
					$parent_foreign_model = false;
					$block = false;
					
					if ($action === '*') {
						// ToDo: Langzeittest
						
						if(isset($children)) {
							unset($children);
						}
						
						// Check if children are cached in Session > faster execution
						if($this->request->session()->check('Alaxos.Acl.children')) {
							$read_children = $this->request->session()->read('Alaxos.Acl.children');
							if(isset($read_children[$perm['aco_id']])) {
								$children = $read_children[$perm['aco_id']];
							}
						}
						
						if(!isset($children)) {
							$children = $this->getChildrenIDs_helper($perm['aco_id'], $this->Aco->alias());
							
							if(isset($write_children)) {
								unset($write_children);
							}
							
							if($this->request->session()->check('Alaxos.Acl.children')) {
								if(!isset($read_children)) {
									$read_children = $this->request->session()->read('Alaxos.Acl.children');
								}
								$read_children[$perm['aco_id']] = $children;
								$write_children = $read_children;
							} else {
								$write_children = $children;
							}
							$this->request->session()->write('Alaxos.Acl.children', $write_children);
						}
						
						if($aroPaths[$i]->model == 'Users') {
							// Simple variant without parents
							// Assumes that ARO_ACOs are only set for single actions and not for whole controllers
							// ToDo: Change this behaviour
							
							// Assume application has following structure:
							// Teams (Subdomains, n)
							// 		Subteams (Role Model AROs, n)
							//			User (User Model AROs, n)
							// Assume ACL-Plugin (modified by boindil) is loaded!
							
							$role_model_id = $this->Aro->find()->select([
								'foreign_key'
							])->where([
								'id' => $aroPaths[$i]->parent_id
							])->hydrate(false)->first();
							
							if($role_model_id) {
								$role_model_id = $role_model_id['foreign_key'];
							}
							
							$role = $this->roleTable->find()->where([
								$this->roleTable->alias() . '.' . $this->roleTable->primaryKey() => $role_model_id
							])->hydrate(false)->first();

							// Even though ARO_ACOs should be only set for a single Role Model per Team, we check if the Subteam is the standard one!
							if(
								$role != null &&
								($this->team_foreignkey == null && $this->teamid == null) || 
								(
									$this->team_foreignkey != null && $this->teamid != null && 
									((isset($role[$this->team_foreignkey]) && $role[$this->team_foreignkey] == $this->teamid) || !isset($role[$this->team_foreignkey]))
								) &&
								(
									!isset($role['standard']) ||
									(isset($role['standard']) && $role['standard'] == true)
								)
							) {
								$parent = true;
								$parent_user = true;
							} else {
								$block = true;
							}
						} else {
							foreach($aco_child_ids as $aco_aro => $child_acos) {
								$aro_id = explode(":", $aco_aro);
								if(isset($aro_id[1]) && $aro_id[1] == $perm['aro_id']) {
									if(array_key_exists($perm['aco_id'], $child_acos)) {
										$parent = true;
										$parent_model = true;
										break;
									}
								} elseif(isset($aro_id[1]) && $aro_id[1] != $perm['aro_id']) {
									$aro_row = $this->Aro->find()->select([
										'model'
									])->where([
										'id' => $aro_id[1],
										'not' => [
											'model' => 'User'
										]
									])->hydrate(false)->first();
									
									if(!empty($aro_row)) {
										if(isset($child_acos[$perm['aco_id']])) {
											$parent = true;
											$parent_foreign_model = true;
											break;
										}
									}
								}
							}
						}
						
						if(!$block && ($parent || empty($inherited[$i]))) {
							foreach ($permKeys as $key) {
								if (!empty($perm)) {
									if ($perm[$key] == -1) {
										if(($overwrite || !isset($inherited[$i][$key]) || $aroPaths[$i]->model == 'Users') && !isset($lock[$i])) {
											if($parent_model || $parent_user || !isset($inherited[$i][$key])) {
												$inherited[$i][$key] = -1;
											} elseif($parent_foreign_model) {
												$inherited[$i][$key] = max($inherited[$key], -1);
											}
										}
									} elseif ($perm[$key] == 1) {
										if(($overwrite || !isset($inherited[$i][$key]) || $aroPaths[$i]->model == 'Users') && !isset($lock[$i])) {
											if($parent_model || $parent_user || !isset($inherited[$i][$key])) {
												$inherited[$i][$key] = 1;
											} elseif($parent_foreign_model) {
												$inherited[$i][$key] = max($inherited[$key], 1);
											}
										}
									}
								}
							}
							
							if($aroPaths[$i]->model == "Users") {
								$lock[$i] = true;
							}
							
							// Add processed ACO_AROs with children
							$array = array($perm['aco_id'] . ':' . $perm['aro_id'] => $children);
							if(!array_key_exists($perm['aco_id'] . ':' . $perm['aro_id'], $aco_child_ids)) {
								$aco_child_ids = array_merge($aco_child_ids, $array);
							}
						}
					} else {
						// NEVER Fall in this case! NEVER! This is standard CakePHP behavior as is! This will NOT WORK CORRECTLY!
						switch ($perm['_' . $action]) {
							case -1:
								return false;
							case 0:
								continue;
							case 1:
								return true;
						}
					}
				}
			}

			if(!empty($lock)) {
				$inherited = $inherited[key($lock)];
			} else {
				$tmp = null;
				for ($i = 0; $i < $count; $i++) {
					if(!isset($tmp) && !empty($inherited[$i])) {
						$tmp = $inherited[$i];
					} else {
						if(is_array($inherited[$i]) && !empty($inherited[$i])) {
							foreach($inherited[$i] as $key => $value) {
								if(!isset($tmp[$key])) {
									$tmp[$key] = $value;
								} else {
									$tmp[$key] = max($tmp[$key], $value);
								}
							}
						}
					}
				}
				$inherited = $tmp;
			}
			
			if (count($inherited) === count($permKeys)) {
				foreach($inherited as $value) {
					if($value !== 1) {
						return false;
					}
				}
				return true;
			}
			
			return false;
		}
	}
?>