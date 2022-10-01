#!/usr/bin/php
<?php
set_include_path(get_include_path() . PATH_SEPARATOR . '/usr/local/mgr5/include/php');
define('__MODULE__', 'pmtcinet');

require_once 'bill_func.php';

$longopts  = [
	'command:',
	'subcommand:',
	'id:',
	'item:',
	'lang:',
	'module:',
	'itemtype:',
	'intname:',
	'param:',
	'value:',
	'runningoperation:',
	'level:',
	'addon:',
	// registrar specific
	'tld:',
	'searchstring:',
	'email:',
];

$options = getopt('', $longopts);

function get_item_info($db, $item) {
	$res = $db->query('SELECT * FROM item i WHERE i.id=' . $item);
	$row = $res->fetch_assoc();

	$res = $db->query('SELECT * FROM itemparam WHERE item='.$item);
	while ($param = $res->fetch_assoc()) {
		$row['params'][$param['intname']] = $param['value'];
	}

	return $row;
}

function get_module_params($id) {
	$params = LocalQuery('processing.edit', ['elid' => $id]);
	return $params;
}

function get_item_profiles($db, $item, $module) {
	$param = array();
	$res = $db->query("SELECT sp2i.service_profile AS service_profile, sp2i.type AS type, sp.profiletype, sp2p.externalid AS externalid, sp2p.externalpassword AS externalpassword FROM item i JOIN service_profile2item sp2i ON sp2i.item = i.id JOIN service_profile sp ON sp.id = sp2i.service_profile LEFT JOIN service_profile2processingmodule sp2p ON sp2p.service_profile = sp2i.service_profile AND sp2i.type = sp2p.type AND sp2p.processingmodule = {$module} WHERE i.id = {$item}");
	while ($row = $res->fetch_assoc()) {
		$param[$row['type']] = array();
		$param[$row['type']]['externalid'] = $row['externalid'];
		$param[$row['type']]['externalpassword'] = $row['externalpassword'];
		$param[$row['type']]['service_profile'] = $row['service_profile'];
		$param[$row['type']]['profiletype'] = $row['profiletype'];
		
		$profile_res = $db->query('SELECT * FROM service_profileparam WHERE service_profile=' . $row['service_profile']);
		
		while ($profile_row = $profile_res->fetch_assoc()) {
			$param[$row['type']][$profile_row['intname']] = $profile_row['value'];
		}
	}
	
	return $param;
}

function get_service_profile($db, $id, $module) {
	$profile = $db->query("SELECT sp.id, sp.profiletype, sp.account, sp2p.externalid AS externalid, sp2p.externalpassword AS externalpassword FROM service_profile sp LEFT JOIN service_profile2processingmodule sp2p ON sp2p.service_profile = sp.id AND sp2p.processingmodule = {$module} WHERE sp.id = {$id}");
	$profile = $profile->fetch_assoc();
	if (empty($profile)) return null;
	$profile_res = $db->query('SELECT * FROM service_profileparam WHERE service_profile=' . $profile['id']);
	while ($profile_row = $profile_res->fetch_assoc()) {
		$profile[$profile_row['intname']] = $profile_row['value'];
	}
	
	return $profile;
}

function translit($str) {
	$replace_table = array('а' => 'a','б' => 'b','в' => 'v','г' => 'g','д' => 'd','е' => 'e','ё' => 'yo','ж' => 'zh','з' => 'z','и' => 'i','й' => 'j','к' => 'k','л' => 'l','м' => 'm','н' => 'n','о' => 'o','п' => 'p','р' => 'r','с' => 's','т' => 't','у' => 'u','ф' => 'f','х' => 'h','ц' => 'cz','ч' => 'ch','ш' => 'sh','щ' => 'shh','ъ' => '','ы' => 'y','ь' => '','э' => 'e','ю' => 'yu','я' => 'ya','А' => 'A','Б' => 'B','В' => 'V','Г' => 'G','Д' => 'D','Е' => 'E','Ё' => 'YO','Ж' => 'ZH','З' => 'Z','И' => 'I','Й' => 'J','К' => 'K','Л' => 'L','М' => 'M','Н' => 'N','О' => 'O','П' => 'P','Р' => 'R','С' => 'S','Т' => 'T','У' => 'U','Ф' => 'F','Х' => 'H','Ц' => 'CZ','Ч' => 'CH','Ш' => 'SH','Щ' => 'SHH','Ъ' => '','Ы' => 'Y','Ь' => '','Э' => 'E','Ю' => 'YU','Я' => 'YA');
	return strtr($str, $replace_table);
}

function get_country_info($db, $id) {
	$res = $db->query("SELECT * FROM country WHERE id = {$id}");
	$row = $res->fetch_assoc();
	return $row;
}

function get_country_by_code($db, $code) {
	$res = $db->query("SELECT * FROM country WHERE iso2 = '{$code}'");
	$row = $res->fetch_assoc();
	return $row;
}

function get_country_by_name($db, $name) {
	$res = $db->query("SELECT * FROM country WHERE name = '{$name}' OR name_ru = '{$name}'");
	$row = $res->fetch_assoc();
	return $row;
}

function save_param($id, $name, $value) {
	LocalQuery('service.saveparam', array('elid' => $id, 'name' => $name, 'value' => $value));
}

