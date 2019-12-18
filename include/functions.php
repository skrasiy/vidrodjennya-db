<?PHP
use PHPStamp\Templator;
use PHPStamp\Document\WordDocument;
use Da\QrCode\QrCode;


function parseURI($string, $root) {
	$root = ($root == '/') ? array() : explode('/', $root);
	$uri = explode('?', $string, 2);
	$slice = array();
	$i = 0;
	foreach (array_filter(explode('/', $uri[0])) as $path) {
		if ($path != $root[$i]) $slice[] = $path;
		$i++;
	}
	$get = array();
	if (isset($uri[1])) {
		foreach (explode('&', $uri[1]) as $pair) {
			$arg = explode('=', $pair, 2);
			$get[$arg[0]] = $arg[1];
		}
	}
	return array(
		'slice' => $slice,
		'args' => $get
	);
}

function update_path() {
	global $page;
	//return $page->self . '/' . implode('/', $page->path['slice']);
	return $page->root . implode('/', $page->path['slice']);
}

function xor_this($string, $key=false) {
	global $cfg;
	if (!$key) $key = ($cfg['salt']);
	$text = $string;
	$outText = '';
	for($i=0; $i<strlen($text); ) {
		for($j=0; ($j<strlen($key) && $i<strlen($text)); $j++,$i++) {
			$outText .= $text{$i} ^ $key{$j};
		}
	}
	return $outText;
}

function audit($user, $action) {
	global $cfg;
	if (!$cfg['log_actions']) return;
	
	$action = preg_replace( '/\r|\n/', '\n', $action);
	$action = preg_replace( '/\\\\n\\\\n/', '\n', $action);
	if ($cfg['debug']['enable']) {
		$log = pathinfo($cfg['log_filename']);
		$log = $cfg['log_path'].$log['filename'].$cfg['debug']['db_name_suffix'].'.'.$log['extension'];
	} else {
		$log = $cfg['log_path'].$cfg['log_filename'];
	}
	$date = new DateTime();
	$date = $date->format("Y-m-d H:i:s");
	$ip = $_SERVER['REMOTE_ADDR'];
	$msg = "[" . $date . "] " . $user->accountName . " (". $ip . ") " . $action . PHP_EOL;
	file_put_contents($log, $msg, FILE_APPEND | LOCK_EX);
}

function doLogin() {
	global $page;
	if (empty($_POST['auth'])) return 'nopassword';
	$page->user->login('password', $_POST['auth']);
	if ($page->user->logged) {
		audit($page->user, 'авторизувався у системі');
		setcookie('auth', base64_encode(xor_this($page->user->passwordHash, $_SERVER['REMOTE_ADDR'])), 0, $page->root);
		$location = 'Location: '.$page->root;
		if (isset($_POST['return'])) $location .= $_POST['return'];
		$args = array();
		foreach($_POST as $key => $value){
			$exp_key = explode('-', $key);
			if ($exp_key[0] == 'arg') $args[$exp_key[1]] = $value;
		}
		if (!empty($args)) $location .= '?'. http_build_query($args, '', '&');
		header($location);
		exit();
	} else {
		audit($page->user, '[WARNING] спробував увійти до системи з невірним паролем');
		return 'badpassword';
	}
}

function doLogout() {
	global $page;
	audit($page->user, 'вийшов з системи');
	unset($_COOKIE['auth']); 
    setcookie('auth', null, -1, $page->root); 
	header('Location: '.$page->root);
	exit();
}

function trim_value(&$value) {
	$value = trim($value);
	return $value;
}

function remove_empty($value) {
	return ($value !== NULL && $value !== FALSE && $value !== '');
}

function strip_data($text) {
	$quotes = array ("\x27", "\x22", "\x60", "\t", "\n", "\r", "*", "%", "<", ">", "?", "!" );
	$goodquotes = array ("-", "+", "#" );
	$repquotes = array ("\-", "\+", "\#" );
	$text = trim( strip_tags( $text ) );
	$text = str_replace( "\x27", "’", $text ); // апостроф
	$text = str_replace( $quotes, '', $text );
	$text = str_replace( $goodquotes, $repquotes, $text );
	$text = preg_replace("/ +/", " ", $text);     
	return $text;
}

function strip_textarea($text) {
	$quotes = array ("\x27", "\x22", "\x60", "\t", "*", "%", "<", ">", "?", "!" );
	$text = trim( strip_tags( $text ) );
	$text = str_replace( "\x27", "’", $text );
	$text = str_replace( $quotes, '', $text );
	$text = preg_replace("/ +/", " ", $text);  
	return $text;
}

function strip_array(&$value) {
	$quotes = array ("\x27", "\x22", "\x60", "\t", "\n", "\r", "*", "%", "<", ">", "?", "!" );
	$goodquotes = array ("#" );
	$repquotes = array ("\#" );
	$value = trim( strip_tags( $value ) );
	$value = str_replace( "\x27", "’", $value ); // апостроф
	$value = str_replace( $quotes, '', $value );
	$value = str_replace( $goodquotes, $repquotes, $value );
	$value = preg_replace("/ +/", " ", $value);
	return $value;
}

function db_select($query) {
	global $db;
	mysqli_report(MYSQLI_REPORT_OFF);
	$_GET["db_queries"]++;

	$mysqli = new mysqli($db['host'].":".$db['port'], $db['user'], $db['pass'], $db['name']);
	$mysqli->set_charset("utf8");
	
	$result = $mysqli->query($query);

	$objects = array();
	while ($object = $result->fetch_object()) {
		$objects[] = $object;
	}

	$result->close();
	$mysqli->close();
	return $objects;
}

function db_write($query) {
	global $db;
	mysqli_report(MYSQLI_REPORT_OFF);
	$_GET["db_queries"]++;

	$mysqli = new mysqli($db['host'].":".$db['port'], $db['user'], $db['pass'], $db['name']);
	$mysqli->set_charset("utf8");
	
	$stmt = $mysqli->prepare($query);
	$stmt->execute();
	$result = $mysqli->affected_rows;
	$stmt->close();
	return $result;
}

function get_enum($column) {
	$result = db_select("SHOW COLUMNS FROM clients LIKE '".$column."'");
	preg_match("/^enum\(\'(.*)\'\)$/", $result[0]->Type, $matches);
	return explode("','", $matches[1]);
}

function geocode($client) {
	$city = explode('. ', $client->city, 2);
	$city = (!empty($city[1])) ? $city[1] : $city[0];
	$address = explode(',', $client->address);
	$street = explode('. ', $address[0], 2)[1];
	$building = (int) filter_var($address[1], FILTER_SANITIZE_NUMBER_INT);
	

	$token = 'eb97c4f7f04241';
	$api = 'https://eu1.locationiq.com/v1/search.php';
	
	$cache = 'cache/geocode/'.md5($city.$street.$building);
	if (file_exists($cache)) {
		$coords = explode(':', file_get_contents($cache));
		return (object) [
			'lat'	=> $coords[0],
			'lon'	=> $coords[1]
		];
	}

	$url = $api.'?key='.$token.'&country=Ukraine&city='.$city.'&street='.$building.' '.$street.'&format=json&limit=1';
	$opts = array('http'=>array('header'=>"User-Agent: LOCSRDI Vidrodjennya mapper\r\n"));
	$context = stream_context_create($opts);
	$json = @file_get_contents($url, false, $context);
	if (!$json) return FALSE;
	$responce = json_decode($json, true);
	if (empty($responce[0]['lat'])) return FALSE;
	file_put_contents($cache, $responce[0]['lat'].':'.$responce[0]['lon'], LOCK_EX);
	return (object) [
		'lat'	=> $responce[0]['lat'],
		'lon'	=> $responce[0]['lon']
	];
}

