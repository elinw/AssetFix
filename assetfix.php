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

//ini_set('display_errors','1');
error_reporting(-1);
ini_set('display_errors', 'On');

// Set flag that this is a parent file.
// We are a valid Joomla entry point.
define('_JEXEC', 1);

// Setup the base path related constants.
define('JPATH_BASE', dirname(__FILE__));
define('JPATH_SITE', JPATH_BASE);
define('JPATH_CONFIGURATION',JPATH_BASE);
define('JPATH_CACHE',JPATH_BASE . '/cache');

// Bootstrap the application.
require JPATH_BASE . '/libraries/import.php';


// Import the JApplicationWeb class from the platform.
// IS_MAC is not defined in the CMS 3 version of the platform.
if (!defined('IS_MAC'))
{
	define('JPATH_LIBRARIES', JPATH_PLATFORM);
	require JPATH_BASE . '/libraries/import.legacy.php';
	require JPATH_BASE . '/libraries/cms.php';

	// Import the JApplicationWeb class from the platform.
	JLoader::import('joomla.application.web');
	JLoader::import('cms.helper.tags');
	JLoader::import('cms.table.corecontent');
	JLoader::import('joomla.observer.mapper');
	// Categories is in legacy for CMS 3 so we have to check there.
	JLoader::registerPrefix('J', JPATH_PLATFORM . '/legacy');
	JLoader::Register('J', JPATH_PLATFORM . '/cms');
}
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
			<p>This is an unofficial way of fixing the asset table for extensions, categories and articles</p>
			<p>It attempts to fix some of the reported issues in asset tables, but is not guaranteed to fix everything</p>'
		);

			$this->_db = JFactory::getDBO();

			$contenttable =  JTable::getInstance('Content');
			$asset = JTable::getInstance('Asset');

			$asset->loadByName('root.1');
			if ($asset)
			{
				$rootId = (int) $asset->id;
			}

			if ($rootId && ($asset->level != 0 || $asset->parent_id != 0))
			{
				self::fixRoot($rootId);
			}

			if (!$asset->id)
			{
				$rootId = self::getAssetRootId();
				self::fixRoot($rootId);
			}

			if ($rootId === false)
			{
				// Should the row just be inserted here? 
				$this->appendBody('<p>There is no valid root. Please manually create a root asset and rerun.</p>');
			}
			if ($rootId)
			{
				// Now let's make sure that the components  make sense
				$query = $this->_db->getQuery(true);
				$query->select('extension_id, name');
				$query->from($this->_db->quoteName('#__extensions'));
				$query->where($this->_db->quoteName('type') . ' = ' . $this->_db->quote('component'));
				$this->_db->setQuery($query);
				$components = $this->dbo->loadObjectList();

				foreach ($components as $component)
				{
					$asset->reset();
					$asset->loadByName($component->name);

					if ($asset && ($asset->parent_id !=  $rootId || $asset->level != 1))
					{
						self::fixExtensionAsset($asset,$rootId);
						$this->appendBody('<p>This asset for this extension was fixed: ' . $component->name . '</p>');
					}
					elseif (!$asset)
					{
						$this->appendBody('<p>This extension is missing an asset: ' . $component->name . '</p>');
					}
				}

				// Let's rebuild the categories tree
				JTable::getInstance('Category')->rebuild();

				// Although we have rebuilt it may not have fully worked. Let's do some extra checks.
				$asset = JTable::getInstance('Asset');
				
				$assetTree = $asset->getTree(1);

				// Get all the categories as objects
				$queryc = $this->_db->getQuery(true);
				$queryc->select('id, asset_id, parent_id');
				$queryc->from('#__categories');
				$this->_db->setQuery($queryc);
				$categories = $this->dbo->loadObjectList();

				// Create an array of just level 1 assets that look like the are extensons. 

				$extensionAssets = array();

				foreach ($assetTree as $aid => $assetData)
				{
						// Now we will make a list of components based on the assets table not the extensions table.
						if (substr($assetData->name, 0, 4) === 'com_' && $assetData->level ==1)
						{
								$extensionAssets[$assetData->title] = $assetData->id;
						}
				}

				foreach ($assetTree as $assetData)
				{
					// Assume the root asset is valid.
					if ($assetData->name != 'root.1')
					{
						// There have been some reports of misnamed contact assets.
						if (strpos($assetData->name, 'contact_details') != false)
						{
							str_replace($assetData->name, 'contact_details', 'contact');
						}

						// Now handle categories with parent_id of 0 or 1
						if (strpos($assetData->name, 'category') != false)
						{
							$catFixCount = 0;
							$fixedCats = array();
							// Just assume that they are top level categories.
							// We are also goingto fix parent_id of 1 since some people in the forums did this to temporarily
							// fix a problem and also categories should never have a parent_id of 1.
							if ($assetData->parent_id == 0 || $assetData->parent_id == 1)
							{
								$catFixCount += 1;
								$explodeAssetName = explode('.', $assetData->name);
								$assetData->parent_id = $extensionAssets[$explodeAssetName[0]];
								$fixedCats[] = $assetData->id;
	
								$asset->load($assetData->id);
								// For categories the ultimate parent is the extension
								$asset->parent_id = $extensionAssets[$explodeAssetName[0]];
								$asset->store();
								$asset->reset();

								$this->appendBody('<p>The assets for the following category was fixed:' . $assetData->name . ' You will want to
								check the category manager to make sure any nesting you require is in place.');
							}
						}
					}
				}

				// Rebuild again as a final check to clear up any newly created inconsistencies.
                JTable::getInstance('Category')->rebuild();
				$this->appendBody('<p>Categories were successfully finished.</p>');

				// Now we will start work on the articles
				$query = $this->_db->getQuery(true);
				$query->select('id, asset_id');
				$query->from('#__content');
				$this->_db->setQuery($query);
				$articles = $this->dbo->loadObjectList();

				foreach ($articles as $article)
				{
					$asset->id = 0;
					$asset->reset();
					
					// We're going to load the articles by asset name.
					if ($article->id > 0)
					{
						$asset->loadByName('com_content.article.' . (int) $article->id);
						$query = $this->_db->getQuery(true);
						$query->update($this->_db->quoteName('#__content'));
						$query->set($this->_db->quoteName('asset_id') . ' = ' . (int) $asset->id);
						$query->where('id = ' . (int) $article->id);
						$this->dbo->setQuery($query);
						$this->dbo->query();
					}

					//  JTableAssets can clean an empty value for asset_id but not a 0 value. 
					if ($article->asset_id == 0)
					{
						$article->asset_id = '';
					}
					$contenttable->load($article->id);
					$contenttable->store();
				}

				$this->appendBody('<p>Article assets successfully finished.</p>');

			$this->appendBody('</li>');
			$this->appendBody('</li>
			</ul>');
			}

		// Finish up the HTML response.
		$this->appendBody('</body>')
			->appendBody('</html>');
	}

	protected function fixRoot($rootId)
	{
		// Set up the proper nested values for root
		$queryr = $this->_db->getQuery(true);
		$queryr->update($this->_db->quoteName('#__assets'));
		$queryr->set($this->_db->quoteName('parent_id') . ' = 0 ')
			->set($this->_db->quoteName('level') . ' =  0 ' )
			->set($this->_db->quoteName('lft') . ' = 1 ')
			->set($this->_db->quoteName('name') . ' = ' . $this->_db->quote('root.' . (int) $rootId));
		$queryr->where('id = ' . (int) $rootId);
		$this->dbo->setQuery($queryr);
		$this->dbo->query();

		return;
	}

	/**
	 * Fix the asset record for extensions
	 * 
	 * @param  JTableAsset  $asset   The asset table object
	 * @param   integer     $rootId  The primary key value for the root id, usually 1.
	 *
	 * @return  mixed  The primary id of the root row, or false if not found and the internal error is set.
	 *
	 * @since   11.1
	 */
	protected function fixExtensionAsset($asset, $rootId = 1)
	{
		// Set up the proper nested values for an extension
		$querye = $this->_db->getQuery(true);
		$querye->update($this->_db->quoteName('#__assets'));
		$querye->set($this->_db->quoteName('parent_id') . ' =  ' . $rootId )
			->set($this->_db->quoteName('level') . ' = 1 ' );
		$querye->where('name = ' . $this->_db->quote($asset->name));
		$this->dbo->setQuery($querye);
		$this->dbo->query();

		return;
	}

	/**
	 * Gets the ID of the root item in the tree
	 *
	 * @return  mixed  The primary id of the root row, or false if not found and the internal error is set.
	 *
	 * @since   11.1
	 */
	public function getAssetRootId()
	{
		// Test for a unique record with parent_id = 0
		$query = $this->dbo->getQuery(true);
		$query->select($this->dbo->quote('id'))
			->from($this->dbo->quoteName('#__assets'))
			->where($this->dbo->quote('parent_id') .' = 0');

		$result = $this->dbo->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		// Test for a unique record with lft = 0
		$query = $this->dbo->getQuery(true);
		$query->select('id')
			->from($this->_db->quoteName('#__assets'))
			->where($this->_db->quote('lft') . ' = 0');

		$result = $this->_db->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		// Test for a unique record alias = root
		$query = $this->_db->getQuery(true);
		$query->select($this->_db->quoteName('id'))
			->from($this->_db->quoteName('#__assets'))
			->where('name LIKE ' . $this->_db->quote('root%'));

		$result = $this->_db->setQuery($query)->loadColumn();

		if (count($result) == 1)
		{
			return $result[0];
		}

		$e = new UnexpectedValueException(sprintf('%s::getRootId', get_class($this)));
		//$this->setError($e);

		return false;
	}
}
JApplicationWeb::getInstance('Assetfix')->execute();
