<?php
	//Make sure we're xml otherwise the phone will not parse correctly
	header('Content-type: text/xml');
	
	
	//Get the url of this page so we can do page forward/back requests
	$url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
	
	//Mode if we're searching by first, last or number
	$mode = '';
	
	if(isset($_GET['name']) and !isset($_GET['number']))
    $mode = 'name';
	else if(isset($_GET['number']) and !isset($_GET['name']))
    $mode = 'number';
	else if(isset($_GET['number']) and isset($_GET['name']))
    $mode = 'all';
	else{
		
		/* Nothing was searched for on this page so return an error via the 
		* CiscoIPPhoneText item */
		$error = showCiscoPhoneError('Ошибка_поиска', 
		'Введите_данные_поиска', 
		'Ошибка_поиска,не_введены_данные');
		
		echo $error -> asXML();
		exit(1);
		
	}
	
	$xml = showAddresses($mode, $url);
	
	echo $xml -> asXML();
	
	/**
		* Returns an error message in the form of a CiscoIPPhoneText XML snippet
		* @param $title: Title shown at top of phone screen
		* @param $prompt: Prompt shown at bottom of phone screen
		* @param $text: Text to show in middle of phone screen
		* @return Returns properly formatted XML snippet for Cisco 7942 and
	* compatible phones */
	function showCiscoPhoneError($title, $prompt, $text){
		
		$xml = new SimpleXMLElement('<CiscoIPPhoneText/>');
		$xml -> addChild('Title', $title);
		$xml -> addChild('Prompt', $prompt);
		$xml -> addChild('Text', $text);
		
		addSoftKey($xml, 'Exit', 'SoftKey:Exit', 1);
		
		return $xml;
		
	}
	
	/**
		* Adds a Cisco SoftKeyItem to the given XML object
		* @param $xml: SimpleXMLElement to act upon
		* @param $name: Name of the Key displayed on the phone (8 char limit)
		* @param $url: URL to call when key pressed
		*    Built in URLs:
		*        SoftKey:Exit - Exits
		*        SoftKey:Dial - Dials selected
		* @param $position: Position for soft key 1 - 4
	*/
	function addSoftKey($xml, $name, $url, $position){
		
		$softKey = $xml -> addChild('SoftKeyItem');
		$softKey -> addChild('Name', $name);
		$softKey -> addChild('URL', $url);
		$softKey -> addChild('Position', $position);
		
	}
	
	
	function showAddresses($mode, $url){
		require_once '/var/www/admin/conf.inc.php';
		//Setup PDO connection and options
		$dsn = 'mysql:host=' . $host . ';dbname=' . $db;
		
		$options = [
		PDO::ATTR_ERRMODE     => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES    => false,
		];
		
		$pdo = NULL;
		
		//Connect to MySQL
		try {
			$pdo = new PDO($dsn, $user, $pw, $options);
			} catch (\PDOException $e) {
			
			//Format Exception as XML
			return showCiscoPhoneError('MySQL Connect Error', 'Please inform IT', 
			'There was an error connecting to the MySQL database (' . $e->getCode() . '): ' . $e->getMessage());
			
		}
		
		$stmt = NULL;
		$query = NULL;
		
		switch($mode) {
			// SELECT number,name FROM phonebook_mix WHERE number LIKE :user ORDER BY number,name ASC
			case 'number':
			$sql = 'SELECT name,number FROM phonebook_mix WHERE number LIKE :user ORDER BY number,name ASC';
			
			$query = '%' . $_GET['number'] . '%';
			
			break;
			
			case 'name':
			
			$sql = 'SELECT name,number FROM phonebook_mix WHERE name LIKE :user ORDER BY name,number ASC';
			
			$query = '%' . $_GET['name'] . '%';
			
			break;
			
			case 'all':
			
			$sql = 'SELECT name,number FROM phonebook_mix WHERE name LIKE :user AND number LIKE :num ORDER BY name,number ASC';
			
			$query = '%' . $_GET['name'] . '%';
			$query2 = '%' . $_GET['number'] . '%';
			
			break;
		}
		
		
		
		
		$stmt = $pdo->prepare($sql);
		
		if( $mode== 'all') {
			$stmt->execute([$query,$query2]);
			} else{
			$stmt->execute([$query]);
		}
		
		$extensions = $stmt->fetchAll(PDO::FETCH_ASSOC);
		
		
		if(sizeof($extensions) == 0){
		
			if( $mode== 'all') {
				return showCiscoPhoneError('Нет_результатов', 'Попробуйте_еще_раз', 'He найдено : ' . str_replace('%', '', $query) . " " . str_replace('%', '', $query2));
			} else{
				return showCiscoPhoneError('Нет_результатов', 'Попробуйте_еще_раз', 'He найдено : ' . str_replace('%', '', $query));
			}
			
			
			} else {
			
			
			
			//Paginate results, 31 items max (need 1 result extra for next page item)
			$start = 0;
			$page = 0;
			
			if(isset($_GET['page']))
			$page = (int)$_GET['page'];
			
			//We have results format as phonedirectory
			$xml = new SimpleXMLElement('<CiscoIPPhoneDirectory/>');
			$xml -> addChild('Title', 'GS');
			$xml -> addChild('Prompt', 'Найдено:'." ".sizeof($extensions).", Cтраница: ".($page+1)." из ".(intval(sizeof($extensions)/32)+1));
			
			$start = $page * 32;
			
			//Check to see if we need more pages
			$morePages = false;
			
			if(sizeof($extensions) > $start + 31)
			$morePages = true;
			
			$row = $start;
			
			while($row < sizeof($extensions) && $row < $start + 32){
				
				$entry = $xml -> addChild('DirectoryEntry');
				$entry -> addChild('Name', $extensions[$row]['name']);
				$entry -> addChild('Telephone', $extensions[$row]['number']);
				$row++;
				
			}
			
			//Add the softkeys to the results
			addSoftKey($xml, 'Набор', 'SoftKey:Dial', 1);
			addSoftKey($xml, 'Выход', 'SoftKey:Exit', 2);
			
			//Check if we need a previous page button
			if($page > 0)
			addSoftKey($xml, 'Пред.', 'SoftKey:Exit', 3);
			
			//Check if we need a next page button
			if($morePages){
				
				if( $mode== 'all') {
					
					$query = str_replace('%', '', $query);
					$query2 = str_replace('%', '', $query2);
					
					//&amp; required as & not valid xml
					addSoftKey($xml, 'След.', $url . '?name=' . $query . '&amp;number='.$query2.'&amp;page=' . ++$page, 4);
					
					} else {
					
					$query = str_replace('%', '', $query);
					//&amp; required as & not valid xml
					addSoftKey($xml, 'След.', $url . '?' . $mode . '=' . $query . '&amp;page=' . ++$page, 4);
				}
				
			}
			
		}
		
		return $xml;
		
	}
	
?>
