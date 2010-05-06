<?php

if(!defined('EZCOPY_APP_PATH'))
{
	define('EZCOPY_APP_PATH', '');
}

set_include_path(get_include_path() . PATH_SEPARATOR . EZCOPY_APP_PATH . 'lib/phpseclib0.1.5');

include_once(EZCOPY_APP_PATH . 'classes/ezcomponents.php');
include_once('Net/SSH2.php');
include_once('Net/SFTP.php');

class eZCopy
{
	var $dbRootUser;
	var $dbRootPass;
	var $dbDumpDir;
	
	// If this var is set to true, commands will be executed directly (locally)
	// with the exec command instead of through the current SSH connection.
	var $local;
	
	// The base path of the eZ installation. This will be set by ezcopy, but can be
	// overriden
	var $ezBasePath;
	
	function eZCopy()
	{
		$this->output = new ezcConsoleOutput();
		
		$this->cfg = ezcConfigurationManager::getInstance();
		$this->cfg->init( 'ezcConfigurationIniReader', 'settings' );
		
		$this->dbRootUser = $this->cfg->getSetting('ezcopy', 'DBRoot', 'username');
		$this->dbRootPass = $this->cfg->getSetting('ezcopy', 'DBRoot', 'password');
		
		$this->checkpoint = false;
		if($this->cfg->hasSetting( 'ezcopy', 'General', 'checkpoints' ))
		{
			if ( $this->cfg->getSetting(  'ezcopy', 'General', 'checkpoints' ) == 'true' )
			{
				$this->checkpoint = true;
			}
		}
		
		$this->dbDumpDir = '_dbdump/';
		
		$this->local = false;
	}

	
	function getBasePath()
	{
		return $this->ezBasePath;
	}
	
	function setBasePath($path)
	{
		$this->ezBasePath 	= $path;
	}
	
	function setIsLocal($value, $path = '')
	{
		$this->local 		= $value;
		$this->setBasePath($path);
	}
	
	function exec($cmd)
	{
		// if we are working locally
		if($this->local)
		{
			// execute the command locally
			return exec($cmd);
		}
		
		// if not, execute the command through the ssh connection
		else
		{
			return $this->s->exec($cmd);	
		}
	}
	
	function describeUsage()
	{
		$this->log("\nUSAGE\n", 'heading');
		$this->log("php ezcopy [action] [account_username]\n\n");
		$this->log("AVAILABLE ACTIONS\n", 'heading');
		$this->log("- download-install     Downloads and installs the entire eZ installation.\n");
		$this->log("- download             Downloads entire eZ installation.\n");
		$this->log("- install              Installs the site from a locally stored archive. See docs for details.\n");
		$this->log("- delete               Deletes the entire eZ installation.\n");
		$this->log("- update-db            Updates only the database on the target server.\n");
		$this->log("- download-db          Downloads the sql-dumps\n" );
		$this->log("- list                 Lists the configured accounts.\n");
		$this->log("- package              Creates an tarball with the site and database on the source server.\n\n" );
	}
	
	function fetchSettings($identifier)
	{
		if(!$this->cfg->hasGroup('ezcopy', 'Account_' . $identifier))
		{
			$this->log("No configuration details for this account. Please add the account to ezcopy.ini", 'critical');
		}
		else
		{
			return $this->cfg->getSettingsInGroup( 'ezcopy', 'Account_' . $identifier );
		}
	}
		
	function iniInstance($file, $basePath = false)
	{
		if(!$basePath)
		{
			$basePath = $this->getNewDistroPathName();
		}
		set_include_path(get_include_path() . PATH_SEPARATOR . $basePath);
		
		// include files in new distro for parsing ini files
		include_once($basePath . '/lib/ezutils/classes/ezdebug.php');
		include_once($basePath . '/lib/ezutils/classes/ezsys.php');
		include_once($basePath . '/lib/ezfile/classes/ezdir.php');
		include_once($basePath . '/lib/ezfile/classes/ezfile.php');
		include_once($basePath . '/lib/ezutils/classes/ezini.php');
		
		// initate INI files
		$useTextCodec 	= false;
		$ini 			= eZINI::fetchFromFile( $basePath . $file, $useTextCodec );
		return $ini;
	}
	