function processing($result) {
	$clients = array();
	foreach($result as $client) {
		$additional = array();
		if ($client->status_ato == 1) $additional[] = _('STATUS_ATO_DESC');
		if ($client->status_vpl == 1) $additional[] = _('STATUS_VPO_DESC');
		if ($client->additional) $additional[] = $client->additional;
		if ($client->comment) $additional[] = $client->comment;
		$client->contacts = preg_split('/[\n,]/u', $client->contact_data, -1, PREG_SPLIT_NO_EMPTY);
		$client->gender_readable = (($client->gender == 0) ? _('GENDER_MALE') : _('GENDER_FEMALE'));
		$client->disabled_group = (($client->status_disabled == 0) ? mb_strtolower(_('NO')) : $client->disabled_group);
		$client->additional_summary = implode("\n", $additional);
		
		$clients[] = $client;
	}
	return $clients;
}

function clients_obj2array($data=null) {
	$clients = array();
	if (empty($data)) return $clients;
	foreach($data as $client) {
		$ipr = array();
		if ($client->pcons) $ipr[] = _('IPR_SVC_PCONS_SHORT');
		if ($client->ppd) $ipr[] = _('IPR_SVC_PPD_SHORT');
		if ($client->ppp) $ipr[] = _('IPR_SVC_PPP_SHORT');
		if ($client->ppk) $ipr[] = _('IPR_SVC_PPK_SHORT');
		if ($client->fcons) $ipr[] = _('IPR_SVC_FCONS_SHORT');
		if ($client->lm) $ipr[] = _('IPR_SVC_LM_SHORT');
		if ($client->lfk) $ipr[] = _('IPR_SVC_LFK_SHORT');
		if ($client->nosn) $ipr[] = _('IPR_SVC_NOSN_SHORT');
		if ($client->spp) $ipr[] = _('IPR_SVC_SPP_SHORT');
		$client->ipr_services = implode(', ', $ipr);
		if (is_array($client->contacts)) $client->contacts = implode(', ', $client->contacts);
		$clients[] = (array)$client;
	}
	return $clients;
}

function forceLocale($locale) {
	global $page;
	$page->locale = $locale;
	putenv("LC_ALL={$page->locale}");
	putenv("LANG={$page->locale}");
	return $page;
}

function compute_day($weekNumber, $dayOfWeek, $monthNumber, $year) {
	$dayOfWeekFirstDayOfMonth = date('w', mktime(0, 0, 0, $monthNumber, 1, $year));
	$diference = 0;
	if ($dayOfWeekFirstDayOfMonth <= $dayOfWeek){
		$diference = $dayOfWeek - $dayOfWeekFirstDayOfMonth;
	} else {
		$diference = 7 - $dayOfWeekFirstDayOfMonth + $dayOfWeek;
	}
	return 1 + $diference + ($weekNumber - 1) * 7;
}

function layout_switcher($text,$arrow=1){
	// 0 = ru\uk -> en, 1 = en -> uk
	$str[0] = array('й'=>'q','ц'=>'w','у'=>'e','к'=>'r','е'=>'t','н'=>'y','г'=>'u','ш'=>'i','щ'=>'o','з'=>'p','х'=>'[','ъ'=>']','ф'=>'a','ы'=>'s','в'=>'d','а'=>'f','п'=>'g','р'=>'h','о'=>'j','л'=>'k','д'=>'l','ж'=>';','э'=>'\'','я'=>'z','ч'=>'x','с'=>'c','м'=>'v','и'=>'b','т'=>'n','ь'=>'m','б'=>',','ю'=>'.','.'=>'/','ё'=>'`','і'=>'s','є'=>'\'','Й'=>'Q','Ц'=>'W','У'=>'E','К'=>'R','Е'=>'T','Н'=>'Y','Г'=>'U','Ш'=>'I','Щ'=>'O','З'=>'P','Х'=>'[','Ъ'=>']','Ф'=>'A','Ы'=>'S','В'=>'D','А'=>'F','П'=>'G','Р'=>'H','О'=>'J','Л'=>'K','Д'=>'L','Ж'=>';','Э'=>'\'','Я'=>'Z','Ч'=>'X','С'=>'C','М'=>'V','И'=>'B','Т'=>'N','Ь'=>'M','Б'=>',','Ю'=>'.','Ё'=>'~','І'=>'S','Є'=>'"');
	$str[1] = array ('q'=>'й','w'=>'ц','e'=>'у','r'=>'к','t'=>'е','y'=>'н','u'=>'г','i'=>'ш','o'=>'щ','p'=>'з','['=>'х',']'=>'ї','a'=>'ф','s'=>'і','d'=>'в','f'=>'а','g'=>'п','h'=>'р','j'=>'о','k'=>'л','l'=>'д',';'=>'ж','\''=>'є','z'=>'я','x'=>'ч','c'=>'с','v'=>'м','b'=>'и','n'=>'т','m'=>'ь',','=>'б','.'=>'ю','/'=>'.','`'=>'\'','Q'=>'Й','W'=>'Ц','E'=>'У','R'=>'К','T'=>'Е','Y'=>'Н','U'=>'Г','I'=>'Ш','O'=>'Щ','P'=>'З','{'=>'Х','}'=>'Ї','A'=>'Ф','S'=>'І','D'=>'В','F'=>'А','G'=>'П','H'=>'Р','J'=>'О','K'=>'Л','L'=>'Д',':'=>'Ж','"'=>'Є','Z'=>'Я','X'=>'Ч','C'=>'С','V'=>'М','B'=>'И','N'=>'Т','M'=>'Ь','<'=>'Б','>'=>'Ю','?'=>',','~'=>'₴');
	return strtr($text,isset( $str[$arrow] ) ? $str[$arrow] : array_merge($str[0],$str[1]));
}

function qrcode_contact($id) {
	global $page;
	
	$client = new Client;
	$client->fromDB($id);
	if (!$client->loaded) die;

	$contacts = explode(',', $client->contact_data);
	$name = explode(' ', trim($contacts[0]));
	$phone = trim(preg_replace('/\s+/', '', $contacts[1]));
	$size = (isset($page->path['args']['size'])) ? abs((int)$page->path['args']['size']) : (int)250;
	$vCard = 'BEGIN:VCARD
VERSION:3.0
FN:'.$name[1].' '.$name[2].' '.$name[0].'
N:'.implode(';',$name).'
TEL;TYPE=voice,home,pref:+38'.$phone.'
END:VCARD';
	
	$qrCode = (new QrCode($vCard))
		->setSize($size)
		->setMargin(5)
		->useForegroundColor(0, 0, 0);

	header('Content-Type: '.$qrCode->getContentType());
	echo $qrCode->writeString();
}

function get_clients_grouped($id=false) {
	$grouped = array();
	$groupChar = '';
	foreach (db_select("SELECT id, file, name FROM clients WHERE active = 1 ORDER BY name ASC") as $client) {
		$firstChar = mb_substr($client->name, 0, 1);
		if ($firstChar != $groupChar) {
			$grouped[] = array(
				'optgroup'	=> true,
				'name'		=> $firstChar
			);
			$groupChar = $firstChar;
		}
		$grouped[] = array(
			'optgroup'	=> false,
			'name'		=> $client->name,
			'file'		=> (($id)?$client->id:$client->file)
		);
	}
	return $grouped;
}

function get_courses($user_id) {
	$result = db_select("SELECT * FROM courses WHERE user_id = ".(int)$user_id." ORDER BY course_end DESC");
	if (empty($result)) return NULL;
	$data = array();
	foreach ($result as $course) {
		$course->course_start = \DateTime::createFromFormat("Y-m-d", $course->course_start);
		$course->course_end = \DateTime::createFromFormat("Y-m-d", $course->course_end);
		$data[] = $course;
	}
	return $data;
}

function get_course($user_id, $course_id) {
	$result = db_select("SELECT * FROM courses WHERE user_id = ".(int)$user_id." AND id = ".(int)$course_id);
	if (empty($result)) return NULL;
	$result[0]->course_start = \DateTime::createFromFormat("Y-m-d", $result[0]->course_start);
	$result[0]->course_end = \DateTime::createFromFormat("Y-m-d", $result[0]->course_end);
	return $result[0];
}

