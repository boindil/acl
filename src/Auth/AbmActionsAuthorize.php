<?php
	namespace Acl\Auth;

	use Cake\Network\Request;

	class AbmActionsAuthorize extends ActionsAuthorize
	{
		public function authorize($user, Request $request)
		{
			$Acl = $this->_registry->load('Acl.AbmAcl');
			$user = [$this->_config['userModel'] => $user];
			return $Acl->check($user, $this->action($request));
		}
	}