	function getAccountList()
	{
		return $this->cfg->getSetting('ezcopy', 'General', 'account_list');
	}
	
	function getSiteAccessMatchOrderSetting()
	{
		if($this->cfg->hasSetting( 'ezcopy', 'General', 'match_order' ))
		{
			return $this->cfg->getSetting('ezcopy', 'General', 'match_order');
		}
		else
		{
			return false;	
		}
	}
	
	function updateSiteAccessMatchOrder()
	{
		$matchOrder = $this->getSiteAccessMatchOrderSetting();
		
		if($matchOrder)
		{		
			$this->log("Updating site access match order ");
			
			$ezPath = $this->data['document_root'] . $this->data['ssh_user'];
			
			// get instance of site.ini override file
			$ini = $this->iniInstance('/settings/override/site.ini.append.php', $ezPath);
			
			// set match order
			$ini->setVariable('SiteAccessSettings', 'MatchOrder', $matchOrder);
			
			// save changes in ini file
			if(!$ini->save())
			{
				$this->log("Unable to store changes to INI file.\n", 'critical');
			}
			
			$this->log("OK\n", 'ok');
		}
	}
		
	function unPackTar($sourceFile, $destinationPath)
	{
		// replacement for components archive functionality until bug is fixed
		$this->log("Extracting $sourceFile ");

		$cmd = "cd " . $destinationPath . "/;tar xfvz " . $sourceFile;
		exec($cmd);
		
		$this->log("OK\n", 'ok');
		
		// TODO: Commented out until this issue is resolved:
		// http://issues.ez.no/IssueView.php?Id=13501
		/*
		$archive = ezcArchive::open( $sourceFile );
		
		$this->log("Counting entries in " . $sourceFile . " ");
		
		$entryCount = count($archive->getListing());
		
		$this->log('OK (' . $entryCount . ")\n", 'ok');	

		$this->log("Extracting $sourceFile\n");
		
		$output = new ezcConsoleOutput();
		$bar 	= new ezcConsoleProgressbar( $output, (int) $entryCount );
		
		$currentStep = 0;
		
		foreach( $archive as $entry )
		{
			$bar->advance();
			$archive->extractCurrent( $destinationPath );
		}
		
		$bar->finish();
		$this->log("\n");
		*/
	}
	
	function selectAccount($identifier)
	{
		$this->data = $this->fetchSettings($identifier);
		
		$this->data['identifier'] = $identifier;
		
		// TODO: Fetch password (and server name) from nethosting.no
		
		if(!isset($this->data['mysql_pass']))
		{
			$this->data['mysql_pass']	= $this->data['ssh_pass'];
		}
		
		if(!isset($this->data['mysql_user']))
		{
			$this->data['mysql_user']	= $this->data['ssh_user'] . "_ezp";
		}
		
		if(!isset($this->data['mysql_db']))
		{
			$this->data['mysql_db']		= $this->data['mysql_user'];
		}
		
		if(!isset($this->data['mysql_host']))
		{
			$this->data['mysql_host']	= 'localhost';
		}
		
		if(!isset($this->data['path_to_ez']))
		{
			$this->data['path_to_ez']	= "www/ezpublish-" . $this->data['ez_version'];		
		}
		
		if(isset($this->data['ssh_user']))
		{
			$this->data['archive_name']	= $this->data['ssh_user'] . '.tar.gz';	
		}
		else
		{
			$this->data['archive_name']	= '';
		}
		
		if(!isset($this->data['local_file']))
		{
			$this->data['local_file']	= $this->data['archive_name'];
		}
		
		$this->data['document_root'] = $this->getLocalDocumentRoot();
		
		$this->data['mysql_file']	= $this->data['mysql_db'] . '.sql';
		$this->data['remote_file']	= $this->data['archive_name'];
	}
	
