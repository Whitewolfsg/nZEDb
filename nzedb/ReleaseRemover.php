<?php
namespace nzedb;

use nzedb\db\Settings;

/**
 * Handles removing of various unwanted releases.
 *
 * Class ReleaseRemover
 */
class ReleaseRemover
{
	/**
	 * @const New line.
	 */
	const N = PHP_EOL;

	/**
	 * @var string
	 */
	protected $blacklistID;

	/**
	 * Is is run from the browser?
	 *
	 * @var bool
	 */
	protected $browser;

	/**
	 * @var \nzedb\ConsoleTools
	 */
	protected $consoleTools;

	/**
	 * @var string
	 */
	protected $crapTime = '';

	/**
	 * @var bool
	 */
	protected $delete;

	/**
	 * @var int
	 */
	protected $deletedCount = 0;

	/**
	 * @var bool
	 */
	protected $echoCLI;

	/**
	 * If an error occurred, store it here.
	 *
	 * @var string
	 */
	protected $error;

	/**
	 * Ignore user check?
	 *
	 * @var bool
	 */
	protected $ignoreUserCheck;

	/**
	 * @var string
	 */
	protected $method = '';

	/**
	 * @var \nzedb\db\Settings
	 */
	protected $pdo;

	/**
	 * The query we will use to select unwanted releases.
	 *
	 * @var string
	 */
	protected $query;

	/**
	 * @var Releases
	 */
	protected $releases;

	/**
	 * Result of the select query.
	 *
	 * @var array
	 */
	protected $result;

	/**
	 * Time we started.
	 *
	 * @var int
	 */
	protected $timeStart;

	/**
	 * @var NZB
	 */
	private $nzb;

	/**
	 * Construct.
	 *
	 * @param array $options Class instances / various options.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Browser'      => false, // Are we coming from the web script.
			'ConsoleTools' => null,
			'Echo'         => true, // Echo to CLI?
			'NZB'          => null,
			'ReleaseImage' => null,
			'Releases'     => null,
			'Settings'     => null,
		];
		$options += $defaults;

		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());
		$this->consoleTools = ($options['ConsoleTools'] instanceof ConsoleTools ? $options['ConsoleTools'] : new ConsoleTools(['ColorCLI' => $this->pdo->log]));
		$this->releases = ($options['Releases'] instanceof Releases ? $options['Releases'] : new Releases(['Settings' => $this->pdo]));
		$this->nzb = ($options['NZB'] instanceof NZB ? $options['NZB'] : new NZB($this->pdo));
		$this->releaseImage = ($options['ReleaseImage'] instanceof ReleaseImage ? $options['ReleaseImage'] : new ReleaseImage($this->pdo));

		$this->query = '';
		$this->error = '';
		$this->ignoreUserCheck = false;
		$this->browser = $options['Browser'];
		$this->echoCLI = (!$this->browser && nZEDb_ECHOCLI && $options['Echo']);
	}

	/**
	 * Remove releases using user criteria.
	 *
	 * @param array $arguments Array of criteria used to delete unwanted releases.
	 *                         Criteria muse look like this : columnName=modifier="content"
	 *                         columnName is a column name from the releases table.
	 *                         modifiers are : equals,like,bigger,smaller
	 *                         content is what to change the column content to
	 *
	 * @return string|bool
	 */
	public function removeByCriteria($arguments)
	{
		$this->delete = true;
		$this->ignoreUserCheck = false;
		// Time we started.
		$this->timeStart = TIME();

		// Start forming the query.
		$this->query = 'SELECT id, guid, searchname FROM releases WHERE 1=1';

		// Keep forming the query based on the user's criteria, return if any errors.
		foreach ($arguments as $arg) {
			$this->error = '';
			$string = $this->formatCriteriaQuery($arg);
			if ($string === false) {
				return $this->returnError();
			}
			$this->query .= $string;
		}
		$this->query = $this->cleanSpaces($this->query);

		// Check if the user wants to run the query.
		if ($this->checkUserResponse() === false) {
			return false;
		}

		// Check if the query returns results.
		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		$this->method = 'userCriteria';

		$this->deletedCount = 0;
		// Delete the releases.
		$this->deleteReleases();

		if ($this->echoCLI) {
			echo $this->pdo->log->headerOver(($this->delete ? "Deleted " : "Would have deleted ") . $this->deletedCount . " release(s). This script ran for ");
			echo $this->pdo->log->header($this->consoleTools->convertTime(TIME() - $this->timeStart));
		}

		return ($this->browser
			?
			'Success! ' .
			($this->delete ? "Deleted " : "Would have deleted ") .
			$this->deletedCount .
			' release(s) in ' .
			$this->consoleTools->convertTime(TIME() - $this->timeStart)
			:
			true
		);
	}

