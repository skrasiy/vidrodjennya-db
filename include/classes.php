<?PHP
class User {
	public $accountName;
    public $displayName;
	public $accessLevel;
	public $logged;
	public $licenseData;
	public $licenseStatus;
	public $passwordHash;
	public $localIP;
	public $externalIP;

    function __construct(){
        $this->accountName = 'unregistered';
		$this->displayName = '';
		$this->accesLevel = 0;
		$this->logged = false;
    }
	
	public function login($type, $string) {
		global $cfg;
		global $users;
		switch ($type) {
			case 'password':
				$hash = substr(hash('sha256', $cfg['salt'].$string), 0, 16);
				break;
			case 'cookie':
				$hash = xor_this(base64_decode($_COOKIE["auth"]), $_SERVER['REMOTE_ADDR']);
				break;
		}
		if (empty($users)) {
			// default user
			echo defaultAccount();
			audit($page->user, '[ERROR] файл налаштувань не має жодного запису користувача, створений тимчасовий запис адміністратора за замовчуванням. Він НЕ БУДЕ АКТИВНИМ, поки строку з цим записом не буде внесено до файлу налаштувань.');
			die;
		}
		foreach ($users as $accountName => $account) {
			if ($hash == $account['hash']) {
				$this->accountName = $accountName;
				$this->displayName = $account['displayName'];
				$this->accessLevel = (int)$account['accessLevel'];
				$this->passwordHash = $account['hash'];
				$this->logged = true;
				$this->getAvatar();
			}
		}
	}
	
	private function getAvatar() {
		$avatar_exts = array("jpg", "jpeg", "png");
		foreach ($avatar_exts as $ext) {
			if (file_exists('static/img/avatars/'.$this->accountName.'.'.$ext)) {
				$this->avatar = 'static/img/avatars/'.$this->accountName.'.'.$ext;
			}
		}
	}
}

class Client {
	public $id;
	public $file;
	public $rnokpp;
	public $name;
	public $gender;
	public $birthdate;
	public $age;
	public $registered;
	public $dismissed;
	public $diagnosis;
	public $diag_code;
	public $diag_group;
	public $status_disabled;
	public $disabled_group;
	public $status_ato;
	public $status_vpl;
	public $region;
	public $district;
	public $city;
	public $address;
	public $contact_data;
	public $active;
	public $comment;
	public $incomplete;
	public $ipr_services;
	public $ipr_services_text;
	public $ipr_start;
	public $ipr_end;
	public $svc_psycho = FALSE;
	public $svc_phys = FALSE;
	public $svc_social = FALSE;
	public $changes = array();
	public $changes_ipr = array();
	public $loaded = FALSE;
	public $new = FALSE;
	private $ipr_services_list = array ('pcons','ppd','ppp','ppk','fcons','lm','lfk','nosn','spp');
	private $ipr_services_group = array (
		'psycho'	=> array('pcons','ppd','ppp','ppk'),
		'phys'		=> array('fcons','lm','lfk'),
		'social'	=> array('nosn','spp')
	);
	private $types = array(
		'id' => 'int', 'file' => 'int', 'rnokpp' => 'string', 'name' => 'string', 'gender' => 'int',
		'birthdate' => 'date', 'registered' => 'date', 'dismissed' => 'date', 'diagnosis' => 'string',
		'diag_code' => 'string', 'diag_group' => 'string', 'status_disabled' => 'int', 'disabled_group' => 'string',
		'status_ato' => 'int', 'status_vpl' => 'int', 'region' => 'string', 'district' => 'string',
		'city' => 'string', 'address' => 'string', 'contact_data' => 'string', 'active' => 'int',
		'comment' => 'string', 'incomplete' => 'int'
	);
	private $types_ipr = array(
		'user_id' => 'int', 'ipr_start' => 'date', 'ipr_end' => 'date', 'pcons' => 'string', 'ppd' => 'string',
		'ppp' => 'string', 'ppk' => 'string', 'fcons' => 'string', 'lm' => 'string', 'lfk' => 'string',
		'nosn' => 'string', 'spp' => 'string'
	);
	
	
	function __construct() {
		$this->ipr_services = array_fill_keys($this->ipr_services_list, '');
	}
	
