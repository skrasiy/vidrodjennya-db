<?PHP
$start = microtime(true);
$_GET["db_queries"] = 0;

$root = 'new';  // '/', 'subfolder', 'folder1/folder2'

require 'include/config.php';
require 'include/functions.php';
require 'include/classes.php';
require 'vendor/autoload.php';

if ($cfg['debug']['enable']) $db['name'] .= $cfg['debug']['db_name_suffix'];

$loader = new \Twig\Loader\FilesystemLoader('templates/html/');
$page = (object) [
	'user'	=> new User,
	'access' => $cfg['access'],
	'twig'	=> new \Twig\Environment($loader, [
				'auto_reload'	=> true,
				'cache'			=> 'cache/html'
			]),
	'root'		=> ($root == '/') ? $root : '/'.$root.'/',
	'self'		=> ($root == '/') ? $root : '/'.$root, // Без слэша на конце
	'debug'		=> $cfg['debug']['enable'],
	'title'		=> '',
	'path'		=> parseURI($_SERVER['REQUEST_URI'], $root),
	'host'		=> (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . '://' . $_SERVER['HTTP_HOST'],
	'locale'	=> $cfg['default_locale']
];
$page->twig->addExtension(new Twig_Extensions_Extension_Intl());
$page->twig->addExtension(new Twig_Extensions_Extension_I18n());

$langs = array('uk_UA', 'ru_RU');
if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $langs)) {
	$page->locale = $_COOKIE['lang'];
}
putenv("LC_ALL={$page->locale}");
putenv("LANG={$page->locale}");
bindtextdomain('messages', 'locale');
textdomain('messages');

if (isset($_COOKIE['auth'])) $page->user->login('cookie', $_COOKIE['auth']);
if ((!$page->user->logged)&&($page->path['slice'][0] != 'login')) {
	$redirpath = implode('/', $page->path['slice']);
	$redirargs = http_build_query($page->path['args'], '', '&');
	$location = 'Location: '.$page->root.'login'.(($redirpath)?'?return='.$redirpath:'').(($redirargs)?'&'.$redirargs:'');
	header($location);
	exit();
}

$html = $page->twig->render('navbar.twig', ['page' => $page, 'config' => $cfg]);

