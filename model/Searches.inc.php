<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Searches {
	use Zotero_DataObjects;
	
	private static $objectType = 'search';
	private static $_table = 'savedSearches';
	private static $primaryDataSQLParts = [
		'id' => 'O.searchID',
		'name' => 'O.searchName',
		'libraryID' => 'O.libraryID',
		'key' => 'O.key',
		'dateAdded' => 'O.dateAdded',
		'dateModified' => 'O.dateModified',
		'version' => 'O.version'
	];
	
	
	public static function search($libraryID, $params) {
		$results = array('results' => array(), 'total' => 0);
		
		$shardID = Zotero_Shards::getByLibraryID($libraryID);
		
		$sql = "SELECT SQL_CALC_FOUND_ROWS DISTINCT ";
		if ($params['format'] == 'keys') {
			$sql .= "`key`";
		}
		else if ($params['format'] == 'versions') {
			$sql .= "`key`, version";
		}
		else {
			$sql .= "searchID";
		}
		$sql .= " FROM savedSearches WHERE libraryID=? ";
		$sqlParams = array($libraryID);
		
		// Pass a list of searchIDs, for when the initial search is done via SQL
		$searchIDs = !empty($params['searchIDs'])
			? $params['searchIDs'] : array();
		// Or keys, for the searchKey parameter
		$searchKeys = $params['searchKey'];
		
		if (!empty($params['since'])) {
			$sql .= "AND version > ? ";
			$sqlParams[] = $params['since'];
		}
		
		// TEMP: for sync transition
		if (!empty($params['sincetime'])) {
			$sql .= "AND serverDateModified >= FROM_UNIXTIME(?) ";
			$sqlParams[] = $params['sincetime'];
		}
		
		if ($searchIDs) {
			$sql .= "AND searchID IN ("
					. implode(', ', array_fill(0, sizeOf($searchIDs), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $searchIDs);
		}
		
		if ($searchKeys) {
			$sql .= "AND `key` IN ("
					. implode(', ', array_fill(0, sizeOf($searchKeys), '?'))
					. ") ";
			$sqlParams = array_merge($sqlParams, $searchKeys);
		}
		
		if (!empty($params['sort'])) {
			switch ($params['sort']) {
			case 'title':
				$orderSQL = 'searchName';
				break;
			
			case 'searchKeyList':
				$orderSQL = "FIELD(`key`,"
						. implode(',', array_fill(0, sizeOf($searchKeys), '?')) . ")";
				$sqlParams = array_merge($sqlParams, $searchKeys);
				break;
			
			default:
				$orderSQL = $params['sort'];
			}
			
			$sql .= "ORDER BY $orderSQL";
			if (!empty($params['direction'])) {
				$sql .= " {$params['direction']}";
			}
			$sql .= ", ";
		}
		$sql .= "version " . (!empty($params['direction']) ? $params['direction'] : "ASC")
			. ", searchID " . (!empty($params['direction']) ? $params['direction'] : "ASC") . " ";
		
		if (!empty($params['limit'])) {
			$sql .= "LIMIT ?, ?";
			$sqlParams[] = $params['start'] ? $params['start'] : 0;
			$sqlParams[] = $params['limit'];
		}
		
		if ($params['format'] == 'versions') {
			$rows = Zotero_DB::query($sql, $sqlParams, $shardID);
		}
		// keys and ids
		else {
			$rows = Zotero_DB::columnQuery($sql, $sqlParams, $shardID);
		}
		
		$results['total'] = Zotero_DB::valueQuery("SELECT FOUND_ROWS()", false, $shardID);
		if ($rows) {
			if ($params['format'] == 'keys') {
				$results['results'] = $rows;
			}
			else if ($params['format'] == 'versions') {
				foreach ($rows as $row) {
					$results['results'][$row['key']] = $row['version'];
				}
			}
			else {
				$searches = array();
				foreach ($rows as $id) {
					$searches[] = self::get($libraryID, $id);
				}
				$results['results'] = $searches;
			}
		}
		
		return $results;
	}
	
	
	/**
	 * @param Zotero_Searches $search The search object to update;
	 *                                this should be either an existing
	 *                                search or a new search
	 *                                with a library assigned.
	 * @param object $json Search data to write
	 * @param boolean $requireVersion See Zotero_API::checkJSONObjectVersion()
	 * @return bool True if the search was changed, false otherwise
	 */
	public static function updateFromJSON(Zotero_Search $search,
	                                      $json,
	                                      $requestParams,
	                                      $userID,
	                                      $requireVersion=0,
	                                      $partialUpdate=false) {
		$json = Zotero_API::extractEditableJSON($json);
		$exists = Zotero_API::processJSONObjectKey($search, $json, $requestParams);
		Zotero_API::checkJSONObjectVersion($search, $json, $requestParams, $requireVersion);
		self::validateJSONSearch($json, $requestParams, $partialUpdate && $exists);
		
		if (isset($json->name)) {
			$search->name = $json->name;
		}
		
		if (isset($json->conditions)) {
			$conditions = [];
			foreach ($json->conditions as $condition) {
				$newCondition = get_object_vars($condition);
				// Parse 'mode' (e.g., '/regexp') out of condition name
				if (preg_match('/(.+)\/(.+)/', $newCondition['condition'], $matches)) {
					$newCondition['condition'] = $matches[1];
					$newCondition['mode'] = $matches[2];
				}
				else {
					$newCondition['mode'] = "";
				}
				$conditions[] = $newCondition;
			}
			$search->updateConditions($conditions);
		}
		return !!$search->save();
	}
	
	
	private static function validateJSONSearch($json, $requestParams, $partialUpdate=false) {
		if (!is_object($json)) {
			throw new Exception('$json must be a decoded JSON object');
		}
		
		if ($partialUpdate) {
			$requiredProps = [];
		}
		else {
			$requiredProps = ['name', 'conditions'];
		}
		foreach ($requiredProps as $prop) {
			if (!isset($json->$prop)) {
				throw new Exception("'$prop' property not provided", Z_ERROR_INVALID_INPUT);
			}
		}
		foreach ($json as $key => $val) {
			switch ($key) {
				// Handled by Zotero_API::checkJSONObjectVersion()
				case 'key':
				case 'version':
				case 'searchKey':
				case 'searchVersion':
					break;
				
				case 'name':
					if (!is_string($val)) {
						throw new Exception("'name' must be a string", Z_ERROR_INVALID_INPUT);
					}
					
					if ($val === "") {
						throw new Exception("Search name cannot be empty", Z_ERROR_INVALID_INPUT);
					}
					
					if (mb_strlen($val) > 255) {
						throw new Exception("Search name cannot be longer than 255 characters", Z_ERROR_FIELD_TOO_LONG);
					}
					break;
					
				case 'conditions':
					if (!is_array($val)) {
						throw new Exception("'conditions' must be an array (" . gettype($val) . ")", Z_ERROR_INVALID_INPUT);
					}
					if (empty($val)) {
						throw new Exception("'conditions' cannot be empty", Z_ERROR_INVALID_INPUT);
					}
					
					foreach ($val as $condition) {
						$requiredProps = ['condition', 'operator', 'value'];
						foreach ($requiredProps as $prop) {
							if (!isset($condition->$prop)) {
								throw new Exception("'$prop' property not provided for search condition", Z_ERROR_INVALID_INPUT);
							}
						}
						
						foreach ($condition as $conditionKey => $conditionVal) {
							if (!is_string($conditionVal)) {
								throw new Exception("'$conditionKey' must be a string", Z_ERROR_INVALID_INPUT);
							}
							
							switch ($conditionKey) {
							case 'condition':
								if ($conditionVal === "") {
									throw new Exception("Search condition cannot be empty", Z_ERROR_INVALID_INPUT);
								}
								$maxLen = 50;
								if (strlen($conditionVal) > $maxLen) {
									throw new Exception("Search condition cannot be longer than $maxLen characters", Z_ERROR_INVALID_INPUT);
								}
								break;
								
							case 'operator':
								if ($conditionVal === "") {
									throw new Exception("Search operator cannot be empty", Z_ERROR_INVALID_INPUT);
								}
								$maxLen = 25;
								if (strlen($conditionVal) > $maxLen) {
									throw new Exception("Search operator cannot be longer than $maxLen characters", Z_ERROR_INVALID_INPUT);
								}
								break;
								
							case 'value':
								$maxLen = 255;
								if (strlen($conditionVal) > $maxLen) {
									throw new Exception("Search operator cannot be longer than $maxLen characters", Z_ERROR_INVALID_INPUT);
								}
								break;
								
							default:
								throw new Exception("Invalid property '$conditionKey' for search condition", Z_ERROR_INVALID_INPUT);
							}
						}
					}
					break;
				
				default:
					throw new Exception("Invalid property '$key'", Z_ERROR_INVALID_INPUT);
			}
		}
	}
}

Zotero_Searches::init();