	/**
	 * Delete crap releases.
	 *
	 * @param bool       $delete                 Delete the release or just show the result?
	 * @param int|string $time                   Time in hours (to select old releases) or 'full' for no time limit.
	 * @param string     $type                   Type of query to run [blacklist, executable, gibberish, hashed, installbin, passworded,
	 *                                           passwordurl, sample, scr, short, size, ''] ('' runs against all types)
	 * @param string     $blacklistID
	 *
	 * @return string|bool
	 */
	public function removeCrap($delete, $time, $type = '', $blacklistID = '')
	{
		$this->timeStart = time();
		$this->delete = $delete;
		$this->blacklistID = '';

		if (isset($blacklistID) && is_numeric($blacklistID)) {
			$this->blacklistID = sprintf("AND id = %d", $blacklistID);
		}

		$time = trim($time);
		$this->crapTime = '';
		$type = strtolower(trim($type));

		switch ($time) {
			case 'full':
				if ($this->echoCLI) {
					echo $this->pdo->log->header("Removing " . ($type == '' ? "All crap releases " : $type . " crap releases") . " - no time limit.\n");
				}
				break;
			default:
				if (!is_numeric($time)) {
					$this->error = 'Error, time must be a number or full.';

					return $this->returnError();
				}
				if ($this->echoCLI) {
					echo $this->pdo->log->header("Removing " . ($type == '' ? "All crap releases " : $type . " crap releases") . " from the past " . $time . " hour(s).\n");
				}
				$this->crapTime = ' AND r.adddate > (NOW() - INTERVAL ' . $time . ' HOUR)';
				break;

		}

		$this->deletedCount = 0;
		switch ($type) {
			case 'blacklist':
				$this->removeBlacklist();
				break;
			case 'blfiles':
				$this->removeBlacklistFiles();
				break;
			case 'executable':
				$this->removeExecutable();
				break;
			case 'gibberish':
				$this->removeGibberish();
				break;
			case 'hashed':
				$this->removeHashed();
				break;
			case 'installbin':
				$this->removeInstallBin();
				break;
			case 'passworded':
				$this->removePassworded();
				break;
			case 'passwordurl':
				$this->removePasswordURL();
				break;
			case 'sample':
				$this->removeSample();
				break;
			case 'scr':
				$this->removeSCR();
				break;
			case 'short':
				$this->removeShort();
				break;
			case 'size':
				$this->removeSize();
				break;
			case 'huge':
				$this->removeHuge();
				break;
			case 'codec':
				$this->removeCodecPoster();
				break;
			case 'wmv_all':
				$this->removeWMV();
				break;
			case '':
				$this->removeBlacklist();
				$this->removeBlacklistFiles();
				$this->removeExecutable();
				$this->removeGibberish();
				$this->removeHashed();
				$this->removeInstallBin();
				$this->removePassworded();
				$this->removeSample();
				$this->removeSCR();
				$this->removeShort();
				$this->removeSize();
				$this->removeHuge();
				$this->removeCodecPoster();
				break;
			default:
				$this->error = 'Wrong type: ' . $type;

				return $this->returnError();
		}

		if ($this->echoCLI) {
			echo $this->pdo->log->headerOver(($this->delete ? "Deleted " : "Would have deleted ") . $this->deletedCount . " release(s). This script ran for ");
			echo $this->pdo->log->header($this->consoleTools->convertTime(TIME() - $this->timeStart));
		}

		return ($this->browser
			?
			'Success! ' .
			($this->delete ? "Deleted " : "Would have deleted ") .
			$this->deletedCount .
			' release(s) in ' .
			$this->consoleTools->convertTime(TIME() - $this->timeStart)
			:
			true
		);
	}

