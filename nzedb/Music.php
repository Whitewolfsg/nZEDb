<?php
namespace nzedb;

use libs\AmazonProductAPI;

use nzedb\db\Settings;

/**
 * Class Music
 */
class Music
{
	/**
	 * @var \nzedb\db\Settings
	 */
	public $pdo;

	/**
	 * @var bool
	 */
	public $echooutput;

	/**
	 * @var array|bool|string
	 */
	public $pubkey;

	/**
	 * @var array|bool|string
	 */
	public $privkey;

	/**
	 * @var array|bool|string
	 */
	public $asstag;

	/**
	 * @var array|bool|int|string
	 */
	public $musicqty;

	/**
	 * @var array|bool|int|string
	 */
	public $sleeptime;

	/**
	 * @var string
	 */
	public $imgSavePath;

	/**
	 * @var string
	 */
	public $renamed;

	/**
	 * Store names of failed Amazon lookup items
	 * @var array
	 */
	public $failCache;

	/**
	 * @param array $options Class instances/ echo to CLI.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Echo'     => false,
			'Settings' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && nZEDb_ECHOCLI);

		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());
		$this->pubkey = $this->pdo->getSetting('amazonpubkey');
		$this->privkey = $this->pdo->getSetting('amazonprivkey');
		$this->asstag = $this->pdo->getSetting('amazonassociatetag');
		$this->musicqty = ($this->pdo->getSetting('maxmusicprocessed') != '') ? $this->pdo->getSetting('maxmusicprocessed') : 150;
		$this->sleeptime = ($this->pdo->getSetting('amazonsleep') != '') ? $this->pdo->getSetting('amazonsleep') : 1000;
		$this->imgSavePath = nZEDb_COVERS . 'music' . DS;
		$this->renamed = '';
		if ($this->pdo->getSetting('lookupmusic') == 2) {
			$this->renamed = 'AND isrenamed = 1';
		}

		$this->failCache = array();
	}

	/**
	 * @param $id
	 *
	 * @return array|bool
	 */
	public function getMusicInfo($id)
	{
		return $this->pdo->queryOneRow(
			sprintf("
				SELECT musicinfo.*,
					genres.title AS genres
				FROM musicinfo
				LEFT OUTER JOIN genres ON genres.id = musicinfo.genre_id
				WHERE musicinfo.id = %d",
				$id
			)
		);
	}