	function getLocalDocumentRoot()
	{
		if($this->cfg->hasSetting( 'ezcopy', 'General', 'document_root' ))
		{
			return $this->cfg->getSetting('ezcopy', 'General', 'document_root');
		}
		else
		{
			return '';	
		}
	}
	
	function actionListInstallations()
	{
		// get account list
		$accountList = $this->getAccountList();
		
		$installed = array();
		$notInstalled = array();
		foreach($accountList as $accountName)
		{
			$this->selectAccount($accountName);
			
			$path = $this->data['document_root'] . $this->data['ssh_user'];

			if(file_exists($path))
			{
				$installed[] = $accountName;
			}
			else
			{
				$notInstalled[] = $accountName;
			}
		}
		
		$this->log("\n");
		
		if(count($installed) > 0)
		{
			// list installed accounts
			$this->log("INSTALLED ACCOUNTS:\n", '9');
			foreach($installed as $accountName)
			{
				$this->log('- ' . $accountName. "\n");
			}	
		}
		
		if(count($notInstalled) > 0)
		{
			// list accounts not installed
			$this->log("\nACCOUNTS NOT INSTALLED:\n", '9');
			foreach($notInstalled as $accountName)
			{
				$this->log('- ' . $accountName. "\n");
			}
		}
		
		$this->log("\n");
	}
	
	function actionDownloadDatabase( $identifier )
	{
		
		// get details for account
		$this->selectAccount($identifier);
		
		// log in
		$this->logIn();
				
		// dump database
		$this->dumpDatabase();
		
		// download database
		$this->downloadDatabase();
	}
	function actionUpdateDatabase($identifier)
	{
		// get details for account
		$this->selectAccount($identifier);
		
		// log in
		$this->logIn();
				
		// dump database
		$this->dumpDatabase();
		
		// download database
		$this->downloadDatabase();
		
		// remote database cleanup
		$this->remoteDatabaseCleanup();
		
		// apply database
		$this->applyDatabase();
		
		// local cleanup
		$this->localDatabaseCleanUp();
		
		// clear cache
		$this->clearLocalCache( $this->data['document_root'] . $this->data['ssh_user'] );
		
		$this->log("DATABASE UPDATED\n", 'ok');
	}
	
	function actionCreateArchive( $identifier )
	{
		$this->actionDownloadAll( $identifier, false );
	}
	function actionDownloadAll($identifier, $download=true)
	{
		// get details for account
		$this->selectAccount($identifier);
		
		// log in
		$this->logIn();
		
		// dump database
		$this->dumpDatabase();
		
		// create archive
		$this->createArchive();
		
		if ( $download )
		{
			// spawn process for downloading archive
			$this->spawnArchiveDownload();
			
			// remote cleanup
			$this->remoteCleanUp();
			
			$this->log("Site archive at: " . $this->data['document_root'] . $this->data['local_file'] . "\n");
			
			$this->log("DOWNLOAD COMPLETED\n\n", 'ok');
		}
		else
		{
			$this->log( "ARCHIVE FILE IS MADE AND READY TO BE DOWNLOADED FROM THE SERVER\n", 'ok' );
			$this->log( "Remember to delete the tarball and the dump in _dbdump after you have downloaded the file\n\n", 'warning' );
		}
	}
	
	function actionInstallAll($identifier)
	{
		// get details for account
		$this->selectAccount($identifier);
		
		// log in
		$this->logIn();
		
		// check quota
		$this->checkQuota();
		
		// dump database
		$this->dumpDatabase();
		
		// create archive
		$this->createArchive();
		
		// spawn process for downloading archive
		$this->spawnArchiveDownload();
		
		// remote cleanup
		$this->remoteCleanUp();
		
		// unpack archive
		$this->unPackArchive();
		
		// create database
		$this->createDatabaseList();
		
		// grant user access to database
		$this->grantDBUserAccessList();
		
		// apply database
		$this->applyDatabases();
		
		// local cleanup
		$this->localCleanUp();
		
		// fix ez installation
		$this->fixEzInstall();
		
		$this->log("DOWNLOAD AND INSTALLATION COMPLETED\n\n", 'ok');
	}
	
