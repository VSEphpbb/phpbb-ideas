<?php
/**
 *
 * Ideas extension for the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\ideas;

/**
* This ext class is optional and can be omitted if left empty.
* However you can add special (un)installation commands in the
* methods enable_step(), disable_step() and purge_step(). As it is,
* these methods are defined in \phpbb\extension\base, which this
* class extends, but you can overwrite them to give special
* instructions for those cases.
*/
class ext extends \phpbb\extension\base
{
	/**
	 * Check whether or not the extension can be enabled.
	 *
	 * Requires phpBB >= 3.2.1 due to use of $event->update_subarray()
	 * Also incompatible with SQLite which does not support SQRT in SQL queries
	 *
	 * @return bool
	 * @access public
	 */
	public function is_enableable()
	{
		if (phpbb_version_compare(PHPBB_VERSION, '3.2.1', '<'))
		{
			return false;
		}

		$db = $this->container->get('dbal.conn');
		return ($db->get_sql_layer() !== 'sqlite3');
	}
}