function set_course($id=false) {
	global $page;
	
	foreach (array('start', 'end') as $key) {
		$time = strtotime($_POST['course-'.$key]);
		if (!$time) {
			$page->status = 'dataerror';
			return FALSE;
		}
		$$key = (string)date('Y-m-d', $time);
	}
	if(strtotime($start) > strtotime($end)) {
		$page->status = 'dataerror';
		return FALSE;
	}
	$uid = abs((int)$_POST['course-user']);
	if ($uid < 1) {
		$page->status = 'dataerror';
		return FALSE;
	};
	foreach (array('services', 'additional', 'comment') as $key) {
		$$key = (string)strip_data($_POST['course-'.$key]);
	}
	
	if(!$id) {
		$query = "INSERT INTO courses (user_id, course_start, course_end, ipr_svc, additional_svc, comment) VALUES (".$uid.", '".$start."', '".$end."', '".$services."', '".$additional."', '".$comment."');";
		$audit_msg = 'додав новий курс клієнту з id='.$uid;
	} else {
		$query = "UPDATE courses SET course_start = '".$start."', course_end = '".$end."', ipr_svc = '".$services."', additional_svc = '".$additional."', comment = '".$comment."' WHERE user_id = ".$uid." AND id = ".$id;
		$audit_msg = 'відредагував курс №'.$id.' клієнту з id='.$uid;
	}

	try {
		$result = db_write($query);
	} catch (Error $e) {
		audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
		$page->status = 'dberror';
		return FALSE;
	}
	audit($page->user, $audit_msg);
	$page->status = 'ok';
	return TRUE;
}

function delete_course($user_id, $course_id) {
	global $page;

	$audit_msg = 'видалив курс №'.$course_id.' клієнту з id='.$user_id;
	$query = 'DELETE FROM courses WHERE id = '.(int)$course_id.' AND user_id = '.(int)$user_id;
	try {
		$result = db_write($query);
	} catch (Error $e) {
		audit($page->user, '[ERROR] помилка при видаленні даних з БД, запит: "'.$query.'"');
	}
	audit($page->user, $audit_msg);
}

function generateRandomString($length = 16) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function defaultAccount() {
	global $cfg;
	global $page;
	$pass = generateRandomString(10);
	$hash = substr(hash('sha256', $cfg['salt'].$pass), 0, 16);
	$account = '$users["admin"] = array("displayName"=>"Default admin", "hash"=>"'.$hash.'", "accessLevel"=>"'.$cfg['access']['admin'].'");';
	return 'Your users section of config file is empty. Copy & paste this into config file and login with password "'.$pass.'"<br><pre>'.$account.'</pre>';
}

function getGitBranch() {
	$head = file('.git/HEAD', FILE_USE_INCLUDE_PATH);
	if (!$head) return '';
	$parsed = explode("/", $head[0], 3);
	return $parsed[2];
}

// =====================