	function actionInstall($identifier)
	{
		// get details for account
		$this->selectAccount($identifier);
		
		// unpack archive, keeping the original archive intact
		$this->unPackArchive(true);
		
		// create database
		$this->createDatabase();
		
		// grant user access to database
		$this->grantDBUserAccess();
		
		// apply database
		$this->applyDatabase();
		
		// local cleanup
		$this->localCleanUp();
		
		// fix ez installation
		$this->fixEzInstall();
		
		$this->log("INSTALLATION COMPLETED\n\n", 'ok');
	}
	
	function spawnArchiveDownload()
	{
		$this->log("Downloading archive...\n");
		
		$remoteFile = $this->data['remote_file'];
		$localFile 	= $this->data['document_root'] . $this->data['local_file'];
		
		$this->spawnDownload($remoteFile, $localFile, $this->data['archive_filesize']);
		
		$this->log(" OK\n", 'ok');
	}
	
	function getFileSize($path)
	{
		clearstatcache();
		return filesize($path);
	}
	
	function actionDeleteAll($identifier)
	{
		// get details for account
		$this->selectAccount($identifier);
		
		// delete local database
		$this->deleteLocalDatabase();
				
		// delete site directory
		$this->deleteLocalSite();
		
		$this->log("DELETE COMPLETED\n\n", 'ok');
	}
	
	function deleteLocalSite()
	{
		$this->log("Deleting site ");
		
		exec('rm -rf ' . $this->data['document_root'] . $this->data['ssh_user']);

		$this->log("OK\n", 'ok');
	}
	
	function deleteLocalDatabase()
	{
		$this->log("Deleting database and DB user ");

		$cmd = "echo \"drop database " . $this->data['mysql_db'] . "; " . 
		"revoke all privileges, grant option from '" . $this->data['mysql_user'] . "'@'localhost';" . 
		"drop user '" . $this->data['mysql_user'] . "'@'localhost';\" | " . $this->dbRootCmd();
				
		exec($cmd);
		
		$this->log("OK\n", 'ok');
	}
	
	function logIn()
	{
		$this->log("Logging in via SSH ");
		
		$this->s 		= new Net_SSH2($this->data['ssh_host']);
		
		if ($this->s->login($this->data['ssh_user'], $this->data['ssh_pass']))
		{
			$this->log("OK\n", 'ok');
			
			// get base path
			$this->data['base_path'] = trim($this->exec('pwd')) . "/";
			
			// set the base path
			$this->setBasePath($this->data['base_path'] . $this->data['path_to_ez'] . '/');
		}
		else
		{
			$this->log("Unable to log in\n", 'critical');
		}
	}
	
