<?php
/* Copyright (C) 2026 IODE
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/dolibarrupdater/core/modules/modDolibarrupdater.class.php
 * \ingroup dolibarrupdater
 * \brief   Module descriptor for Dolibarr core updates.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Dolibarrupdater.
 */
class modDolibarrupdater extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		$this->db = $db;
		$this->numero = 500024;
		$this->rights_class = 'dolibarrupdater';
		$this->family = 'technic';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'DolibarrUpdaterDescription';
		$this->descriptionlong = 'DolibarrUpdaterDescription';
		$this->editor_name = 'IODE';
		$this->editor_url = '';
		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-download';
		$this->langfiles = array('dolibarrupdater@dolibarrupdater');

		$this->module_parts = array();
		$this->dirs = array('/dolibarrupdater');
		$this->config_page_url = array('update.php@dolibarrupdater');

		$this->rights = array();
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=setup',
			'type' => 'left',
			'titre' => 'DolibarrUpdaterMenu',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'home',
			'leftmenu' => 'dolibarrupdater',
			'url' => '/dolibarrupdater/admin/update.php?mainmenu=home&leftmenu=setup',
			'langs' => 'dolibarrupdater@dolibarrupdater',
			'position' => 1000,
			'enabled' => 'isModEnabled("dolibarrupdater")',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);

		$langs->load('dolibarrupdater@dolibarrupdater');
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		dol_mkdir(DOL_DATA_ROOT.'/dolibarrupdater');
		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
