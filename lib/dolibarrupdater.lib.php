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
 * \file    htdocs/custom/dolibarrupdater/lib/dolibarrupdater.lib.php
 * \ingroup dolibarrupdater
 * \brief   Library for the Dolibarr updater admin page.
 */

/**
 * Tabs of the updater admin page.
 *
 * @return array<int,array<int,string>>
 */
function dolibarrupdaterAdminPrepareHead()
{
	global $langs;

	$langs->load('dolibarrupdater@dolibarrupdater');

	$head = array();
	$head[0][0] = dol_buildpath('/dolibarrupdater/admin/update.php', 1);
	$head[0][1] = $langs->trans('DolibarrUpdaterTitle');
	$head[0][2] = 'update';

	return $head;
}