	function logInSFTP()
	{
		$this->log("Logging in via SFTP ");
		
		$this->sftp 		= new Net_SFTP($this->data['ssh_host']);
		
		if ($this->sftp->login($this->data['ssh_user'], $this->data['ssh_pass']))
		{
			$this->log("OK\n", 'ok');
		}
		else
		{
			$this->log("Unable to log in\n", 'critical');
		}
	}
	
	
	function checkQuota()
	{
		/*
		$result = trim($this->execCommandBlocking('quota'));
		
		if($result == "")
		{
			$this->log("Unable to detect account quota");
		}
		else
		{
			$this->log("Quota usage: ");
			
			$lineArray = explode("\n", $result);
			
			foreach($lineArray as $k => $line)
			{
				if($k > 1)
				{
					$fieldArray = split("[ ]+", $line);
					
					$this->log($fieldArray[1] . ": " . $this->MBFormat($fieldArray[2], 1, true) . " of " . $this->MBFormat($fieldArray[3], 1, true));
				}
			}
		}
		*/
	}
	function fetchDbList()
	{
		// fetch list of db accesses
		$result = array();
		$result[ $this->data[ 'mysql_db' ] ] = array( 	'User'		=> $this->data[ 'mysql_user' ],
														'Password'	=> $this->data[ 'mysql_pass' ],
														'Database'	=> $this->data[ 'mysql_db' ],
														'Server' 	=> $this->data[ 'mysql_host' ],
														'File'		=> $this->data[ 'mysql_file' ] );
		if ( isset($this->data[ 'additional_mysql' ]) and is_array( $this->data[ 'additional_mysql' ] ) )
		{
			foreach( $this->data[ 'additional_mysql' ] as $additional_mysql )
			{
				if ( trim($additional_mysql[ 'user' ]) == '' )
				{
					$user = $this->data[ 'mysql_user' ];
				}
				else
				{
					$user = $additional_mysql[ 'user' ];
				}
				if ( trim($additional_mysql[ 'pass' ]) == '' )
				{
					$pass = $this->data[ 'mysql_pass' ];
				}
				else
				{
					$pass = $additional_mysql[ 'pass' ];
				}
				if ( trim($additional_mysql[ 'host' ]) == '' )
				{
					$host = $this->data[ 'mysql_host' ];
				}
				else
				{
					$host = $additional_mysql[ 'host' ];
				}
				if ( trim( $additional_mysql[ 'db' ] ) != '' )
				{
					$result[$additional_mysql['db']] = array( 	'User'		=> $user,
																'Password'	=> $pass,
																'Database'	=> $additional_mysql['db'],
																'Server'	=> $host,
																'File'		=> $additional_mysql['db'].'.sql' );
				}
			}
		}
		return $result;
	}
	function dumpDatabase()
	{
		$this->log("Dumping database\n");
		
		// prepare the directory to which the databases will be dumped
		$this->prepareDBDumpDir();
		
		$dbList = $this->fetchDbList();
		
		foreach( $dbList as $db )
		{	
			// build and execute the database dump command
			$cmd = "cd " . $this->getBasePath() . ";mysqldump -h" . $db['Server'] . " -u" . $db['User'] . " -p" . $db['Password'] . " " . $db['Database'] . " > " . $this->dbDumpDir . $db['File'];
			$this->exec($cmd);
			
			// get the size of the file
			//$cmd = "stat -c%s " . $this->getBasePath() . $this->dbDumpDir . $db['File'];
			
			$cmd = "du -k " . $this->getBasePath() . $this->dbDumpDir . $db['File'];
			$filesizeinfo = $this->exec($cmd);
			$filesize = str_replace( $this->getBasePath() . $this->dbDumpDir . $db['File'], '', $filesizeinfo );
			$this->data['db_dump_filesize'] = trim($filesize)*1024;
			$this->log( $db[ 'Database' ] . ': OK (' . $this->MBFormat($this->data['db_dump_filesize']) . ")\n", 'ok');
		}
	}
	
	function prepareDBDumpDir()
	{
		// if the database dump dir does not already exist
		$dirPath = $this->getBasePath() . $this->dbDumpDir;
		
		// TODO: BUG - this does a local check, not a check on the server - d'oh!
		if(!file_exists($dirPath))
		{
			// create it
			$cmd = "cd " . $this->getBasePath() . ";mkdir " . $this->dbDumpDir . ";chmod 777 " . $this->dbDumpDir;
			$this->exec($cmd);
		}
	}
	
	function checkDiskUsage()
	{
		/*
		$result = trim($this->execCommandBlocking('cd ' . $this->data['base_path'] . $this->data['path_to_ez'] . ';du --summarize'));

		$fieldArray = split("[ ]+", $result);
		
		$this->log("Installation size is " . $this->MBFormat($fieldArray[0], true) . "\n");
		*/
	}
	
	function createArchive()
	{
		// $this->checkDiskUsage();
		
		$this->fileCount();
		
		$this->log("Creating archive ");
		
		// build command
		$command = "cd " . $this->data['base_path'] . $this->data['path_to_ez'] . ";tar -cf - * | gzip -c > " . $this->data['base_path'] . $this->data['archive_name'];
		
		// waits until the command is done
		$this->exec($command);
		
		// get the size of the file
		$fileSize = trim($this->exec("stat -c%s " . $this->data['archive_name']));
		
		$this->data['archive_filesize'] = $fileSize;
		
		$this->log("OK (" . $this->MBFormat($fileSize). ")\n", 'ok');
	}
	