function search($enum) {
	global $page;
	global $cfg;
	$queries = array_intersect_key($_POST, array_flip(preg_grep('/^search-/', array_keys($_POST))));
	$queries = array_filter(array_map('trim_value', $queries), 'remove_empty');
	if (empty($queries)) return false;

	$description = array();
	$ipr_svc_desc = array();
	$ipr_svc_affected = array();
	$search = array();
	$ipr_group = array();
	$use_courses = false;
	$search_courses = array();
	
	if (isset($queries['search-name'])) {
		$query = strip_data($queries['search-name']);
		if (preg_match("/[a-z]/i", $query)) $query = layout_switcher($query);
		$search[] = "AND name LIKE '%".$query."%'";
		$description[] = _('QUERY_NAME').' "'.$query.'"';
	};
	if (isset($queries['search-gender'])) {
		$query = intval($queries['search-gender'])-1;
		$search[] = "AND gender = ".$query;
		switch ($query) {
			case "0":
				$description[] = _('GENDER').' '._('GENDER_MALE');
				break;
			case "1":
				$description[] = _('GENDER').' '._('GENDER_FEMALE');
				break;
		}
	};
	if (isset($queries['search-birthdate-dd'])) {
		$query = intval($queries['search-birthdate-dd']);
		$search[] = "AND EXTRACT(DAY FROM birthdate) = ".$query;
		$description[] = _('DAY').' '._('QUERY_BIRTH').': '.$query;
	};
	if (isset($queries['search-birthdate-mm'])) {
		$query = intval($queries['search-birthdate-mm']);
		$search[] = "AND EXTRACT(MONTH FROM birthdate) = ".$query;
		$description[] = _('MONTH').' '._('QUERY_BIRTH').': '.$query;
	};
	if (isset($queries['search-birthdate-yyyy'])) {
		$query = intval($queries['search-birthdate-yyyy']);
		$search[] = "AND EXTRACT(YEAR FROM birthdate) = ".$query;
		$description[] = _('YEAR').' '._('QUERY_BIRTH').': '.$query;
	};
	if (isset($queries['search-age'])) {
		$query = intval($queries['search-age']);
		$search[] = "AND ((YEAR(CURRENT_DATE) - YEAR(birthdate)) - (DATE_FORMAT(CURRENT_DATE, '%m%d') < DATE_FORMAT(birthdate, '%m%d'))) = ".$query;
		$description[] = _('AGE').', '._('QUERY_AGE').': '.$query;
	}
	if (isset($queries['search-file'])) {
		$query = intval($queries['search-file']);
		$search[] = "AND file = ".$query;
		$description[] = _('FILE_NO').': '.$query;
	};
	if (isset($queries['search-diagnosis'])) {
		$query = strip_data($queries['search-diagnosis']);
		$search[] = "AND diagnosis LIKE '%".$query."%'";
		$description[] = _('QUERY_DIAGNOSIS').' "'.$query.'"';
	};
	if (isset($queries['search-code'])) {
		$query = mb_strtoupper(strip_data($queries['search-code']));
		$search[] = "AND diag_code LIKE '%".$query."%'";
		$description[] = _('ICD10_CODE').' "'.$query.'"';
	};
	if (isset($queries['search-diag-group'])) {
		$query = $enum['diag'][(intval($queries['search-diag-group'])-1)];
		$search[] = "AND diag_group = '".$query."'";
		$description[] = _('DIAGNOSIS_CATEGORY').': '.$query;
	}
	if (isset($queries['search-disabled-group'])) {
		$query = $enum['disabled'][(intval($queries['search-disabled-group'])-1)];
		if (intval($queries['search-disabled-group'])==1) {
			$search[] = "AND status_disabled = 0";
		} else {
			$search[] = "AND status_disabled = 1 AND disabled_group = '".$query."'";
		}
		$description[] = _('DISABLED_GROUP').': '.$query;
	}
	if (isset($queries['search-status-ato'])) {
		$search[] = "AND status_ato = 1";
		$description[] = _('SPECIAL_STATUS').': '._('STATUS_ATO_DESC');
	}
	if (isset($queries['search-status-vpo'])) {
		$search[] = "AND status_vpl = 1";
		$description[] = _('SPECIAL_STATUS').': '._('STATUS_VPO_DESC');
	}
	switch ($queries['search-active']) {
		case "1":
			$search[] = "AND active = 1";
			$description[] = _('ACTIVE');
			break;
		case "0":
			$search[] = "AND active = 0";
			$description[] = _('INACTIVE');
			break;
	}
	if (isset($queries['search-ipr-dd'])) {
		$query = intval($queries['search-ipr-dd']);
		$search[] = "AND EXTRACT(DAY FROM ipr_end) = ".$query;
		$description[] = _('DAY').' '._('QUERY_IPR').': '.$query;
	}
	if (isset($queries['search-ipr-mm'])) {
		$query = intval($queries['search-ipr-mm']);
		$search[] = "AND EXTRACT(MONTH FROM ipr_end) = ".$query;
		$description[] = _('MONTH').' '._('QUERY_IPR').': '.$query;
	}
	if (isset($queries['search-ipr-yyyy'])) {
		$query = intval($queries['search-ipr-yyyy']);
		$search[] = "AND EXTRACT(YEAR FROM ipr_end) = ".$query;
		$description[] = _('YEAR').' '._('QUERY_IPR').': '.$query;
	}
	if (isset($queries['search-register-dd'])) {
		$query = intval($queries['search-register-dd']);
		$search[] = "AND EXTRACT(DAY FROM registered) = ".$query;
		$description[] = _('DAY').' '._('QUERY_REGISTER').': '.$query;
	}
	if (isset($queries['search-register-mm'])) {
		$query = intval($queries['search-register-mm']);
		$search[] = "AND EXTRACT(MONTH FROM registered) = ".$query;
		$description[] = _('MONTH').' '._('QUERY_REGISTER').': '.$query;
	}
	if (isset($queries['search-register-yyyy'])) {
		$query = intval($queries['search-register-yyyy']);
		$search[] = "AND EXTRACT(YEAR FROM registered) = ".$query;
		$description[] = _('YEAR').' '._('QUERY_REGISTER').': '.$query;
	}
	if (isset($queries['search-dismiss-dd'])) {
		$query = intval($queries['search-dismiss-dd']);
		$search[] = "AND EXTRACT(DAY FROM dismissed) = ".$query;
		$description[] = _('DAY').' '._('QUERY_DISMISS').': '.$query;
	}
	if (isset($queries['search-dismiss-mm'])) {
		$query = intval($queries['search-dismiss-mm']);
		$search[] = "AND EXTRACT(MONTH FROM dismissed) = ".$query;
		$description[] = _('MONTH').' '._('QUERY_DISMISS').': '.$query;
	}
	if (isset($queries['search-dismiss-yyyy'])) {
		$query = intval($queries['search-dismiss-yyyy']);
		$search[] = "AND EXTRACT(YEAR FROM dismissed) = ".$query;
		$description[] = _('YEAR').' '._('QUERY_DISMISS').': '.$query;
	}
	if (isset($queries['search-ipr-pcons'])) {
		$search[] = "AND ((pcons IS NOT NULL)&&(pcons != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_PCONS');
		$ipr_svc_affected[] = 'pcons';
	}
	if (isset($queries['search-ipr-ppd'])) {
		$search[] = "AND ((ppd IS NOT NULL)&&(ppd != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_PPD');
		$ipr_svc_affected[] = 'ppd';
	}
	if (isset($queries['search-ipr-ppp'])) {
		$search[] = "AND ((ppp IS NOT NULL)&&(ppp != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_PPP');
		$ipr_svc_affected[] = 'ppp';
	}
	if (isset($queries['search-ipr-ppk'])) {
		$search[] = "AND ((ppk IS NOT NULL)&&(ppk != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_PPK');
		$ipr_svc_affected[] = 'ppk';
	}
	if (isset($queries['search-ipr-fcons'])) {
		$search[] = "AND ((fcons IS NOT NULL)&&(fcons != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_FCONS');
		$ipr_svc_affected[] = 'fcons';
	}
	if (isset($queries['search-ipr-lm'])) {
		$search[] = "AND ((lm IS NOT NULL)&&(lm != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_LM');
		$ipr_svc_affected[] = 'lm';
	}
	if (isset($queries['search-ipr-lfk'])) {
		$search[] = "AND ((lfk IS NOT NULL)&&(lfk != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_LFK');
		$ipr_svc_affected[] = 'lfk';
	}
	if (isset($queries['search-ipr-nosn'])) {
		$search[] = "AND ((nosn IS NOT NULL)&&(nosn != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_NOSN');
		$ipr_svc_affected[] = 'nosn';
	}
	if (isset($queries['search-ipr-spp'])) {
		$search[] = "AND ((spp IS NOT NULL)&&(spp != ''))";
		$ipr_svc_desc[] = _('IPR_SVC_SPP');
		$ipr_svc_affected[] = 'spp';
	}
	if (!empty($ipr_svc_desc)) $description[] = _('IPR_SERVICES').': '.mb_strtolower(implode(', ', $ipr_svc_desc));
	if (isset($queries['search-address'])) {
		$query = strip_data($queries['search-address']);
		$fields = "CONCAT(COALESCE(clients.region, ''),' ',COALESCE(clients.district, ''),' ',COALESCE(clients.city, ''),' ',COALESCE(clients.address, ''))";
		$search[] = "AND ".$fields." LIKE '%".$query."%'";
		$description[] = _('QUERY_ADDRESS').' "'.$query.'"';
	};
	if (isset($queries['search-contacts'])) {
		$query = strip_data($queries['search-contacts']);
		$search[] = "AND contact_data LIKE '%".$query."%'";
		$description[] = _('QUERY_CONTACTS').' "'.$query.'"';
	};
	if (isset($queries['search-comment'])) {
		$query = strip_data($queries['search-comment']);
		$search[] = "AND comment LIKE '%".$query."%'";
		$description[] = _('QUERY_COMMENT').' "'.$query.'"';
	};
	if (isset($queries['search-rnokpp'])) {
		$query = strip_data($queries['search-rnokpp']);
		$search[] = "AND rnokpp = ".$query;
		$description[] = _('QUERY_RNOKPP').': '.$query;
	};
	if (($page->user->accessLevel >= $cfg['access']['admin']) && (isset($queries['search-manual-query']))) {
		$query = $queries['search-manual-query'];
		$search[] = $query;
		$description[] = _('QUERY_MANUAL').': ['.$query.']';
		audit($page->user, '[WARNING] до SQL-запиту додано користувачем наступні умови: "'.$queries['search-manual-query'].'"');
	}
	if (isset($queries['search-ipr-group-psycho'])) {
		$search[] = "AND (IF (pcons = '', 0, 1)||IF (ppd = '', 0, 1)||IF (ppp = '', 0, 1)||IF (ppk = '', 0, 1)) = 1";
		$description[] = _('QUERY_IPR_CATEGORY').' "'._('IPR_CAT_PSYCHO').'"';
		array_push($ipr_svc_affected, 'pcons','ppd','ppp','ppk');
	}
	if (isset($queries['search-ipr-group-phys'])) {
		$search[] = "AND (IF (fcons = '', 0, 1)||IF (lm = '', 0, 1)||IF (lfk = '', 0, 1)) = 1";
		$description[] = _('QUERY_IPR_CATEGORY').' "'._('IPR_CAT_PHYS').'"';
		array_push($ipr_svc_affected, 'fcons','lm','lfk');
	}
	if (isset($queries['search-ipr-group-social'])) {
		$search[] = "AND (IF (nosn = '', 0, 1)||IF (spp = '', 0, 1)) = 1";
		$description[] = _('QUERY_IPR_CATEGORY').' "'._('IPR_CAT_SOCIAL').'"';
		array_push($ipr_svc_affected, 'nosn','spp');
	}
	if (isset($queries['search-course-active'])) {
		$use_courses = true;
		$search_courses[] = "AND course_start <= CURDATE() AND course_end >= CURDATE()";
		$description[] = _('COURSE_ACTIVE');
	}
	if (isset($queries['search-course-start'])) {
		$use_courses = true;
		$query = date("Y-m-d", strtotime($queries['search-course-start']));
		$search_courses[] = "AND course_start = '".$query."'";
		$description[] = _('COURSE_START').': '.date("d.m.Y", strtotime($queries['search-course-start']));
	}
	if (isset($queries['search-course-end'])) {
		$use_courses = true;
		$query = date("Y-m-d", strtotime($queries['search-course-end']));
		$search_courses[] = "AND course_end = '".$query."'";
		$description[] = _('COURSE_END').': '.date("d.m.Y", strtotime($queries['search-course-end']));
	}
	if (isset($queries['search-course-services'])) {
		$use_courses = true;
		$query = strip_data($queries['search-course-services']);
		$search_courses[] = "AND CONCAT(ipr_svc, ' ', additional_svc) LIKE '%".$query."%'";
		$description[] = _('COURSE_SERVICES').' "'.$query.'"';
	}
	$ipr_svc_affected = array_unique($ipr_svc_affected);
	if (empty($ipr_svc_affected)) $ipr_svc_affected = array('pcons','ppd','ppp','ppk','fcons','lm','lfk','nosn','spp');
	if (isset($queries['search-services-fulltext'])) {
		$svc_fulltext = explode(';', strip_data($queries['search-services-fulltext']));
		$description[] = _('IPR_SERVICES_VALUES').': "'.implode('; ', $svc_fulltext).'"';
		$constructor = array();
		foreach ($svc_fulltext as $query) {
			$constructor[] = "CONCAT(".implode(',',$ipr_svc_affected).") LIKE '%".$query."%'";
		}
		$search[] = 'AND ('.implode(') OR (', $constructor).')';
	}
	
	$condition = implode(' ', $search);
	$description = implode('; ', $description);
	$query = "
		SELECT
			id, active, file, rnokpp, name, gender,
			DATE_FORMAT(birthdate, '%d.%m.%Y') AS 'birthdate',
			(
				(YEAR(CURRENT_DATE) - YEAR(birthdate)) -
				(DATE_FORMAT(CURRENT_DATE, '%m%d') < DATE_FORMAT(birthdate, '%m%d'))
			) AS 'age',
			DATE_FORMAT(ipr_end, '%d.%m.%Y') AS 'ipr_end',
			DATE_FORMAT(registered, '%d.%m.%Y') AS 'registered',
			DATE_FORMAT(dismissed, '%d.%m.%Y') AS 'dismissed',
			diagnosis, diag_code, diag_group, status_disabled, disabled_group, status_ato, status_vpl,
			CONCAT (
				IF(region = '".$cfg['home_region']."', '', CONCAT (region, ' обл., ')),
				IF(district IS NULL or district = '', '', CONCAT (district, ' р-н, ')),
				`city`, ', ',
				`address`
			) AS 'address',
			contact_data, additional, comment, incomplete,
			pcons, ppd, ppp, ppk, fcons, lm, lfk, nosn, spp,
			(
				IF (pcons = '', 0, 1) || IF (ppd = '', 0, 1) || IF (ppp = '', 0, 1) || IF (ppk = '', 0, 1)
			) AS 'service_psycho',
			(
				IF (fcons = '', 0, 1) || IF (lm = '', 0, 1) || IF (lfk = '', 0, 1)
			) AS 'service_phys',
			(
				IF (nosn = '', 0, 1) || IF (spp = '', 0, 1)
			) AS 'service_social'
		FROM clients
		INNER JOIN ipr ON clients.id = ipr.user_id
		WHERE 1 = 1 ".$condition."
		ORDER BY name ASC
	";
	
	if (!isset($_POST['export'])) audit($page->user, 'здійснив пошук у базі за наступними умовами: "'.$description.'"');

	try {
		$result = db_select($query);
	} catch (Error $e) {
		audit($page->user, '[ERROR] запит до бази даних викликав помилку');
		return ['clients' => array(), 'description' => $description, 'query' => $query, 'error' => 'sql', 'sql' => $queries['search-manual-query']];
	}
	
	if(!$result) return ['clients' => array(), 'description' => $description, 'query' => $query, 'error' => 'noresult'];

	// постобработка данных запроса
	$clients = processing($result);
	
	// работа с курсами
	if ($use_courses) {
		$id_list = array();
		foreach ($clients as $client) { $id_list[] = $client->id; }
		sort($id_list);
		
		$query = "SELECT user_id FROM courses WHERE user_id IN (".implode(',', $id_list).") ".implode(' ', $search_courses);
		try {
			$uids = db_select($query);
		} catch (Error $e) {
			audit($page->user, '[ERROR] запит до бази даних викликав помилку');
			return ['clients' => array(), 'description' => $description, 'query' => $query, 'error' => 'sql', 'sql' => $queries['search-manual-query']];
		}
		
		$id_list = array_map(function($e) { return (int)$e->user_id; }, $uids);
		$id_list = array_unique($id_list, SORT_REGULAR);
		
		$filtered = array_filter($clients, function($client) use(&$id_list) {
			return in_array($client->id, $id_list);
		});
		$clients = $filtered;
	}
	
	if(empty($clients)) return ['clients' => array(), 'description' => $description, 'query' => $query, 'error' => 'noresult'];
	return ['clients' => $clients, 'description' => $description, 'query' => $query, 'error' => NULL];
}

