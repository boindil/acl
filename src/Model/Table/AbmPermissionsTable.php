<?php
	namespace Acl\Model\Table;
	use Acl\Model\Table\PermissionsTable;
	class AbmPermissionsTable extends PermissionsTable {
		public function check($aro, $aco, $action = '*') {
			return parent::check($aro, $aco, $action);
		}
	}
?>