	public function set($property, $value) {
		if (property_exists($this, $property)) {
			if ($this->$property == $value) return $this;
			$this->changes[] = array(
				'oldValue' => $this->$property,
				'newValue' => $value,
				'valueName' => $property
			);
			$this->$property = $value;
			return $this;
		}
	}
	
	public function setService($property, $value) {
		if (array_key_exists($property, $this->ipr_services)) {
			if ($this->ipr_services[$property] == $value) return $this;
			$this->changes_ipr[] = array(
				'oldValue' => $this->ipr_services[$property],
				'newValue' => $value,
				'valueName' => $property
			);
			$this->ipr_services[$property] = $value;
			return $this;
		} elseif (property_exists($this, $property)) {
			if ($this->$property == $value) return $this;
			$this->changes_ipr[] = array(
				'oldValue' => $this->$property,
				'newValue' => $value,
				'valueName' => $property
			);
			$this->$property = $value;
			return $this;
		}
	}
	
	private function getServicesText() {
		$data = array();
		foreach ($this->ipr_services as $key => $value) {
			$svc_name = 'IPR_SVC_'.strtoupper($key).'_SHORT';
			if (!empty($value)) $data[] = _($svc_name);
		}
		$data = (empty($data)) ? NULL : implode(', ', $data);
		return $data;
	}
	
	public function new() {
		global $cfg;
		
		$this->new = TRUE;
		$result = db_select("SHOW TABLE STATUS LIKE 'clients';");
		$this->id = (int)$result[0]->Auto_increment;
		$result = db_select("SELECT file FROM clients ORDER BY file DESC LIMIT 1;");
		$this->file = ++$result[0]->file;
		$this->active = '1';
		$this->registered = date('Y-m-d');
		$this->region = $cfg['home_region'];
		$this->loaded = TRUE;
		return $this;
	}
	
	public function fromDB($id) {
		if (!$id) return FALSE;
		$data = db_select("SELECT * FROM clients INNER JOIN ipr ON clients.id = ipr.user_id WHERE id = ".abs((int)$id));
		if (!$data) return FALSE;
		foreach ($data[0] as $key => $value) {
			if (property_exists($this, $key)) { $this->$key = $value; }
			if (array_key_exists($key, $this->ipr_services)) { $this->ipr_services[$key] = $value; }
		}
		foreach ($this->ipr_services_group as $group => $svc) {
			$svc_group = 'svc_'.$group;
			foreach ($svc as $key) {
				if(!empty($this->ipr_services[$key])) $this->$svc_group = TRUE;
			}
		}
		$age = date_diff(date_create($this->birthdate), date_create(date("d-m-Y")));
		$this->age = $age->format('%y');
		$this->ipr_services_text = $this->getServicesText();
		$this->new = FALSE;
		$this->loaded = TRUE;
		return $this->loaded;
	}
	
