<?php
/***********************************************************************
| Cerb(tm) developed by Webgroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2002-2014, Webgroup Media LLC
|   unless specifically noted otherwise.
|
| This source code is released under the Devblocks Public License.
| The latest version of this license can be found here:
| http://cerberusweb.com/license
|
| By using this software, you acknowledge having read this license
| and agree to be bound thereby.
| ______________________________________________________________________
|	http://www.cerbweb.com	    http://www.webgroupmedia.com/
***********************************************************************/

class DAO_Address extends Cerb_ORMHelper {
	const ID = 'id';
	const EMAIL = 'email';
	const FIRST_NAME = 'first_name';
	const LAST_NAME = 'last_name';
	const CONTACT_PERSON_ID = 'contact_person_id';
	const CONTACT_ORG_ID = 'contact_org_id';
	const NUM_SPAM = 'num_spam';
	const NUM_NONSPAM = 'num_nonspam';
	const IS_BANNED = 'is_banned';
	const IS_DEFUNCT = 'is_defunct';
	const UPDATED = 'updated';
	
	private function __construct() {}
	
	public static function getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		return array(
			'id' => $translate->_('address.id'),
			'email' => $translate->_('address.email'),
			'first_name' => $translate->_('address.first_name'),
			'last_name' => $translate->_('address.last_name'),
			'contact_person_id' => $translate->_('address.contact_person_id'),
			'contact_org_id' => $translate->_('address.contact_org_id'),
			'num_spam' => $translate->_('address.num_spam'),
			'num_nonspam' => $translate->_('address.num_nonspam'),
			'is_banned' => $translate->_('address.is_banned'),
			'is_defunct' => $translate->_('address.is_defunct'),
			'updated' => mb_convert_case($translate->_('common.updated'), MB_CASE_TITLE),
		);
	}
	
	/**
	 * Creates a new email address record.
	 *
	 * @param array $fields An array of fields=>values
	 * @return integer The new address ID
	 *
	 * DAO_Address::create(array(
	 *   DAO_Address::EMAIL => 'user@domain'
	 * ));
	 *
	 */
	static function create($fields) {
		$db = DevblocksPlatform::getDatabaseService();
		
		if(null == ($email = @$fields[self::EMAIL]))
			return NULL;
		
		// [TODO] Validate
		@$addresses = imap_rfc822_parse_adrlist('<'.$email.'>', 'host');
		
		if(!is_array($addresses) || empty($addresses))
			return NULL;
		
		$address = array_shift($addresses);
		
		if(empty($address->host) || $address->host == 'host')
			return NULL;
		
		$full_address = trim(strtolower($address->mailbox.'@'.$address->host));
			
		// Make sure the address doesn't exist already
		if(null == ($check = self::getByEmail($full_address))) {
			$sql = sprintf("INSERT INTO address (email,first_name,last_name,contact_person_id,contact_org_id,num_spam,num_nonspam,is_banned,is_defunct,updated) ".
				"VALUES (%s,'','',0,0,0,0,0,0,0)",
				$db->qstr($full_address)
			);
			$db->Execute($sql) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg());
			$id = $db->LastInsertId();

		} else { // update
			$id = $check->id;
			unset($fields[self::ID]);
			unset($fields[self::EMAIL]);
		}

		self::update($id, $fields);
		
		return $id;
	}
	
	static function update($ids, $fields, $check_deltas=true) {
		if(!is_array($ids))
			$ids = array($ids);
		
		if(!isset($fields[DAO_Address::UPDATED]))
			$fields[DAO_Address::UPDATED] = time();
		
		// Make a diff for the requested objects in batches
		
		$chunks = array_chunk($ids, 100, true);
		while($batch_ids = array_shift($chunks)) {
			if(empty($batch_ids))
				continue;
			
			// Send events
			if($check_deltas) {
				CerberusContexts::checkpointChanges(CerberusContexts::CONTEXT_ADDRESS, $batch_ids);
			}
			
			// Make changes
			parent::_update($batch_ids, 'address', $fields);
			
			// Send events
			if($check_deltas) {
				// Trigger an event about the changes
				$eventMgr = DevblocksPlatform::getEventService();
				$eventMgr->trigger(
					new Model_DevblocksEvent(
						'dao.address.update',
						array(
							'fields' => $fields,
						)
					)
				);
				
				// Log the context update
				DevblocksPlatform::markContextChanged(CerberusContexts::CONTEXT_ADDRESS, $batch_ids);
			}
		}
	}
	
	static function updateWhere($fields, $where) {
		parent::_updateWhere('address', $fields, $where);
	}
	
	static function maint() {
		$db = DevblocksPlatform::getDatabaseService();
		$logger = DevblocksPlatform::getConsoleLog();
		
		$sql = "DELETE FROM address_to_worker WHERE worker_id NOT IN (SELECT id FROM worker)";
		$db->Execute($sql);
		$logger->info('[Maint] Purged ' . $db->Affected_Rows() . ' address_to_worker records.');

		// Fire event
		$eventMgr = DevblocksPlatform::getEventService();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'context.maint',
				array(
					'context' => CerberusContexts::CONTEXT_ADDRESS,
					'context_table' => 'address',
					'context_key' => 'id',
				)
			)
		);
	}
	
	static function delete($ids) {
		if(!is_array($ids)) $ids = array($ids);

		if(empty($ids))
			return;

		$db = DevblocksPlatform::getDatabaseService();
		
		$address_ids = implode(',', $ids);
		
		// Addresses
		$sql = sprintf("DELETE FROM address WHERE id IN (%s)", $address_ids);
		$db->Execute($sql) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg());
	
		// Fire event
		$eventMgr = DevblocksPlatform::getEventService();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'context.delete',
				array(
					'context' => CerberusContexts::CONTEXT_ADDRESS,
					'context_ids' => $ids
				)
			)
		);
	}
	
	static function getWhere($where=null, $sortBy=null, $sortAsc=true, $limit=null) {
		$db = DevblocksPlatform::getDatabaseService();
		
		list($where_sql, $sort_sql, $limit_sql) = self::_getWhereSQL($where, $sortBy, $sortAsc, $limit);
		
		// SQL
		$sql = "SELECT id, email, first_name, last_name, contact_person_id, contact_org_id, num_spam, num_nonspam, is_banned, is_defunct, updated ".
			"FROM address ".
			$where_sql.
			$sort_sql.
			$limit_sql
		;
		$rs = $db->Execute($sql);

		$objects = self::_getObjectsFromResult($rs);

		return $objects;
	}

	/**
	 * @param resource $rs
	 * @return Model_Address[]
	 */
	static private function _getObjectsFromResult($rs) {
		$objects = array();
		
		while($row = mysqli_fetch_assoc($rs)) {
			$object = new Model_Address();
			$object->id = intval($row['id']);
			$object->email = $row['email'];
			$object->first_name = $row['first_name'];
			$object->last_name = $row['last_name'];
			$object->contact_person_id = intval($row['contact_person_id']);
			$object->contact_org_id = intval($row['contact_org_id']);
			$object->num_spam = intval($row['num_spam']);
			$object->num_nonspam = intval($row['num_nonspam']);
			$object->is_banned = intval($row['is_banned']);
			$object->is_defunct = intval($row['is_defunct']);
			$object->updated = intval($row['updated']);
			$objects[$object->id] = $object;
		}
		
		mysqli_free_result($rs);
		
		return $objects;
	}
	
	/**
	 * @return Model_Address|null
	 */
	static function getByEmail($email) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$results = self::getWhere(sprintf("%s = %s",
			self::EMAIL,
			$db->qstr(strtolower($email))
		));

		if(!empty($results))
			return array_shift($results);
			
		return NULL;
	}
	
	static function getCountByOrgId($org_id) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$sql = sprintf("SELECT count(id) FROM address WHERE contact_org_id = %d",
			$org_id
		);
		return intval($db->GetOne($sql));
	}
	
	/**
	 * Enter description here...
	 *
	 * @param unknown_type $id
	 * @return Model_Address
	 */
	static function get($id) {
		if(empty($id)) return null;
		
		$addresses = DAO_Address::getWhere(
			sprintf("%s = %d",
				self::ID,
				$id
		));
		
		if(isset($addresses[$id]))
			return $addresses[$id];
			
		return null;
	}
	
	/**
	 * Enter description here...
	 *
	 * @param unknown_type $email
	 * @param unknown_type $create_if_null
	 * @return Model_Address
	 */
	static function lookupAddress($email,$create_if_null=false) {
		$db = DevblocksPlatform::getDatabaseService();
		
		$address = null;
		
		$email = trim(mb_convert_case($email, MB_CASE_LOWER));
		
		$addresses = self::getWhere(sprintf("email = %s",
			$db->qstr($email)
		));
		
		if(is_array($addresses) && !empty($addresses)) {
			$address = array_shift($addresses);
			
		} elseif($create_if_null) {
			$fields = array(
				self::EMAIL => $email
			);
			
			if(false == ($id = DAO_Address::create($fields)))
				return false;
			
			$address = DAO_Address::get($id);
		}
		
		return $address;
	}
	
	static function addOneToSpamTotal($address_id) {
		$db = DevblocksPlatform::getDatabaseService();
		$sql = sprintf("UPDATE address SET num_spam = num_spam + 1 WHERE id = %d",$address_id);
		$db->Execute($sql) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg());
	}
	
	static function addOneToNonSpamTotal($address_id) {
		$db = DevblocksPlatform::getDatabaseService();
		$sql = sprintf("UPDATE address SET num_nonspam = num_nonspam + 1 WHERE id = %d",$address_id);
		$db->Execute($sql) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg());
	}
	
	public static function random() {
		return self::_getRandom('address');
	}
	
	public static function getSearchQueryComponents($columns, $params, $sortBy=null, $sortAsc=null) {
		$fields = SearchFields_Address::getFields();
		
		// Sanitize
		if('*'==substr($sortBy,0,1) || !isset($fields[$sortBy])
			|| (SearchFields_Address::EMAIL != $sortBy && !in_array($sortBy, $columns))
		)
			$sortBy=null;
		
		list($tables,$wheres) = parent::_parseSearchParams($params, $columns, $fields,$sortBy);
		
		$select_sql = sprintf("SELECT ".
			"a.id as %s, ".
			"a.email as %s, ".
			"a.first_name as %s, ".
			"a.last_name as %s, ".
			"a.contact_person_id as %s, ".
			"a.contact_org_id as %s, ".
			"o.name as %s, ".
			"a.num_spam as %s, ".
			"a.num_nonspam as %s, ".
			"a.is_banned as %s, ".
			"a.is_defunct as %s, ".
			"a.updated as %s ",
				SearchFields_Address::ID,
				SearchFields_Address::EMAIL,
				SearchFields_Address::FIRST_NAME,
				SearchFields_Address::LAST_NAME,
				SearchFields_Address::CONTACT_PERSON_ID,
				SearchFields_Address::CONTACT_ORG_ID,
				SearchFields_Address::ORG_NAME,
				SearchFields_Address::NUM_SPAM,
				SearchFields_Address::NUM_NONSPAM,
				SearchFields_Address::IS_BANNED,
				SearchFields_Address::IS_DEFUNCT,
				SearchFields_Address::UPDATED
			);
		
		$join_sql =
			"FROM address a ".
			"LEFT JOIN contact_org o ON (o.id=a.contact_org_id) ".
		
			// [JAS]: Dynamic table joins
			(isset($tables['context_link']) ? "INNER JOIN context_link ON (context_link.to_context = 'cerberusweb.contexts.address' AND context_link.to_context_id = a.id) " : " ")
			;

		$cfield_index_map = array(
			CerberusContexts::CONTEXT_ADDRESS => 'a.id',
			CerberusContexts::CONTEXT_ORG => 'a.contact_org_id',
		);
		
		// Custom field joins
		list($select_sql, $join_sql, $has_multiple_values) = self::_appendSelectJoinSqlForCustomFieldTables(
			$tables,
			$params,
			$cfield_index_map,
			$select_sql,
			$join_sql
		);
		
		$where_sql = "".
			(!empty($wheres) ? sprintf("WHERE %s ",implode(' AND ',$wheres)) : "WHERE 1 ");
		
		$sort_sql =	(!empty($sortBy) ? sprintf("ORDER BY %s %s ",$sortBy,($sortAsc || is_null($sortAsc))?"ASC":"DESC") : " ");
		
		// Translate virtual fields
		
		$args = array(
			'join_sql' => &$join_sql,
			'where_sql' => &$where_sql,
			'tables' => &$tables,
			'has_multiple_values' => &$has_multiple_values
		);
		
		array_walk_recursive(
			$params,
			array('DAO_Address', '_translateVirtualParameters'),
			$args
		);
		
		$result = array(
			'primary_table' => 'a',
			'select' => $select_sql,
			'join' => $join_sql,
			'where' => $where_sql,
			'has_multiple_values' => $has_multiple_values,
			'sort' => $sort_sql,
		);
		
		return $result;
	}
	
	private static function _translateVirtualParameters($param, $key, &$args) {
		if(!is_a($param, 'DevblocksSearchCriteria'))
			return;

		$from_context = CerberusContexts::CONTEXT_ADDRESS;
		$from_index = 'a.id';
		
		$param_key = $param->field;
		settype($param_key, 'string');
		
		switch($param_key) {
			case SearchFields_Address::FULLTEXT_COMMENT_CONTENT:
				$search = Extension_DevblocksSearchSchema::get(Search_CommentContent::ID);
				$query = $search->getQueryFromParam($param);
				$ids = $search->query($query, array('context_crc32' => sprintf("%u", crc32($from_context))));
				
				if(is_array($ids)) {
					$from_ids = DAO_Comment::getContextIdsByContextAndIds($from_context, $ids);
					
					$args['where_sql'] .= sprintf('AND %s IN (%s) ',
						$from_index,
						implode(', ', (!empty($from_ids) ? $from_ids : array(-1)))
					);
					
				} elseif(is_string($ids)) {
					$db = DevblocksPlatform::getDatabaseService();
					$temp_table = sprintf("_tmp_%s", uniqid());
					
					$db->Execute(sprintf("CREATE TEMPORARY TABLE %s SELECT DISTINCT context_id AS id FROM comment INNER JOIN %s ON (%s.id=comment.id)",
						$temp_table,
						$ids,
						$ids
					));
					
					$args['join_sql'] .= sprintf("INNER JOIN %s ON (%s.id=a.id) ",
						$temp_table,
						$temp_table
					);
				}
				
				break;
			
			case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				$args['has_multiple_values'] = true;
				self::_searchComponentsVirtualContextLinks($param, $from_context, $from_index, $args['join_sql'], $args['where_sql']);
				break;
				
			case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				self::_searchComponentsVirtualHasFieldset($param, $from_context, $from_index, $args['join_sql'], $args['where_sql']);
				break;
			
			case SearchFields_Address::VIRTUAL_WATCHERS:
				$args['has_multiple_values'] = true;
				self::_searchComponentsVirtualWatchers($param, $from_context, $from_index, $args['join_sql'], $args['where_sql'], $args['tables']);
				break;
		}
	}
	
	/**
	 * Enter description here...
	 *
	 * @param DevblocksSearchCriteria[] $params
	 * @param integer $limit
	 * @param integer $page
	 * @param string $sortBy
	 * @param boolean $sortAsc
	 * @param boolean $withCounts
	 * @return array
	 */
	static function search($columns, $params, $limit=10, $page=0, $sortBy=null, $sortAsc=null, $withCounts=true) {
		$db = DevblocksPlatform::getDatabaseService();

		// Build search queries
		$query_parts = self::getSearchQueryComponents($columns,$params,$sortBy,$sortAsc);

		$select_sql = $query_parts['select'];
		$join_sql = $query_parts['join'];
		$where_sql = $query_parts['where'];
		$has_multiple_values = $query_parts['has_multiple_values'];
		$sort_sql = $query_parts['sort'];
		
		$sql =
			$select_sql.
			$join_sql.
			$where_sql.
			($has_multiple_values ? 'GROUP BY a.id ' : '').
			$sort_sql;
			
		$rs = $db->SelectLimit($sql,$limit,$page*$limit) or die(__CLASS__ . '('.__LINE__.')'. ':' . $db->ErrorMsg());
		
		$results = array();
		
		while($row = mysqli_fetch_assoc($rs)) {
			$id = intval($row[SearchFields_Address::ID]);
			$results[$id] = $row;
		}

		$total = count($results);
		
		if($withCounts) {
			// We can skip counting if we have a less-than-full single page
			if(!(0 == $page && $total < $limit)) {
				$count_sql =
					($has_multiple_values ? "SELECT COUNT(DISTINCT a.id) " : "SELECT COUNT(*) ").
					$join_sql.
					$where_sql;
				$total = $db->GetOne($count_sql);
			}
		}
		
		mysqli_free_result($rs);
		
		return array($results,$total);
	}
};

