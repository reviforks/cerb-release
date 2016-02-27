<?php
class DAO_MessageHeaders extends Cerb_ORMHelper {
	const MESSAGE_ID = 'message_id';
	const HEADERS = 'headers';

	static function upsert($message_id, $raw_headers) {
		$db = DevblocksPlatform::getDatabaseService();
		
		if(empty($message_id) || !is_string($raw_headers))
			return false;
		
		$db->ExecuteMaster(sprintf("REPLACE INTO message_headers (message_id, headers) ".
				"VALUES (%d, %s)",
				$message_id,
				$db->qstr(ltrim($raw_headers))
		));
	}
	
	static function parse($raw_headers, $flatten_arrays=true) {
		if(false == ($mime = new MimeMessage('var', $raw_headers)))
			return false;
		
		if(!isset($mime->data))
			return false;
		
		$headers = CerberusParser::fixQuotePrintableArray($mime->data['headers']);
		
		if($flatten_arrays)
		foreach($headers as &$v) {
			if(is_array($v))
				$v = implode(';; ', $v);
		}
		
		ksort($headers);
		
		return $headers;
	}

	static function getRaw($message_id) {
		$db = DevblocksPlatform::getDatabaseService();

		$sql = sprintf("SELECT headers ".
			"FROM message_headers ".
			"WHERE message_id = %d",
			$message_id
		);
		
		if(false === ($raw_headers = $db->GetOneSlave($sql)))
			return false;
		
		return ltrim($raw_headers);
	}
	
	static function getAll($message_id, $flatten_arrays=true) {
		if(false == ($raw_headers = self::getRaw($message_id)))
			return false;
		
		$headers = self::parse($raw_headers, $flatten_arrays);
		
		return $headers;
	}

	static function delete($ids) {
		if(!is_array($ids))
			$ids = array($ids);
		
		$ids = DevblocksPlatform::sanitizeArray($ids, 'int');

		if(empty($ids))
			return;
		
		$db = DevblocksPlatform::getDatabaseService();
		 
		$sql = sprintf("DELETE FROM message_headers WHERE message_id IN (%s)",
			implode(',', $ids)
		);
		$db->ExecuteMaster($sql);
	}
};