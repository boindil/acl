<?php
	namespace Acl\Adapter;
	
	use Acl\Adapter\DbAcl;
	use Cake\Core\App;
	use Cake\Controller\Component;
	use Cake\Network\Request;
	use Cake\ORM\TableRegistry;
	
	class AbmDbAcl extends CachedDbAcl {
		private $request;
		
		public function __construct() {
			$config = ['className' => App::className('Acl.AbmPermissionsTable', 'Model/Table')];
			$this->Permission = TableRegistry::get('Permissions', $config);
			$this->Aro = $this->Permission->Aros->target();
			$this->Aco = $this->Permission->Acos->target();
		}
		
		public function initialize(Component $component) {
			parent::initialize($component);
		}
		
		public function setRequest(Request &$request) {
			$this->request = $request;
		}
		
		public function setRequestForTable() {
			$this->Permission->setRequest($this->request);
		}
		
		public function check($aro, $aco, $action = "*", $overwrite = true) {
			return $this->Permission->check($aro, $aco, $action, $overwrite);
		}
	}
?>