	function fileCount()
	{
		$this->log("Counting files to archive ");
		
		// get the file count
		$cmd = "cd " . $this->data['base_path'] . $this->data['path_to_ez'] . ";ls -laR | wc -l";
		$fileCount = trim($this->exec($cmd));
		
		$this->log("OK (" . $fileCount. " files)\n", 'ok');
	}
	
	function downloadFile($remoteFile, $localFile)
	{
		$this->sftp->get($remoteFile, $localFile);
	}
	
	function downloadDatabase()
	{
		$this->spawnDBDownload();
	}
	
	function spawnDownload($remoteFile, $localFile, $remoteFileSize)
	{
		$cmd = $this->getPathToPHP() . " ./bin/download.php " . $this->data['identifier'] . " " . $remoteFile . " " . $localFile . " > /dev/null 2>&1 &";
				
		exec($cmd);
		
		// wait until the archive exist locally
		while(!file_exists($localFile))
		{
			usleep(1000);
		}
		
		$output = new ezcConsoleOutput();
		$bar 	= new ezcConsoleProgressbar( $output, (int) $remoteFileSize );
		
		$currentStep = 0;
		
		// until the file is completely downloaded
		do
		{
			// get current size of local file
			$currentFileSize = $this->getFileSize($localFile);

			$steps = $currentFileSize - $currentStep;
			
			$bar->advance(true, $steps);
			
			$currentStep = $currentFileSize;
			
			// wait for half  second
			usleep(500000);
			
		} while($currentFileSize < $remoteFileSize);
		
		$bar->finish(); 
	}
	
	function spawnDBDownload()
	{
		$this->log("Downloading database...\n");
		
		$dbList = $this->fetchDbList();
		foreach ( $dbList as $db )
		{
			$remoteFile = $this->data['base_path'] . $this->data['path_to_ez'] . '/' . $this->dbDumpDir . $db[ 'File' ];
			$localFile 	= $this->data['document_root'] . $this->data['ssh_user'] . "/" . $this->dbDumpDir . $db[ 'File' ];
			
			$this->spawnDownload($remoteFile, $localFile, $this->data['db_dump_filesize']);
			
			$this->log( "\n" .$db[ 'Database' ] . ": OK\n", 'ok');
		}
	}
	
	function remoteCleanUp()
	{
		$this->log("Deleting archive from the remote server ");
		
		$this->exec('rm ' . $this->data['remote_file']);

		$this->log("OK\n", 'ok');
		
		$this->remoteDatabaseCleanup();
	}
	
	function remoteDatabaseCleanup()
	{
		$this->log("Deleted database dump from the remote server ");
		$cmd = 'rm -rf ' . $this->data['base_path'] . $this->data['path_to_ez'] . "/" . $this->dbDumpDir;
		$this->exec($cmd);
		
		$this->log("OK\n", 'ok');
	}
	
	function unPackArchive($copyArchive = false)
	{
		// make directory where the site should be unpacked and move the tarball into the directory
		$target = $this->data['document_root'] . $this->data['ssh_user'] . "/" . $this->data['local_file'];
		$cmd = "mkdir " . $this->data['document_root'] . $this->data['ssh_user'] . ";";
		
		// if the archive should be copied
		if($copyArchive)
		{
			$cmd .= "cp";
		}
		
		// if not, move the archive
		else
		{
			$cmd .= "mv";
		}
		
		$cmd .= " " . $this->data['document_root'] . $this->data['local_file'] . " " . $target;
		exec($cmd);
		
		// unpack tarball
		$this->unPackTar($target, $this->data['document_root'] . $this->data['ssh_user']);
	}
	
	function dbUserPass()
	{
		$cmd = "-u " . $this->dbRootUser;
		
		if($this->dbRootPass != '')
		{
			$cmd .= " -p" . $this->dbRootPass;
		}
		
		return $cmd;
	}
	
