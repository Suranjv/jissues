<?php
/**
 * @package     JTracker
 * @subpackage  com_tracker
 *
 * @copyright   Copyright (C) 2012 Open Source Matters. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Model to get data for the issue list view
 *
 * @package     JTracker
 * @subpackage  com_tracker
 * @since       1.0
 */
class TrackerModelIssues extends JModelTrackerList
{
	/**
	 * Method to get a JDatabaseQuery object for retrieving the data set from a database.
	 *
	 * @return  JDatabaseQuery   A JDatabaseQuery object to retrieve the data set.
	 *
	 * @since   1.0
	 */
	protected function getListQuery()
	{
		$db    = $this->getDb();
		$query = $db->getQuery(true);

		$query->select('a.*');
		$query->from($db->quoteName('#__issues', 'a'));

		// Join over the status.
		$query->select('s.status AS status_title, s.closed AS closed_status');
		$query->join('LEFT', '#__status AS s ON a.status = s.id');

		// Join over the category
		$query->select('c.title AS category');
		$query->leftJoin('#__categories AS c ON a.catid = c.id');

		$filter = $this->state->get('filter.project');

		if ($filter)
		{
			$query->where($db->quoteName('a.project_id') . ' = ' . (int) $filter);
		}

		$filter = $this->state->get('list.filter');

		if ($filter)
		{
			// Clean filter variable
			$filter = $db->quote('%' . $db->escape(JString::strtolower($filter), true) . '%', false);

			// Check the author, title, and publish_up fields
			$query->where('(' . $db->quoteName('a.title') . ' LIKE ' . $filter . ' OR ' . $db->quoteName('a.description') . ' LIKE ' . $filter . ')');
		}

		$status = $this->state->get('filter.status', 0);
		if ($status > 0)
		{
			$query->where($db->quoteName('a.status') . ' = ' . (int) $status);
		}

		$priority = $this->state->get('filter.priority', 0);
		if ($priority > 0)
		{
			$query->where($db->quoteName('a.priority') . ' = ' . (int) $priority);
		}

		$opendate = $this->state->get('filter.opendate', '');
		if ($opendate !=  'all')
		{
			if ($opendate == 'today')
			{
				$query->where('DATE(a.opened) = DATE(NOW())');
			}
			else if ($opendate == 'thisweek')
			{
				$query->where('WEEKOFYEAR(a.opened) = WEEKOFYEAR(NOW())');
			}
			else if ($opendate == 'thismonth')
			{
				$query->where('MONTH(a.opened) = MONTH(NOW())');
			}
			else if ($opendate == 'last3')
			{
				$query->where('MONTH(a.opened) <= MONTH(NOW()) AND  MONTH(a.opened) >= (MONTH(NOW()) - 2)');
			}
			else if ($opendate == 'last6')
			{
				$query->where('MONTH(a.opened) <= MONTH(NOW()) AND  MONTH(a.opened) >= (MONTH(NOW()) - 5)');
			}
		}

		$other = $this->state->get('filter.other', 'nothing');
		if ($other != 'nothing')
		{
			if ($other == 'icreated')
			{
				$user = JFactory::getUser();
				$query->where($db->quoteName('a.author_id') . ' = ' . (int) $user->get('id'));
			}
			else if ($other == 'icommented')
			{
				$user = JFactory::getUser();
				$query->where('a.id IN (SELECT issue_id FROM #__issue_comments WHERE author_id ='.(int) $user->get('id').')');
			}
			else if ($other == 'havenocomment')
			{
				$query->where('a.id NOT IN (SELECT issue_id FROM #__issue_comments)');
			}
		}


		// TODO: Implement filtering and join to other tables as added

		$ordering  = $db->escape($this->state->get('list.ordering', 'a.id'));
		$direction = $db->escape($this->state->get('list.direction', 'ASC'));
		$query->order($ordering . ' ' . $direction);

		return $query;
	}

	/**
	 * Method to get a store id based on the model configuration state.
	 *
	 * This is necessary because the model is used by the component and
	 * different modules that might need different sets of data or different
	 * ordering requirements.
	 *
	 * @param   string  $id  An identifier string to generate the store id.
	 *
	 * @return  string  A store id.
	 *
	 * @since   1.0
	 */
	protected function getStoreId($id = '')
	{
		// Add the list state to the store id.
		$id .= ':' . $this->state->get('filter.priority');
		$id .= ':' . $this->state->get('filter.status');
		$id .= ':' . $this->state->get('list.filter');

		return parent::getStoreId($id);
	}

	/**
	 * Load the model state.
	 *
	 * @return  JRegistry  The state object.
	 *
	 * @since   1.0
	 */
	protected function loadState()
	{
		$this->state = new JRegistry;

		$app = JFactory::getApplication();
		$input = JFactory::getApplication()->input;

		$fields = new JRegistry($input->get('fields', array(), 'array'));

		$this->state->set('filter.project', (int) $fields->get('project'));

		$this->state->set('list.ordering', $input->get('filter_order', 'a.id'));

		$listOrder = $input->get('filter_order_Dir', 'ASC');
		if (!in_array(strtoupper($listOrder), array('ASC', 'DESC', '')))
		{
			$listOrder = 'ASC';
		}
		$this->state->set('list.direction', $listOrder);

		$this->state->set('filter.priority', $input->getUint('priority', 3));

		$this->state->set('filter.status', $input->getUint('status'));

		$this->state->set('filter.opendate', $input->getString('opendate', 'all'));

		$this->state->set('filter.other', $input->getString('that', 'nothing'));

		// Optional filter text
		$this->state->set('list.filter', $input->getString('filter-search'));

		// List state information.
		parent::loadState();
	}
}
