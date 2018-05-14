<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  System.userlogs
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

JLoader::register('UserlogsHelper', JPATH_ADMINISTRATOR . '/components/com_userlogs/helpers/userlogs.php');

/**
 * Joomla! Users Actions Logging Plugin.
 *
 * @since  __DEPLOY_VERSION__
 */
class PlgSystemUserLogs extends JPlugin
{
	/**
	 * Array of loggable extensions.
	 *
	 * @var    array
	 * @since  __DEPLOY_VERSION__
	 */
	protected $loggableExtensions = array();

	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  __DEPLOY_VERSION__
	 */
	protected $app;

	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 * @since  __DEPLOY_VERSION__
	 */
	protected $db;

	/**
	 * Language plugin language file automatically so that it can be used inside component
	 *
	 * @var    bool
	 * @since  __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * Constructor.
	 *
	 * @param   object  &$subject  The object to observe.
	 * @param   array   $config    An optional associative array of configuration settings.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		if (is_array($this->params->get('loggable_extensions')))
		{
			$this->loggableExtensions = $this->params->get('loggable_extensions');

			return;
		}

		$this->loggableExtensions = explode(',', $this->params->get('loggable_extensions'));
	}

	/**
	 * Function to add logs to the database
	 * This method adds a record to #__user_logs contains (message_language_key, message, date, context, user)
	 *
	 * @param   array    $messages            The contents of the messages to be logged
	 * @param   string   $messageLanguageKey  The language key of the message
	 * @param   string   $context             The context of the content passed to the plugin
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function addLogsToDb($messages, $messageLanguageKey, $context)
	{
		$user       = JFactory::getUser();
		$date       = JFactory::getDate();

		if ($this->params->get('ip_logging', 0))
		{
			$ip = $this->app->input->server->get('REMOTE_ADDR');
		}
		else
		{
			$ip = JText::_('PLG_SYSTEM_USERLOGS_DISABLED');
		}


		$loggedMessages = array();

		foreach ($messages as $message)
		{
			$logMessage                       = new stdClass;
			$logMessage->message_language_key = $messageLanguageKey;
			$logMessage->message              = json_encode($message);
			$logMessage->log_date             = (string) $date;
			$logMessage->extension            = $context;
			$logMessage->user_id              = $user->id;
			$logMessage->ip_address           = $ip;

			try
			{
				$this->db->insertObject('#__user_logs', $logMessage);
				$loggedMessages[] = $logMessage;

			}
			catch (RuntimeException $e)
			{
				// Ignore it
			}
		}

		// Send notification email to users who choose to be notified about the action logs
		$this->sendNotificationEmails($loggedMessages, $user->name, $context);
	}

	/**
	 * Function to check if a component is loggable or not
	 *
	 * @param   string   $extension  The extension that triggered the event
	 *
	 * @return  boolean
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function checkLoggable($extension)
	{
		return in_array($extension, $this->loggableExtensions);
	}

	/**
	 * After save content logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called right after the content is saved
	 *
	 * @param   string   $context  The context of the content passed to the plugin
	 * @param   object   $article  A JTableContent object
	 * @param   boolean  $isNew    If the content is just about to be created
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onContentAfterSave($context, $article, $isNew)
	{
		if (!$this->checkLoggable($this->app->input->getCmd('option')))
		{
			return;
		}

		$params = UserlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		if ($isNew)
		{
			$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_ADDED');
			$action             = 'add';
		}
		else
		{
			$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_UPDATED');
			$action             = 'update';
		}

		$user = JFactory::getUser();

		$message = array(
			'id'       => empty($params->id_holder) ? 0 : $article->get($params->id_holder),
			'title'    => $article->get($params->title_holder),
			'userid'   => $user->id,
			'username' => $user->username,
			'action'   => $action
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * After delete content logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called right after the content is deleted
	 *
	 * @param   string   $context  The context of the content passed to the plugin
	 * @param   object   $article  A JTableContent object
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onContentAfterDelete($context, $article)
	{
		if (!$this->checkLoggable($this->app->input->get('option')))
		{
			return;
		}

		$params = UserlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		$user = JFactory::getUser();

		$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_DELETED');

		$message = array(
			'id'       => empty($params->id_holder) ? 0 : $article->get($params->id_holder),
			'title'    => $article->get($params->title_holder),
			'userid'   => $user->id,
			'username' => $user->username,
			'action'   => 'delete',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On content change status logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called when the status of the article is changed
	 *
	 * @param   string   $context  The context of the content passed to the plugin
	 * @param   array    $pks      An array of primary key ids of the content that has changed state.
	 * @param   integer  $value    The value of the state that the content has been changed to.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onContentChangeState($context, $pks, $value)
	{
		if (!$this->checkLoggable($this->app->input->get('option')))
		{
			return;
		}

		$params = UserlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		$user = JFactory::getUser();

		switch ($value)
		{
			case 0:
				$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_UNPUBLISHED');
				$action             = 'unpublish';
				break;
			case 1:
				$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_PUBLISHED');
				$action             = 'publish';
				break;
			case 2:
				$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_ARCHIVED');
				$action             = 'archive';
				break;
			case -2:
				$messageLanguageKey = strtoupper($params->text_prefix . '_' . $params->type_title . '_TRASHED');
				$action             = 'trash';
				break;
			default:
				$messageLanguageKey = '';
				$action             = '';
				break;
		}

		$items = UserlogsHelper::getDataByPks($pks, $params->title_holder, $params->id_holder, $params->table_name);

		$messages = array();

		foreach ($pks as $pk)
		{
			$message = array(
				'id'       => $pk,
				'title'    => $items[$pk]->{$params->title_holder},
				'userid'   => $user->id,
				'username' => $user->username,
				'action'   => $action,
			);

			$messages[] = $message;
		}

		$this->addLogsToDb($messages, $messageLanguageKey, $context);
	}

	/**
	 * On installing extensions logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called when an extension is installed
	 *
	 * @param   JInstaller  $installer  Installer object
	 * @param   integer     $eid        Extension Identifier
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterInstall($installer, $eid)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$user     = JFactory::getUser();
		$manifest = $installer->get('manifest');

		$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $manifest->attributes()->type . '_INSTALLED');

		$message = array(
			'id' => $eid,
			'extension_name' => (string) $manifest->name,
			'userid'         => $user->id,
			'username'       => $user->username,
			'action'         => 'install',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On uninstalling extensions logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called when an extension is uninstalled
	 *
	 * @param   JInstaller  $installer  Installer instance
	 * @param   integer     $eid        Extension id
	 * @param   integer     $result     Installation result
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterUninstall($installer, $eid, $result)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$user     = JFactory::getUser();
		$manifest = $installer->get('manifest');

		$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $manifest->attributes()->type . '_UNINSTALLED');

		$message = array(
			'id' => $eid,
			'extension_name' => (string) $manifest->name,
			'userid'         => $user->id,
			'username'       => $user->username,
			'action'         => 'install',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On updating extensions logging method
	 * This method adds a record to #__user_logs contains (message, date, context, user)
	 * Method is called when an extension is updated
	 *
	 * @param   JInstaller  $installer  Installer instance
	 * @param   integer     $eid        Extension id
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterUpdate($installer, $eid)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$user     = JFactory::getUser();
		$manifest = $installer->get('manifest');

		$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $manifest->attributes()->type . '_UPDATED');

		$message = array(
			'id'             => $eid,
			'extension_name' => (string) $manifest->name,
			'userid'         => $user->id,
			'username'       => $user->username,
			'action'         => 'update',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On Saving extensions logging method
	 * Method is called when an extension is being saved
	 *
	 * @param   string   $context  The extension
	 * @param   JTable   $table    DataBase Table object
	 * @param   boolean  $isNew    If the extension is new or not
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterSave($context, $table, $isNew)
	{
		if (!$this->checkLoggable($this->app->input->get('option')))
		{
			return;
		}

		$params = UserlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		if ($isNew)
		{
			$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $params->type_title . '_ADDED');
			$action             = 'add';
		}
		else
		{
			$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $params->type_title . '_UPDATED');
			$action             = 'update';
		}

		$user = JFactory::getUser();

		$message = array(
			'id'             => $table->get($params->id_holder),
			'title'          => $table->get($params->title_holder),
			'extension_name' => $table->get($params->title_holder),
			'userid'         => $user->id,
			'username'       => $user->username,
			'action'         => $action
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On Deleting extensions logging method
	 * Method is called when an extension is being deleted
	 *
	 * @param   string  $context  The extension
	 * @param   JTable  $table    DataBase Table object
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onExtensionAfterDelete($context, $table)
	{
		if (!$this->checkLoggable($this->app->input->get('option')))
		{
			return;
		}

		$params = UserlogsHelper::getLogContentTypeParams($context);

		// Not found a valid content type, don't process further
		if ($params === null)
		{
			return;
		}

		$messageLanguageKey = strtoupper('PLG_SYSTEM_USERLOGS_' . $params->type_title . '_DELETED');
		$user = JFactory::getUser();

		$message = array(
			'title' => $table->get($params->title_holder),
			'userid'   => $user->id,
			'username' => $user->username,
			'action'   => 'delete',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On saving user data logging method
	 *
	 * Method is called after user data is stored in the database.
	 * This method logs who created/edited any user's data
	 *
	 * @param   array    $user     Holds the new user data.
	 * @param   boolean  $isnew    True if a new user is stored.
	 * @param   boolean  $success  True if user was succesfully stored in the database.
	 * @param   string   $msg      Message.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserAfterSave($user, $isnew, $success, $msg)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$jUser = JFactory::getUser();

		if ($isnew)
		{
			$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_ADDED';
			$action             = 'add';
		}
		else
		{
			$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_ADDED_UPDATED';
			$action             = 'update';
		}

		$message = array(
			'id'       => $user['id'],
			'name'     => $user['name'],
			'userid'   => $jUser->id,
			'username' => $jUser->username,
			'action'   => $action,
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On deleting user data logging method
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   array    $user     Holds the user data
	 * @param   boolean  $success  True if user was succesfully stored in the database
	 * @param   string   $msg      Message
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserAfterDelete($user, $success, $msg)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_DELETED';
		$jUser              = JFactory::getUser();

		$message = array(
			'id'       => $user['id'],
			'name'     => $user['name'],
			'userid'   => $jUser->id,
			'username' => $jUser->username,
			'action'   => 'delete_user',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On after save user group data logging method
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   string   $context  The context
	 * @param   JTable   $table    DataBase Table object
	 * @param   boolean  $isNew    Is new or not
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserAfterSaveGroup($context, $table, $isNew)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		if ($isNew)
		{
			$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_GROUP_ADDED';
			$action             = 'add_user_group';
		}
		else
		{
			$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_GROUP_UPDATED';
			$action             = 'update_user_group';
		}

		$user = JFactory::getUser();

		$message = array(
			'id'       => $table->id,
			'name'     => $table->title,
			'userid'   => $user->id,
			'username' => $user->username,
			'action'   => $action,
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * On deleting user group data logging method
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   array    $group    Holds the group data
	 * @param   boolean  $success  True if user was succesfully stored in the database
	 * @param   string   $msg      Message
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserAfterDeleteGroup($group, $success, $msg)
	{
		$context = $this->app->input->get('option');

		if (!$this->checkLoggable($context))
		{
			return;
		}

		$user = JFactory::getUser();

		$messageLanguageKey = 'PLG_SYSTEM_USERLOGS_USER_GROUP_DELETED';

		$message = array(
			'id'       => $group['id'],
			'title'    => $group['title'],
			'userid'   => $user->id,
			'username' => $user->username,
			'action'   => 'delete',
		);

		$this->addLogsToDb(array($message), $messageLanguageKey, $context);
	}

	/**
	 * Adds additional fields to the user editing form for logs e-mail notifications
	 *
	 * @param   JForm  $form  The form to be altered.
	 * @param   mixed  $data  The associated data for the form.
	 *
	 * @return  boolean
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onContentPrepareForm($form, $data)
	{
		if (!$form instanceof JForm)
		{
			$this->subject->setError('JERROR_NOT_A_FORM');

			return false;
		}

		$formName = $form->getName();

		$allowedFormNames = array(
			'com_users.profile',
			'com_admin.profile',
		);

		if (!in_array($formName, $allowedFormNames) || !JFactory::getUser()->authorise('core.viewlogs'))
		{
			return true;
		}

		$lang = JFactory::getLanguage();
		$lang->load('plg_system_userlogs', JPATH_ADMINISTRATOR);

		JForm::addFormPath(dirname(__FILE__) . '/forms');
		$form->loadFile('userlogs', false);
	}

	/**
	 * Send notification emails about the action log
	 *
	 * @param   array   $messages  The logged messages
	 * @param   string  $username  The username
	 * @param   string  $context   The Context
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function sendNotificationEmails($messages, $username, $context)
	{
		$query = $this->db->getQuery(true);

		$query->select('a.email, a.params')
			->from($this->db->quoteName('#__users', 'a'))
			->where($this->db->quoteName('params') . ' LIKE ' . $this->db->quote('%"logs_notification_option":"1"%'));

		$this->db->setQuery($query);

		try
		{
			$users = $this->db->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			JError::raiseWarning(500, $e->getMessage());

			return;
		}

		$recipients = array();

		foreach ($users as $user)
		{
			$userParams = json_decode($user->params, true);
			$extensions = $userParams['logs_notification_extensions'];

			if (in_array(strtok($context, '.'), $extensions))
			{
				$recipients[] = $user->email;
			}
		}

		if (empty($recipients))
		{
			return;
		}

		$layout = new JLayoutFile('plugins.system.userlogs.layouts.logstable', JPATH_ROOT);
		$extension = UserlogsHelper::translateExtensionName(strtoupper(strtok($context, '.')));

		foreach ($messages as $message)
		{
			$message->extension = $extension;
			$message->message = UserlogsHelper::getHumanReadableLogMessage($message);
		}

		$displayData = array(
			'messages' => $messages,
			'username' => $username,
		);

		$body = $layout->render($displayData);
		$mailer = JFactory::getMailer();
		$mailer->addRecipient($recipients);
		$mailer->setSubject(JText::_('PLG_SYSTEM_USERLOGS_EMAIL_SUBJECT'));
		$mailer->isHTML(true);
		$mailer->Encoding = 'base64';
		$mailer->setBody($body);

		if (!$mailer->Send())
		{
			$this->app->enqueueMessage(JText::_('JERROR_SENDING_EMAIL'), 'warning');
		}
	}

	/**
	 * Runs after the HTTP response has been sent to the client and delete log records older than certain days
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onAfterRespond()
	{
		$daysToDeleteAfter = (int) $this->params->get('logDeletePeriod', 0);

		if ($daysToDeleteAfter <= 0)
		{
			return;
		}

		$deleteFrequency = 3600 * 24; // The delete frequency will be once per day

		// Do we need to run? Compare the last run timestamp stored in the plugin's options with the current
		// timestamp. If the difference is greater than the cache timeout we shall not execute again.
		$now  = time();
		$last = (int) $this->params->get('lastrun', 0);

		if (abs($now - $last) < $deleteFrequency)
		{
			return;
		}

		// Update last run status
		$this->params->set('lastrun', $now);

		$db    = JFactory::getDbo();
		$query = $db->getQuery(true)
			->update($db->qn('#__extensions'))
			->set($db->qn('params') . ' = ' . $db->q($this->params->toString('JSON')))
			->where($db->qn('type') . ' = ' . $db->q('plugin'))
			->where($db->qn('folder') . ' = ' . $db->q('system'))
			->where($db->qn('element') . ' = ' . $db->q('userlogs'));

		try
		{
			// Lock the tables to prevent multiple plugin executions causing a race condition
			$db->lockTable('#__extensions');
		}
		catch (Exception $e)
		{
			// If we can't lock the tables it's too risky to continue execution
			return;
		}

		try
		{
			// Update the plugin parameters
			$result = $db->setQuery($query)->execute();

			$this->clearCacheGroups(array('com_plugins'), array(0, 1));
		}
		catch (Exception $exc)
		{
			// If we failed to execite
			$db->unlockTables();
			$result = false;
		}

		try
		{
			// Unlock the tables after writing
			$db->unlockTables();
		}
		catch (Exception $e)
		{
			// If we can't lock the tables assume we have somehow failed
			$result = false;
		}

		// Abort on failure
		if (!$result)
		{
			return;
		}

		$daysToDeleteAfter = (int) $this->params->get('logDeletePeriod', 0);

		if ($daysToDeleteAfter > 0)
		{
			$conditions = array($db->quoteName('log_date') . ' < DATE_SUB(NOW(), INTERVAL ' . $daysToDeleteAfter . ' DAY)');

			$query->clear()
				->delete($db->quoteName('#__user_logs'))->where($conditions);
			$db->setQuery($query);

			try
			{
				$db->execute();
			}
			catch (RuntimeException $e)
			{
				// Ignore it
				return;
			}
		}
	}

	/**
	 * Clears cache groups. We use it to clear the plugins cache after we update the last run timestamp.
	 *
	 * @param   array  $clearGroups   The cache groups to clean
	 * @param   array  $cacheClients  The cache clients (site, admin) to clean
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function clearCacheGroups(array $clearGroups, array $cacheClients = array(0, 1))
	{
		$conf = JFactory::getConfig();

		foreach ($clearGroups as $group)
		{
			foreach ($cacheClients as $client_id)
			{
				try
				{
					$options = array(
						'defaultgroup' => $group,
						'cachebase'    => $client_id ? JPATH_ADMINISTRATOR . '/cache' :
							$conf->get('cache_path', JPATH_SITE . '/cache')
					);

					$cache = JCache::getInstance('callback', $options);
					$cache->clean();
				}
				catch (Exception $e)
				{
					// Ignore it
				}
			}
		}
	}
}