	function dbRootCmd()
	{
		$cmd = "mysql " . $this->dbUserPass();; 
		
		return $cmd;
	}
	function createDatabaseList()
	{
		$dbList = $this->fetchDbList();
		foreach( $dbList as $db )
		{
			$this->createDatabase( $db['Database'] );
		}
	}
	function createDatabase($dbName = false)
	{
		if(!$dbName)
		{
			$dbName = $this->data['mysql_db'];
		}

		$this->log("Creating database $dbName ");
		
		$cmd = "echo \"create database if not exists " . $dbName . " default character set utf8 collate utf8_general_ci\" | " . $this->dbRootCmd();
				
		exec($cmd);
		
		$this->log("OK\n", 'ok');
	}
	
	function grantDBUserAccessList()
	{
		$dbList = $this->fetchDbList();
		foreach( $dbList as $db )
		{
			$this->grantDBUserAccess( $db['User'], $db['Password'], $db['Database'] );
		}
	}
	function grantDBUserAccess($user = false, $pass = false, $name = false)
	{
		if(!$user)
		{
			$user = $this->data['mysql_user'];
		}
		
		if(!$pass)
		{
			$pass = $this->data['mysql_pass'];
		}
		
		if(!$name)
		{
			$name = $this->data['mysql_db'];
		}
		
		$this->log("Granting DB user $user access to database $name ");
		
		$cmd = "echo \"grant all privileges on " . $name . ".* to " .
     	$user . "@localhost identified by '" . $pass . "'\" | " . $this->dbRootCmd();
	 	
     	exec($cmd);
		
		$this->log("OK\n", 'ok');
	}
	
	function getCopyLocation()
	{
		return $this->data['document_root'] . $this->data['ssh_user'] . "/";
	}
	
	function applyDatabases()
	{
		$dbList = $this->fetchDbList();
		foreach( $dbList as $db )
		{
			$dumpFile = $this->getCopyLocation() . $this->dbDumpDir . $db[ 'File' ];
			$this->applyDatabase( $db[ 'Database' ], $dumpFile );
		}
	}
	function applyDatabase($dbName = false, $sqlDumpFile = false)
	{
		if(!$sqlDumpFile)
		{
			$sqlDumpFile = $this->getCopyLocation() . $this->dbDumpDir . $this->data['mysql_file'];
		}
		
		if(!$dbName)
		{
			$dbName = $this->data['mysql_db'];
		}
		
		$this->log("Applying $sqlDumpFile to database $dbName ");
		
		// checkpoint 
		$this->checkpoint( 'Applying ' . $sqlDumpFile . ' to database ' . $dbName );
		
		exec($this->dbRootCmd() . " " . $dbName . " < " . $sqlDumpFile);
		
		$this->log("OK\n", 'ok');
	}
	
	function localCleanUp()
	{
		$this->log("Deleting archive from the local client ");
		
		unlink($this->data['document_root'] . $this->data['ssh_user'] . "/" . $this->data['local_file']);

		$this->log("OK\n", 'ok');
		
		$this->localDatabaseCleanUp();
		
		$this->updateSiteAccessMatchOrder();
	}
	
	function localDatabaseCleanUp()
	{
		$this->log("Deleting database dump from the local client ");
		
		$dbList = $this->fetchDbList();
		foreach( $dbList as $db )
		{
			$localDBFile = $this->data['document_root'] . $this->data['ssh_user'] . "/" . $this->dbDumpDir . $db[ 'File' ];
			unlink($localDBFile);
			$this->log( $db[ 'File' ] . "OK\n", 'ok');
		}
	}
	