try {
	$command = $options['command'];
	$runningoperation = (isset($options['runningoperation']) ? (int) $options['runningoperation'] : 0);
	$item = (isset($options['item']) ? (int) $options['item'] : 0);
	
	Debug('COMMAND: '. $command.' OPTIONS: '.json_encode($options));
	
	if ($command === 'features') {
		$config_xml = simplexml_load_string($default_xml_string);
		
		$itemtypes_node = $config_xml->addChild('itemtypes');
		$itemtypes_node->addChild('itemtype')->addAttribute('name', 'domain');
		
		$params_node = $config_xml->addChild('params');
		$params_node->addChild('param')->addAttribute('name', 'host');
		$params_node->addChild('param')->addAttribute('name', 'port');
		$params_node->addChild('param')->addAttribute('name', 'username');
		$params_node->addChild('param')->addAttribute('name', 'cert_path');
		$params_node->addChild('param')->addAttribute('name', 'certkey_path');
		
		$password = $params_node->addChild('param');
		$password->addAttribute('name', 'password');
		$password->addAttribute('crypted', 'yes');
		
		$features_node = $config_xml->addChild('features');
		$features_node->addChild('feature')->addAttribute('name', 'open');
		$features_node->addChild('feature')->addAttribute('name', 'transfer');
		$features_node->addChild('feature')->addAttribute('name', 'resume');
		$features_node->addChild('feature')->addAttribute('name', 'close');
		$features_node->addChild('feature')->addAttribute('name', 'suspend');
		$features_node->addChild('feature')->addAttribute('name', 'prolong');
		$features_node->addChild('feature')->addAttribute('name', 'sync_item');
		$features_node->addChild('feature')->addAttribute('name', 'update_ns');
		$features_node->addChild('feature')->addAttribute('name', 'getbalance');
		$features_node->addChild('feature')->addAttribute('name', 'import');
		$features_node->addChild('feature')->addAttribute('name', 'get_contact_type');
		$features_node->addChild('feature')->addAttribute('name', 'getauthcode');
		$features_node->addChild('feature')->addAttribute('name', 'service_profile_update');
		$features_node->addChild('feature')->addAttribute('name', 'change_owner');
		$features_node->addChild('feature')->addAttribute('name', 'transfer_approve');
		$features_node->addChild('feature')->addAttribute('name', 'transfer_reject');
		$features_node->addChild('feature')->addAttribute('name', 'transfer_to_user');
		
		echo $config_xml->asXML();
	} elseif ($command === 'open' || $command === 'transfer') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);
		$profiles = get_item_profiles($db, $item, $item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		// Создание контактов для домена
		foreach ($profiles as $key => $profile) {
			if (!empty($profile['externalid'])) continue;
			if ($key === 'owner') {
				$profile['location_country'] = get_country_info($db, $profile['location_country']);
				$profile['postal_country'] = get_country_info($db, $profile['postal_country']);

				$contact = $client->command_create('contact', $profile);
				LocalQuery('service_profile2processingmodule.edit', [
					'processingmodule' => $item_info['processingmodule'], 
					'service_profile' => $profile['service_profile'],
					'type' => $key,
					'externalid' => $contact['id'],
					'externalpassword' => $contact['password'],
					'sok' => 'ok',
				]);
				$profiles[$key]['externalid'] = $contact['id'];
				$profiles[$key]['externalpassword'] = $contact['password'];
			} else {
				LocalQuery('service_profile2processingmodule.edit', [
					'processingmodule' => $item_info['processingmodule'], 
					'service_profile' => $profile['service_profile'],
					'type' => $key,
					'externalid' => $profiles['owner']['externalid'],
					'externalpassword' => $profiles['owner']['externalpassword'],
					'sok' => 'ok',
				]);
				$profiles[$key]['externalid'] = $profiles['owner']['externalid'];
				$profiles[$key]['externalpassword'] = $profiles['owner']['externalpassword'];
			}
		}

		if ($command === 'open') {
			$ns = [];
			$ns_num = 0;
			while (!empty($item_info['params']['ns'.$ns_num])) {
				$ns[] = explode('/', $item_info['params']['ns'.$ns_num])[0];
				$ns_num++;
			}
	
			// Создание домена
			$domain = $client->command_create('domain', [
				'domain' => idn_to_ascii($item_info['params']['domain']),
				'period' => round($item_info['period']/12),
				'ns' => $ns,
				'contact_reg' => $profiles['owner']['externalid'],
			]);
			save_param($item, 'externalpassword', $domain['password']);
		} elseif ($command === 'transfer') {
			$domain = $client->command_transfer([
				'op' => 'request',
				'domain' => idn_to_ascii($item_info['params']['domain']),
				'password' => $item_info['params']['auth_code'],
			]);
			save_param($item, 'externalpassword', $item_info['params']['auth_code']);
		}
		
		LocalQuery('domain.open', ['elid' => $item, 'sok' => 'ok']);

		//$client->close();
	} elseif ($command === 'resume') {
		LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
	} elseif ($command === 'suspend') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		$client->command_update_status(idn_to_ascii($item_info['params']['domain']), ['add' => ['clientHold' => 'Payment overdue']]);
		LocalQuery('service.postsuspend', array('elid' => $item, 'sok' => 'ok'));

		//$client->close();
	} elseif ($command === 'close') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		if ($item_info['params']['service_status'] == 6) { // Проходит процедуру смены регистратора
			$client->command_transfer([
				'op' => 'cancel',
				'domain' => idn_to_ascii($item_info['params']['domain']),
				'password' => $item_info['params']['auth_code'],
			]);
		} else {
			$client->command_delete('domain', idn_to_ascii($item_info['params']['domain']));
		}

		LocalQuery('service.postclose', ['elid' => $item, 'sok' => 'ok']);

		//$client->close();
	} elseif ($command === 'prolong') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		$domain_info = $client->command_info('domain', idn_to_ascii($item_info['params']['domain']));
		$statuses = [];
		if (isset($domain_info['data']->infData->status[0])) {
			$index = 0;
			while (isset($domain_info['data']->infData->status[$index])) {
				$statuses[] = (string) $domain_info['data']->infData->status[$index]['s'];
				$index++;
			}
		}

		$period = round($item_info['period']/12);
		$expire_date = new DateTime($item_info['expiredate']);
		$expire_date = $expire_date->modify('-'.$period.' years');
		$expire_date = $expire_date->format('Y-m-d');

		if (in_array('serverRenewProhibited', $statuses) || in_array('serverUpdateProhibited', $statuses) || in_array('clientRenewProhibited', $statuses) || in_array('clientUpdateProhibited', $statuses)) {
			throw new Exception('Домен не может быть продлен, так как имеет статусы: '.implode(', ', $statuses));
		}

		$client->command_renew(idn_to_ascii($item_info['params']['domain']), $expire_date, $period);

		if (in_array('clientHold', $statuses)) {
			$client->command_update_status(idn_to_ascii($item_info['params']['domain']), ['rem' => ['clientHold' => 'Delete status hold']]);
		}

		LocalQuery('service.postprolong', ['elid' => $item, 'sok' => 'ok']);

		//$client->close();
	} elseif ($command === 'sync_item') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		$domain_info = $client->command_info('domain', idn_to_ascii($item_info['params']['domain']));
		$domain_ns = [];
		if (!empty($domain_info['data']->infData->ns->hostObj)) {
			foreach ($domain_info['data']->infData->ns->hostObj as $value) {
				$domain_ns[] = (string) $value;
			}
		}
		for ($i=0; $i < 4; $i++) { 
			if (isset($domain_ns[$i])) {
				save_param($item, 'ns'.$i, $domain_ns[$i]);
			} else {
				save_param($item, 'ns'.$i, '');
			}
		}

		$statuses = [];
		if (isset($domain_info['data']->infData->status[0])) {
			$index = 0;
			while (isset($domain_info['data']->infData->status[$index])) {
				$statuses[] = (string) $domain_info['data']->infData->status[$index]['s'];
				$index++;
			}
		}

		$exp_date = strtotime($domain_info['data']->infData->exDate);
		if ($exp_date) {
			$exp_date = date('Y-m-d', $exp_date);
		}

		if (in_array('ok', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 2]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('inactive', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 3]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('pendingCreate', $statuses) || in_array('pendingUpdate', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 5]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('pendingTransfer', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 6]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('pendingRenew', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 7]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('pendingDelete', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 4]);
			LocalQuery('service.postsuspend', ['elid' => $item, 'sok' => 'ok']);
		} elseif (in_array('clientHold', $statuses) || in_array('serverHold', $statuses)) {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 1]);
			LocalQuery('service.postsuspend', ['elid' => $item, 'sok' => 'ok']);
		} else {
			LocalQuery('service.setstatus', ['elid' => $item, 'service_status' => 2]);
			LocalQuery('service.postresume', ['elid' => $item, 'sok' => 'ok']);
		}

		if ($exp_date) {
			LocalQuery('service.setexpiredate', ['elid' => $item, 'expiredate' => $exp_date]);
		}

		//$client->close();
	} elseif ($command === 'update_ns') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		// Получаем текущие NS домена от регистратора
		$domain_info = $client->command_info('domain', idn_to_ascii($item_info['params']['domain']));
		$domain_ns = [];
		if (!empty($domain_info['data']->infData->ns->hostObj)) {
			foreach ($domain_info['data']->infData->ns->hostObj as $value) {
				$domain_ns[] = (string) $value;
			}
		}

		// Берем NS из параметров биллинга
		$new_ns = [];
		$ns_num = 0;
		while (array_key_exists('ns'.$ns_num, $item_info['params'])) {
			if (!empty($item_info['params']['ns'.$ns_num])) {
				$new_ns[] = explode('/', $item_info['params']['ns'.$ns_num])[0];
			}
			$ns_num++;
		}

		// Сравниваем и разделяем на NS, которые нужно добавить и которые нужно удалить
		$add_ns = [];
		$rem_ns = [];
		foreach ($new_ns as $value) {
			if (!in_array($value, $domain_ns)) {
				$add_ns[] = $value;
			}
		}
		foreach ($domain_ns as $value) {
			if (!in_array($value, $new_ns)) {
				$rem_ns[] = $value;
			}
		}
		
		$client->command_update_ns(idn_to_ascii($item_info['params']['domain']), ['add' => $add_ns, 'rem' => $rem_ns]);
		
		//$client->close();
	} elseif ($command === 'getbalance') {
		$db = dbConnection();

		$modules = $db->query("SELECT * FROM processingmodule WHERE module = 'pmtcinet.php' AND active = 'on'");

		while ($module_info = $modules->fetch_assoc()) {
			$module_params = get_module_params($module_info['id']);

			$client = new EPPClient([
				'id' => $module_info['id'],
				'host' => $module_params['host']['$'],
				'port' => $module_params['port']['$'],
				'username' => $module_params['username']['$'],
				'password' => $module_params['password']['$'],
				'cert_path' => $module_params['cert_path']['$'],
				'certkey_path' => $module_params['certkey_path']['$'],
			]);
			$client->connect();

			$balance_info = $client->command_balance_info();

			$balance = (float) $balance_info['data']->infData->balance->sum;
			$balance = round($balance);
			LocalQuery('processing.savebalance', ['processingmodule' => $module_info['id'], 'balance' => $balance]);
			LocalQuery('processing.saveparam', ['elid' => $module_info['id'], 'param' => 'currency', 'value' => 'RUB']);
			LocalQuery('processing.saveparam', ['elid' => $module_info['id'], 'param' => 'notify_time', 'value' => date('Y-m-d H:i:s')]);
			
			//$client->close();
		}
	} elseif ($command === 'import') {
		Debug('START IMPORT');
		$module = $options['module'];
		$search = array_key_exists('searchstring', $options) ? $options['searchstring'] : '';

		$domains = explode(' ', str_replace('\\', '', $search));

		if (empty($domains)) exit;

		$db = dbConnection();
		$module_params = get_module_params($module);

		$client = new EPPClient([
			'id' => $module,
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		foreach ($domains as $domain) {
			$domain = trim($domain);
			if (empty($domain)) continue;

			try {
				$domain_info = $client->command_info('domain', idn_to_ascii($domain));

				$client_id = (string) $domain_info['data']->infData->clID;
				if ($client_id !== strtoupper($module_params['username']['$'])) continue;

				$domain_ns = [];
				if (!empty($domain_info['data']->infData->ns->hostObj)) {
					foreach ($domain_info['data']->infData->ns->hostObj as $value) {
						$domain_ns[] = (string) $value;
					}
				}

				$statuses = [];
				if (isset($domain_info['data']->infData->status[0])) {
					$index = 0;
					while (isset($domain_info['data']->infData->status[$index])) {
						$statuses[] = (string) $domain_info['data']->infData->status[$index]['s'];
						$index++;
					}
				}

				$exp_date = strtotime($domain_info['data']->infData->exDate);
				if ($exp_date) $exp_date = date('Y-m-d', $exp_date);
				$status_id = 2; // Активен
				$service_status = 2; // Зарегистрирован и делегирован

				if (in_array('ok', $statuses)) {
					$service_status = 2; // Зарегистрирован и делегирован
				} elseif (in_array('inactive', $statuses)) {
					$service_status = 3; // Зарегистрирован но не делегирован
				} elseif (in_array('pendingCreate', $statuses) || in_array('pendingUpdate', $statuses)) {
					$service_status = 5; // Регистрируется
				} elseif (in_array('pendingTransfer', $statuses)) {
					$service_status = 6; // Происходит трансфер
				} elseif (in_array('pendingRenew', $statuses)) {
					$service_status = 7; // На продлении
				} elseif (in_array('pendingDelete', $statuses)) {
					$status_id = 3; // Остановлен
					$service_status = 4; // Удален
				} elseif (in_array('clientHold', $statuses) || in_array('serverHold', $statuses)) {
					$status_id = 3; // Остановлен
					$service_status = 1; // Не оплачен
				}

				// Добавляем домен
				$tld = explode('.', $domain);
				$tld = $tld[count($tld)-1];
				$tld_id = $db->query("SELECT id FROM tld WHERE name = '{$tld}'")->fetch_row()[0];
				$params = [
					'sok' => 'ok',
					'expiredate' => $exp_date,
					'module' => $module,
					'status' => $status_id,
					'service_status' => $service_status,
					'import_pricelist_intname' => $tld_id,
					'import_service_name' => $domain,
					'domain' => $domain,
				];
				foreach ($domain_ns as $key => $ns) {
					$params['ns'.$key] = $ns;
				}
				if (!empty($domain_info['extension'])) {
					foreach ($domain_info['extension'] as $key => $value) {
						$params[$key] = $value;
					}
				}
				Debug('IMPORT DOMAIN: '.json_encode($params, JSON_UNESCAPED_UNICODE));
				$service_import = LocalQuery('processing.import.service', $params);
				Debug('IMPORT DOMAIN RESPONSE: '.json_encode($service_import, JSON_UNESCAPED_UNICODE));
				$service_id = $service_import['service_id']['$'];
				if (empty($service_id)) throw new Exception('Import domain error');
				
				// Добавляем контакты
				$contacts = ['owner' => (string) $domain_info['data']->infData->registrant];
				if (!empty($domain_info['data']->infData->contact)) {
					foreach ($domain_info['data']->infData->contact as $value) {
						$type = (string) $value['type'];
						if ($type === 'billing') $type = 'bill';
						$contacts[$type] = (string) $value;
					}
				}
				$imported_contacts = [];
				foreach ($contacts as $type => $contact) {
					Debug('TRY IMPORT CONTACT: '.$type.' - '.$contact);
					if (!empty($imported_contacts[$contact])) {
						LocalQuery('service_profile2processingmodule.edit', [
							'processingmodule' => $module, 
							'service_profile' => $imported_contacts[$contact]['id'],
							'type' => $type,
							'externalid' => $contact,
							'externalpassword' => $imported_contacts[$contact]['externalpassword'],
							'sok' => 'ok',
						]);
						LocalQuery('service_profile2item.edit', ['sok' => 'ok', 'service_profile' => $imported_contacts[$contact]['id'], 'item' => $service_id, 'type' => $type]);
					} else {
						$contact_info = $client->command_info('contact', $contact);
						$params = [
							'sok' => 'ok',
							'type' => $type,
							'module' => $module,
							'externalid' => $contact,
							'externalpassword' => (!empty($contact_info['data']->infData->authInfo->pw) ? (string) $contact_info['data']->infData->authInfo->pw : ''),
						];
						if (!empty($contact_info['data']->infData->person)) {
							$person = $contact_info['data']->infData->person;
							$loc_name = explode(' ', (string) $person->locPostalInfo->name[0]);
							$int_name = explode(' ', (string) $person->intPostalInfo->name[0]);
							$address = explode(',', (string) $person->locPostalInfo->address[0]);
							$country = get_country_by_name($db, trim($address[1]));
							$country_id = (!empty($country['id']) ? $country['id'] : 182);

							// Тип профиля - физ лицо
							$params['profiletype'] = 1;

							// ФИО
							$params['name'] = (string) $person->locPostalInfo->name[0];
							$params['firstname'] = $int_name[0];
							$params['lastname'] = $int_name[1];
							$params['middlename'] = (!empty($int_name[2]) ? $int_name[2] : '');
							$params['firstname_locale'] = $loc_name[0];
							$params['lastname_locale'] = $loc_name[1];
							$params['middlename_locale'] = (!empty($loc_name[2]) ? $loc_name[2] : '');

							// Адрес
							$params['location_postcode'] = (is_numeric($address[0]) ? $address[0] : '');
							$params['postal_postcode'] = (is_numeric($address[0]) ? $address[0] : '');
							$params['location_country'] = $country_id;
							$params['postal_country'] = $country_id;
							$params['location_state'] = trim($address[2]);
							$params['postal_state'] = trim($address[2]);
							$params['location_city'] = trim($address[3]);
							$params['postal_city'] = trim($address[3]);
							$params['location_address'] = (string) $person->locPostalInfo->address[0];
							$params['postal_address'] = (string) $person->locPostalInfo->address[0];
							$params['postal_addressee'] = (string) $person->locPostalInfo->name[0];

							// Контакты
							$params['phone'] = (string) $person->voice[0];
							$params['email'] = (string) $person->email[0];
							$params['birthdate'] = (string) $person->birthday[0];
							$params['passport_org'] = (string) $person->passport[0];
						} elseif (!empty($contact_info['data']->infData->organization)) {
							$organization = $contact_info['data']->infData->organization;
							$address = explode(',', (string) $organization->locPostalInfo->address[0]);
							$legal_address = explode(',', (string) $organization->legalInfo->address[0]);
							$country = get_country_by_name($db, trim($address[1]));
							$country_id = (!empty($country['id']) ? $country['id'] : 182);
							$legal_country = get_country_by_name($db, trim($legal_address[1]));
							$legal_country_id = (!empty($legal_country['id']) ? $legal_country['id'] : 182);

							// Тип профиля - юр лицо
							$params['profiletype'] = 2;

							// Наименование
							$params['name'] = (string) $organization->locPostalInfo->org[0];
							$params['company'] = (string) $organization->intPostalInfo->org[0];
							$params['company_locale'] = (string) $organization->locPostalInfo->org[0];

							// Адрес
							$params['location_postcode'] = (is_numeric($legal_address[0]) ? $legal_address[0] : '');
							$params['postal_postcode'] = (is_numeric($address[0]) ? $address[0] : '');
							$params['location_country'] = $legal_country_id;
							$params['postal_country'] = $country_id;
							$params['location_state'] = trim($legal_address[2]);
							$params['postal_state'] = trim($address[2]);
							$params['location_city'] = trim($legal_address[3]);
							$params['postal_city'] = trim($address[3]);
							$params['location_address'] = (string) $organization->legalInfo->address[0];
							$params['postal_address'] = (string) $organization->locPostalInfo->address[0];
							$params['postal_addressee'] = (string) $organization->locPostalInfo->org[0];

							// Контакты
							$params['phone'] = (string) $organization->voice[0];
							$params['email'] = (string) $organization->email[0];
							$params['inn'] = (!empty($organization->taxpayerNumbers) ? (string) $organization->taxpayerNumbers[0] : '');
						} else {
							throw new Exception('Undefined contact type');
						}

						Debug('IMPORT PROFILE: '.json_encode($params, JSON_UNESCAPED_UNICODE));
						$profile_import = LocalQuery('processing.import.profile', $params);
						Debug('IMPORT PROFILE RESPONSE: '.json_encode($profile_import, JSON_UNESCAPED_UNICODE));
						$profile_id = $profile_import['profile_id']['$'];
						if (empty($profile_id)) throw new Exception('Import contact error');
						LocalQuery('service_profile2item.edit', ['sok' => 'ok', 'service_profile' => $profile_id, 'item' => $service_id, 'type' => $type]);
						$imported_contacts[$contact] = ['id' => $profile_id, 'externalpassword' => $params['externalpassword']];
					}
				}
			} catch (Exception $e) {
				Error($e->getMessage());
			}
		}

		//$client->close();
	} elseif ($command === 'get_contact_type') {
		$config_xml = simplexml_load_string($default_xml_string);
		$tld = $options['tld'];
		$config_xml->addAttribute('auth_code', 'require');
		$config_xml->addChild('contact_type', 'owner');

		echo $config_xml->asXML();
	} elseif ($command === 'getauthcode') {
		$config_xml = simplexml_load_string($default_xml_string);

		$db = dbConnection();
		$item_info = get_item_info($db, $item);
		$config_xml->addChild('authcode', $item_info['params']['externalpassword']);

		echo $config_xml->asXML();
	} elseif ($command === 'service_profile_update') {
		$db = dbConnection();

		$module_params = get_module_params($options['module']);
		$profile = get_service_profile($db, $options['param'], $options['module']);

		$client = new EPPClient([
			'id' => $options['module'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		$profile['location_country'] = get_country_info($db, $profile['location_country']);
		$profile['postal_country'] = get_country_info($db, $profile['postal_country']);

		$contact = $client->command_update_contact($profile);

		//$client->close();
	} elseif ($command === 'change_owner') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);
		$profile = get_service_profile($db, $options['id'], $item_info['processingmodule']);

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		try {
			if (empty($profile['externalid'])) {
				$profile['location_country'] = get_country_info($db, $profile['location_country']);
				$profile['postal_country'] = get_country_info($db, $profile['postal_country']);
				$contact = $client->command_create('contact', $profile);
				LocalQuery('service_profile2processingmodule.edit', [
					'processingmodule' => $item_info['processingmodule'], 
					'service_profile' => $profile['id'],
					'type' => 'owner',
					'externalid' => $contact['id'],
					'externalpassword' => $contact['password'],
					'sok' => 'ok',
				]);
				$profile['externalid'] = $contact['id'];
			}

			$client->command_update_registrant($item_info['params']['domain'], $profile['externalid']);

			$db->query("UPDATE service_profile2item SET service_profile = {$options['id']} WHERE item = {$item} AND type = 'owner'");

			echo 'Команда выполнена успешно';
		} catch (Exception $e) {
			echo $e->getMessage();
		}

		//$client->close();
	} elseif ($command === 'transfer_approve') {
		$db = dbConnection();

		$module_params = get_module_params($options['module']);

		$client = new EPPClient([
			'id' => $options['module'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		try {
			$client->command_transfer([
				'op' => 'approve',
				'domain' => idn_to_ascii($options['param']),
			]);
			echo 'Команда выполнена успешно';
		} catch (Exception $e) {
			echo $e->getMessage();
		}

		//$client->close();
	} elseif ($command === 'transfer_reject') {
		$db = dbConnection();

		$module_params = get_module_params($options['module']);

		$client = new EPPClient([
			'id' => $options['module'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		try {
			$client->command_transfer([
				'op' => 'reject',
				'domain' => idn_to_ascii($options['param']),
			]);
			echo 'Команда выполнена успешно';
		} catch (Exception $e) {
			echo $e->getMessage();
		}

		//$client->close();
	} elseif ($command === 'contact_info') {
		$db = dbConnection();

		$module_params = get_module_params($options['module']);

		$client = new EPPClient([
			'id' => $options['module'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		$client->command_info('contact', $options['id']);

		//$client->close();
	} elseif ($command === 'transfer_to_user') {
		$db = dbConnection();

		$item_info = get_item_info($db, $item);
		$module_params = get_module_params($item_info['processingmodule']);
		$profile = get_service_profile($db, $options['id'], $item_info['processingmodule']);

		if (empty($profile)) {
			echo 'Error: контакт с ИД '.$options['id'].' не найден';
			exit;
		}

		$user = $db->query("SELECT * FROM user WHERE account = {$profile['account']} AND email = '{$options['email']}'");
		$user = $user->fetch_assoc();
		if (empty($user)) {
			echo 'Error: пользователь '.$options['email'].' не найден';
			exit;
		}

		$client = new EPPClient([
			'id' => $item_info['processingmodule'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);
		$client->connect();

		try {
			if (empty($profile['externalid'])) {
				$profile['location_country'] = get_country_info($db, $profile['location_country']);
				$profile['postal_country'] = get_country_info($db, $profile['postal_country']);
				$contact = $client->command_create('contact', $profile);
				LocalQuery('service_profile2processingmodule.edit', [
					'processingmodule' => $item_info['processingmodule'], 
					'service_profile' => $profile['id'],
					'type' => 'owner',
					'externalid' => $contact['id'],
					'externalpassword' => $contact['password'],
					'sok' => 'ok',
				]);
				$profile['externalid'] = $contact['id'];
			}

			$client->command_update_registrant($item_info['params']['domain'], $profile['externalid']);

			$db->query("UPDATE service_profile2item SET service_profile = {$options['id']} WHERE item = {$item} AND type = 'owner'");
			$db->query("UPDATE item SET account = {$profile['account']} WHERE id = {$item}");
			LocalQuery('tool.clearcache');

			echo 'Команда выполнена успешно';
		} catch (Exception $e) {
			echo $e->getMessage();
		}

		//$client->close();
	} elseif ($command === 'start_session') {
		set_time_limit(3600);

		if (empty($options['id'])) {
			echo "Передайте ИД обработчика в параметре id\n";
			exit;
		}

		$module_params = get_module_params($options['id']);

		if (empty($module_params['module']['$']) || $module_params['module']['$'] !== 'pmtcinet.php') {
			echo "Этот обработчик не поддерживает эту команду\n";
			exit;
		}

		echo "Старт сессии...\n";

		$client = new EPPClient([
			'id' => $options['id'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);

		$started = false;
		for ($i=0; $i < 30; $i++) { 
			try {
				$client->connect();
				$started = true;
			} catch (Exception $e) {
				echo $e->getMessage()."\n";
				echo "Ожидание 60 секунд...\n";
			}
			if ($started) break;
			sleep(60);
		}
		if ($started) {
			echo "Сессия успешно запущена\n";
		} else {
			echo "Не удалось запустить сессию\n";
		}
	} elseif ($command === 'close_session') {
		if (empty($options['id'])) {
			echo "Передайте ИД обработчика в параметре id\n";
			exit;
		}

		$module_params = get_module_params($options['id']);

		if (empty($module_params['module']['$']) || $module_params['module']['$'] !== 'pmtcinet.php') {
			echo "Этот обработчик не поддерживает эту команду\n";
			exit;
		}

		echo "Завершение сессии...\n";

		$client = new EPPClient([
			'id' => $options['id'],
			'host' => $module_params['host']['$'],
			'port' => $module_params['port']['$'],
			'username' => $module_params['username']['$'],
			'password' => $module_params['password']['$'],
			'cert_path' => $module_params['cert_path']['$'],
			'certkey_path' => $module_params['certkey_path']['$'],
		]);

		try {
			$client->close();
			echo "Сессия успешно завершена\n";
		} catch (Exception $e) {
			echo $e->getMessage()."\n";
		}
	}
} catch (Exception $e) {
	if ($runningoperation > 0) {
		$error_xml = simplexml_load_string($default_xml_string);
      $error_node = $error_xml->addChild('error');
		$error_node->addAttribute('type', $e->getMessage());
		LocalQuery('runningoperation.edit', ['sok' => 'ok', 'elid' => $runningoperation, 'errorxml' => $error_xml->asXML()]);
		
		if ($item > 0) {
			LocalQuery('runningoperation.setmanual', ['elid' => $runningoperation]);
			$task_type = LocalQuery('task.gettype', ['operation' => $command])['task_type']['$'];
			if ($task_type != '') {
				LocalQuery('task.edit', ['sok' => 'ok', 'item' => $item, 'runningoperation' => $runningoperation, 'type' => $task_type]);
			}
		}
	}
	Error($e);
	//if (!empty($client)) $client->close();
}

/**
 * Класс для EPP запросов
 */
class EPPClient {
	private $config = [];
	private $ch;
	private $logged = false;
	private $cookie_file = '';
	
	public function __construct($config = [])	{
		$this->config = $config;
		$this->cookie_file =  __DIR__.'/pmtcinet-'.$this->config['id'].'-cookie.txt';

		$this->ch = curl_init('https://'.$this->config['host'].':'.$this->config['port']);
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->ch, CURLOPT_POST, true);
		curl_setopt($this->ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml; charset=utf-8']);
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($this->ch, CURLOPT_COOKIEJAR, $this->cookie_file);
		curl_setopt($this->ch, CURLOPT_COOKIEFILE, $this->cookie_file);
		curl_setopt($this->ch, CURLOPT_SSLCERT, $this->config['cert_path']);
		curl_setopt($this->ch, CURLOPT_SSLKEY, $this->config['certkey_path']);
	}

	/**
	 * Открытие соединения
	 */
	public function connect() {
		if (!file_exists($this->cookie_file)) $this->command_login();
	}

	/**
	 * Отправка данных и получение ответа
	 */
	public function request($data) {
		Debug('EPP SEND DATA: '.$data);
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
		$response = curl_exec($this->ch);
		Debug('EPP RESPONSE: '.$response);

		$response = preg_replace('/<[a-z-]+:/i', '<', $response);
		$response = preg_replace('/<\/[a-z-]+:/i', '</', $response);
		$response = preg_replace('/ xmlns[a-z:-]*=".+?"/i', '', $response);
		$response = preg_replace('/ xsi:[a-z-]+=".+?"/i', '', $response);

		$xml = simplexml_load_string($response);
		if (empty($xml)) throw new Exception('BAD XML: '.$response);
		$result = $xml->response->result[0];
		$data = null;
		if (!empty($xml->response->resData)) {
			$data = $xml->response->resData;
		}
		$out = [
			'code' => (int) $result['code'],
			'msg' => (string) $result->msg,
			'data' => $data,
		];
		//Debug('EPP RESPONSE ARRAY: '.print_r($out, true));
		return $out;
	}

	/**
	 * Генерация ИД транзакции
	 */
	private function generate_clTRID() {
		return uniqid($this->config['username']);
	}

	/**
	 * Авторизация
	 */
	private function command_login() {
		Debug('COMMAND LOGIN');
		$xml = $this->create_xml();

		$command = $xml->addChild('command');

		$login = $command->addChild('login');
		$login->addChild('clID', $this->config['username']);
		$login->addChild('pw', $this->config['password']);

		$options = $login->addChild('options');
		$options->addChild('version', '1.0');
		$options->addChild('lang', 'ru');

		$svcs = $login->addChild('svcs');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-epp-1.0');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-eppcom-1.0');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-contact-1.0');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-domain-1.0');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-domain-1.1');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-host-1.0');
		$svcs->addChild('objURI', 'http://www.ripn.net/epp/ripn-registrar-1.0');
		$ext = $svcs->addChild('svcExtension');
		$ext->addChild('extURI', 'urn:ietf:params:xml:ns:secDNS-1.1');
		$ext->addChild('extURI', 'http://www.tcinet.ru/epp/tci-promo-ext-1.0');

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Login error: '.$response['msg'].' ('.$response['code'].')');
		}

		$this->logged = true;

		return $response;
	}

	/**
	 * Выход
	 */
	private function command_logout() {
		Debug('COMMAND LOGOUT');
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$command->addChild('logout');
		$command->addChild('clTRID', $this->generate_clTRID());
		return $this->request($xml->asXML());
	}

	/**
	 * Создание домена или контакта
	 */
	public function command_create($type, $params) {
		Debug('COMMAND CREATE: '.$type.' PARAMS: '.json_encode($params, JSON_UNESCAPED_UNICODE));

		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$create = $command->addChild('create');

		$password = $this->generate_password();

		if ($type === 'contact') {
			$contact = $create->addChild('contact:create', null, 'http://www.ripn.net/epp/ripn-contact-1.0');
			$contact_id = uniqid();

			$contact->addChild('contact:id', $contact_id);

			if ($params['profiletype'] == 1 || $params['profiletype'] == 3) {
				$person = $contact->addChild('contact:person');

				$info = $person->addChild('contact:intPostalInfo');
				$info->addChild('contact:name', trim($params['lastname'].' '.$params['firstname'].' '.$params['middlename']));
				$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name'].', '.translit($params['postal_state']).', '.translit($params['postal_city']).', '.translit($params['postal_address']));

				$info = $person->addChild('contact:locPostalInfo');
				$info->addChild('contact:name', trim($params['lastname_locale'].' '.$params['firstname_locale'].' '.$params['middlename_locale']));
				$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name_ru'].', '.$params['postal_state'].', '.$params['postal_city'].', '.$params['postal_address']);

				if (!empty($params['birthdate'])) $person->addChild('contact:birthday', $params['birthdate']);
				if (!empty($params['passport'])) $person->addChild('contact:passport', $params['passport'].' выдан '.date('d.m.Y', strtotime($params['passport_date'])).' '.$params['passport_org']);
				$person->addChild('contact:voice', $params['phone']);
				if (!empty($params['fax'])) $person->addChild('contact:fax', $params['fax']);
				$person->addChild('contact:email', $params['email']);
			} else {
				$organization = $contact->addChild('contact:organization');

				$info = $organization->addChild('contact:intPostalInfo');
				$info->addChild('contact:org', $params['company']);
				$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name'].', '.translit($params['postal_state']).', '.translit($params['postal_city']).', '.translit($params['postal_address']));

				$info = $organization->addChild('contact:locPostalInfo');
				$info->addChild('contact:org', $params['company_locale']);
				$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name_ru'].', '.$params['postal_state'].', '.$params['postal_city'].', '.$params['postal_address']);

				$info = $organization->addChild('contact:legalInfo');
				$info->addChild('contact:address', $params['location_postcode'].', '.$params['location_country']['name_ru'].', '.$params['location_state'].', '.$params['location_city'].', '.$params['location_address']);

				if (!empty($params['inn'])) $organization->addChild('contact:taxpayerNumbers', $params['inn']);
				$organization->addChild('contact:voice', $params['phone']);
				if (!empty($params['fax'])) $organization->addChild('contact:fax', $params['fax']);
				$organization->addChild('contact:email', $params['email']);
			}

			$contact->addChild('contact:authInfo')->addChild('pw', $password);
		} elseif ($type === 'domain') {
			$domain = $create->addChild('domain:create', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
			$domain->addChild('domain:name', $params['domain']);
			$domain->addChild('domain:period', $params['period'])->addAttribute('unit', 'y');

			if (!empty($params['ns'])) {
				$ns = $domain->addChild('domain:ns');
				foreach ($params['ns'] as $value) {
					$avail = $this->command_check('host', $value);
					if ($avail == true) $this->command_create('host', ['host' => $value]);
					$ns->addChild('domain:hostObj', $value);
				}
			}

			$domain->addChild('domain:registrant', $params['contact_reg']);

			$domain->addChild('domain:authInfo')->addChild('pw', $password);
		} elseif ($type === 'host') {
			$host = $create->addChild('host:create', null, 'http://www.ripn.net/epp/ripn-host-1.0');
			$host->addChild('host:name', $params['host']);
		}

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Create '.$type.' error: '.$response['msg'].' ('.$response['code'].')');
		}

		if ($type === 'contact') $response['id'] = (string) $response['data']->creData->id;
		$response['password'] = $password;

		return $response;
	}

	/**
	 * Обновление контакта
	 */
	public function command_update_contact($params) {
		Debug('COMMAND UPDATE CONTACT: PARAMS: '.json_encode($params, JSON_UNESCAPED_UNICODE));

		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$update = $command->addChild('update');

		$contact = $update->addChild('contact:update', null, 'http://www.ripn.net/epp/ripn-contact-1.0');

		$contact->addChild('contact:id', $params['externalid']);

		$chg = $contact->addChild('contact:chg');

		if ($params['profiletype'] == 1 || $params['profiletype'] == 3) {
			$person = $chg->addChild('contact:person');

			$info = $person->addChild('contact:intPostalInfo');
			$info->addChild('contact:name', trim($params['lastname'].' '.$params['firstname'].' '.$params['middlename']));
			$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name'].', '.translit($params['postal_state']).', '.translit($params['postal_city']).', '.translit($params['postal_address']));

			$info = $person->addChild('contact:locPostalInfo');
			$info->addChild('contact:name', trim($params['lastname_locale'].' '.$params['firstname_locale'].' '.$params['middlename_locale']));
			$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name_ru'].', '.$params['postal_state'].', '.$params['postal_city'].', '.$params['postal_address']);

			if (!empty($params['birthdate'])) $person->addChild('contact:birthday', $params['birthdate']);
			if (!empty($params['passport'])) $person->addChild('contact:passport', $params['passport'].' выдан '.date('d.m.Y', strtotime($params['passport_date'])).' '.$params['passport_org']);
			$person->addChild('contact:voice', $params['phone']);
			$person->addChild('contact:email', $params['email']);
		} else {
			$organization = $chg->addChild('contact:organization');

			$info = $organization->addChild('contact:intPostalInfo');
			$info->addChild('contact:org', $params['company']);
			$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name'].', '.translit($params['postal_state']).', '.translit($params['postal_city']).', '.translit($params['postal_address']));

			$info = $organization->addChild('contact:locPostalInfo');
			$info->addChild('contact:org', $params['company_locale']);
			$info->addChild('contact:address', $params['postal_postcode'].', '.$params['postal_country']['name_ru'].', '.$params['postal_state'].', '.$params['postal_city'].', '.$params['postal_address']);

			$info = $organization->addChild('contact:legalInfo');
			$info->addChild('contact:address', $params['location_postcode'].', '.$params['location_country']['name_ru'].', '.$params['location_state'].', '.$params['location_city'].', '.$params['location_address']);

			if (!empty($params['inn'])) $organization->addChild('contact:taxpayerNumbers', $params['inn']);
			$organization->addChild('contact:voice', $params['phone']);
			$organization->addChild('contact:email', $params['email']);
		}

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Update contact error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Трансфер домена
	 */
	public function command_transfer($params) {
		Debug('COMMAND TRANSFER: '.json_encode($params, JSON_UNESCAPED_UNICODE));
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$transfer = $command->addChild('transfer');
		$transfer->addAttribute('op', $params['op']);

		$domain = $transfer->addChild('domain:transfer', null, 'http://www.ripn.net/epp/ripn-domain-1.1');
		$domain->addChild('domain:name', $params['domain']);
		if (!empty($params['password'])) $domain->addChild('domain:authInfo')->addChild('pw', $params['password']);

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Transfer error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Проверка домена, контакта или хоста
	 */
	public function command_check($type, $id) {
		Debug('COMMAND CHECK: '.$type.' '.$id);
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$check = $command->addChild('check');
		if ($type === 'contact') {
			$contact = $check->addChild('contact:check', null, 'http://www.ripn.net/epp/ripn-contact-1.0');
			$contact->addChild('contact:id', $id);
		} elseif ($type === 'domain') {
			$domain = $check->addChild('domain:check', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
			$domain->addChild('domain:name', $id);
		} elseif ($type === 'host') {
			$host = $check->addChild('host:check', null, 'http://www.ripn.net/epp/ripn-host-1.0');
			$host->addChild('host:name', $id);
		}

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Check '.$type.' error: '.$response['msg'].' ('.$response['code'].')');
		}

		if ($type === 'contact') {
			$avail = (string) $response['data']->chkData->cd->id['avail'];
		} else {
			$avail = (string) $response['data']->chkData->cd->name['avail'];
		}

		return ($avail === '1' ? true : false);
	}

	/**
	 * Продление домена
	 */
	public function command_renew($domain_name, $current_expire, $renew_years) {
		Debug('COMMAND RENEW: '.$domain_name.' '.$current_expire.' '.$renew_years);
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$renew = $command->addChild('renew');
		$domain = $renew->addChild('domain:renew', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
		$domain->addChild('domain:name', $domain_name);
		$domain->addChild('domain:curExpDate', $current_expire);
		$domain->addChild('domain:period', $renew_years)->addAttribute('unit', 'y');

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Renew error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Удаление домена или контакта
	 */
	public function command_delete($type, $id) {
		Debug('COMMAND DELETE: '.$type.' '.$id);
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$delete = $command->addChild('delete');
		if ($type === 'contact') {
			$contact = $delete->addChild('contact:delete', null, 'http://www.ripn.net/epp/ripn-contact-1.0');
			$contact->addChild('contact:id', $id);
		} elseif ($type === 'domain') {
			$domain = $delete->addChild('domain:delete', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
			$domain->addChild('domain:name', $id);
		}
		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000 && $response['code'] !== 2303) {
			throw new Exception('Delete error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Получение информации о домене или контакте
	 */
	public function command_info($type, $id, $password = null) {
		Debug('COMMAND INFO: '.$type.' '.$id.' '.$password);
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$info = $command->addChild('info');
		if ($type === 'contact') {
			$contact = $info->addChild('contact:info', null, 'http://www.ripn.net/epp/ripn-contact-1.0');
			$contact->addChild('contact:id', $id);
			if ($password) $contact->addChild('contact:authInfo')->addChild('pw', $password);
		} elseif ($type === 'domain') {
			$domain = $info->addChild('domain:info', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
			$domain->addChild('domain:name', $id);
			if ($password) $domain->addChild('domain:authInfo')->addChild('pw', $password);
		}
		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());

		if ($response['code'] !== 1000) {
			throw new Exception('Info error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Обновление владельца домена
	 */
	public function command_update_registrant($domain_name, $id) {
		Debug('COMMAND UPDATE DOMAIN REGISTRANT: '.$domain_name.' '.$id);
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$update = $command->addChild('update');
		$domain = $update->addChild('domain:update', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
		$domain->addChild('domain:name', $domain_name);
		$chg = $domain->addChild('domain:chg');
		$chg->addChild('domain:registrant', $id);

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Update domain error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Обновление NS серверов
	 */
	public function command_update_ns($domain_name, $params) {
		Debug('COMMAND UPDATE NS: '.$domain_name.' '.json_encode($params, JSON_UNESCAPED_UNICODE));
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$update = $command->addChild('update');
		$domain = $update->addChild('domain:update', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
		$domain->addChild('domain:name', $domain_name);
		if (!empty($params['add'])) {
			$add = $domain->addChild('domain:add');
			$ns = $add->addChild('domain:ns');
			foreach ($params['add'] as $value) {
				$avail = $this->command_check('host', $value);
				if ($avail == true) $this->command_create('host', ['host' => $value]);
				$ns->addChild('domain:hostObj', $value);
			}
		}
		if (!empty($params['rem'])) {
			$rem = $domain->addChild('domain:rem');
			$ns = $rem->addChild('domain:ns');
			foreach ($params['rem'] as $value) {
				$ns->addChild('domain:hostObj', $value);
			}
		}

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Update NS error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Обновление статуса
	 */
	public function command_update_status($domain_name, $params) {
		Debug('COMMAND UPDATE STATUS: '.$domain_name.' '.json_encode($params, JSON_UNESCAPED_UNICODE));
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$update = $command->addChild('update');
		$domain = $update->addChild('domain:update', null, 'http://www.ripn.net/epp/ripn-domain-1.0');
		$domain->addChild('domain:name', $domain_name);
		if (!empty($params['add'])) {
			$add = $domain->addChild('domain:add');
			foreach ($params['add'] as $key => $value) {
				$status = $add->addChild('domain:status', $value);
				$status->addAttribute('s', $key);
				$status->addAttribute('lang', 'en');
			}
		}
		if (!empty($params['rem'])) {
			$rem = $domain->addChild('domain:rem');
			foreach ($params['rem'] as $key => $value) {
				$status = $rem->addChild('domain:status', $value);
				$status->addAttribute('s', $key);
				$status->addAttribute('lang', 'en');
			}
		}

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Update status error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Получение информации о балансе
	 */
	public function command_balance_info() {
		Debug('COMMAND BALANCE INFO');
		$xml = $this->create_xml();
		$command = $xml->addChild('command');
		$info = $command->addChild('info');
		$billing = $info->addChild('billing:info', null, 'http://www.tcinet.ru/epp/tci-billing-1.0');
		$billing->addChild('billing:type', 'balance');
		$param = $billing->addChild('billing:param');
		$param->addChild('billing:date', date('Y-m-d'));
		$period = $param->addChild('billing:period', '1');
		$period->addAttribute('unit', 'd');;
		$param->addChild('billing:currency', 'RUB');

		$command->addChild('clTRID', $this->generate_clTRID());

		$response = $this->request($xml->asXML());
		if ($response['code'] !== 1000) {
			throw new Exception('Update error: '.$response['msg'].' ('.$response['code'].')');
		}

		return $response;
	}

	/**
	 * Создание XML объекта
	 */
	private function create_xml() {
		$xml = simplexml_load_string('<?xml version="1.0" encoding="UTF-8" standalone="no"?><epp xmlns="http://www.ripn.net/epp/ripn-epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.ripn.net/epp/ripn-epp-1.0 ripn-epp-1.0.xsd"></epp>');
		return $xml;
	}

	/**
	 * Закрытие соединения
	 */
	public function close() {
		if ($this->ch) {
			$this->command_logout();
			curl_close($this->ch);
			unset($this->ch);
			unlink($this->cookie_file);
		}
	}

	/**
	 * Генерация пароля
	 */
	private function generate_password($length = 10) {
		$arr = array('a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z','A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','1','2','3','4','5','6','7','8','9','0');
		$pass = '';
		$count = count($arr);
		for ($i = 0; $i < $length; $i++) {
			$index = rand(0, $count-1);
			$pass .= $arr[$index];
		}
		return $pass;
	}

}