class SearchFields_Address implements IDevblocksSearchFields {
	// Address
	const ID = 'a_id';
	const EMAIL = 'a_email';
	const FIRST_NAME = 'a_first_name';
	const LAST_NAME = 'a_last_name';
	const CONTACT_PERSON_ID = 'a_contact_person_id';
	const CONTACT_ORG_ID = 'a_contact_org_id';
	const NUM_SPAM = 'a_num_spam';
	const NUM_NONSPAM = 'a_num_nonspam';
	const IS_BANNED = 'a_is_banned';
	const IS_DEFUNCT = 'a_is_defunct';
	const UPDATED = 'a_updated';
	
	const ORG_NAME = 'o_name';

	// Virtuals
	const VIRTUAL_CONTEXT_LINK = '*_context_link';
	const VIRTUAL_HAS_FIELDSET = '*_has_fieldset';
	const VIRTUAL_WATCHERS = '*_workers';
	
	// Comment Content
	const FULLTEXT_COMMENT_CONTENT = 'ftcc_content';

	// Context Links
	const CONTEXT_LINK = 'cl_context_from';
	const CONTEXT_LINK_ID = 'cl_context_from_id';
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$columns = array(
			self::ID => new DevblocksSearchField(self::ID, 'a', 'id', $translate->_('address.id'), null),
			self::EMAIL => new DevblocksSearchField(self::EMAIL, 'a', 'email', $translate->_('address.email'), Model_CustomField::TYPE_SINGLE_LINE),
			self::FIRST_NAME => new DevblocksSearchField(self::FIRST_NAME, 'a', 'first_name', $translate->_('address.first_name'), Model_CustomField::TYPE_SINGLE_LINE),
			self::LAST_NAME => new DevblocksSearchField(self::LAST_NAME, 'a', 'last_name', $translate->_('address.last_name'), Model_CustomField::TYPE_SINGLE_LINE),
			self::CONTACT_PERSON_ID => new DevblocksSearchField(self::NUM_SPAM, 'a', 'contact_person_id', $translate->_('address.contact_person_id'), null),
			self::NUM_SPAM => new DevblocksSearchField(self::NUM_SPAM, 'a', 'num_spam', $translate->_('address.num_spam'), Model_CustomField::TYPE_NUMBER),
			self::NUM_NONSPAM => new DevblocksSearchField(self::NUM_NONSPAM, 'a', 'num_nonspam', $translate->_('address.num_nonspam'), Model_CustomField::TYPE_NUMBER),
			self::IS_BANNED => new DevblocksSearchField(self::IS_BANNED, 'a', 'is_banned', $translate->_('address.is_banned'), Model_CustomField::TYPE_CHECKBOX),
			self::IS_DEFUNCT => new DevblocksSearchField(self::IS_DEFUNCT, 'a', 'is_defunct', $translate->_('address.is_defunct'), Model_CustomField::TYPE_CHECKBOX),
			self::UPDATED => new DevblocksSearchField(self::UPDATED, 'a', 'updated', $translate->_('common.updated'), Model_CustomField::TYPE_DATE),
			
			self::CONTACT_ORG_ID => new DevblocksSearchField(self::CONTACT_ORG_ID, 'a', 'contact_org_id', $translate->_('address.contact_org_id'), null),
			self::ORG_NAME => new DevblocksSearchField(self::ORG_NAME, 'o', 'name', $translate->_('contact_org.name'), Model_CustomField::TYPE_SINGLE_LINE),
			
			self::VIRTUAL_CONTEXT_LINK => new DevblocksSearchField(self::VIRTUAL_CONTEXT_LINK, '*', 'context_link', $translate->_('common.links'), null),
			self::VIRTUAL_HAS_FIELDSET => new DevblocksSearchField(self::VIRTUAL_HAS_FIELDSET, '*', 'has_fieldset', $translate->_('common.fieldset'), null),
			self::VIRTUAL_WATCHERS => new DevblocksSearchField(self::VIRTUAL_WATCHERS, '*', 'workers', $translate->_('common.watchers'), 'WS'),
			
			self::CONTEXT_LINK => new DevblocksSearchField(self::CONTEXT_LINK, 'context_link', 'from_context', null, null),
			self::CONTEXT_LINK_ID => new DevblocksSearchField(self::CONTEXT_LINK_ID, 'context_link', 'from_context_id', null, null),
				
			self::FULLTEXT_COMMENT_CONTENT => new DevblocksSearchField(self::FULLTEXT_COMMENT_CONTENT, 'ftcc', 'content', $translate->_('comment.filters.content'), 'FT'),
		);
		