function save($client, $enum) {
	global $page;
	
	$checkboxes = array('status_ato', 'status_vpl');
	$textareas = array('diagnosis', 'contact_data', 'comment');
	
	$values = array_intersect_key($_POST, array_flip(preg_grep('/^edit-/', array_keys($_POST))));
	$values = array_map('trim_value', $values);
	$values = array_map('strip_array', $values);
	$values = array_combine(
		array_map(
			function($k) { return str_replace('edit-', '', $k); }, 
			array_keys($values)
		),
		array_values($values)
	);
	foreach ($checkboxes as $key) {
		if (!array_key_exists($key, $values)) $values[$key] = '0';
	}
	foreach ($textareas as $key) {
		$values[$key] = strip_textarea($_POST["edit-".$key]);
	}
	if ($_POST['edit-disabled_group'] == '0') {
		$values['status_disabled'] = 0;
		$values['disabled_group'] = $enum['disabled'][0];
	} else {
		$values['status_disabled'] = 1;
		$values['disabled_group'] = $enum['disabled'][(int)$_POST['edit-disabled_group']];
	}
	$values['diag_group'] = $enum['diag'][(int)$_POST['edit-diag_group']];
	$values['region'] = $enum['region'][(int)$_POST['edit-region']];
	
	$services = array_intersect_key($_POST, array_flip(preg_grep('/^ipr-svc-/', array_keys($_POST))));
	$services = array_map('trim_value', $services);
	$services = array_map('strip_array', $services);
	$services = array_combine(
		array_map(
			function($k) { return str_replace('ipr-svc-', '', $k); }, 
			array_keys($services)
		),
		array_values($services)
	);
	
	foreach ($values as $key => $value) { $client->set($key, $value); }
	foreach ($services as $key => $value) { $client->setService($key, $value); }
	
	$result = $client->toDB();
	return $page->twig->render('edit_status.twig', ['page' => $page, 'client' => $client, 'result' => $result]);
}

function noaccess($requiredAccess) {
	global $page;
	$page->title = 'TITLE_NOACCESS';
	$page->self = update_path();
	return $page->twig->render('noaccess.twig', ['page' => $page, 'accessLevel' => $requiredAccess]);
}

function delete($client) {
	global $page;
	if (($client->loaded) && (!$client->new)) {
		$client->delete();
		return $page->twig->render('delete_status.twig', ['page' => $page, 'result' => TRUE]);
	}
	return $page->twig->render('delete_status.twig', ['page' => $page, 'result' => FALSE]);
}