	/**
	 * @param $artist
	 * @param $album
	 *
	 * @return array|bool
	 */
	public function getMusicInfoByName($artist, $album)
	{
		//only used to get a count of words
		$searchwords = $searchsql = '';
		$ft = $this->pdo->queryDirect("SHOW INDEX FROM musicinfo WHERE key_name = 'ix_musicinfo_artist_title_ft'");
		if ($ft->rowCount() !== 2) {
			$searchsql .= sprintf(" artist %s AND title %s'", $this->pdo->likeString($artist, true, true), $this->pdo->likeString($album, true, true));
		} else {
			$album = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $album);
			$album = preg_replace('/[^\w ]+/', '', $album);
			$album = preg_replace('/(WEB|FLAC|CD)/', '', $album);
			$album = trim(preg_replace('/\s\s+/i', ' ', $album));
			$album = trim($album);
			$words = explode(' ', $album);

			foreach ($words as $word) {
				$word = trim(rtrim(trim($word), '-'));
				if ($word !== '' && $word !== '-') {
					$word = '+' . $word;
					$searchwords .= sprintf('%s ', $word);
				}
			}
			$searchwords = trim($searchwords);
			$searchsql .= sprintf(" MATCH(artist, title) AGAINST(%s IN BOOLEAN MODE)", $this->pdo->escapeString($searchwords));
		}
		return $this->pdo->queryOneRow(
			sprintf("
				SELECT *
				FROM musicinfo
				WHERE %s",
				$searchsql
			)
		);
	}

	/**
	 * @param $start
	 * @param $num
	 *
	 * @return array
	 */
	public function getRange($start, $num)
	{
		if ($start === false) {
			$limit = "";
		} else {
			$limit = "LIMIT " . $num . " OFFSET " . $start;
		}

		return $this->pdo->query("
			SELECT m.*
			FROM musicinfo m
			ORDER BY m.createddate DESC {$limit}"
		);
	}

	/**
	 * @return mixed
	 */
	public function getCount()
	{
		$res = $this->pdo->queryOneRow("
			SELECT COUNT(id) AS num
			FROM musicinfo"
		);
		return $res["num"];
	}

	/**
	 * @param       $cat
	 * @param       $maxage
	 * @param array $excludedcats
	 *
	 * @return mixed
	 */
	public function getMusicCount($cat, $maxage = -1, array $excludedcats = [])
	{
		$res = $this->pdo->query(
			sprintf("
				SELECT COUNT(DISTINCT r.musicinfo_id) AS num
				FROM releases r
				INNER JOIN musicinfo m ON m.id = r.musicinfo_id
				AND m.title != ''
				AND m.cover = 1
				WHERE nzbstatus = 1
				AND r.passwordstatus %s
				AND %s %s %s %s",
				Releases::showPasswords($this->pdo),
				$this->getBrowseBy(),
				(count($cat) > 0 && $cat[0] != -1 ? (new Category(['Settings' => $this->pdo]))->getCategorySearch($cat) : ''),
				($maxage > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxage) : ''),
				(count($excludedcats) > 0 ? " AND r.categories_id NOT IN (" . implode(",", $excludedcats) . ")" : '')
			), true, nZEDb_CACHE_EXPIRY_MEDIUM
		);
		return (isset($res[0]["num"]) ? $res[0]["num"] : 0);
	}

	/**
	 * @param       $cat
	 * @param       $start
	 * @param       $num
	 * @param       $orderby
	 * @param array $excludedcats
	 *
	 * @return array
	 */
	public function getMusicRange($cat, $start, $num, $orderby, array $excludedcats = [])
	{
		$browseby = $this->getBrowseBy();

		$catsrch = '';
		if (count($cat) > 0 && $cat[0] != -1) {
			$catsrch = (new Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}

		$exccatlist = "";
		if (count($excludedcats) > 0) {
			$exccatlist = " AND r.categories_id NOT IN (" . implode(",", $excludedcats) . ")";
		}

		$order = $this->getMusicOrder($orderby);

		$music = $this->pdo->queryCalc(
				sprintf("
				SELECT SQL_CALC_FOUND_ROWS
					m.id,
					GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id
				FROM musicinfo m
				LEFT JOIN releases r ON r.musicinfo_id = m.id
				WHERE r.nzbstatus = 1
				AND m.title != ''
				AND m.cover = 1
				AND r.passwordstatus %s
				AND %s %s %s
				GROUP BY m.id
				ORDER BY %s %s %s",
				Releases::showPasswords($this->pdo),
				$browseby,
				$catsrch,
				$exccatlist,
				$order[0],
				$order[1],
				($start === false ? '' : ' LIMIT ' . $num . ' OFFSET ' . $start)
				), true, nZEDb_CACHE_EXPIRY_MEDIUM
		);

		$musicIDs = $releaseIDs = false;

		if (is_array($music['result'])) {
			foreach ($music['result'] as $mus => $id) {
				$musicIDs[] = $id['id'];
				$releaseIDs[] = $id['grp_release_id'];
			}
		}

		$sql = sprintf("
			SELECT
				GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id,
				GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') as grp_rarinnerfilecount,
				GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview,
				GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password,
				GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid,
				GROUP_CONCAT(rn.releases_id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid,
				GROUP_CONCAT(g.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname,
				GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name,
				GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate,
				GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size,
				GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts,
				GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments,
				GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs,
				GROUP_CONCAT(df.failed ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_failed,
				m.*,
				r.musicinfo_id, r.haspreview,
				g.name AS group_name,
				rn.releases_id AS nfoid
			FROM releases r
			LEFT OUTER JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			INNER JOIN musicinfo m ON m.id = r.musicinfo_id
			WHERE m.id IN (%s)
			AND r.id IN (%s)
			AND %s
			GROUP BY m.id
			ORDER BY %s %s",
			(is_array($musicIDs) ? implode(',', $musicIDs) : -1),
			(is_array($releaseIDs) ? implode(',', $releaseIDs) : -1),
			$catsrch,
			$order[0],
			$order[1]
		);
		$return = $this->pdo->query($sql, true, nZEDb_CACHE_EXPIRY_MEDIUM);
		if (!empty($return)) {
			$return[0]['_totalcount'] = (isset($music['total']) ? $music['total'] : 0);
		}

		return $return;
	}

	/**
	 * @param $orderby
	 *
	 * @return array
	 */
	public function getMusicOrder($orderby)
	{
		$order = ($orderby == '') ? 'r.postdate' : $orderby;
		$orderArr = explode("_", $order);
		switch ($orderArr[0]) {
			case 'artist':
				$orderfield = 'm.artist';
				break;
			case 'size':
				$orderfield = 'r.size';
				break;
			case 'files':
				$orderfield = 'r.totalpart';
				break;
			case 'stats':
				$orderfield = 'r.grabs';
				break;
			case 'year':
				$orderfield = 'm.year';
				break;
			case 'genre':
				$orderfield = 'm.genre_id';
				break;
			case 'posted':
			default:
				$orderfield = 'r.postdate';
				break;
		}
		$ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';
		return [$orderfield, $ordersort];
	}

	/**
	 * @return array
	 */
	public function getMusicOrdering()
	{
		return ['artist_asc', 'artist_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc', 'files_asc', 'files_desc', 'stats_asc', 'stats_desc', 'year_asc', 'year_desc', 'genre_asc', 'genre_desc'];
	}

	/**
	 * @return array
	 */
	public function getBrowseByOptions()
	{
		return ['artist' => 'artist', 'title' => 'title', 'genre' => 'genre_id', 'year' => 'year'];
	}

	/**
	 * @return string
	 */
	public function getBrowseBy()
	{
		$browseby = ' ';
		$browsebyArr = $this->getBrowseByOptions();
		foreach ($browsebyArr as $bbk => $bbv) {
			if (isset($_REQUEST[$bbk]) && !empty($_REQUEST[$bbk])) {
				$bbs = stripslashes($_REQUEST[$bbk]);
				if (preg_match('/id/i', $bbv)) {
					$browseby .= 'm.' . $bbv . ' = ' . $bbs . ' AND ';
				} else {
					$browseby .= 'm.' . $bbv . ' ' . $this->pdo->likeString($bbs, true, true) . ' AND ';
				}
			}
		}
		return $browseby;
	}

	/**
	 * @param $data
	 * @param $field
	 *
	 * @return string
	 */
	public function makeFieldLinks($data, $field)
	{
		$tmpArr = explode(', ', $data[$field]);
		$newArr = [];
		$i = 0;
		foreach ($tmpArr as $ta) {
			if (trim($ta) == '') {
				continue;
			}
			if ($i > 5) {
				break;
			} //only use first 6
			$newArr[] = '<a href="' . WWW_TOP . '/music?' . $field . '=' . urlencode($ta) . '" title="' . $ta . '">' . $ta . '</a>';
			$i++;
		}
		return implode(', ', $newArr);
	}

	/**
	 * @param $id
	 * @param $title
	 * @param $asin
	 * @param $url
	 * @param $salesrank
	 * @param $artist
	 * @param $publisher
	 * @param $releasedate
	 * @param $year
	 * @param $tracks
	 * @param $cover
	 * @param $genreID
	 */
	public function update($id, $title, $asin, $url, $salesrank, $artist, $publisher, $releasedate, $year, $tracks, $cover, $genreID)
	{
		$this->pdo->queryExec(
					sprintf("
						UPDATE musicinfo
						SET title = %s, asin = %s, url = %s, salesrank = %s, artist = %s, publisher = %s, releasedate = %s,
							year = %s, tracks = %s, cover = %d, genre_id = %d, updateddate = NOW()
						WHERE id = %d",
						$this->pdo->escapeString($title), $this->pdo->escapeString($asin),
						$this->pdo->escapeString($url), $salesrank, $this->pdo->escapeString($artist),
						$this->pdo->escapeString($publisher), $this->pdo->escapeString($releasedate),
						$this->pdo->escapeString($year), $this->pdo->escapeString($tracks), $cover, $genreID, $id
					)
		);
	}

	/**
	 * @param             $title
	 * @param             $year
	 * @param object|null $amazdata
	 *
	 * @return bool
	 */
	public function updateMusicInfo($title, $year, $amazdata = null)
	{
		$gen = new Genres(['Settings' => $this->pdo]);
		$ri = new ReleaseImage($this->pdo);
		$titlepercent = 0;

		$mus = [];
		if ($title != '') {
			$amaz = $this->fetchAmazonProperties($title);
		} else if ($amazdata != null) {
			$amaz = $amazdata;
		} else {
			$amaz = false;
		}

		if (!$amaz) {
			return false;
		}

		if (isset($amaz->Items->Item->ItemAttributes->Title)) {
			$mus['title'] = (string)$amaz->Items->Item->ItemAttributes->Title;
			if (empty($mus['title'])) {
				return false;
			}
		} else {
			return false;
		}

		// Load genres.
		$defaultGenres = $gen->getGenres(Category::MUSIC_ROOT);
		$genreassoc = [];
		foreach ($defaultGenres as $dg) {
			$genreassoc[$dg['id']] = strtolower($dg['title']);
		}

		// Get album properties.
		$mus['coverurl'] = (string)$amaz->Items->Item->LargeImage->URL;
		if ($mus['coverurl'] != "") {
			$mus['cover'] = 1;
		} else {
			$mus['cover'] = 0;
		}

		$mus['asin'] = (string)$amaz->Items->Item->ASIN;

		$mus['url'] = (string)$amaz->Items->Item->DetailPageURL;
		$mus['url'] = str_replace("%26tag%3Dws", "%26tag%3Dopensourceins%2D21", $mus['url']);

		$mus['salesrank'] = (string)$amaz->Items->Item->SalesRank;
		if ($mus['salesrank'] == "") {
			$mus['salesrank'] = 'null';
		}

		$mus['artist'] = (string)$amaz->Items->Item->ItemAttributes->Artist;
		if (empty($mus['artist'])) {
			$mus['artist'] = (string)$amaz->Items->Item->ItemAttributes->Creator;
			if (empty($mus['artist'])) {
				$mus['artist'] = "";
			}
		}

		$mus['publisher'] = (string)$amaz->Items->Item->ItemAttributes->Publisher;

		$mus['releasedate'] = $this->pdo->escapeString((string)$amaz->Items->Item->ItemAttributes->ReleaseDate);
		if ($mus['releasedate'] == "''") {
			$mus['releasedate'] = 'null';
		}

		$mus['review'] = "";
		if (isset($amaz->Items->Item->EditorialReviews)) {
			$mus['review'] = trim(strip_tags((string)$amaz->Items->Item->EditorialReviews->EditorialReview->Content));
		}

		$mus['year'] = $year;
		if ($mus['year'] == "") {
			$mus['year'] = ($mus['releasedate'] != 'null' ? substr($mus['releasedate'], 1, 4) : date("Y"));
		}

		$mus['tracks'] = "";
		if (isset($amaz->Items->Item->Tracks)) {
			$tmpTracks = (array)$amaz->Items->Item->Tracks->Disc;
			$tracks = $tmpTracks['Track'];
			$mus['tracks'] = (is_array($tracks) && !empty($tracks)) ? implode('|', $tracks) : '';
		}

		similar_text($mus['artist'] . " " . $mus['title'], $title, $titlepercent);
		if ($titlepercent < 60) {
			return false;
		}

		$genreKey = -1;
		$genreName = '';
		if (isset($amaz->Items->Item->BrowseNodes)) {
			// Had issues getting this out of the browsenodes obj.
			// Workaround is to get the xml and load that into its own obj.
			$amazGenresXml = $amaz->Items->Item->BrowseNodes->asXml();
			$amazGenresObj = simplexml_load_string($amazGenresXml);
			$amazGenres = $amazGenresObj->xpath("//BrowseNodeId");

			foreach ($amazGenres as $amazGenre) {
				$currNode = trim($amazGenre[0]);
				if (empty($genreName)) {
					$genreMatch = $this->matchBrowseNode($currNode);
					if ($genreMatch !== false) {
						$genreName = $genreMatch;
						break;
					}
				}
			}

			if (in_array(strtolower($genreName), $genreassoc)) {
				$genreKey = array_search(strtolower($genreName), $genreassoc);
			} else {
				$genreKey = $this->pdo->queryInsert(
									sprintf("
										INSERT INTO genres (title, type)
										VALUES (%s, %d)",
										$this->pdo->escapeString($genreName),
										Category::MUSIC_ROOT
									)
				);
			}
		}
		$mus['musicgenre'] = $genreName;
		$mus['musicgenreid'] = $genreKey;

		$check = $this->pdo->queryOneRow(
			sprintf('
				SELECT id
				FROM musicinfo
				WHERE asin = %s',
				$this->pdo->escapeString($mus['asin'])
			)
		);

		if ($check === false) {
			$musicId = $this->pdo->queryInsert(
				sprintf("
					INSERT INTO musicinfo
						(title, asin, url, salesrank, artist, publisher,
						releasedate, review, year, genre_id, tracks, cover, createddate, updateddate)
					VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, now(), now())",
					$this->pdo->escapeString($mus['title']),
					$this->pdo->escapeString($mus['asin']),
					$this->pdo->escapeString($mus['url']),
					$mus['salesrank'],
					$this->pdo->escapeString($mus['artist']),
					$this->pdo->escapeString($mus['publisher']),
					$mus['releasedate'],
					$this->pdo->escapeString($mus['review']),
					$this->pdo->escapeString($mus['year']),
					($mus['musicgenreid'] == -1 ? "null" : $mus['musicgenreid']),
					$this->pdo->escapeString($mus['tracks']),
					$mus['cover']
				)
			);
		} else {
			$musicId = $check['id'];
			$this->pdo->queryExec(
				sprintf('
					UPDATE musicinfo
					SET title = %s, asin = %s, url = %s, salesrank = %s, artist = %s,
						publisher = %s, releasedate = %s, review = %s, year = %s, genre_id = %s, tracks = %s, cover = %s,
						updateddate = NOW()
					WHERE id = %d',
					$this->pdo->escapeString($mus['title']),
					$this->pdo->escapeString($mus['asin']),
					$this->pdo->escapeString($mus['url']),
					$mus['salesrank'],
					$this->pdo->escapeString($mus['artist']),
					$this->pdo->escapeString($mus['publisher']),
					$mus['releasedate'], $this->pdo->escapeString($mus['review']),
					$this->pdo->escapeString($mus['year']),
					($mus['musicgenreid'] == -1 ? "null" : $mus['musicgenreid']),
					$this->pdo->escapeString($mus['tracks']),
					$mus['cover'],
					$musicId
				)
			);
		}

		if ($musicId) {
			if ($this->echooutput) {
				$this->pdo->log->doEcho(
					$this->pdo->log->header("\nAdded/updated album: ") .
					$this->pdo->log->alternateOver("   Artist: ") .
					$this->pdo->log->primary($mus['artist']) .
					$this->pdo->log->alternateOver("   Title:  ") .
					$this->pdo->log->primary($mus['title']) .
					$this->pdo->log->alternateOver("   Year:   ") .
					$this->pdo->log->primary($mus['year'])
				);
			}
			$mus['cover'] = $ri->saveImage($musicId, $mus['coverurl'], $this->imgSavePath, 250, 250);
		} else {
			if ($this->echooutput) {
				if ($mus["artist"] == "") {
					$artist = "";
				} else {
					$artist = "Artist: " . $mus['artist'] . ", Album: ";
				}
				$this->pdo->log->doEcho(
					$this->pdo->log->headerOver("Nothing to update: ") .
					$this->pdo->log->primary(
						$artist .
						$mus['title'] .
						" (" .
						$mus['year'] .
						")"
					)
				);
			}
		}

		return $musicId;
	}

	/**
	 * @param $title
	 *
	 * @return bool|mixed
	 */
	public function fetchAmazonProperties($title)
	{
		$result = false;
		$obj = new AmazonProductAPI($this->pubkey, $this->privkey, $this->asstag);
		// Try Music category.
		try {
			$result = $obj->searchProducts($title, AmazonProductAPI::MUSIC, "TITLE");
		} catch (\Exception $e) {
			// Empty because we try another method.
		}

		// Try MP3 category.
		if ($result === false) {
			usleep(700000);
			try {
				$result = $obj->searchProducts($title, AmazonProductAPI::MP3, "TITLE");
			} catch (\Exception $e) {
				// Empty because we try another method.
			}
		}

		// Try Digital Music category.
		if ($result === false) {
			usleep(700000);
			try {
				$result = $obj->searchProducts($title, AmazonProductAPI::DIGITALMUS, "TITLE");
			} catch (\Exception $e) {
				// Empty because we try another method.
			}
		}

		// Try Music Tracks category.
		if ($result === false) {
			usleep(700000);
			try {
				$result = $obj->searchProducts($title, AmazonProductAPI::MUSICTRACKS, "TITLE");
			} catch (\Exception $e) {
				// Empty because we exhausted all possibilities.
			}
		}

		return $result;
	}

	/**
	 * @param bool $local
	 */
	public function processMusicReleases($local = false)
	{
		$res = $this->pdo->queryDirect(
				sprintf('
					SELECT searchname, id
					FROM releases
					WHERE musicinfo_id IS NULL
					AND nzbstatus = 1 %s
					AND categories_id IN (%s, %s, %s)
					ORDER BY postdate DESC
					LIMIT %d',
					$this->renamed,
					Category::MUSIC_MP3,
					Category::MUSIC_LOSSLESS,
					Category::MUSIC_OTHER,
					$this->musicqty
				)
		);
		if ($res instanceof \Traversable && $res->rowCount() > 0) {
			if ($this->echooutput) {
				$this->pdo->log->doEcho(
					$this->pdo->log->header("Processing " . $res->rowCount() . ' music release(s).'
					)
				);
			}

			foreach ($res as $arr) {
				$startTime = microtime(true);
				$usedAmazon = false;
				$album = $this->parseArtist($arr['searchname']);
				if ($album !== false) {
					$newname = $album["name"] . ' (' . $album["year"] . ')';

					if ($this->echooutput) {
						$this->pdo->log->doEcho($this->pdo->log->headerOver('Looking up: ') . $this->pdo->log->primary($newname));
					}

					// Do a local lookup first
					$musicCheck = $this->getMusicInfoByName('', $album["name"]);

					if ($musicCheck === false && in_array($album['name'] . $album['year'], $this->failCache)) {
						// Lookup recently failed, no point trying again
						if ($this->echooutput) {
							$this->pdo->log->doEcho($this->pdo->log->headerOver('Cached previous failure. Skipping.') . PHP_EOL);
						}
						$albumId = -2;
					} else if ($musicCheck === false && $local === false) {
						$albumId = $this->updateMusicInfo($album['name'], $album['year']);
						$usedAmazon = true;
						if ($albumId === false) {
							$albumId = -2;
							$this->failCache[] = $album['name'] . $album['year'];
						}
					} else {
						$albumId = $musicCheck['id'];
					}

					// Update release.
					$this->pdo->queryExec(sprintf("UPDATE releases SET musicinfo_id = %d WHERE id = %d", $albumId, $arr["id"]));
				} // No album found.
				else {
					$this->pdo->queryExec(sprintf("UPDATE releases SET musicinfo_id = %d WHERE id = %d", -2, $arr["id"]));
					echo '.';
				}

				// Sleep to not flood amazon.
				$diff = floor((microtime(true) - $startTime) * 1000000);
				if ($this->sleeptime * 1000 - $diff > 0 && $usedAmazon === true) {
					usleep($this->sleeptime * 1000 - $diff);
				}
			}

			if ($this->echooutput) {
				echo "\n";
			}

		} else {
			if ($this->echooutput) {
				$this->pdo->log->doEcho($this->pdo->log->header('No music releases to process.'));
			}
		}
	}

	/**
	 * @param $releasename
	 *
	 * @return array|bool
	 */
	public function parseArtist($releasename)
	{
		if (preg_match('/(.+?)(\d{1,2} \d{1,2} )?\(?(19\d{2}|20[0-1][0-9])\b/', $releasename, $name)) {
			$result = [];
			$result["year"] = $name[3];

			$a = preg_replace('/[ -](\d{1,2} \d{1,2} )?(Bootleg|Boxset|Clean.+Version|Compiled by.+|\dCD|Digipak|DIRFIX|DVBS|FLAC|(Ltd )?(Deluxe|Limited|Special).+Edition|Promo|PROOF|Reissue|Remastered|REPACK|RETAIL(.+UK)?|SACD|Sampler|SAT|Summer.+Mag|UK.+Import|Deluxe.+Version|VINYL|WEB)/i', ' ', $name[1]);
			$b = preg_replace('/[ -]([a-z]+[0-9]+[a-z]+[0-9]+.+|[a-z]{2,}[0-9]{2,}?.+|3FM|B00[a-z0-9]+|BRC482012|H056|UXM1DW086|(4WCD|ATL|bigFM|CDP|DST|ERE|FIM|MBZZ|MSOne|MVRD|QEDCD|RNB|SBD|SFT|ZYX)[ -]\d.+)/i', ' ', $a);
			$c = preg_replace('/[ -](\d{1,2} \d{1,2} )?([A-Z])( ?$)|\(?[0-9]{8,}\)?|[ -](CABLE|FREEWEB|LINE|MAG|MCD|YMRSMILES)|\(([a-z]{2,}[0-9]{2,}|ost)\)|-web-/i', ' ', $b);
			$d = preg_replace('/VA[ -]/', 'Various Artists ', $c);
			$e = preg_replace('/[ -](\d{1,2} \d{1,2} )?(DAB|DE|DVBC|EP|FIX|IT|Jap|NL|PL|(Pure )?FM|SSL|VLS)[ -]/i', ' ', $d);
			$f = preg_replace('/[ -](\d{1,2} \d{1,2} )?(CABLE|CD(A|EP|M|R|S)?|QEDCD|SAT|SBD)[ -]/i', ' ', $e);
			$g = str_replace(['_', '-'], ' ', $f);
			$h = trim(preg_replace('/\s\s+/', ' ', $g));
			$newname = trim(preg_replace('/ [a-z]{2}$| [a-z]{3} \d{2,}$|\d{5,} \d{5,}$|-WEB$/i', '', $h));

			if (!preg_match('/^[a-z0-9]+$/i', $newname) && strlen($newname) > 10) {
				$result["name"] = $newname;
				return $result;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * @param bool $activeOnly
	 *
	 * @return array
	 */
	public function getGenres($activeOnly = false)
	{
		if ($activeOnly) {
			return $this->pdo->query("
				SELECT ge.*
				FROM genres ge
				INNER JOIN
				(
					SELECT DISTINCT genre_id
					FROM musicinfo
				) x ON x.genre_id = ge.id
				WHERE ge.type = " . Category::MUSIC_ROOT . "
				ORDER BY title"
			);
		} else {
			return $this->pdo->query("
				SELECT * FROM genres
				WHERE type = " . Category::MUSIC_ROOT . "
				ORDER BY title"
			);
		}
	}

	/**
	 * @param $nodeId
	 *
	 * @return bool|string
	 */
	public function matchBrowseNode($nodeId)
	{
		$str = '';

		//music nodes above mp3 download nodes
		switch ($nodeId) {
			case '163420':
				$str = 'Music Video & Concerts';
				break;
			case '30':
			case '624869011':
				$str = 'Alternative Rock';
				break;
			case '31':
			case '624881011':
				$str = 'Blues';
				break;
			case '265640':
			case '624894011':
				$str = 'Broadway & Vocalists';
				break;
			case '173425':
			case '624899011':
				$str = "Children's Music";
				break;
			case '173429': //christian
			case '2231705011': //gospel
			case '624905011': //christian & gospel
				$str = 'Christian & Gospel';
				break;
			case '67204':
			case '624916011':
				$str = 'Classic Rock';
				break;
			case '85':
			case '624926011':
				$str = 'Classical';
				break;
			case '16':
			case '624976011':
				$str = 'Country';
				break;
			case '7': //dance & electronic
			case '624988011': //dance & dj
				$str = 'Dance & Electronic';
				break;
			case '32':
			case '625003011':
				$str = 'Folk';
				break;
			case '67207':
			case '625011011':
				$str = 'Hard Rock & Metal';
				break;
			case '33': //world music
			case '625021011': //international
				$str = 'World Music';
				break;
			case '34':
			case '625036011':
				$str = 'Jazz';
				break;
			case '289122':
			case '625054011':
				$str = 'Latin Music';
				break;
			case '36':
			case '625070011':
				$str = 'New Age';
				break;
			case '625075011':
				$str = 'Opera & Vocal';
				break;
			case '37':
			case '625092011':
				$str = 'Pop';
				break;
			case '39':
			case '625105011':
				$str = 'R&B';
				break;
			case '38':
			case '625117011':
				$str = 'Rap & Hip-Hop';
				break;
			case '40':
			case '625129011':
				$str = 'Rock';
				break;
			case '42':
			case '625144011':
				$str = 'Soundtracks';
				break;
			case '35':
			case '625061011':
				$str = 'Miscellaneous';
				break;
		}
		return ($str != '') ? $str : false;
	}
}