	public function toDB() {
	// exit codes
	//	0		nothing changed
	//	-1		db error
	//	>= 1	ok (affected lines)
		global $page;
		
		if (!$this->loaded) return -1;
		

		// clients
		$fields = array();
		$values = array();
		$keyvalues = array();
		$named = array();
		foreach ($this->types as $property => $type) {
			if ($property != 'id') {
				switch ($type) {
					case 'int':
						$fields[] = $property;
						if (empty($this->$property) && ($this->$property != 0)) {
							$integer = "NULL";
						} else {
							$integer = (int)$this->$property;
						}
						$values[] = $integer;
						$named[$property] = $integer;
						break;
					case 'string':
						$fields[] = $property;
						$values[] = "'".$this->$property."'";
						$named[$property] = "'".$this->$property."'";
						break;
					case 'date':
						$date = strtotime($this->$property);
						if ($date < 1) {
							$date = "NULL";
						} else {
							$date = "'".date("Y-m-d", $date)."'";
						}
						$fields[] = $property;
						$values[] = $date;
						$named[$property] = $date;
						break;
					case 'object':
						$serialized = "'".base64_encode(serialize($this->$property))."'";
						$fields[] = $property;
						$values[] = $serialized;
						$named[$property] = $serialized;
						break;
				}
			}
		}
		for ($i = 0; $i < count($fields); $i++) { $keyvalues[] = $fields[$i].'='.$values[$i]; }
		//ipr
		$iprvalues = array();
		foreach ($this->ipr_services_list as $key) { $iprvalues[] = "'".$this->ipr_services[$key]."'"; }
		$named_ipr = array();
		foreach ($this->types_ipr as $property => $type) {
			if ($property != 'id') {
				switch ($type) {
					case 'string':
						$named_ipr[$property] = "'".$this->ipr_services[$property]."'";
						break;
					case 'date':
						$date = strtotime($this->$property);
						if ($date < 1) {
							$named_ipr[$property] = "NULL";
						} else {
							$named_ipr[$property] = "'".date("Y-m-d", $date)."'";
						}
						break;
				}
			}
		}
		
		// query constructor
		if ($this->new) {
			$queries = array();
			$queries[] = "INSERT INTO clients (id, ".implode(', ', $fields).") VALUES (".$this->id.", ".implode(', ', $values)."); ";
			$queries[] = "INSERT INTO ipr (user_id, ipr_start, ipr_end, ".implode(', ', $this->ipr_services_list).") VALUES (".$this->id.", '".$this->ipr_start."', '".$this->ipr_end."', ".implode(', ', $iprvalues).");";
			audit($page->user, 'додав новий запис клієнта, id='.$this->id.', номер справи = '.$this->file);
		} else {
			$changelog = array();
			$queries = array();
			if (!empty($this->changes)){
				$querydata = array();
				foreach ($this->changes as $change) {
					$querydata[$change['valueName']] = $change['valueName'].'='.$named[$change['valueName']];
					$changelog[$change['valueName']] = 'ключ "'.$change['valueName'].'", старе значення = "'.$change['oldValue'].'", нове значення = "'.$change['newValue'].'"';
				}
				$queries[] = "UPDATE clients SET ".implode(", ", $querydata)." WHERE id = ".$this->id.";";
			}
			if (!empty($this->changes_ipr)){
				$querydata = array();
				$ipr_non_svc = array('ipr_start' => 'IPR_STARTED', 'ipr_end' => 'IPR_DATE');
				foreach ($this->changes_ipr as $change) {
					$querydata[$change['valueName']] = $change['valueName']."=".$named_ipr[$change['valueName']];
					if (array_key_exists($change['valueName'], $ipr_non_svc)) {
						$changelog[$change['valueName']] = 'ключ "'._($ipr_non_svc[$change['valueName']]).'", старе значення = "'.$change['oldValue'].'", нове значення = "'.$change['newValue'].'"';
					} else {
						$changelog[$change['valueName']] = 'ключ "ІПР '._('IPR_SVC_'.strtoupper($change['valueName']).'_SHORT').'", старе значення = "'.$change['oldValue'].'", нове значення = "'.$change['newValue'].'"';
					}
				}
				$queries[] = "UPDATE ipr SET ".implode(", ", $querydata)." WHERE user_id = ".$this->id.";";
			}
			if (empty($queries)) return 0;
			audit($page->user, 'змінив дані запису "'.$this->name.'" (id='.$this->id.'): '.implode("; ", $changelog));
		}

		// execute query
		foreach($queries as $query) {
			try {
				$result = db_write($query);
			} catch (Error $e) {
				audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
				return -1;
			}
			if ($result == -1) audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
		}
		return 1;
	}
	
	public function delete() {
		if (!$this->loaded) return FALSE;
		$log_data = array();
		foreach ($this->types as $property => $type) {
			if (($type == 'object') || ($type == 'array')) {
				$log_data[] = $property.'="'.serialize($this->$property).'"';
			} else {
				$log_data[] = $property.'="'.$this->$property.'"';
			}
		}
		$query = "DELETE FROM clients WHERE id = ".$this->id.";";
		try {
			$result = db_write($query);
		} catch (Error $e) {
			audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
			return FALSE;
		}
		audit($page->user, 'видалив запис клієнта "'.$this->name.'" (id='.$this->id.'), дані перед видаленням: '.implode(', ', $log_data));
		return TRUE;
	}
}