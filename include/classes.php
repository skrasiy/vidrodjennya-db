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
			global $cfg;
			global $page;
			$pass = generateRandomString(10);
			$hash = substr(hash('sha256', $cfg['salt'].$pass), 0, 16);
			$account = '$users["admin"] = array("displayName"=>"Default admin", "hash"=>"'.$hash.'", "accessLevel"=>"'.$cfg['access']['admin'].'");';
			echo 'Your users section of config file is empty. Copy & paste this into config file and login with password "'.$pass.'"<br><pre>'.$account.'</pre>';
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
	public $course_start;
	public $course_end;
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
	public $changes = array();
	public $loaded = FALSE;
	public $new = FALSE;
	private $types = array(
		'id' => 'int', 'file' => 'int', 'rnokpp' => 'string', 'name' => 'string', 'gender' => 'int',
		'birthdate' => 'date', 'course_start' => 'date', 'course_end' => 'date', 'registered' => 'date',
		'dismissed' => 'date', 'diagnosis' => 'string', 'diag_code' => 'string', 'diag_group' => 'string',
		'status_disabled' => 'int', 'disabled_group' => 'string', 'status_ato' => 'int', 'status_vpl' => 'int',
		'region' => 'string', 'district' => 'string', 'city' => 'string', 'address' => 'string',
		'contact_data' => 'string', 'active' => 'int', 'comment' => 'string', 'incomplete' => 'int',
		'ipr_services' => 'object'
	);
	private $ipr_locale = array(
		'pscons' => 'IPR_SVC_PCONS_SHORT', 'ppd' => 'IPR_SVC_PPD_SHORT', 'ppp' => 'IPR_SVC_PPP_SHORT', 'ppk' => 'IPR_SVC_PPK_SHORT',
		'phcons' => 'IPR_SVC_FCONS_SHORT', 'lm' => 'IPR_SVC_LM_SHORT', 'lfk' => 'IPR_SVC_LFK_SHORT', 'nosn' => 'IPR_SVC_NOSN_SHORT',
		'spp' => 'IPR_SVC_SPP_SHORT'
	);
	
	function __construct() {
		$this->ipr_services = (object)[
			'psycho'	=> (object) [
				'pscons'	=>	'',
				'ppd'		=>	'',
				'ppp'		=>	'',
				'ppk'		=>	''
			],
			'phys'		=> (object) [
				'phcons'	=>	'',
				'lm'		=>	'',
				'lfk'		=>	''
			],
			'social'	=> (object) [
				'nosn'		=>	'',
				'spp'		=>	''
			]
		];
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
	
	private function getServicesText() {
		$data = array();
		foreach ($this->ipr_services as $svcgroup) {
			foreach ($svcgroup as $key => $value ) {
				if (!empty($value)) $data[] = _($this->ipr_locale[$key]);
			}
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
		$data = db_select("SELECT * FROM clients WHERE id = ".abs((int)$id));
		if (!$data) return FALSE;
		foreach ($data[0] as $key => $value) {
			if ($key == 'ipr_services') $value = unserialize(base64_decode($value));
			if (property_exists($this, $key)) {
				$this->$key = $value;
			}
		}
		$age = date_diff(date_create($this->birthdate), date_create(date("d-m-Y")));
		$this->age = $age->format('%y');
		$this->ipr_services_text = $this->getServicesText();
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
		if ($this->new) {
			$fields = array();
			$values = array();
			foreach ($this->types as $property => $type) {
				switch ($type) {
					case 'int':
						$fields[] = $property;
						if (empty($this->$property) && ($this->$property != 0)) { $values[] = "NULL"; } else { $values[] = (int)$this->$property; }
						break;
					case 'string':
						$fields[] = $property;
						$values[] = "'".$this->$property."'";
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
						break;
					case 'object':
						$fields[] = $property;
						$values[] = "'".base64_encode(serialize($this->$property))."'";
						break;
				}
			}
			$query = "INSERT INTO clients (".implode(', ', $fields).") VALUES (".implode(', ', $values).");";
			audit($page->user, 'додав новий запис клієнта, id='.$this->id.', номер справи = '.$this->file);
			try {
				$result = db_write($query);
			} catch (Error $e) {
				audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
				return -1;
			}
			if ($result == -1) audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
		} else {
			$changelog = array();
			$values = array();
			foreach ($this->changes as $change) {
				$type = $this->types[$change['valueName']];
				switch ($type) {
					case 'string':
						$values[] = $change['valueName']." = '".$change['newValue']."'";
						$changelog[] = 'ключ "'.$change['valueName'].'", старе значення = "'.$change['oldValue'].'", нове значення = "'.$change['newValue'].'"';
						break;
					case 'int':
						if (empty($change['newValue']) && ($change['newValue'] != 0)) {
							$change['newValue'] = 'NULL';
						} else {
							$change['newValue'] = (int)$change['newValue'];
						}
						if (($change['valueName'] == 'file') && ($change['newValue']==0)) return -1;
						$values[] = $change['valueName']." = ".$change['newValue'];
						$changelog[] = 'ключ "'.$change['valueName'].'", старе значення = '.$change['oldValue'].', нове значення = '.$change['newValue'];
						break;
					case 'date':
						if (empty($change['newValue'])) {
							$change['newValue'] = 'NULL';
						} else {
							$date = strtotime($change['newValue']);
							if ($date < 0) return -1;
							$change['newValue'] = "'".date("Y-m-d", $date)."'";
						}
						$values[] = $change['valueName']." = ".$change['newValue'];
						$changelog[] = 'ключ "'.$change['valueName'].'", старе значення = "'.$change['oldValue'].'", нове значення = "'.trim($change['newValue'],"'").'"';
						break;
					case 'object':
						$values[] = $change['valueName']." = '".base64_encode($change['newValue'])."'";
						$changelog[] = 'ключ "'.$change['valueName'].'", старе значення = "'.$change['oldValue'].'", нове значення = "'.$change['newValue'].'"';
						break;
				}
			}
			if (!empty($values)) {
				$query = "UPDATE clients SET ".implode(", ", $values)." WHERE id = ".$this->id.";";
				audit($page->user, 'змінив дані запису "'.$this->name.'" (id='.$this->id.'): '.implode("; ", $changelog));
				try {
					$result = db_write($query);
				} catch (Error $e) {
					audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
					return -1;
				}
				if ($result == -1) audit($page->user, '[ERROR] помилка при внесенні даних до БД, запит: "'.$query.'"');
			} else {
				return 0;
			}
		}
		
		return $result;
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