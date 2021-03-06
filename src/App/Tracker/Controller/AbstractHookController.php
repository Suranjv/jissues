<?php
/**
 * Part of the Joomla Tracker's Tracker Application
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace App\Tracker\Controller;

use App\Projects\Table\LabelsTable;
use App\Projects\TrackerProject;
use App\Tracker\Model\ActivityModel;
use App\Tracker\Table\StatusTable;

use Joomla\Database\DatabaseDriver;
use Joomla\Http\Exception\InvalidResponseCodeException;

use JTracker\Authentication\GitHub\GitHubLoginHelper;
use JTracker\Controller\AbstractAjaxController;
use JTracker\Github\GithubFactory;
use JTracker\Helper\IpHelper;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Abstract controller class for web hook requests
 *
 * @since  1.0
 */
abstract class AbstractHookController extends AbstractAjaxController implements LoggerAwareInterface
{
	use LoggerAwareTrait;

	/**
	 * The database object
	 *
	 * @var    DatabaseDriver
	 * @since  1.0
	 */
	protected $db;

	/**
	 * The data payload
	 *
	 * @var    object
	 * @since  1.0
	 */
	protected $hookData;

	/**
	 * The project information of the project whose data has been received
	 *
	 * @var    TrackerProject
	 * @since  1.0
	 */
	protected $project;

	/**
	 * Debug mode.
	 *
	 * @var    integer
	 * @since  1.0
	 */
	protected $debug;