function sortable_list($type, $pagenum) {
	global $page;
	global $cfg;
	if (empty($pagenum)) $pagenum = 1;
	$limit = $cfg['page_limit'];
	$count = db_select("SELECT COUNT(*) as 'clients' FROM clients WHERE active = 1");
	$count = $count[0]->clients;
	if ($_POST['export']) {
		$limit = $count;
		forceLocale('uk_UA');
	}
	$maxpages = (int)ceil($count/$limit);
	if ($pagenum > $maxpages) $pagenum = $maxpages;
	$offset = ($pagenum - 1) * $limit;
	$data_start = ($pagenum -1 ) * $limit + 1;
	$data_end = ($pagenum >= $maxpages) ? $count : ($pagenum * $limit);
	
	$query = "
		SELECT
			id, active, file, rnokpp, name, gender,
			DATE_FORMAT(birthdate, '%d.%m.%Y') AS 'birthdate',
			(
				(YEAR(CURRENT_DATE) - YEAR(birthdate)) -
				(DATE_FORMAT(CURRENT_DATE, '%m%d') < DATE_FORMAT(birthdate, '%m%d'))
			) AS 'age',
			DATE_FORMAT(ipr_end, '%d.%m.%Y') AS 'ipr_end',
			DATE_FORMAT(registered, '%d.%m.%Y') AS 'registered',
			DATE_FORMAT(dismissed, '%d.%m.%Y') AS 'dismissed',
			diagnosis, diag_code, diag_group, status_disabled, disabled_group, status_ato, status_vpl,
			CONCAT (
				IF(region = '".$cfg['home_region']."', '', CONCAT (region, ' обл., ')),
				IF(district IS NULL or district = '', '', CONCAT (district, ' р-н, ')),
				`city`, ', ',
				`address`
			) AS 'address',
			contact_data, additional, comment, incomplete,
			pcons, ppd, ppp, ppk, fcons, lm, lfk, nosn, spp,
			(
				IF (pcons = '', 0, 1) || IF (ppd = '', 0, 1) || IF (ppp = '', 0, 1) || IF (ppk = '', 0, 1)
			) AS 'service_psycho',
			(
				IF (fcons = '', 0, 1) || IF (lm = '', 0, 1) || IF (lfk = '', 0, 1)
			) AS 'service_phys',
			(
				IF (nosn = '', 0, 1) || IF (spp = '', 0, 1)
			) AS 'service_social'
		FROM clients
		INNER JOIN ipr ON clients.id = ipr.user_id
		WHERE active = 1
	";
	
	switch ($type) {
		case 'alphabet':
			$query .= "ORDER BY name LIMIT ".$limit." OFFSET ".$offset;
			$template = 'list_ordinary_results.twig';
			$description = _('LIST_ALPHABET');
			$clients = processing(db_select($query));
			break;
		case 'file':
			$query .= "ORDER BY file DESC LIMIT ".$limit." OFFSET ".$offset;
			$template = 'list_ordinary_results.twig';
			$description = _('LIST_FILE');
			$clients = processing(db_select($query));
			break;
		case 'age':
			$query .= "ORDER BY age LIMIT ".$limit." OFFSET ".$offset;
			$template = 'list_ordinary_results.twig';
			$description = _('LIST_AGE');
			$clients = processing(db_select($query));
			break;
		case 'diag':
			//$query .= "ORDER BY diag_group, diag_code, name";
			$query .= "ORDER BY diag_group, name";
			$template = 'list_diagnosis_results.twig';
			$description = _('LIST_DIAGNOSIS');
			$raw_clients = processing(db_select($query));
			$raw_clients = array_slice($raw_clients, $offset, $limit);
			$diag_groups = array(
				'nocategory' =>	'Категорія не вибрана',
				'cns' =>		'Опорно-рухова та ЦНС',
				'psycho' =>		'Психічні розлади',
				'aural' =>		'Слух',
				'vision' =>		'Зір',
				'visceral' =>	'Внутрішні органи',
				'endocrine' =>	'Ендокринні',
				'oncology' =>	'Онкологія',
				'cardio' =>		'Серцево-судинні',
				'gastro' =>		'Захворювання ШКТ',
				'common' =>		'Супутні захворювання',
				'risk' =>		'Група ризику'
			);
			$clients = array();
			$additional = array();
			$stats = db_select("SELECT diag_group as 'group', COUNT(diag_group) AS 'count' FROM clients WHERE active = 1 GROUP BY diag_group ");

			foreach ($diag_groups as $key => $value) {
				foreach ($raw_clients as $client) {
					if ($client->diag_group == $value) $clients[$key][] = $client;
				}
				foreach ($stats as $entry) {
					if ($entry->group == $value) $additional[$key] = $entry->count;
				}
			}
			break;
		case 'services':
			$query .= "ORDER BY name";
			$template = 'list_services_results.twig';
			$description = _('LIST_SERVICES');
			$service_groups = array('psycho', 'phys', 'social');
			$clients = array();
			$additional = array();
			$raw_clients = processing(db_select($query));
			foreach ($service_groups as $category) {
				$service_name = 'service_'.$category;
				$clients[$category] = array_filter($raw_clients, function($client) use(&$service_name) {
					if ($client->$service_name == true) return true;
					return false;
				});
				$additional[$category] = count($clients[$category]);
			}
			$additional['all'] = array_sum($additional);
			break;
		case 'geo':
			$query .= "ORDER BY region, COALESCE(district, 'яя') ASC, city, name LIMIT ".$limit." OFFSET ".$offset;
			$template = 'list_ordinary_results.twig';
			$description = _('LIST_ADDRESS');
			$clients = processing(db_select($query));
			break;
		case 'outdated':
			$query .= "AND ipr_end <= CURRENT_DATE() ORDER BY name";
			$template = 'list_special_results.twig';
			$description = _('LIST_IPR_OUTDATED');
			$clients = processing(db_select($query));
			break;
		case 'warning':
			$template = 'list_special_results.twig';
			$description = _('LIST_WARNING');
			$query .= "ORDER BY name";
			$clients = processing(db_select($query));
			for($i=count($clients)-1; $i>=0; $i--) {
				$clients[$i]->warning = FALSE;
				$warning_msg = array();
				if ($clients[$i]->incomplete) {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('INCOMPLETE_DESC');
				}
				if ($clients[$i]->diag_group == 'Категорія не вибрана') {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('MSG_WARNING_NO_CATEGORY');
				}
				if (
					($clients[$i]->diag_group == 'Група ризику') && ($clients[$i]->status_disabled == 1) ||
					($clients[$i]->diag_group == 'Група ризику') && ($clients[$i]->disabled_group == 'дитяча') ||
					($clients[$i]->disabled_group == 'немає') && ($clients[$i]->status_disabled == 1)
				) {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('MSG_WARNING_WRONG_STATUS');
				}
				if (
					$clients[$i]->diag_group == 'Група ризику' &&
					(
						($clients[$i]->service_psycho) OR
						($clients[$i]->service_phys) OR
						($clients[$i]->service_social)
					)
				) {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('MSG_WARNING_RISK_IPR');
				}
				if (
					($clients[$i]->status_disabled == 1) &&
					!(
						($clients[$i]->service_psycho) OR
						($clients[$i]->service_phys) OR
						($clients[$i]->service_social)
					)
				) {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('MSG_WARNING_NO_SERVICES');
				}
				if (!strpos(' '.$clients[$i]->contact_data, '0')) {
					$clients[$i]->warning = TRUE;
					$warning_msg[] = _('MSG_WARNING_NO_PHONE');
				}
				$clients[$i]->warning_msg = implode('; ', $warning_msg);
			}
			$data = $clients;
			$clients = array_filter($data, function($client) {
				if ($client->warning == true) return true;
				return false;
			});
			break;
		default:
			return;
	}
	
	if ($_POST['export']) export_list($clients, $type, $description, $additional);
	
	audit($page->user, 'переглядав список кліентів: '.$description.(($pagenum > 1)?', сторінка '.$pagenum:''));
	
	return	$page->twig->render($template, [
		'page' => $page,
		'clients' => $clients,
		'date' => date('d.m.Y'),
		'sort_order' => $description,
		'type' => $type,
		'total' => $maxpages,
		'current' => $pagenum,
		'range' => $data_start.' - '.$data_end,
		'count' => $count,
		'additional' => (isset($additional)) ? $additional : NULL
	]);
}

function select_client() {
	global $page;
	
	$recent = db_select("SELECT file, name FROM clients WHERE active = 1 ORDER BY file DESC LIMIT 5");
	
	return $page->twig->render('client_selector.twig', ['page' => $page, 'recent' => $recent, 'grouped' => get_clients_grouped()]); 
}

function select_date() {
	global $page;
	
	$date = new DateTime();
	$thisMonth = $date->format("m");
	$thisYear = $date->format("Y");
	
	return $page->twig->render('month_selector.twig', ['page' => $page, 'thisYear' => $thisYear, 'thisMonth' => $thisMonth]); 
}

function select_clients() {
	global $page;
	
	return $page->twig->render('client_selector_multiple.twig', ['page' => $page, 'grouped' => get_clients_grouped()]); 
}

