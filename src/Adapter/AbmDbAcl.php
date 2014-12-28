<?php
	namespace Acl\Adapter;
	use Acl\Adapter\DbAcl;
	use Cake\Core\App;
	use Cake\ORM\TableRegistry;
	class AbmDbAcl extends CachedDbAcl {
		public function __construct() {
			$config = ['className' => App::className('Acl.AbmPermissionsTable', 'Model/Table')];
			$this->Permission = TableRegistry::get('Permissions', $config);
			$this->Aro = $this->Permission->Aros->target();
			$this->Aco = $this->Permission->Acos->target();
		}
		
		public function check($aro, $aco, $action = "*", $overwrite = true) {
			return $this->Permission->check($aro, $aco, $action, $overwrite);
		}
	}
?>