	/**
	 * The type of hook being executed
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $type = 'standard';

	/**
	 * Checks if an issue exists
	 *
	 * @param   integer  $issue  Issue ID to check
	 *
	 * @return  string|null  The issue ID if it exists or null
	 *
	 * @since   1.0
	 */
	protected function checkIssueExists($issue)
	{
		try
		{
			return $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('id'))
					->from($this->db->quoteName('#__issues'))
					->where($this->db->quoteName('project_id') . ' = ' . (int) $this->project->project_id)
					->where($this->db->quoteName('issue_number') . ' = ' . $issue)
			)->loadResult();
		}
		catch (\RuntimeException $e)
		{
			$this->logger->error('Error checking the database for the GitHub ID', ['exception' => $e]);
			$this->getContainer()->get('app')->close();
		}
	}

	/**
	 * Retrieves the project data from the database
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function getProjectData()
	{
		// Get the ID for the project on our tracker
		$query = $this->db->getQuery(true);
		$query->select('alias');
		$query->from($this->db->quoteName('#__tracker_projects'));
		$query->where($this->db->quoteName('gh_project') . ' = ' . $this->db->quote($this->hookData->repository->name));
		$this->db->setQuery($query);

		$alias = '';

		try
		{
			$alias = $this->db->loadResult();
		}
		catch (\RuntimeException $e)
		{
			$this->logger->info(
				sprintf(
					'Error retrieving the project alias for GitHub repo %s in the database',
					$this->hookData->repository->name
				),
				['exception' => $e]
			);

			$this->getContainer()->get('app')->close();
		}

		// Make sure we have a valid project.
		if (!$alias)
		{
			$this->logger->info(
				sprintf(
					'A project does not exist for the %s GitHub repo in the database, cannot add data for it.',
					$this->hookData->repository->name
				)
			);

			$this->getContainer()->get('app')->close();
		}

		/* @type \JTracker\Application $application */
		$application = $this->getContainer()->get('app');

		$application->input->set('project_alias', $alias);

		$this->project = $application->getProject(true);
	}

	/**
	 * Initialize the controller.
	 *
	 * @return  $this  Method allows chiaining
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function initialize()
	{
		$this->debug = $this->getContainer()->get('app')->get('debug.hooks');

		// Initialize the logger
		$this->setLogger(
			new Logger(
				'JTracker',
				[
					new StreamHandler(
						$this->getContainer()->get('app')->get('debug.log-path') . '/github_' . strtolower($this->type) . '.log'
					)
				]
			)
		);

		// Get the event dispatcher
		$this->setDispatcher($this->getContainer()->get('app')->getDispatcher());

		// Get a database object
		$this->db = $this->getContainer()->get('db');

		// Get the payload data
		$data = $this->getContainer()->get('app')->input->post->get('payload', null, 'raw');

		if (!$data)
		{
			$this->logger->error('No data received.');
			$this->getContainer()->get('app')->close();
		}

		// Decode it
		$this->hookData = json_decode($data);

		// Get the project data
		$this->getProjectData();

		// If we have a bot defined for the project, prefer it over the DI object
		if ($this->project->gh_editbot_user && $this->project->gh_editbot_pass)
		{
			$this->github = GithubFactory::getInstance(
				$this->getContainer()->get('app'), true, $this->project->gh_editbot_user, $this->project->gh_editbot_pass
			);
		}
		else
		{
			$this->github = GithubFactory::getInstance(
				$this->getContainer()->get('app')
			);
		}

		// Check the request is coming from GitHub
		$validIps = $this->github->meta->getMeta();

		if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
			$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$myIP = $parts[0];
		}
		// Check if request is from CLI
		elseif (strpos($_SERVER['SCRIPT_NAME'], 'cli/tracker.php') !== false)
		{
			$myIP = '127.0.0.1';
		}
		else
		{
			$myIP = $this->getContainer()->get('app')->input->server->getString('REMOTE_ADDR');
		}

		if (!IpHelper::ipInRange($myIP, $validIps->hooks, 'cidr') && '127.0.0.1' != $myIP)
		{
			// Log the unauthorized request
			$this->logger->error('Unauthorized request from ' . $myIP);
			$this->getContainer()->get('app')->close();
		}

		// Set up the event listener
		$this->addEventListener($this->type);

		return $this;
	}

	/**
	 * Add a new event and store it to the database.
	 *
	 * @param   string   $event       The event name.
	 * @param   string   $dateTime    Date and time.
	 * @param   string   $userName    User name.
	 * @param   integer  $projectId   Project id.
	 * @param   integer  $itemNumber  THE item number.
	 * @param   integer  $commentId   The comment id
	 * @param   string   $text        The parsed html comment text.
	 * @param   string   $textRaw     The raw comment text.
	 *
	 * @return  $this
	 *
	 * @since   1.0
	 */
	protected function addActivityEvent($event, $dateTime, $userName, $projectId, $itemNumber, $commentId = null, $text = '', $textRaw = '')
	{
		try
		{
			(new ActivityModel($this->db))->addActivityEvent($event, $dateTime, $userName, $projectId, $itemNumber, $commentId, $text, $textRaw);
		}
		catch (\Exception $exception)
		{
			$this->logger->info(
				sprintf(
					'Error storing %s activity to the database (ProjectId: %d, ItemNo: %d)',
					$event,
					$projectId,
					$itemNumber
				),
				['exception' => $exception]
			);

			$this->getContainer()->get('app')->close();
		}

		return $this;
	}

	/**
	 * Parse a text with GitHub Markdown.
	 *
	 * @param   string  $text  The text to parse.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	protected function parseText($text)
	{
		try
		{
			return $this->github->markdown->render(
				$text,
				'gfm',
				$this->project->gh_user . '/' . $this->project->gh_project
			);
		}
		catch (InvalidResponseCodeException $exception)
		{
			$this->logger->info(
				sprintf(
					'Error parsing comment %d with GH Markdown',
					$this->hookData->comment->id
				),
				['exception' => $exception]
			);

			return '';
		}
		catch (\DomainException $exception)
		{
			$this->logger->info(
				sprintf(
					'Error parsing comment %d with GH Markdown',
					$this->hookData->comment->id
				),
				['exception' => $exception]
			);

			return '';
		}
	}

	/**
	 * Process labels for adding into the issues table
	 *
	 * @param   integer  $issueId  Issue ID to process
	 *
	 * @return  array
	 *
	 * @since   1.0
	 */
	protected function processLabels($issueId)
	{
		try
		{
			$githubLabels = $this->github->issues->get($this->project->gh_user, $this->project->gh_project, $issueId)->labels;
		}
		catch (InvalidResponseCodeException $exception)
		{
			$this->logger->error(
				sprintf(
					'Error parsing the labels for GitHub issue %s/%s #%d',
					$this->project->gh_user,
					$this->project->gh_project,
					$issueId
				),
				['exception' => $exception]
			);

			return '';
		}
		catch (\DomainException $exception)
		{
			$this->logger->error(
				sprintf(
					'Error parsing the labels for GitHub issue %s/%s #%d',
					$this->project->gh_user,
					$this->project->gh_project,
					$issueId
				),
				['exception' => $exception]
			);

			return '';
		}

		$appLabelIds = array();

		// Make sure the label is present in the database by pulling the ID, add it if it isn't
		$query = $this->db->getQuery(true);

		foreach ($githubLabels as $label)
		{
			$query->clear()
				->select($this->db->quoteName('label_id'))
				->from($this->db->quoteName('#__tracker_labels'))
				->where($this->db->quoteName('project_id') . ' = ' . (int) $this->project->project_id)
				->where($this->db->quoteName('name') . ' = ' . $this->db->quote($label->name));

			$this->db->setQuery($query);
			$id = $this->db->loadResult();

			// If null, add the label
			if ($id === null)
			{
				$table = new LabelsTable($this->db);

				$data = array();
				$data['project_id'] = $this->project->project_id;
				$data['name']       = $label->name;
				$data['color']      = $label->color;

				try
				{
					$table->save($data);

					$id = $table->label_id;
				}
				catch (\RuntimeException $exception)
				{
					$this->logger->error(
						sprintf(
							'Error adding label %s for project %s/%s to the database',
							$label->name,
							$this->project->gh_user,
							$this->project->gh_project
						),
						['exception' => $exception]
					);
				}
			}

			// Add the ID to the array
			$appLabelIds[] = $id;
		}

		return $appLabelIds;
	}

	/**
	 * Process the action of an item to determine its status
	 *
	 * @param   string   $action           The action being performed
	 * @param   integer  $currentStatusId  The current status ID of issue
	 *
	 * @return  integer|null  Status ID if the status changes, null if it stays the same
	 *
	 * @since   1.0
	 */
	protected function processStatus($action, $currentStatusId = null)
	{
		switch ($action)
		{
			case 'closed':
				$status = 10;

				// If the action is closed and this is a pull request, check if the request was merged and set the status to "Fixed in Code Base"
				if ($this->type == 'pulls' && $this->hookData->pull_request->merged)
				{
					$status = 5;
				}

				// Get the list of status IDs based on the GitHub close state
				$statusIds = (new StatusTable($this->db))
					->getStateStatusIds(true);

				// Check if the issue status is in the array.
				// If it is, then the item didn't change close state and we don't need to change the status.
				if ($currentStatusId && in_array($currentStatusId, $statusIds))
				{
					$status = null;
				}

				return $status;

			case 'opened':
			case 'reopened':
				$status = 1;

				// Get the list of status IDs based on the GitHub open state
				$statusIds = (new StatusTable($this->db))
					->getStateStatusIds(false);

				// Check if the issue status is in the array.
				// If it is, then the item didn't change open state and we don't need to change the status.
				if ($currentStatusId && in_array($currentStatusId, $statusIds))
				{
					$status = null;
				}

				return $status;

			default :
				return null;
		}
	}

	/**
	 * Retrieves the user's avatar if it doesn't exist
	 *
	 * @param   string  $login  Username to process
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function pullUserAvatar($login)
	{
		if (!file_exists(JPATH_THEMES . '/images/avatars/' . $login . '.png'))
		{
			(new GitHubLoginHelper($this->getContainer()))->saveAvatar($login);
		}
	}

	/**
	 * Triggers an event if a listener is set
	 *
	 * @param   string  $eventName  Name of the event to trigger
	 * @param   array   $arguments  Associative array of arguments for the event.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function triggerEvent($eventName, array $arguments)
	{
		$arguments['hookData'] = $this->hookData;

		parent::triggerEvent($eventName, $arguments);
	}
}