		// Fulltext indexes
		
		$columns[self::FULLTEXT_COMMENT_CONTENT]->ft_schema = Search_CommentContent::ID;
		
		// Custom fields with fieldsets
		
		$custom_columns = DevblocksSearchField::getCustomSearchFieldsByContexts(array(
			CerberusContexts::CONTEXT_ADDRESS,
			CerberusContexts::CONTEXT_ORG,
		));
		
		if(is_array($custom_columns))
			$columns = array_merge($columns, $custom_columns);
		
		// Sort by label (translation-conscious)
		DevblocksPlatform::sortObjects($columns, 'db_label');
		
		return $columns;
	}
};

class Model_Address {
	public $id;
	public $email = '';
	public $first_name = '';
	public $last_name = '';
	public $contact_person_id = 0;
	public $contact_org_id = 0;
	public $num_spam = 0;
	public $num_nonspam = 0;
	public $is_banned = 0;
	public $is_defunct = 0;
	public $updated = 0;

	function Model_Address() {}
	
	function getName() {
		return sprintf("%s%s%s",
			$this->first_name,
			(!empty($this->first_name) && !empty($this->last_name)) ? " " : "",
			$this->last_name
		);
	}
	
	function getNameWithEmail() {
		$name = $this->getName();
		
		if(!empty($name))
			$name .= ' <' . $this->email . '>';
		else
			$name = $this->email;
		
		return $name;
	}
};