	/**
	 * Remove releases with 15 or more letters or numbers, nothing else.
	 *
	 * @return boolean|string
	 */
	protected function removeGibberish()
	{
		$this->method = 'Gibberish';
		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r
			WHERE r.nfostatus = 0
			AND r.iscategorized = 1
			AND r.rarinnerfilecount = 0
			AND r.categories_id NOT IN (%d)
			AND r.searchname REGEXP '^[a-zA-Z0-9]{15,}$'
			%s",
			Category::OTHER_HASHED,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with 25 or more letters/numbers, probably hashed.
	 *
	 * @return boolean|string
	 */
	protected function removeHashed()
	{
		$this->method = 'Hashed';
		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r
			WHERE r.nfostatus = 0
			AND r.iscategorized = 1
			AND r.rarinnerfilecount = 0
			AND r.categories_id NOT IN (%d, %d)
			AND r.searchname REGEXP '[a-zA-Z0-9]{25,}'
			%s",
			Category::OTHER_MISC, Category::OTHER_HASHED, $this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with 5 or less letters/numbers.
	 *
	 * @return boolean|string
	 */
	protected function removeShort()
	{
		$this->method = 'Short';
		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r
			WHERE r.nfostatus = 0
			AND r.iscategorized = 1
			AND r.rarinnerfilecount = 0
			AND r.categories_id NOT IN (%d)
			AND r.searchname REGEXP '^[a-zA-Z0-9]{0,5}$'
			%s",
			Category::OTHER_MISC, $this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with an exe file not in other misc or pc apps/games.
	 *
	 * @return boolean|string
	 */
	protected function removeExecutable()
	{
		$this->method = 'Executable';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$execFT =
					str_replace('=10000;', '=1000000;',
						$rs->getSearchSQL(
							[
								'searchname' => '-exes* -exec*',
								'filename' => 'exe'
							]
						)
				);
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$execFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			STRAIGHT_JOIN release_files rf ON r.id = rf.releases_id
			WHERE r.searchname NOT REGEXP %s
			AND rf.name %s
			AND r.categories_id NOT IN (%d, %d, %d, %d, %d, %d) %s %s",
			$ftJoin,
			$this->pdo->escapeString('\.exe[sc]'),
			$this->pdo->likeString('.exe', true, false),
			Category::PC_0DAY,
			Category::PC_GAMES,
			Category::PC_ISO,
			Category::PC_MAC,
			Category::OTHER_MISC,
			Category::OTHER_HASHED,
			$execFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with an install.bin file.
	 *
	 * @return boolean|string
	 */
	protected function removeInstallBin()
	{
		$this->method = 'Install.bin';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$instbinFT = str_replace('=10000;', '=10000000;', $rs->getSearchSQL(['filename' => 'install<<bin']));
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$instbinFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			STRAIGHT_JOIN release_files rf ON r.id = rf.releases_id
			WHERE rf.name %s %s",
			$ftJoin,
			$this->pdo->likeString('install.bin', true, true),
			$instbinFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with an password.url file.
	 *
	 * @return boolean|string
	 */
	protected function removePasswordURL()
	{
		$this->method = 'Password.url';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$passurlFT = str_replace('=10000;', '=10000000;', $rs->getSearchSQL(['filename' => 'password<<url']));
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$passurlFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			STRAIGHT_JOIN release_files rf ON r.id = rf.releases_id
			WHERE rf.name %s %s %s",
			$ftJoin,
			$this->pdo->likeString('password.url', true, true),
			$passurlFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with password in the search name.
	 *
	 * @return boolean|string
	 */
	protected function removePassworded()
	{
		$this->method = 'Passworded';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$passFT = str_replace('=10000;', '=10000000;', $rs->getSearchSQL(['searchname' => 'passwor*']));
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$passFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			WHERE r.searchname %s
			AND r.searchname NOT %s
			AND r.searchname NOT %s
			AND r.searchname NOT %s
			AND r.searchname NOT %s
			AND r.searchname NOT %s
			AND r.searchname NOT %s
			AND r.nzbstatus = 1
			AND r.categories_id NOT IN (%d, %d, %d, %d, %d, %d, %d, %d, %d) %s %s",
			// Matches passwort / passworded / etc also.
			$ftJoin,
			$this->pdo->likeString('passwor', true, true),
			$this->pdo->likeString('advanced', true, true),
			$this->pdo->likeString('no password', true, true),
			$this->pdo->likeString('not password', true, true),
			$this->pdo->likeString('recovery', true, true),
			$this->pdo->likeString('reset', true, true),
			$this->pdo->likeString('unlocker', true, true),
			Category::PC_GAMES,
			Category::PC_0DAY,
			Category::PC_ISO,
			Category::PC_MAC,
			Category::PC_PHONE_ANDROID,
			Category::PC_PHONE_IOS,
			Category::PC_PHONE_OTHER,
			Category::OTHER_MISC,
			Category::OTHER_HASHED,
			$passFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases smaller than 2MB with 1 part not in MP3/books/misc section.
	 *
	 * @return boolean|string
	 */
	protected function removeSize()
	{
		$this->method = 'Size';
		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r
			WHERE r.totalpart = 1
			AND r.size < 2097152
			AND r.categories_id NOT IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) %s",
			Category::MUSIC_MP3,
			Category::BOOKS_COMICS,
			Category::BOOKS_EBOOK,
			Category::BOOKS_FOREIGN,
			Category::BOOKS_MAGAZINES,
			Category::BOOKS_TECHNICAL,
			Category::BOOKS_UNKNOWN,
			Category::PC_0DAY,
			Category::PC_GAMES,
			Category::OTHER_MISC,
			Category::OTHER_HASHED,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases bigger than 200MB with just a single file.
	 *
	 * @return boolean|string
	 */
	protected function removeHuge()
	{
		$this->method = 'Huge';
		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r
			WHERE r.totalpart = 1
			AND r.size > 209715200 %s",
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with more than 1 part, less than 40MB, sample in name. TV/Movie sections.
	 *
	 * @return boolean|string
	 */
	protected function removeSample()
	{
		$this->method = 'Sample';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$sampleFT = str_replace('=10000;', '=10000000;', $rs->getSearchSQL(['name' => 'sample']));
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$sampleFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			WHERE r.totalpart > 1
			AND r.size < 40000000
			AND r.name %s
			AND r.categories_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) %s %s",
			$ftJoin,
			$this->pdo->likeString('sample', true, true),
			Category::TV_ANIME,
			Category::TV_DOCUMENTARY,
			Category::TV_FOREIGN,
			Category::TV_HD,
			Category::TV_OTHER,
			Category::TV_SD,
			Category::TV_SPORT,
			Category::TV_WEBDL,
			Category::MOVIE_3D,
			Category::MOVIE_BLURAY,
			Category::MOVIE_DVD,
			Category::MOVIE_FOREIGN,
			Category::MOVIE_HD,
			Category::MOVIE_OTHER,
			Category::MOVIE_SD,
			$sampleFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases with a scr file in the filename/subject.
	 *
	 * @return boolean|string
	 */
	protected function removeSCR()
	{
		$this->method = '.scr';

		switch (nZEDb_RELEASE_SEARCH_TYPE) {
			case ReleaseSearch::SPHINX:
				$rs = new ReleaseSearch($this->pdo);
				$scrFT = str_replace('=10000;', '=10000000;', $rs->getSearchSQL(['(name,filename)' => 'scr']));
				$ftJoin = $rs->getFullTextJoinString();
				break;
			default:
				$scrFT = $ftJoin = '';
				break;
		}

		$this->query = sprintf(
			"SELECT r.guid, r.searchname, r.id
			FROM releases r %s
			STRAIGHT_JOIN release_files rf ON r.id = rf.releases_id
			WHERE (rf.name REGEXP '[.]scr[$ \"]' OR r.name REGEXP '[.]scr[$ \"]')
			%s %s",
			$ftJoin,
			$scrFT,
			$this->crapTime
		);

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases using the site blacklist regexes.
	 *
	 * @return bool
	 */
	protected function removeBlacklist()
	{
		$status = sprintf('AND status = %d', Binaries::BLACKLIST_ENABLED);

		if (isset($this->blacklistID) && $this->blacklistID !== '' && $this->delete === false) {
			$status = '';
		}

		$regexList = $this->pdo->query(
			sprintf(
				'SELECT regex, id, groupname, msgcol
				FROM binaryblacklist
				WHERE optype = %d
				AND msgcol IN (%d, %d) %s %s
				ORDER BY id ASC',
				Binaries::OPTYPE_BLACKLIST,
				Binaries::BLACKLIST_FIELD_SUBJECT,
				Binaries::BLACKLIST_FIELD_FROM,
				$this->blacklistID,
				$status
			)
		);

		if (count($regexList) > 0) {

			foreach ($regexList as $regex) {

				$regexSQL = $ftMatch = $regexMatch = $opTypeName = '';
				$dbRegex = $this->pdo->escapeString($regex['regex']);

				if ($this->crapTime === '') {
					$regexMatch = $this->extractSrchFromRegx($dbRegex);
					if ($regexMatch !== '') {
						switch (nZEDb_RELEASE_SEARCH_TYPE) {
							case ReleaseSearch::SPHINX:
								$ftMatch = sprintf('rse.query = "@(name,searchname) %s;limit=1000000;maxmatches=1000000;mode=any" AND', str_replace('|', ' ', str_replace('"', '', $regexMatch)));
								break;
							case ReleaseSearch::FULLTEXT:
								$ftMatch = sprintf("(MATCH (rs.name) AGAINST ('%1\$s') OR MATCH (rs.searchname) AGAINST ('%1\$s')) AND", str_replace('|', ' ', $regexMatch));
								break;
						}
					}
				}

				switch ((int)$regex['msgcol']) {
					case Binaries::BLACKLIST_FIELD_SUBJECT:
						$regexSQL = sprintf("WHERE %s (r.name REGEXP %s OR r.searchname REGEXP %2\$s)", $ftMatch, $dbRegex);
						$opTypeName = "Subject";
						break;
					case Binaries::BLACKLIST_FIELD_FROM:
						$regexSQL = "WHERE r.fromname REGEXP " . $dbRegex;
						$opTypeName = "Poster";
						break;
				}

				if ($regexSQL === '') {
					continue;
				}

				// Get the group ID if the regex is set to work against a group.
				$groupID = '';
				if (strtolower($regex['groupname']) !== 'alt.binaries.*') {

					$groupIDs = $this->pdo->query(
						'SELECT id FROM groups WHERE name REGEXP ' .
						$this->pdo->escapeString($regex['groupname'])
					);

					$groupIDCount = count($groupIDs);
					if ($groupIDCount === 0) {
						continue;
					} elseif ($groupIDCount === 1) {
						$groupIDs = $groupIDs[0]['id'];
					} else {
						$string = '';
						foreach ($groupIDs as $ID) {
							$string .= $ID['id'] . ',';
						}
						$groupIDs = (substr($string, 0, -1));
					}

					$groupID = ' AND r.groups_id in (' . $groupIDs . ') ';
				}
				$this->method = 'Blacklist [' . $regex['id'] . ']';

				// Check if using FT Match and declare for echo
				if ($ftMatch !== '' && $opTypeName == "Subject") {
					$blType = "FULLTEXT match with REGEXP";
					$ftUsing = 'Using (' . $regexMatch . ') as interesting words.' . PHP_EOL;
				} else {
					$blType = "only REGEXP";
					$ftUsing = PHP_EOL;
				}

				// Provide useful output of operations
				echo $this->pdo->log->header(sprintf("Finding crap releases for %s: Using %s method against release %s.\n" .
						"%s", $this->method, $blType, $opTypeName, $ftUsing
					)
				);

				if ($opTypeName == 'Subject') {
					$join = (nZEDb_RELEASE_SEARCH_TYPE == ReleaseSearch::SPHINX ? 'INNER JOIN releases_se rse ON rse.id = r.id' : 'INNER JOIN release_search_data rs ON rs.releases_id = r.id');
				} else {
					$join = '';
				}

				$this->query = sprintf("
							SELECT r.guid, r.searchname, r.id
							FROM releases r %s %s %s %s",
					$join,
					$regexSQL,
					$groupID,
					$this->crapTime
				);

				if ($this->checkSelectQuery() === false) {
					continue;
				}
				$this->deleteReleases();

			}
		} else {
			echo $this->pdo->log->error("No regular expressions were selected for blacklist removal. Make sure you have activated REGEXPs in Site Edit and you're specifying a valid ID.\n");
		}

		return true;
	}

	/**
	 * Remove releases using the site blacklist regexes against file names.
	 *
	 * @return bool
	 */
	protected function removeBlacklistFiles()
	{
		$allRegex = $this->pdo->query(
			sprintf(
				'SELECT regex, id, groupname
				FROM binaryblacklist
				WHERE status = %d
				AND optype = %d
				AND msgcol = %d
				ORDER BY id ASC',
				Binaries::BLACKLIST_ENABLED,
				Binaries::OPTYPE_BLACKLIST,
				Binaries::BLACKLIST_FIELD_SUBJECT
			)
		);

		if (count($allRegex) > 0) {

			foreach ($allRegex as $regex) {
				$dbRegex = $this->pdo->escapeString($regex['regex']);
				$ftMatch = $ftJoin = $regexMatch = '';
				if ($this->crapTime === '') {
					$regexMatch = $this->extractSrchFromRegx($dbRegex);
					if ($regexMatch !== '') {
						switch (nZEDb_RELEASE_SEARCH_TYPE) {
							case ReleaseSearch::SPHINX:
								$ftMatch = sprintf('AND (rse.query = "@(filename) %s;limit=1000000;maxmatches=1000000;mode=any")', str_replace('|', ' ', str_replace('"', '', $regexMatch)));
								$ftJoin = "INNER JOIN releases_se rse ON rse.id = r.id";
								break;
							default:
								break;
						}
					}
				}

				$regexSQL = sprintf("STRAIGHT_JOIN release_files rf ON r.id = rf.releases_id
				WHERE rf.name REGEXP %s ", $this->pdo->escapeString($regex['regex'])
				);

				if ($regexSQL === '') {
					continue;
				}

				// Get the group ID if the regex is set to work against a group.
				$groupID = '';
				if (strtolower($regex['groupname']) !== 'alt.binaries.*') {
					$groupIDs = $this->pdo->query(
						'SELECT id FROM groups WHERE name REGEXP ' .
						$this->pdo->escapeString($regex['groupname'])
					);
					$groupIDCount = count($groupIDs);
					if ($groupIDCount === 0) {
						continue;
					} elseif ($groupIDCount === 1) {
						$groupIDs = $groupIDs[0]['id'];
					} else {
						$string = '';
						foreach ($groupIDs as $fID) {
							$string .= $fID['id'] . ',';
						}
						$groupIDs = (substr($string, 0, -1));
					}

					$groupID = ' AND r.groups_id in (' . $groupIDs . ') ';
				}

				$this->method = 'Blacklist Files ' . $regex['id'];

				// Check if using FT Match and declare for echo
				if ($ftMatch !== '') {
					$blType = "FULLTEXT match with REGEXP";
					$ftUsing = 'Using (' . $regexMatch . ') as interesting words.' . PHP_EOL;
				} else {
					$blType = "only REGEXP";
					$ftUsing = PHP_EOL;
				}

				// Provide useful output of operations
				echo $this->pdo->log->header(sprintf("Finding crap releases for %s: Using %s method against release filenames." . PHP_EOL .
						"%s", $this->method, $blType, $ftUsing
					)
				);

				$this->query = sprintf(
					"SELECT DISTINCT r.id, r.guid, r.searchname
					FROM releases r %s %s %s %s %s",
					$ftJoin,
					$regexSQL,
					$groupID,
					$ftMatch,
					$this->crapTime
				);

				if ($this->checkSelectQuery() === false) {
					continue;
				}

				$this->deleteReleases();
			}
		}

		return true;
	}

	/**
	 * Remove releases that contain .wmv file, aka that spam poster.
	 * Thanks to dizant from nZEDb forums for the sql query
	 *
	 * @return string|boolean
	 */
	protected function removeWMV()
	{
		$this->method = 'WMV_ALL';
		$this->query = "
			SELECT r.guid, r.searchname
			FROM releases r
			LEFT JOIN release_files rf ON (r.id = rf.releases_id)
			WHERE r.categories_id BETWEEN 2000 AND 2999
			AND rf.name REGEXP 'x264.*\.wmv$'
			GROUP BY r.id"
		;

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Remove releases that contain .wmv files and Codec\Setup.exe files, aka that spam poster.
	 * Thanks to dizant from nZEDb forums for parts of the sql query
	 *
	 * @return string|boolean
	 */
	protected function removeCodecPoster()
	{
		$categories = sprintf("r.categories_id IN (%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d)",
			Category::MOVIE_3D,
			Category::MOVIE_BLURAY,
			Category::MOVIE_DVD,
			Category::MOVIE_FOREIGN,
			Category::MOVIE_HD,
			Category::MOVIE_OTHER,
			Category::MOVIE_SD,
			Category::XXX_WMV,
			Category::XXX_X264,
			Category::XXX_XVID,
			Category::XXX_OTHER
		);

		$regex =
			'\.*((DVDrip|BRRip)[. ].*[. ](R[56]|HQ)|720p[ .](DVDrip|HQ)|Webrip.*[. ](R[56]|Xvid|AC3|US)' .
			'|720p.*[. ]WEB-DL[. ]Xvid[. ]AC3[. ]US|HDRip.*[. ]Xvid[. ]DD5).*[. ]avi$';

		$this->query = "
			SELECT r.guid, r.searchname, r.id
			FROM releases r
			LEFT JOIN release_files rf ON r.id = rf.releases_id
			WHERE {$categories}
			AND (r.imdbid NOT IN ('0000000', 0) OR xxxinfo_id > 0)
			AND nfostatus = 1
			AND haspreview = 0
			AND jpgstatus = 0
			AND predb_id = 0
			AND videostatus = 0
			AND
			(
				rf.name REGEXP 'XviD-[a-z]{3}\\.(avi|mkv|wmv)$'
				OR rf.name REGEXP 'x264.*\\.(wmv|avi)$'
				OR rf.name REGEXP '{$regex}'
				OR rf.name LIKE '%\\Codec%Setup.exe%'
				OR rf.name LIKE '%\\Codec%Installer.exe%'
				OR rf.name LIKE '%If_you_get_error.txt%'
				OR rf.name LIKE '%read me if the movie not playing.txt%'
				OR rf.name LIKE '%Lisez moi si le film ne demarre pas.txt%'
				OR rf.name LIKE '%lees me als de film niet spelen.txt%'
				OR rf.name LIKE '%Lesen Sie mir wenn der Film nicht abgespielt.txt%'
				OR rf.name LIKE '%Lesen Sie mir, wenn der Film nicht starten.txt%'
			)
			GROUP BY r.id {$this->crapTime}";

		if ($this->checkSelectQuery() === false) {
			return $this->returnError();
		}

		return $this->deleteReleases();
	}

	/**
	 * Delete releases from the database.
	 */
	protected function deleteReleases()
	{
		$deletedCount = 0;
		foreach ($this->result as $release) {
			if ($this->delete) {
				$this->releases->deleteSingle(['g' => $release['guid'], 'i' => $release['id']], $this->nzb, $this->releaseImage);
				if ($this->echoCLI) {
					echo $this->pdo->log->primary('Deleting: ' . $this->method . ': ' . $release['searchname']);
				}
			} elseif ($this->echoCLI) {
				echo $this->pdo->log->primary('Would be deleting: ' . $this->method . ': ' . $release['searchname']);
			}
			$deletedCount++;
		}

		$this->deletedCount += $deletedCount;

		return true;
	}

	/**
	 * Verify if the query has any results.
	 *
	 * @return boolean False on failure, true on success after setting a count of found releases.
	 */
	protected function checkSelectQuery()
	{
		// Run the query, check if it picked up anything.
		$result = $this->pdo->query($this->cleanSpaces($this->query));
		if (count($result) <= 0) {
			if ($this->method === 'userCriteria') {
				$this->error = 'No releases were found to delete, try changing your criteria.';
			} else {
				$this->error = '';
			}

			return false;
		}
		$this->result = $result;

		return true;
	}

	/**
	 * Go through user arguments and format part of the query.
	 *
	 * @param string $argument User argument.
	 *
	 * @return string|false
	 */
	protected function formatCriteriaQuery($argument)
	{
		// Check if the user wants to ignore the check.
		if ($argument === 'ignore') {
			$this->ignoreUserCheck = true;

			return '';
		}

		$this->error = 'Invalid argument supplied: ' . $argument . self::N;
		$args = explode('=', $argument);
		if (count($args) === 3) {

			$args[0] = $this->cleanSpaces($args[0]);
			$args[1] = $this->cleanSpaces($args[1]);
			$args[2] = $this->cleanSpaces($args[2]);
			switch ($args[0]) {
				case 'categoryid':
					switch ($args[1]) {
						case 'equals':
							return ' AND categories_id = ' . $args[2];
						default:
							break;
					}
					break;
				case 'imdbid':
					switch ($args[1]) {
						case 'equals':
							if ($args[2] === 'NULL') {
								return ' AND imdbID IS NULL ';
							} else {
								return ' AND imdbID = ' . $args[2];
							}
						default:
							break;
					}
					break;
				case 'nzbstatus':
					switch ($args[1]) {
						case 'equals':
							return ' AND nzbstatus = ' . $args[2];
						default:
							break;
					}
					break;
				case 'videos_id':
					switch ($args[1]) {
						case 'equals':
							return ' AND videos_id = ' . $args[2];
						default:
							break;
					}
					break;
				case 'totalpart':
					switch ($args[1]) {
						case 'equals':
							return ' AND totalpart = ' . $args[2];
						case 'bigger':
							return ' AND totalpart > ' . $args[2];
						case 'smaller':
							return ' AND totalpart < ' . $args[2];
						default:
							break;
					}
					break;
				case 'fromname':
					switch ($args[1]) {
						case 'equals':
							return ' AND fromname = ' . $this->pdo->escapeString($args[2]);
						case 'like':
							return ' AND fromname ' . $this->formatLike($args[2], 'fromname');
					}
					break;
				case 'groupname':
					switch ($args[1]) {
						case 'equals':
							$group = $this->pdo->queryOneRow('SELECT id FROM groups WHERE name = ' . $this->pdo->escapeString($args[2]));
							if ($group === false) {
								$this->error = 'This group was not found in your database: ' . $args[2] . PHP_EOL;
								break;
							}

							return ' AND groups_id = ' . $group['id'];
						case 'like':
							$groups = $this->pdo->query('SELECT id FROM groups WHERE name ' . $this->formatLike($args[2], 'name'));
							if (count($groups) === 0) {
								$this->error = 'No groups were found with this pattern in your database: ' . $args[2] . PHP_EOL;
								break;
							}
							$gQuery = ' AND groups_id IN (';
							foreach ($groups as $group) {
								$gQuery .= $group['id'] . ',';
							}
							$gQuery = substr($gQuery, 0, strlen($gQuery) - 1) . ')';

							return $gQuery;
						default:
							break;
					}
					break;
				case 'guid':
					switch ($args[1]) {
						case 'equals':
							return ' AND guid = ' . $this->pdo->escapeString($args[2]);
						default:
							break;
					}
					break;
				case 'name':
					switch ($args[1]) {
						case 'equals':
							return ' AND name = ' . $this->pdo->escapeString($args[2]);
						case 'like':
							return ' AND name ' . $this->formatLike($args[2], 'name');
						default:
							break;
					}
					break;
				case 'searchname':
					switch ($args[1]) {
						case 'equals':
							return ' AND searchname = ' . $this->pdo->escapeString($args[2]);
						case 'like':
							return ' AND searchname ' . $this->formatLike($args[2], 'searchname');
						default:
							break;
					}
					break;
				case 'size':
					if (!is_numeric($args[2])) {
						break;
					}
					switch ($args[1]) {
						case 'equals':
							return ' AND size = ' . $args[2];
						case 'bigger':
							return ' AND size > ' . $args[2];
						case 'smaller':
							return ' AND size < ' . $args[2];
						default:
							break;
					}
					break;
				case 'adddate':
					if (!is_numeric($args[2])) {
						break;
					}
					switch ($args[1]) {
						case 'bigger':
							return ' AND adddate <  NOW() - INTERVAL ' . $args[2] . ' HOUR';
						case 'smaller':
							return ' AND adddate >  NOW() - INTERVAL ' . $args[2] . ' HOUR';
						default:
							break;
					}
					break;
				case 'postdate':
					if (!is_numeric($args[2])) {
						break;
					}
					switch ($args[1]) {
						case 'bigger':
							return ' AND postdate <  NOW() - INTERVAL ' . $args[2] . ' HOUR';
						case 'smaller':
							return ' AND postdate >  NOW() - INTERVAL ' . $args[2] . ' HOUR';
						default:
							break;
					}
					break;
				case 'completion':
					if (!is_numeric($args[2])) {
						break;
					}
					switch ($args[1]) {
						case 'smaller':
							return ' AND completion > 0 AND completion < ' . $args[2];
						default:
							break;
					}
			}
		}

		return false;
	}

	/**
	 * Check if the user wants to run the current query.
	 *
	 * @return bool
	 */
	protected function checkUserResponse()
	{
		if ($this->ignoreUserCheck || $this->browser) {
			return true;
		}

		// Print the query to the user, ask them if they want to continue using it.
		echo $this->pdo->log->primary(
			'This is the query we have formatted using your criteria, you can run it in SQL to see if you like the results:' .
			self::N . $this->query . ';' . self::N .
			'If you are satisfied, type yes and press enter. Anything else will exit.'
		);

		// Check the users response.
		$userInput = trim(fgets(fopen('php://stdin', 'r')));
		if ($userInput !== 'yes') {
			echo $this->pdo->log->primary('You typed: "' . $userInput . '", the program will exit.');

			return false;
		}

		return true;
	}

	/**
	 * Remove multiple spaces and trim leading spaces.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	protected function cleanSpaces($string)
	{
		return trim(preg_replace('/\s{2,}/', ' ', $string));
	}

	/**
	 * Format a "like" string. ie: "name LIKE '%test%' AND name LIKE '%123%'
	 *
	 * @param string $string The string to format.
	 * @param string $type   The column name.
	 *
	 * @return string
	 */
	protected function formatLike($string, $type)
	{
		$newString = explode(' ', $string);
		if (count($newString) > 1) {
			$string = implode("%' AND {$type} LIKE '%", array_unique($newString));
		}

		return " LIKE '%" . $string . "%' ";
	}

	/**
	 * Echo the error and return false if on CLI.
	 * Return the error if on browser.
	 *
	 * @return bool/string
	 */
	protected function returnError()
	{
		if ($this->browser) {
			return $this->error . '<br />';
		} else {
			if ($this->echoCLI && $this->error !== '') {
				echo $this->pdo->log->error($this->error);
			}

			return false;
		}
	}

	protected function extractSrchFromRegx($dbRegex = '')
	{
		$regexMatch = '';

		// Match Regex beginning for long running foreign search
		if (substr($dbRegex, 2, 17) === 'brazilian|chinese') {
			// Find first brazilian instance position in Regex, then find first closing parenthesis.
			// Then substitute all pipes (|) with spaces for FT search and insert into query
			$forBegin = strpos($dbRegex, 'brazilian');
			$regexMatch =
				substr($dbRegex, $forBegin,
					strpos($dbRegex, ')') - $forBegin
				);
		} else if (substr($dbRegex, 7, 11) === 'bl|cz|de|es') {
			// Find first bl|cz instance position in Regex, then find first closing parenthesis.
			$forBegin = strpos($dbRegex, 'bl|cz');
			$regexMatch = '"' .
				str_replace('|', '" "',
					substr($dbRegex, $forBegin, strpos($dbRegex, ')') - $forBegin)
				) . '"';
		} else if (substr($dbRegex, 8, 5) === '19|20') {
			// Find first bl|cz instance position in Regex, then find last closing parenthesis as this is reversed.
			$forBegin = strpos($dbRegex, 'bl|cz');
			$regexMatch = '"' .
				str_replace('|', '" "',
					substr($dbRegex, $forBegin, strrpos($dbRegex, ')') - $forBegin)
				) . '"';
		} else if (substr($dbRegex, 7, 14) === 'chinese.subbed') {
			// Find first brazilian instance position in Regex, then find first closing parenthesis.
			$forBegin = strpos($dbRegex, 'chinese');
			$regexMatch =
				str_replace('nl  subed|bed|s', 'nlsubs|nlsubbed|nlsubed',
					str_replace('?', '',
						str_replace('.', ' ',
							str_replace(['-', '(', ')'], '',
								substr($dbRegex, $forBegin,
									strrpos($dbRegex, ')') - $forBegin
								)
							)
						)
					)
				)
			;
		} else if (substr($dbRegex, 8, 2) === '4u') {
			// Find first 4u\.nl instance position in Regex, then find first closing parenthesis.
			$forBegin = strpos($dbRegex, '4u');
			$regexMatch =
				str_replace('nov[ a]+rip', 'nova',
					str_replace('4u.nl', '"4u" "nl"',
						substr($dbRegex, $forBegin, strpos($dbRegex, ')') - $forBegin)
					)
				)
			;
		} else if (substr($dbRegex, 8, 5) === 'bd|dl') {
			// Find first bd|dl instance position in Regex, then find last closing parenthesis as this is reversed.
			$forBegin = strpos($dbRegex, 'bd|dl');
			$regexMatch =
				str_replace(['\\', ']', '['], '',
					str_replace('bd|dl)mux', 'bdmux|dlmux',
						substr($dbRegex, $forBegin,
							strrpos($dbRegex, ')') - $forBegin
						)
					)
				)
			;
		} else if (substr($dbRegex, 7, 9) === 'imageset|') {
			// Find first imageset| instance position in Regex, then find last closing parenthesis.
			$forBegin = strpos($dbRegex, 'imageset');
			$regexMatch = substr($dbRegex, $forBegin, strpos($dbRegex, ')') - $forBegin);
		} else if (substr($dbRegex, 1, 9) === 'hdnectar|') {
			// Find first hdnectar| instance position in Regex.
			$regexMatch = str_replace('\'', '', $dbRegex);
		} else if (substr($dbRegex, 1, 10) === 'Passworded') {
			// Find first Passworded instance position esin Regex, then find last closing parenthesis.
			$regexMatch = str_replace('\'', '', $dbRegex);
		}
		return $regexMatch;
	}
}