function eventlog() {
	global $cfg, $users, $page;
	$log = $cfg['log_path'].$cfg['log_filename'];
	
	audit($page->user, 'дивився журнал подій');

	$handle = @fopen($log, 'r');
	$lines = array_fill(0, $cfg['log_entries']+1, ''); // +1 из-за перевода строки в конце лога

	if ($handle) {
		while (!feof($handle)) {
			$buffer = fgets($handle);
			array_push($lines, $buffer);
			array_shift($lines);
		}
		fclose($handle);
	}
	$lines = array_reverse($lines);

	$parsed =  array();

	foreach ($lines as $line) {
		preg_match('/^\[([\d-]*)\s*([\d:]*)\]\s(\w*)\s\(([\d\.]*)\)\s(.*$)/', $line, $out);
		if (isset($out[1])) {
			$is_error = FALSE;
			$errors = array('[WARNING]', '[ERROR]');
			foreach ($errors as $error) {
				if (strpos($out[5], $error) !== false) $is_error = TRUE;
			}
			$date = DateTime::createFromFormat('Y-m-d H:i:s', $out[1].' '.$out[2]);
			$parsed[] = array(
				'date'			=> $date,
				'timestamp'		=> str_replace('+00:00', 'Z', gmdate('c', strtotime($out[1].' '.$out[2]))),
				'user_id'		=> $out[3],
				'user_name'		=> $users[$out[3]]['displayName'],
				'user_lvl'		=> $users[$out[3]]['accessLevel'],
				'ip'			=> $out[4],
				'action'		=> $out[5],
				'error'			=> $is_error
			);
		}
	}

	return $page->twig->render('eventlog.twig', ['page' => $page, 'events' => $parsed, 'count' => $cfg['log_entries']]);
}

function user_register() {
	global $page;
	global $cfg;
	
	$stage = 'request';
	if (isset($_POST['stage'])) {
		$stage = 'create';
	}
	
	return $page->twig->render('register.twig', [
		'page' => $page,
		'stage' => $stage,
		'editor' => $cfg['access']['editor'],
		'admin' => $cfg['access']['admin'],
		'user_id' => $_POST['user-id'],
		'user_name' => $_POST['user-name'],
		'user_lvl' => $_POST['user-access'],
		'user_pswd' => $_POST['user-password'],
		'user_hash' => $hash = substr(hash('sha256', $cfg['salt'].$_POST['user-password']), 0, 16)
	]);
}

function courses() {
	global $page;

	$query = "SELECT
		courses.id, courses.user_id, courses.course_start, courses.course_end, courses.ipr_svc, courses.additional_svc, courses.comment, clients.name, clients.id AS uid
		FROM courses INNER JOIN clients ON courses.user_id = clients.id
		WHERE courses.course_start <= CURDATE() AND courses.course_end >= CURDATE()
		ORDER BY courses.course_end, clients.name";
	$active_courses = db_select($query);
	for ($i = 0; $i < count($active_courses); $i++) {
		$active_courses[$i]->course_start = \DateTime::createFromFormat("Y-m-d", $active_courses[$i]->course_start);
		$active_courses[$i]->course_end = \DateTime::createFromFormat("Y-m-d", $active_courses[$i]->course_end);
	}

	return $page->twig->render('courses_all.twig', ['page' => $page, 'clients' => get_clients_grouped(TRUE), 'courses' => $active_courses]);
}

// =====================

function export_search($data) {
	global $page;
	
	$clients = clients_obj2array($data['clients']);
	$values = array(
		'clients' => $clients,
		'count' => count($clients),
		'query' => (string)$data['description'],
		'user' => (string)$page->user->displayName,
		'date' => date('Y-m-d H:i:s')
	);
	
	audit($page->user, 'зберіг до файлу результати пошуку за умовами: "'.$values['query'].'"');

	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/search_results.docx');
	$result = $templator->render($document, $values);
	$result->download(_('FILENAME_EXPORT_SEARCH').' '.date("d-m-Y").'.docx');
	exit();
}

function export_list($data, $type, $order, $additional = NULL) {
	global $page;

	$values = array(
		'order' => $order,
		'user' => (string)$page->user->displayName,
		'date' => date('d-m-Y'),
		'fulldate' => date('Y-m-d H:i:s')
	);

	switch ($type) {
		case 'alphabet':
		case 'file':
		case 'age':
		case 'geo':
			$template = 'list_clients.docx';
			$values['clients'] = clients_obj2array($data);
			$values['count'] = count($data); 
			break;
		case 'diag':
			$template = 'list_clients_diagnosis.docx';
			$values['count'] = $additional;
			$values['count']['all'] = array_sum($additional);
			$values['clients0'] = clients_obj2array($data['risk']);
			$values['clients1'] = clients_obj2array($data['cns']);
			$values['clients2'] = clients_obj2array($data['psycho']);
			$values['clients3'] = clients_obj2array($data['aural']);
			$values['clients4'] = clients_obj2array($data['vision']);
			$values['clients5'] = clients_obj2array($data['visceral']);
			$values['clients6'] = clients_obj2array($data['endocrine']);
			$values['clients7'] = clients_obj2array($data['oncology']);
			$values['clients8'] = clients_obj2array($data['cardio']);
			$values['clients9'] = clients_obj2array($data['gastro']);
			$values['clientsa'] = clients_obj2array($data['common']);
			break;
		case 'services':
			$template = 'list_clients_services.docx';
			$values['count'] = $additional;
			$values['clients0'] = clients_obj2array($data['psycho']);
			for ($i = 0; $i < count($values['clients0']); $i++) {
				$ipr_services = array();
				if (!empty($values['clients0'][$i]['pcons'])) $ipr_services[] = _('IPR_SVC_PCONS_SHORT').' - '.$values['clients0'][$i]['pcons'];
				if (!empty($values['clients0'][$i]['ppd'])) $ipr_services[] = _('IPR_SVC_PPD_SHORT').' - '.$values['clients0'][$i]['ppd'];
				if (!empty($values['clients0'][$i]['ppp'])) $ipr_services[] = _('IPR_SVC_PPP_SHORT').' - '.$values['clients0'][$i]['ppp'];
				if (!empty($values['clients0'][$i]['ppk'])) $ipr_services[] = _('IPR_SVC_PPK_SHORT').' - '.$values['clients0'][$i]['ppk'];
				$values['clients0'][$i]['ipr_services'] = implode(', ', $ipr_services);
			}
			$values['clients1'] = clients_obj2array($data['phys']);
			for ($i = 0; $i < count($values['clients1']); $i++) {
				$ipr_services = array();
				if (!empty($values['clients1'][$i]['fcons'])) $ipr_services[] = _('IPR_SVC_FCONS_SHORT').' - '.$values['clients1'][$i]['fcons'];
				if (!empty($values['clients1'][$i]['lm'])) $ipr_services[] = _('IPR_SVC_LM_SHORT').' - '.$values['clients1'][$i]['lm'];
				if (!empty($values['clients1'][$i]['lfk'])) $ipr_services[] = _('IPR_SVC_LFK_SHORT').' - '.$values['clients1'][$i]['lfk'];
				$values['clients1'][$i]['ipr_services'] = implode(', ', $ipr_services);
			}
			$values['clients2'] = clients_obj2array($data['social']);
			for ($i = 0; $i < count($values['clients2']); $i++) {
				$ipr_services = array();
				if (!empty($values['clients2'][$i]['nosn'])) $ipr_services[] = _('IPR_SVC_NOSN_SHORT').' - '.$values['clients2'][$i]['nosn'];
				if (!empty($values['clients2'][$i]['spp'])) $ipr_services[] = _('IPR_SVC_SPP_SHORT').' - '.$values['clients2'][$i]['spp'];
				$values['clients2'][$i]['ipr_services'] = implode(', ', $ipr_services);
			}
			break;
		case 'outdated':
			$template = 'list_outdated.docx';
			$values['clients'] = clients_obj2array($data);
			$values['count'] = count($data);
			break;
	}

	audit($page->user, 'зберіг до файлу список: "'.$order.'"');

	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/'.$template);
	$result = $templator->render($document, $values);
	$result->download(_('FILENAME_EXPORT_LIST').' — '.$order.' '.date("d-m-Y").'.docx');
	exit();
}

function export_docs() {
	switch ($_POST['doctype']) {
		case 'register-order':
		case 'register-notify':
			doc_register();
			break;
		case 'committee-protocol':
			doc_committee_protocol();
			break;
		case 'longterm-plan':
			doc_plan();
			break;
	}
}

// =====================

