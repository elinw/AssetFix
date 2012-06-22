<?php
/**
 * A JApplicationWeb application built on the Joomla Platform.
 *
 * To run this place it in the root of your Joomla CMS installation.
 * This application is buil
 *
 * @package    Joomla.AssetFix
 * @copyright  Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

ini_set('display_errors','1');

// Set flag that this is a parent file.
// We are a valid Joomla entry point.
define('_JEXEC', 1);

// Setup the base path related constants.
define('JPATH_BASE', dirname(__FILE__));
define('JPATH_SITE', JPATH_BASE);
define('JPATH_CONFIGURATION',JPATH_BASE);

// Bootstrap the application.
require JPATH_BASE . '/libraries/import.php';


// Import the JApplicationWeb class from the platform.
jimport('joomla.application.web');

/**
 * This class checks some common situations that occur when the asset table is corrupted.
 */
// Instantiate the application.
class Assetfix extends JApplicationWeb
{
	/**
	 * Overrides the parent doExecute method to run the web application.
	 *
	 * This method should include your custom code that runs the application.
	 *
	 * @return  void
	 *
	 * @since   11.3
	 */

	public function __construct()
	{
		// Call the parent __construct method so it bootstraps the application class.
		parent::__construct();
		require_once JPATH_CONFIGURATION.'/configuration.php';

		jimport('joomla.database.database');

		// System configuration.
		$config = JFactory::getConfig();

		// Note, this will throw an exception if there is an error
		// Creating the database connection.
		$this->dbo = JDatabase::getInstance(
			array(
				'driver' => $config->get('dbtype'),
				'host' => $config->get('host'),
				'user' => $config->get('user'),
				'password' => $config->get('password'),
				'database' => $config->get('db'),
				'prefix' => $config->get('dbprefix'),
			)
		);

	}

	protected function doExecute()
	{
		// Initialise the body with the DOCTYPE.
		$this->setBody(
			'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
		);

		$this->appendBody('<html>')
			->appendBody('<head>')

			->appendBody('</head>')
			->appendBody('<body style="font-family:verdana; margin-left: 30px; width: 500px;">');

		$this->appendBody('<h1>Asset Fix</h1>
		<p>This is an unofficial way of fixing the asset table for categories and articles</p>');

                $this->dbo = JFactory::getDBO();

				// First let's rebuild the categories 
                JTable::getInstance('Category')->rebuild();

				$this->appendBody('<p>Categories finished.</p>');
				// Now we will start work on the articles
				$query = $this->dbo->getQuery(true);
				$query->select('id, asset_id');
				$query->from('#__content');
				$this->dbo->setQuery($query);
				$articles = $this->dbo->loadObjectList();

				$table =  JTable::getInstance('Content');

				$asset = JTable::getInstance('Asset');

				foreach ($articles as $article)
				{
					$asset->id = 0;
					$asset->reset();
					
					// We're going to load the articles by asset name.
					if ($article->id > 0)
					{
						$asset->loadByName('com_content.article.' . (int) $article->id);
						$query = $this->dbo->getQuery(true);
						$query->update($this->dbo->quoteName('#__content'));
						$query->set($this->dbo->quoteName('asset_id') . ' = ' . (int)$asset->id);
						$query->where('id = ' . (int) $article->id);
						$this->dbo->setQuery($query);
						$this->dbo->query();
					}

					// 
					if ($article->asset_id == 0)
					{
						$article->asset_id = '';
					}
					$table->load($article->id);
					$table->store();
				}

				$this->appendBody('<p>Article assets finished.</p>');
	
		$this->appendBody('</li>');
		$this->appendBody('</li>
		</ul>');
		// Finished up the HTML repsonse.
		$this->appendBody('</body>')
			->appendBody('</html>');
	}
}
JApplicationWeb::getInstance('Assetfix')->execute();
