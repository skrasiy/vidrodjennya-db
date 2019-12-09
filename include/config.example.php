<?php

// Версия
$cfg['version'] = '2.0';
$cfg['commit_date'] = '2020-01-01';

// Язык по умолчанию
$cfg['default_locale'] = 'uk_UA';

// Записей на страницу в списках
$cfg['page_limit'] = 50;

// Область местонахождения
$cfg['home_region'] = 'Луганська';

// Логгирование
$cfg['log_actions'] = true;
$cfg['log_path'] = '../../logs/';
$cfg['log_filename'] = 'datatabase.log';

// Просмотр лога, число последних записей
$cfg['log_entries'] = 80;

// Авторизация и разграничение прав
$cfg['salt'] = 'zzZ9S4iOd6uwUxXE';
$cfg['access']['editor'] = 3; // Редактирование записей
$cfg['access']['admin'] = 5; // Удаление записей
$cfg['access']['developer'] = 8; // Полный доступ

// Переключение отладка\продакшен
// отключает все кэши и переключает на тестовую базу и отдельный лог
$cfg['debug']['enable'] = false;
$cfg['debug']['db_name_suffix'] = '_dev';

// Подключение к БД
$db['host'] = 'localhost';
$db['port'] = '3360';
$db['name'] = 'vidrodjennya';
$db['user'] = 'vidrodjennya';
$db['pass'] = 'dbpassword';

// Пользователи
// права доступа: 0 запрет использования, 1-2 просмотр, 3-4 просмотр и редактирование, 5 просмотр, редактирование и удаление
//$users["user"] = array("displayName"=>"Username", "hash"=>"12345678abcdef", "accessLevel"=>"1");

?>