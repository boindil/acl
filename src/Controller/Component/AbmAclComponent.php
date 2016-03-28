<?php
	namespace Acl\Controller\Component;
	
	use Acl\Controller\Component\AclComponent;
	use Cake\Controller\ComponentRegistry;
	
	class AbmAclComponent extends AclComponent {
		public $request;
		
		public $Permission;
		
		public function __construct(ComponentRegistry $collection, array $config = []) {
			$this->request = $collection->getController()->request;
			parent::__construct($collection, $config);
		}
		
		public function adapter($adapter = null) {
			parent::adapter($adapter);

			if(isset($this->_Instance->Permission)) {
				$this->Permission = clone($this->_Instance->Permission);
			}

			if(in_array("setRequest", get_class_methods($this->_Instance))) {
				$this->_Instance->setRequest($this->request);
				$this->_Instance->setRequestForTable();
			}
		}
		
		public function check($aro, $aco, $action = "*", $overwrite = true) {
			return $this->_Instance->check($aro, $aco, $action, $overwrite);
		}
	}
?>