class View_Address extends C4_AbstractView implements IAbstractView_Subtotals {
	const DEFAULT_ID = 'addresses';

	function __construct() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$this->id = self::DEFAULT_ID;
		$this->name = $translate->_('addy_book.tab.addresses');
		$this->renderLimit = 10;
		$this->renderSortBy = 'a_email';
		$this->renderSortAsc = true;

		$this->view_columns = array(
			SearchFields_Address::FIRST_NAME,
			SearchFields_Address::LAST_NAME,
			SearchFields_Address::ORG_NAME,
			SearchFields_Address::NUM_NONSPAM,
			SearchFields_Address::NUM_SPAM,
		);
		
		$this->addColumnsHidden(array(
			SearchFields_Address::CONTACT_PERSON_ID,
			SearchFields_Address::CONTACT_ORG_ID,
			SearchFields_Address::CONTEXT_LINK,
			SearchFields_Address::CONTEXT_LINK_ID,
			SearchFields_Address::FULLTEXT_COMMENT_CONTENT,
			SearchFields_Address::VIRTUAL_CONTEXT_LINK,
			SearchFields_Address::VIRTUAL_HAS_FIELDSET,
			SearchFields_Address::VIRTUAL_WATCHERS,
		));
		
		$this->addParamsHidden(array(
			SearchFields_Address::CONTACT_PERSON_ID,
			SearchFields_Address::CONTACT_ORG_ID,
			SearchFields_Address::ID,
			SearchFields_Address::CONTEXT_LINK,
			SearchFields_Address::CONTEXT_LINK_ID,
		));
	}

	function getData() {
		$objects = DAO_Address::search(
			$this->view_columns,
			$this->getParams(),
			$this->renderLimit,
			$this->renderPage,
			$this->renderSortBy,
			$this->renderSortAsc,
			$this->renderTotal
		);
		return $objects;
	}
	
	function getDataAsObjects($ids=null) {
		return $this->_getDataAsObjects('DAO_Address', $ids);
	}
	
	function getDataSample($size) {
		return $this->_doGetDataSample('DAO_Address', $size);
	}
	
	function getSubtotalFields() {
		$all_fields = $this->getParamsAvailable(true);
		
		$fields = array();

		if(is_array($all_fields))
		foreach($all_fields as $field_key => $field_model) {
			$pass = false;
			
			switch($field_key) {
				case SearchFields_Address::FIRST_NAME:
				case SearchFields_Address::IS_BANNED:
				case SearchFields_Address::IS_DEFUNCT:
				case SearchFields_Address::LAST_NAME:
				case SearchFields_Address::ORG_NAME:
					$pass = true;
					break;
					
				// Virtuals
				case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				case SearchFields_Address::VIRTUAL_WATCHERS:
					$pass = true;
					break;
					
				// Valid custom fields
				default:
					if('cf_' == substr($field_key,0,3))
						$pass = $this->_canSubtotalCustomField($field_key);
					break;
			}
			
			if($pass)
				$fields[$field_key] = $field_model;
		}
		
		return $fields;
	}
	
	function getSubtotalCounts($column) {
		$counts = array();
		$fields = $this->getFields();

		if(!isset($fields[$column]))
			return array();
		
		switch($column) {
			case SearchFields_Address::IS_BANNED:
			case SearchFields_Address::IS_DEFUNCT:
				$counts = $this->_getSubtotalCountForBooleanColumn('DAO_Address', $column);
				break;
				
			case SearchFields_Address::ORG_NAME:
			case SearchFields_Address::FIRST_NAME:
			case SearchFields_Address::LAST_NAME:
				$counts = $this->_getSubtotalCountForStringColumn('DAO_Address', $column);
				break;
				
			// Virtuals
			
			case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				$counts = $this->_getSubtotalCountForContextLinkColumn('DAO_Address', CerberusContexts::CONTEXT_ADDRESS, $column);
				break;
				
			case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				$counts = $this->_getSubtotalCountForHasFieldsetColumn('DAO_Address', CerberusContexts::CONTEXT_ADDRESS, $column);
				break;
				
			case SearchFields_Address::VIRTUAL_WATCHERS:
				$counts = $this->_getSubtotalCountForWatcherColumn('DAO_Address', $column);
				break;
			
			default:
				// Custom fields
				if('cf_' == substr($column,0,3)) {
					$counts = $this->_getSubtotalCountForCustomColumn('DAO_Address', $column, 'a.id');
				}
				
				break;
		}
		
		return $counts;
	}
	
	function render() {
		$this->_sanitize();
		
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('id', $this->id);
		
		$tpl->assign('view', $this);

		$custom_fields =
			DAO_CustomField::getByContext(CerberusContexts::CONTEXT_ADDRESS) +
			DAO_CustomField::getByContext(CerberusContexts::CONTEXT_ORG)
			;
		$tpl->assign('custom_fields', $custom_fields);
		
		switch($this->renderTemplate) {
			case 'contextlinks_chooser':
			default:
				$tpl->assign('view_template', 'devblocks:cerberusweb.core::contacts/addresses/view.tpl');
				$tpl->display('devblocks:cerberusweb.core::internal/views/subtotals_and_view.tpl');
				break;
		}
		
		$tpl->clearAssign('custom_fields');
		$tpl->clearAssign('id');
	}

	function renderCriteria($field) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('id', $this->id);

		switch($field) {
			case SearchFields_Address::EMAIL:
			case SearchFields_Address::FIRST_NAME:
			case SearchFields_Address::LAST_NAME:
			case SearchFields_Address::ORG_NAME:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__string.tpl');
				break;
				
			case SearchFields_Address::NUM_SPAM:
			case SearchFields_Address::NUM_NONSPAM:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__number.tpl');
				break;
				
			case SearchFields_Address::IS_BANNED:
			case SearchFields_Address::IS_DEFUNCT:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__bool.tpl');
				break;
				
			case SearchFields_Address::UPDATED:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__date.tpl');
				break;
				
			case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				$contexts = Extension_DevblocksContext::getAll(false);
				$tpl->assign('contexts', $contexts);
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_link.tpl');
				break;
				
			case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				$this->_renderCriteriaHasFieldset($tpl, CerberusContexts::CONTEXT_ADDRESS);
				break;
				
			case SearchFields_Address::VIRTUAL_WATCHERS:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_worker.tpl');
				break;
				
			case SearchFields_Address::FULLTEXT_COMMENT_CONTENT:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__fulltext.tpl');
				break;
				
			default:
				// Custom Fields
				if('cf_' == substr($field,0,3)) {
					$this->_renderCriteriaCustomField($tpl, substr($field,3));
				} else {
					echo ' ';
				}
				break;
		}
	}

	function renderVirtualCriteria($param) {
		$key = $param->field;
		
		switch($key) {
			case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				$this->_renderVirtualContextLinks($param);
				break;
				
			case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				$this->_renderVirtualHasFieldset($param);
				break;
			
			case SearchFields_Address::VIRTUAL_WATCHERS:
				$this->_renderVirtualWatchers($param);
				break;
		}
	}
	
	function renderCriteriaParam($param) {
		$field = $param->field;
		$values = !is_array($param->value) ? array($param->value) : $param->value;

		switch($field) {
			case SearchFields_Address::IS_BANNED:
			case SearchFields_Address::IS_DEFUNCT:
				$this->_renderCriteriaParamBoolean($param);
				break;
			
			default:
				parent::renderCriteriaParam($param);
				break;
		}
	}

	function getFields() {
		return SearchFields_Address::getFields();
	}

	function doSetCriteria($field, $oper, $value) {
		$criteria = null;

		switch($field) {
			case SearchFields_Address::EMAIL:
			case SearchFields_Address::FIRST_NAME:
			case SearchFields_Address::LAST_NAME:
			case SearchFields_Address::ORG_NAME:
				$criteria = $this->_doSetCriteriaString($field, $oper, $value);
				break;
				
			case SearchFields_Address::NUM_SPAM:
			case SearchFields_Address::NUM_NONSPAM:
				$criteria = new DevblocksSearchCriteria($field,$oper,$value);
				break;
				
			case SearchFields_Address::IS_BANNED:
			case SearchFields_Address::IS_DEFUNCT:
				@$bool = DevblocksPlatform::importGPC($_REQUEST['bool'],'integer',1);
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_Address::UPDATED:
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_Address::VIRTUAL_CONTEXT_LINK:
				@$context_links = DevblocksPlatform::importGPC($_REQUEST['context_link'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$context_links);
				break;
				
			case SearchFields_Address::VIRTUAL_HAS_FIELDSET:
				@$options = DevblocksPlatform::importGPC($_REQUEST['options'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$options);
				break;
				
			case SearchFields_Address::VIRTUAL_WATCHERS:
				@$worker_ids = DevblocksPlatform::importGPC($_REQUEST['worker_id'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$worker_ids);
				break;
				
			case SearchFields_Address::FULLTEXT_COMMENT_CONTENT:
				@$scope = DevblocksPlatform::importGPC($_REQUEST['scope'],'string','expert');
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_FULLTEXT,array($value,$scope));
				break;
				
			default:
				// Custom Fields
				if(substr($field,0,3)=='cf_') {
					$criteria = $this->_doSetCriteriaCustomField($field, substr($field,3));
				}
				break;
		}

		if(!empty($criteria)) {
			$this->addParam($criteria);
			$this->renderPage = 0;
		}
	}

	function doBulkUpdate($filter, $do, $ids=array()) {
		@set_time_limit(600); // 10m
		
		$change_fields = array();
		$custom_fields = array();

		// Make sure we have actions
		if(empty($do))
			return;

		// Make sure we have checked items if we want a checked list
		if(0 == strcasecmp($filter,"checks") && empty($ids))
			return;
			
		if(is_array($do))
		foreach($do as $k => $v) {
			switch($k) {
				case 'org_id':
					$change_fields[DAO_Address::CONTACT_ORG_ID] = intval($v);
					break;
				case 'banned':
					$change_fields[DAO_Address::IS_BANNED] = intval($v);
					break;
				case 'defunct':
					$change_fields[DAO_Address::IS_DEFUNCT] = intval($v);
					break;
				default:
					// Custom fields
					if(substr($k,0,3)=="cf_") {
						$custom_fields[substr($k,3)] = $v;
					}
			}
		}
		
		$pg = 0;

		if(empty($ids))
		do {
			list($objects,$null) = DAO_Address::search(
				array(),
				$this->getParams(),
				100,
				$pg++,
				SearchFields_Address::ID,
				true,
				false
			);
			 
			$ids = array_merge($ids, array_keys($objects));
			 
		} while(!empty($objects));

		// Broadcast?
		if(isset($do['broadcast'])) {
			$tpl_builder = DevblocksPlatform::getTemplateBuilder();
			
			$params = $do['broadcast'];
			if(
				!isset($params['worker_id'])
				|| empty($params['worker_id'])
				|| !isset($params['subject'])
				|| empty($params['subject'])
				|| !isset($params['message'])
				|| empty($params['message'])
				)
				break;

			$is_queued = (isset($params['is_queued']) && $params['is_queued']) ? true : false;
			$next_is_closed = (isset($params['next_is_closed'])) ? intval($params['next_is_closed']) : 0;
			
			if(is_array($ids))
			foreach($ids as $addy_id) {
				try {
					CerberusContexts::getContext(CerberusContexts::CONTEXT_ADDRESS, $addy_id, $tpl_labels, $tpl_tokens);
					
					$tpl_dict = new DevblocksDictionaryDelegate($tpl_tokens);

					if($tpl_dict->is_defunct)
						continue;
					
					$subject = $tpl_builder->build($params['subject'], $tpl_dict);
					$body = $tpl_builder->build($params['message'], $tpl_dict);
					
					$json_params = array(
						'to' => $tpl_dict->address,
						'group_id' => $params['group_id'],
						'next_is_closed' => $next_is_closed,
						'is_broadcast' => 1,
					);
					
					if(isset($params['format']))
						$json_params['format'] = $params['format'];
					
					if(isset($params['html_template_id']))
						$json_params['html_template_id'] = intval($params['html_template_id']);
					
					if(isset($params['file_ids']))
						$json_params['file_ids'] = $params['file_ids'];
					
					$fields = array(
						DAO_MailQueue::TYPE => Model_MailQueue::TYPE_COMPOSE,
						DAO_MailQueue::TICKET_ID => 0,
						DAO_MailQueue::WORKER_ID => $params['worker_id'],
						DAO_MailQueue::UPDATED => time(),
						DAO_MailQueue::HINT_TO => $tpl_dict->address,
						DAO_MailQueue::SUBJECT => $subject,
						DAO_MailQueue::BODY => $body,
						DAO_MailQueue::PARAMS_JSON => json_encode($json_params),
					);
					
					if($is_queued) {
						$fields[DAO_MailQueue::IS_QUEUED] = 1;
					}
					
					$draft_id = DAO_MailQueue::create($fields);
					
				} catch (Exception $e) {
					// [TODO] ...
				}
			}
		}
		
		$batch_total = count($ids);
		for($x=0;$x<=$batch_total;$x+=100) {
			$batch_ids = array_slice($ids,$x,100);
			DAO_Address::update($batch_ids, $change_fields);
			
			// Custom Fields
			self::_doBulkSetCustomFields(CerberusContexts::CONTEXT_ADDRESS, $custom_fields, $batch_ids);
			
			// Scheduled behavior
			if(isset($do['behavior']) && is_array($do['behavior'])) {
				$behavior_id = $do['behavior']['id'];
				@$behavior_when = strtotime($do['behavior']['when']) or time();
				@$behavior_params = isset($do['behavior']['params']) ? $do['behavior']['params'] : array();
				
				if(!empty($batch_ids) && !empty($behavior_id))
				foreach($batch_ids as $batch_id) {
					DAO_ContextScheduledBehavior::create(array(
						DAO_ContextScheduledBehavior::BEHAVIOR_ID => $behavior_id,
						DAO_ContextScheduledBehavior::CONTEXT => CerberusContexts::CONTEXT_ADDRESS,
						DAO_ContextScheduledBehavior::CONTEXT_ID => $batch_id,
						DAO_ContextScheduledBehavior::RUN_DATE => $behavior_when,
						DAO_ContextScheduledBehavior::VARIABLES_JSON => json_encode($behavior_params),
					));
				}
			}
			
			unset($batch_ids);
		}

		unset($ids);
	}
};

class Context_Address extends Extension_DevblocksContext implements IDevblocksContextProfile, IDevblocksContextPeek, IDevblocksContextImport {
	static function searchInboundLinks($from_context, $from_context_id) {
		list($results, $null) = DAO_Address::search(
			array(
				SearchFields_Address::ID,
			),
			array(
				new DevblocksSearchCriteria(SearchFields_Address::CONTEXT_LINK,'=',$from_context),
				new DevblocksSearchCriteria(SearchFields_Address::CONTEXT_LINK_ID,'=',$from_context_id),
			),
			-1,
			0,
			SearchFields_Address::EMAIL,
			true,
			false
		);
		
		return $results;
	}
	
	function getRandom() {
		return DAO_Address::random();
	}
	
	function profileGetUrl($context_id) {
		if(empty($context_id))
			return '';
	
		$url_writer = DevblocksPlatform::getUrlService();
		$url = $url_writer->writeNoProxy('c=profiles&type=address&id='.$context_id, true);
		return $url;
	}
	
	function getMeta($context_id) {
		$url_writer = DevblocksPlatform::getUrlService();

		if(null == ($address = DAO_Address::get($context_id)))
			return array();
		
		$addy_name = $address->getName();
		if(!empty($addy_name)) {
			$addy_name = sprintf("%s <%s>", $addy_name, $address->email);
		} else {
			$addy_name = $address->email;
		}
		
		$url = $this->profileGetUrl($context_id);
		$friendly = DevblocksPlatform::strToPermalink($address->email);

		if(!empty($friendly))
			$url .= '-' . $friendly;
		
		return array(
			'id' => $address->id,
			'name' => $addy_name,
			'permalink' => $url,
		);
	}
	
	function getPropertyLabels(DevblocksDictionaryDelegate $dict) {
		$labels = $dict->_labels;
		$prefix = $labels['_label'];
		
		if(!empty($prefix)) {
			array_walk($labels, function(&$label, $key) use ($prefix) {
				$label = preg_replace(sprintf("#^%s #", preg_quote($prefix)), '', $label);
				
				// [TODO] Use translations
				switch($key) {
				}
				
				$label = mb_convert_case($label, MB_CASE_LOWER);
				$label[0] = mb_convert_case($label[0], MB_CASE_UPPER);
			});
		}
		
		asort($labels);
		
		return $labels;
	}
	
	// [TODO] Interface
	function getDefaultProperties() {
		return array(
			'full_name',
			'org__label',
			'is_banned',
			'is_defunct',
			'num_nonspam',
			'num_spam',
			'updated',
		);
	}
	
	function getContext($address, &$token_labels, &$token_values, $prefix=null) {
		if(is_null($prefix))
			$prefix = 'Email:';
		
		$translate = DevblocksPlatform::getTranslationService();
		$fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_ADDRESS);
		
		// Polymorph
		if(is_numeric($address)) {
			$address = DAO_Address::get($address);
			
		} elseif(is_array($address)) {
			$address = Cerb_ORMHelper::recastArrayToModel($address, 'Model_Address');
			
		} elseif($address instanceof Model_Address) {
			// It's what we want already.
			
		} elseif(is_string($address)) {
			$address = DAO_Address::getByEmail($address);
			
		} else {
			$address = null;
		}
		
		// Token labels
		$token_labels = array(
			'_label' => $prefix,
			'id' => $prefix.$translate->_('common.id'),
			'address' => $prefix.$translate->_('address.address'),
			'first_name' => $prefix.$translate->_('address.first_name'),
			'full_name' => $prefix.$translate->_('address.full_name'),
			'last_name' => $prefix.$translate->_('address.last_name'),
			'num_spam' => $prefix.$translate->_('address.num_spam'),
			'num_nonspam' => $prefix.$translate->_('address.num_nonspam'),
			'is_banned' => $prefix.$translate->_('address.is_banned'),
			'is_contact' => $prefix.$translate->_('address.is_contact'),
			'is_defunct' => $prefix.$translate->_('address.is_defunct'),
			'updated' => $prefix.$translate->_('common.updated'),
			'record_url' => $prefix.$translate->_('common.url.record'),
		);
		
		// Token types
		$token_types = array(
			'_label' => 'context_url',
			'id' => Model_CustomField::TYPE_NUMBER,
			'address' => Model_CustomField::TYPE_SINGLE_LINE,
			'first_name' => Model_CustomField::TYPE_SINGLE_LINE,
			'full_name' => Model_CustomField::TYPE_SINGLE_LINE,
			'last_name' => Model_CustomField::TYPE_SINGLE_LINE,
			'num_spam' => Model_CustomField::TYPE_NUMBER,
			'num_nonspam' => Model_CustomField::TYPE_NUMBER,
			'is_banned' => Model_CustomField::TYPE_CHECKBOX,
			'is_contact' => Model_CustomField::TYPE_CHECKBOX,
			'is_defunct' => Model_CustomField::TYPE_CHECKBOX,
			'updated' => Model_CustomField::TYPE_DATE,
			'record_url' => Model_CustomField::TYPE_URL,
		);
		
		// Custom field/fieldset token labels
		if(false !== ($custom_field_labels = $this->_getTokenLabelsFromCustomFields($fields, $prefix)) && is_array($custom_field_labels))
			$token_labels = array_merge($token_labels, $custom_field_labels);

		// Custom field/fieldset token types
		if(false !== ($custom_field_types = $this->_getTokenTypesFromCustomFields($fields, $prefix)) && is_array($custom_field_types))
			$token_types = array_merge($token_types, $custom_field_types);
		
		// Token values
		$token_values = array();
		
		$token_values['_context'] = CerberusContexts::CONTEXT_ADDRESS;
		$token_values['_types'] = $token_types;

		// Address token values
		if(null != $address) {
			$full_name = $address->getName();
			
			$token_values['_loaded'] = true;
			$token_values['_label'] = !empty($full_name) ? sprintf("%s <%s>", $full_name, $address->email) : sprintf("%s", $address->email);
			$token_values['id'] = $address->id;
			$token_values['full_name'] = $address->getName();
			if(!empty($address->email))
				$token_values['address'] = $address->email;
			$token_values['first_name'] = $address->first_name;
			$token_values['last_name'] = $address->last_name;
			$token_values['num_spam'] = $address->num_spam;
			$token_values['num_nonspam'] = $address->num_nonspam;
			$token_values['is_banned'] = $address->is_banned;
			$token_values['is_contact'] = !empty($address->contact_person_id);
			$token_values['is_defunct'] = $address->is_defunct;
			$token_values['updated'] = $address->updated;

			// Custom fields
			$token_values = $this->_importModelCustomFieldsAsValues($address, $token_values);
			
			// URL
			$url_writer = DevblocksPlatform::getUrlService();
			$token_values['record_url'] = $url_writer->writeNoProxy(sprintf("c=profiles&type=address&id=%d-%s",$address->id, DevblocksPlatform::strToPermalink($address->email)), true);
			
			// Org
			$org_id = (null != $address && !empty($address->contact_org_id)) ? $address->contact_org_id : null;
			$token_values['org_id'] = $org_id;
		}
		
		// Email Org
		$merge_token_labels = array();
		$merge_token_values = array();
		CerberusContexts::getContext(CerberusContexts::CONTEXT_ORG, null, $merge_token_labels, $merge_token_values, null, true);

		CerberusContexts::merge(
			'org_',
			$prefix,
			$merge_token_labels,
			$merge_token_values,
			$token_labels,
			$token_values
		);
		
		return true;
	}
	
	function lazyLoadContextValues($token, $dictionary) {
		if(!isset($dictionary['id']))
			return;
		
		$context = CerberusContexts::CONTEXT_ADDRESS;
		$context_id = $dictionary['id'];
		
		@$is_loaded = $dictionary['_loaded'];
		$values = array();
		
		if(!$is_loaded) {
			$labels = array();
			CerberusContexts::getContext($context, $context_id, $labels, $values, null, true);
		}
		
		switch($token) {
			case 'watchers':
				$watchers = array(
					$token => CerberusContexts::getWatchers($context, $context_id, true),
				);
				$values = array_merge($values, $watchers);
				break;
				
			default:
				if(substr($token,0,7) == 'custom_') {
					$fields = $this->_lazyLoadCustomFields($token, $context, $context_id);
					$values = array_merge($values, $fields);
				}
				break;
		}
		
		return $values;
	}

	function getChooserView($view_id=null) {
		if(empty($view_id))
			$view_id = 'chooser_'.str_replace('.','_',$this->id).time().mt_rand(0,9999);
		
		// View
		$defaults = new C4_AbstractViewModel();
		$defaults->id = $view_id;
		$defaults->is_ephemeral = true;
		$defaults->class_name = $this->getViewClass();
		
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Addresses';
		
		$view->view_columns = array(
			SearchFields_Address::FIRST_NAME,
			SearchFields_Address::LAST_NAME,
			SearchFields_Address::ORG_NAME,
		);
		
		$view->addParamsDefault(array(
			SearchFields_Address::IS_BANNED => new DevblocksSearchCriteria(SearchFields_Address::IS_BANNED,'=',0),
			SearchFields_Address::IS_DEFUNCT => new DevblocksSearchCriteria(SearchFields_Address::IS_DEFUNCT,'=',0),
		), true);
		$view->addParams($view->getParamsDefault(), true);
		
		$view->renderSortBy = SearchFields_Address::EMAIL;
		$view->renderSortAsc = true;
		$view->renderLimit = 10;
		$view->renderFilters = false;
		$view->renderTemplate = 'contextlinks_chooser';
		
		C4_AbstractViewLoader::setView($view_id, $view);
		return $view;
	}
	
	function getView($context=null, $context_id=null, $options=array()) {
		$view_id = str_replace('.','_',$this->id);
		
		$defaults = new C4_AbstractViewModel();
		$defaults->id = $view_id;
		$defaults->class_name = $this->getViewClass();
		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = 'Email Addresses';
		
		$params_req = array();
		
		if(!empty($context) && !empty($context_id)) {
			$params_req = array(
				new DevblocksSearchCriteria(SearchFields_Address::CONTEXT_LINK,'=',$context),
				new DevblocksSearchCriteria(SearchFields_Address::CONTEXT_LINK_ID,'=',$context_id),
			);
		}
		
		$view->addParamsRequired($params_req, true);
		
		$view->renderTemplate = 'context';
		C4_AbstractViewLoader::setView($view_id, $view);
		return $view;
	}
	
	function renderPeekPopup($context_id=0 , $view_id='') {
		@$email = DevblocksPlatform::importGPC($_REQUEST['email'],'string','');
		@$org_id = DevblocksPlatform::importGPC($_REQUEST['org_id'],'string','');
		
		$tpl = DevblocksPlatform::getTemplateService();
		
		if(!empty($context_id)) {
			$email = '';
			if(null != ($addy = DAO_Address::get($context_id))) {
				@$email = $addy->email;
			}
		}
		$tpl->assign('email', $email);
		
		if(!empty($email)) {
			list($addresses,$null) = DAO_Address::search(
				array(),
				array(
					new DevblocksSearchCriteria(SearchFields_Address::EMAIL,DevblocksSearchCriteria::OPER_EQ,$email)
				),
				1,
				0,
				null,
				null,
				false
			);
				
			$address = array_shift($addresses);
			$tpl->assign('address', $address);
			
			if(empty($context_id)) {
				$context_id = $address[SearchFields_Address::ID];
			}
				
			list($open_tickets, $open_count) = DAO_Ticket::search(
				array(),
				array(
					new DevblocksSearchCriteria(SearchFields_Ticket::TICKET_CLOSED,'=',0),
					new DevblocksSearchCriteria(SearchFields_Ticket::REQUESTER_ID,'=',$context_id),
				),
				1
			);
			$tpl->assign('open_count', $open_count);
				
			list($closed_tickets, $closed_count) = DAO_Ticket::search(
				array(),
				array(
					new DevblocksSearchCriteria(SearchFields_Ticket::TICKET_CLOSED,'=',1),
					new DevblocksSearchCriteria(SearchFields_Ticket::REQUESTER_ID,'=',$context_id),
				),
				1
			);
			$tpl->assign('closed_count', $closed_count);
		}
		
		if (!empty($org_id)) {
			$org = DAO_ContactOrg::get($org_id);
			$tpl->assign('org_name',$org->name);
			$tpl->assign('org_id',$org->id);
		}
		
		// Custom fields
		$custom_fields = DAO_CustomField::getByContext(CerberusContexts::CONTEXT_ADDRESS, false);
		$tpl->assign('custom_fields', $custom_fields);
		
		$custom_field_values = DAO_CustomFieldValue::getValuesByContextIds(CerberusContexts::CONTEXT_ADDRESS, $context_id);
		if(isset($custom_field_values[$context_id]))
			$tpl->assign('custom_field_values', $custom_field_values[$context_id]);
		
		$types = Model_CustomField::getTypes();
		$tpl->assign('types', $types);
		
		// Comments
		
		$comments = DAO_Comment::getByContext(CerberusContexts::CONTEXT_ADDRESS, $context_id);
		$comments = array_reverse($comments, true);
		$tpl->assign('comments', $comments);
		
		// Display
		$tpl->assign('id', $context_id);
		$tpl->assign('view_id', $view_id);
		$tpl->display('devblocks:cerberusweb.core::contacts/addresses/peek.tpl');
	}
	
	function importGetKeys() {
		// [TODO] Translate
	
		$keys = array(
			'contact_org_id' => array(
				'label' => 'Org',
				'type' => 'ctx_' . CerberusContexts::CONTEXT_ORG,
				'param' => SearchFields_Address::CONTACT_ORG_ID,
			),
			'email' => array(
				'label' => 'Email',
				'type' => Model_CustomField::TYPE_SINGLE_LINE,
				'param' => SearchFields_Address::EMAIL,
				'required' => true,
				'force_match' => true,
			),
			'first_name' => array(
				'label' => 'First Name',
				'type' => Model_CustomField::TYPE_SINGLE_LINE,
				'param' => SearchFields_Address::FIRST_NAME,
			),
			'is_banned' => array(
				'label' => 'Is Banned',
				'type' => Model_CustomField::TYPE_CHECKBOX,
				'param' => SearchFields_Address::IS_BANNED,
			),
			'is_defunct' => array(
				'label' => 'Is Defunct',
				'type' => Model_CustomField::TYPE_CHECKBOX,
				'param' => SearchFields_Address::IS_DEFUNCT,
			),
			'last_name' => array(
				'label' => 'Last Name',
				'type' => Model_CustomField::TYPE_SINGLE_LINE,
				'param' => SearchFields_Address::LAST_NAME,
			),
			'num_nonspam' => array(
				'label' => '# Nonspam',
				'type' => Model_CustomField::TYPE_NUMBER,
				'param' => SearchFields_Address::NUM_NONSPAM,
			),
			'num_spam' => array(
				'label' => '# Spam',
				'type' => Model_CustomField::TYPE_NUMBER,
				'param' => SearchFields_Address::NUM_SPAM,
			),
			'updated' => array(
				'label' => 'Updated',
				'type' => Model_CustomField::TYPE_DATE,
				'param' => SearchFields_Address::UPDATED,
			),
		);
	
		$fields = SearchFields_Address::getFields();
		self::_getImportCustomFields($fields, $keys);
		
		DevblocksPlatform::sortObjects($keys, '[label]', true);
	
		return $keys;
	}
	
	function importKeyValue($key, $value) {
		switch($key) {
		}
	
		return $value;
	}
	
	function importSaveObject(array $fields, array $custom_fields, array $meta) {
		// If new...
		if(!isset($meta['object_id']) || empty($meta['object_id'])) {
			// Make sure we have a name
			if(!isset($fields[DAO_Address::EMAIL])) {
				return FALSE;
			}
	
			// Create
			$meta['object_id'] = DAO_Address::create($fields);
	
		} else {
			// Update
			DAO_Address::update($meta['object_id'], $fields);
		}
	
		// Custom fields
		if(!empty($custom_fields) && !empty($meta['object_id'])) {
			DAO_CustomFieldValue::formatAndSetFieldValues($this->manifest->id, $meta['object_id'], $custom_fields, false, true, true); //$is_blank_unset (4th)
		}
	}
};