function doc_register() {
	global $page;
	require_once('lib/NameCaseLib/NCLNameCaseUa.php');

	$file = abs((int)$_POST['file']);
	$id = db_select("SELECT id FROM clients WHERE file = ".$file." LIMIT 1");
	$id = $id[0]->id;
	
	$client = new Client;
	$client->fromDB($id);
	if (!$client->loaded) die();
	
	$nc = new NCLNameCaseUa();
	$name = explode(" ", $client->name);
	($client->gender == 0) ? $gender = NCL::$MAN : $gender = NCL::$WOMAN;
	
	$values = array(
		'file' => $file,
		'date' => date("d.m.Y", strtotime($client->registered)),
		'birthdate' => date("d.m.Y", strtotime($client->birthdate)),
		'nameZnahidnyi' => $nc->qFullName($name[0], $name[1], $name[2], $gender, NCL::$UaZnahidnyi, "S N F"),
		'nameRodovyi' => $nc->qFullName($name[0], $name[1], $name[2], $gender, NCL::$UaRodovyi, "S N F")
	);

	switch ($_POST['doctype']) {
		case 'register-order':
			$template = 'doc_register_order.docx'; 
			$filename = 'Наказ на зарахування';
			break;
		case 'register-notify':
			$template = 'doc_register_notify.docx'; 
			$filename = 'Повідомлення про зарахування';
			break;
	}
	
	audit($page->user, 'створив '.mb_strtolower($filename).' '.$values['nameRodovyi'].' (справа №'.$client->file.')');
	
	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/'.$template);
	$result = $templator->render($document, $values);
	$result->download($filename.' '.$values['nameRodovyi'].'.docx');
	
	exit();
}

function doc_committee_protocol() {
	global $page;
	require_once('lib/NameCaseLib/NCLNameCaseUa.php');

	$date = new DateTime();
	$currentYear = $date->format("Y");
	$targetMonth = abs((int)$_POST['month']);
	
	if ($targetMonth == 0) return;

	$result = db_select("SELECT name, DATE_FORMAT(birthdate, '%d.%m.%Y') AS birthdate, gender FROM clients WHERE EXTRACT(YEAR FROM registered) = ".$currentYear." AND EXTRACT(MONTH FROM registered) = ".$targetMonth);

	$clients = array();
	$nc = new NCLNameCaseUa();
	foreach ($result as $client) {
		$date = "(".$client->birthdate."р.)";
		(($client->gender == 0)) ? $gender = NCL::$MAN : $gender = NCL::$WOMAN;
		$name = explode(" ", $client->name);
		$name = $nc->qFullName($name[0], $name[1], $name[2], $gender, NCL::$UaZnahidnyi, "S N F");
		$clients[] = $name . " " . $date;
	}

	$protocolDay = compute_day(4, 5, $targetMonth, $currentYear);
	$protocolDate = $protocolDay.".".sprintf("%02d", $targetMonth).".".$currentYear;

	$values = array(
		'proto_date'	=> $protocolDate,
		'proto_num'	=> $targetMonth,
		'clients'	=> $clients,
		'clients2'	=> $clients
	);
	
	$docName = 'Протокол засідання приймальної комісії від '.$protocolDate;
	
	audit($page->user, 'створив '.$docName);

	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/doc_commitee_protocol.docx');
	$result = $templator->render($document, $values);
	$result->download($docName.'.docx');
	
	exit();
}

function doc_plan() {
	global $page;
	
	$files = $_POST['files'];
	$month = abs(intval($_POST['month']));
	if (empty($files)) return;
	if (!is_array($files)) return;
	
	forceLocale('uk_UA');
	
	$files = array_map('intval', $files);
	$files = implode(',', $files);
	$date = new DateTime();
	$readable_date = mb_strtolower(_('MONTH'.$month)).' '.$date->format("Y");
	
	$result = db_select("SELECT name, DATE_FORMAT(birthdate, '%d.%m.%Y') AS birthdate, file, city, ipr_services FROM clients WHERE file IN (".$files.")");
	if (empty($result)) return;
	$clients = array();
	foreach ($result as $client) {
		$ipr_services = unserialize(base64_decode($client->ipr_services));
		$readable_services = array();
		if ($ipr_services->psycho->pscons) $readable_services[] = _('IPR_SVC_PCONS_SHORT');
		if ($ipr_services->psycho->ppd) $readable_services[] = _('IPR_SVC_PPD_SHORT');
		if ($ipr_services->psycho->ppp) $readable_services[] = _('IPR_SVC_PPP_SHORT');
		if ($ipr_services->psycho->ppk)  $readable_services[] = _('IPR_SVC_PPK_SHORT');
		if ($ipr_services->phys->phcons) $readable_services[] = _('IPR_SVC_FCONS_SHORT');
		if ($ipr_services->phys->lm) $readable_services[] = _('IPR_SVC_LM_SHORT');
		if ($ipr_services->phys->lfk) $readable_services[] = _('IPR_SVC_LFK_SHORT');
		if ($ipr_services->social->nosn) $readable_services[] = _('IPR_SVC_NOSN_SHORT');
		if ($ipr_services->social->spp) $readable_services[] = _('IPR_SVC_SPP_SHORT');
		if (empty($readable_services)) $readable_services[] = _('NO').' ('._('RISK_GROUP').')';
		$client->ipr_services = implode(', ', $readable_services);
		$clients[] = (array) $client;
	}

	$values = array(
		'date'		=> $readable_date,
		'clients'	=> $clients
	);

	$docName = 'Перспективний план на '.$readable_date;
	
	audit($page->user, 'створив '.$docName);

	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/doc_longterm_plan.docx');
	$result = $templator->render($document, $values);
	$result->download($docName.'.docx');
	
	exit();
}

function doc_soc_year_plan() {
	global $page;
	
	$query = "SELECT
		name, DATE_FORMAT(birthdate, '%d.%m.%Y') AS birthdate, file, diag_code,
		diag_group, DATE_FORMAT(registered, '%d.%m.%Y') AS regdate, ipr_services
	FROM clients ORDER BY file";
	$result = db_select($query);
	if (empty($result)) return;
	
	$data = array();
	foreach ($result as $client) {
		$readable_services = array();
		$ipr_services = unserialize(base64_decode($client->ipr_services));
		if ($ipr_services->psycho->pscons) $readable_services[] = _('IPR_SVC_PCONS_SHORT');
		if ($ipr_services->psycho->ppd) $readable_services[] = _('IPR_SVC_PPD_SHORT');
		if ($ipr_services->psycho->ppp) $readable_services[] = _('IPR_SVC_PPP_SHORT');
		if ($ipr_services->psycho->ppk)  $readable_services[] = _('IPR_SVC_PPK_SHORT');
		if ($ipr_services->phys->phcons) $readable_services[] = _('IPR_SVC_FCONS_SHORT');
		if ($ipr_services->phys->lm) $readable_services[] = _('IPR_SVC_LM_SHORT');
		if ($ipr_services->phys->lfk) $readable_services[] = _('IPR_SVC_LFK_SHORT');
		if ($ipr_services->social->nosn) $readable_services[] = _('IPR_SVC_NOSN_SHORT');
		if ($ipr_services->social->spp) $readable_services[] = _('IPR_SVC_SPP_SHORT');
		if (empty($readable_services)) $readable_services[] = _('NO').' ('._('RISK_GROUP').')';
		$data[] = array(
			'name'		=> $client->name.' ('.$client->file.')',
			'birth'		=> $client->birthdate,
			'diag'		=> (($client->diag_code)?'['.$client->diag_code.'] ':'').$client->diag_group,
			'svc'		=> implode(', ', $readable_services)
		);
	}
	
	$values = array(
		'all'	=> $data,
		'year'	=> date('Y', strtotime('+1 year'))
	);

	$docName = 'Перспективний план соціальних послуг на '.$values['year'];
	
	audit($page->user, 'створив '.$docName);

	$templator = new Templator('cache/docx/');
	$templator->debug = true;
	$document = new WordDocument('templates/docx/doc_socservice_plan.docx');
	$result = $templator->render($document, $values);
	$result->download($docName.'.docx');
	
	exit();
}