<?php
/**
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
use Cake\Core\Configure;

if (!Configure::read('Acl.classname')) {
    Configure::write('Acl.classname', 'AbmDbAcl');
}
if (!Configure::read('Acl.database')) {
	if(Configure::read('debug')) {
		Configure::write('Acl.database', 'test');
	} else {
		Configure::write('Acl.database', 'default');
	}
}