switch ($page->path['slice'][0]) {

	case 'login':
		$page->title = 'TITLE_LOGIN';
		$page->self .= '/login';
		$status = 'authrequired';
		if (isset($_POST['auth'])) $status = doLogin();
		$html .= $page->twig->render('login.twig', ['page' => $page, 'status' => $status]);
		break;

	case 'logout':
		doLogout();
		break;

	case 'search':
		$page->title = 'TITLE_SEARCH';
		$page->self .= '/search';
		$enum = array(
			'diag' => get_enum('diag_group'),
			'disabled' => get_enum('disabled_group')
		);
		$html .= $page->twig->render('search.twig', [
			'page' => $page,
			'post' => $_POST,
			'enum_diag' => $enum['diag'],
			'enum_disabled' => $enum['disabled'],
			'adminAcessLevel' => $cfg['access']['admin']
		]);
		$result = search($enum);
		if (is_array($result['clients'])) {
			if ($_POST['export']) {
				export_search($result);
				exit();
			}
			if (isset($_POST['quickedit']) && ($_POST['quickedit'] == 'true')) {
				if (count($result['clients']) == 1) {
					$id = $result['clients'][0]->id;
					header('Location: '.$page->host.$page->root.'edit/'.$id); 
					exit();
				}
			}
			$html .= $page->twig->render('search_results.twig', [
				'page' => $page,
				'clients' => $result['clients'],
				'count' => count($result['clients']),
				'error' => $result['error'],
				'user_sql' => $result['sql'],
				'description' => $result['description'],
				'debug' => $cfg['debug']['enable'],
				'query' => $result['query']
			]);
		}
		break;

	case 'view':
		$page->title = 'TITLE_CARDVIEW';
		$page->self = update_path();
		$client = new Client;
		$id = abs((int)$page->path['slice']['1']);
		$client->fromDB($id);
		if ($client->loaded) {
			$page->title = _('TITLE_CARDVIEW').': '.$client->name;
			$client->geo = geocode($client);
		}
		$html .= $page->twig->render('cardview.twig', ['page' => $page, 'client' => $client, 'courses' => get_courses($client->id)]);
		audit($page->user, 'відкривав картку клієнта "'.$client->name.'" (справа №'.$client->file.')');
		break;

	case 'edit':
		if ($page->user->accessLevel < $page->access['editor']) {
			$html .= noaccess($page->access['editor']);
			break;
		}
		$page->title = 'TITLE_EDIT_CLIENT';
		$page->self = update_path();
		$id = abs((int)$page->path['slice']['1']);
		$enum = array(
			'diag' => get_enum('diag_group'),
			'disabled' => get_enum('disabled_group'),
			'region' => get_enum('region')
		);
		$client = new Client;
		$client->fromDB($id);
		if ($client->loaded) {
			$page->title = _('TITLE_EDIT_CLIENT').': '.$client->name;
		}
		if (isset($_POST['save']) && $client->loaded) {
			$html .= save($client, $enum);
			break;
		}
		$html .= $page->twig->render('edit.twig', ['page' => $page, 'client' => $client, 'enum' => $enum]);
		break;

	case 'new':
		if ($page->user->accessLevel < $page->access['editor']) {
			$html .= noaccess($page->access['editor']);
			break;
		}
		$page->title = 'TITLE_NEW_CLIENT';
		$page->self .= '/new';
		$enum = array(
			'diag' => get_enum('diag_group'),
			'disabled' => get_enum('disabled_group'),
			'region' => get_enum('region')
		);
		$client = new Client;
		$client->new();
		if (isset($_POST['save']) && $client->loaded) {
			$html .= save($client, $enum);
			break;
		}
		$html .= $page->twig->render('edit.twig', ['page' => $page, 'client' => $client, 'enum' => $enum]);
		break;

	case 'delete':
		if ($page->user->accessLevel < $page->access['admin']) {
			$html .= noaccess($page->access['admin']);
			break;
		}
		$page->title = 'TITLE_DELETE_CLIENT';
		$page->self = update_path();
		$id = abs((int)$page->path['slice']['1']);
		$client = new Client;
		$client->fromDB($id);
		if (isset($_POST['confirm']) && $client->loaded) {
			$html .= delete($client);
			break;
		}
		$html .= $page->twig->render('delete.twig', ['page' => $page, 'client' => $client]);
		break;

	case 'list':
		$page->title = 'LIST';
		$page->self .= '/list';
		$html .= $page->twig->render('list.twig', ['page' => $page]);
		$list_types = array('alphabet', 'file', 'age', 'diag', 'geo', 'services', 'outdated', 'warning');
		if (isset($page->path['slice']['1']) && in_array($page->path['slice']['1'], $list_types)) {
			$html .= sortable_list($page->path['slice']['1'], abs((int)$page->path['slice']['2']));
		}
		break;
		
	case 'docs':
		if ($_POST['export']) {
			$html .= export_docs();
		}
		$page->title = 'DOCS';
		$page->self .= '/docs';
		if(!isset($page->path['slice'][1])) $html .= $page->twig->render('docs.twig', ['page' => $page]);
		// selector
		switch ($page->path['slice'][1]) {
			case 'register-order':
			case 'register-notify':
				$page->title = 'SELECT_ONE';
				$page->self .= '/register-order';
				$html .= select_client();
				break;
			case 'committee-protocol':
				$page->title = 'SELECT_DATE';
				$page->self .= '/committee-protocol';
				$html .= select_date();
				break;
			case 'longterm-plan':
				$page->title = 'SELECT_MULTIPLE';
				$page->self .= '/longterm-plan';
				$html .= select_clients();
				break;
			case 'social-year-plan':
				doc_soc_year_plan();
				break;
		}
		break;
	
	case 'admin':
		$page->self = update_path();
		if ($page->user->accessLevel < $page->access['admin']) {
			$html .= noaccess($page->access['admin']);
			break;
		}
		switch ($page->path['slice'][1]) {
			case 'eventlog':
				$page->title = 'EVENT_LOG';
				$html .= eventlog();
				break;
			case 'register':
				$page->title = 'NEW_USER';
				$html .= user_register();
				break;
			default:
				$html .= $page->twig->render('main.twig', ['page' => $page]);
				break;
		}
		break;
	
	case 'courses':
		$page->title = 'TITLE_EDIT_CLIENT_COURSES';
		$page->self = update_path();
		$id = abs((int)$page->path['slice']['1']);
		$course_id = abs((int)$page->path['slice']['2']);
		$client = new Client;
		$client->fromDB($id);
		if (!$client->loaded) {
			// all active courses
			$html .= courses();
			break;
		}
		if (isset($_POST['course'])) { // save course
			if ($page->user->accessLevel < $page->access['editor']) {
				$html .= noaccess($page->access['editor']);
				break;
			}
			$type = $_POST['course'];
			if ($type == 'new') {
				set_course();
			} elseif ($type == 'edit') {
				set_course($course_id);
				$location = 'Location: '.$page->root.'courses/'.$client->id;
				header($location);
				exit();
				break;
			}
		}
		$page->title = _('TITLE_EDIT_CLIENT_COURSES').': '.$client->name;
		if ($course_id > 0) {
			// edit course
			if ((isset($page->path['slice']['3'])) && ($page->user->accessLevel < $page->access['editor'])) {
				$html .= noaccess($page->access['editor']);
				break;
			}
			switch ($page->path['slice']['3']) {
				case 'edit':
					$html .= $page->twig->render('course_edit.twig', ['page' => $page, 'client' => $client, 'course' => get_course($client->id, $course_id)]);
					break;
				case 'delete':
					delete_course($client->id, $course_id);
					$location = 'Location: '.$page->root.'courses/'.$client->id;
					header($location);
					exit();
					break;
			}
		} else {
			// view courses
			$html .= $page->twig->render('courses.twig', ['page' => $page, 'client' => $client, 'courses' => get_courses($client->id)]);
		}
		break;
		
	case 'api':
		switch ($page->path['slice'][1]) {
			case 'contact':
				$id = abs((int)$page->path['slice']['2']);
				if ($id == 0) break;
				qrcode_contact($id);
				break;
			default:
				break;
		}
		exit;
		break;
	
	case 'test':
		$client = new Client;
		$client->fromDB(112);
		var_dump($client);
		break;

	default:
		$html .= $page->twig->render('main.twig', ['page' => $page]);

}

$header = $page->twig->render('html_header.twig', ['page' => $page]);
$html .= $page->twig->render('html_footer.twig', [
	'page'		=> $page,
	'cfg'		=> $cfg,
	'exec_time'	=> round(microtime(true) - $start, 2),
	'queries'	=> $_GET["db_queries"],
	'js_ver'	=> substr(hash_file('md5', 'static/js/script.js'), -5), // browser cache hack
	'branch'	=> ($page->debug) ? getGitBranch() : ''
]);
$html = $header . $html;

echo $html;
?>