	function fixEzInstall($path = false)
	{
		if(!$path)
		{
			$path = $this->data['document_root'] . $this->data['ssh_user'];
		}
		
		$this->log("Running modfix script ");
		exec("cd " . $path . ";./bin/modfix.sh", $modFixResult);
		$this->log("OK\n", 'ok');
		
		$this->clearLocalCache($path);
		
		$this->log("Giving 777 permissions to the var/ directory ");
		
		exec("chmod -R 777 " . $path . "/var/");
		
		$this->log("OK\n", 'ok');
	}
	function getPathToPHP( $version=5)
	{
		if($this->cfg->hasSetting( 'ezcopy', 'General', 'pathToPHP' . $version ))
		{
			$phpPath = $this->cfg->getSetting( 'ezcopy', 'General', 'pathToPHP' . $version );
			
			if ( $phpPath != '' )
			{
				return $phpPath;
			}
		}
		return 'php';
	}
	function clearLocalCache($path)
	{
		//$this->log("Clearing cache ");
		$this->manualAttentionNotificationList[] = 'You need to clear the cache!' . "\n";
	}
	function checkpoint($exitedOn, $extraText='', $forceCheckpoint=false)
	{
			if ( $this->checkpoint OR $forceCheckpoint)
			{
				$this->output->formats->question->color = 'blue';
				$question = new ezcConsoleQuestionDialog( $this->output );
				$question->options->text = $extraText . "Do you want to continue?";
				$question->options->format = 'question';
				$question->options->showResults = true;
				$question->options->validator = new ezcConsoleQuestionDialogCollectionValidator(
				array( "y", "n" ),
				"y",
				ezcConsoleQuestionDialogCollectionValidator::CONVERT_LOWER
				);
				
				// if the answer is yes
				if(ezcConsoleDialogViewer::displayDialog( $question ) == 'y')
				{
					return true;
				}
				else
				{
					$this->log( 'Exited on: ' . $exitedOn, 'critical' );
				}
			}
		return true;
	}
	function writeLog( $msg, $format='ok')
	{
		switch( $format )
		{
			case 'warning':
				$type 	= ezcLog::WARNING;
				break;
			case 'critical':
				$type	= ezcLog::ERROR;
				break;
			default:
				$type 	= ezcLog::INFO;
				break;
		}
		$log 	= ezcLog::getInstance();
	
	 	$logsFolder 	= $_SERVER["PWD"] ."/" . EZCOPY_APP_PATH . 'logs/';

	 	$accountFolder	= $logsFolder . $this->upgradeData['account_name'];
			
	 
	 	if ( !file_exists( $logsFolder ) )
	 	{
	 		mkdir( $logsFolder, 0777 );
	 	}
		
		// create log folder for the account, if not excists.
		if ( !file_exists( $accountFolder ) )
		{
			mkdir( $accountFolder, 0777 );
		}
		
	 	$writer = new ezcLogUnixFileWriter( $accountFolder, date( 'Y-m-d'). ".log" );
		$log->getmapper()->appendRule( new ezcLogFilterRule( new ezcLogFilter, $writer, true ) );
		// Writing some log messages.
 		$log->log( $msg, $type, array( 'category' => "", 'source' => "" ));
 		unset($log);
	}
	function log($msg, $format = false)
	{
		$this->writeLog( $msg, $format );
		$formatArray = array(	'critical' 	=> array('color' => 'red', 
													 'style' => array( 'bold' ) ),  
								'ok' 		=> array('color' => 'green'), 
								'warning'	=> array('color' => 'yellow', 
													  'style' => array( 'bold' )), 
								'heading'	=> array('style' => array( 'bold' )));
		
		foreach($formatArray as $formatKey => $formatDesc)
		{
			foreach($formatDesc as $attr => $value)
			{
				$this->output->formats->$formatKey->$attr = $value;
			}
		}
		
		
		// if a format is specified and it exists
		if($format AND isset($formatArray[$format]))
		{
			$this->output->outputText( $msg, $format );
			
		}
		else
		{
			$this->output->outputText( $msg );
		}
		// if the message is critical
		if($format == 'critical')
		{
			$this->output->outputLine();
			$this->output->outputText( "CRITICAL ERROR - ENDING SCRIPT", $format );
			$this->output->outputLine();
			$this->output->outputLine();
			exit();
		}
	}
	
	function MBFormat($bytes, $decimals = 1, $kiloBytes = false)
	{
		if($kiloBytes)
		{
			$factor = 1024;
		}
		else
		{
			$factor = 1024*1024;
		}
		
	    return number_format($bytes/$factor, $decimals, ",", ".") . " MB";
	}
}

?>